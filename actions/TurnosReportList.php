<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseData,
    CWebUser;

/**
 * TurnosReportList — lista os repasses de plantão, abertos e fechados.
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * O fechamento de turno é append-only: refechar grava outra linha e nenhuma é
 * sobrescrita. Só que a tela do Repasse mostra apenas o ÚLTIMO fechamento de
 * (data, turno), no banner. Todos os anteriores continuavam no banco sem porta
 * de entrada — chegava neles quem soubesse o id na mão, na URL do PDF.
 *
 * Esta tela é essa porta: uma linha por DOCUMENTO fechado (o histórico
 * inteiro), mais uma linha por turno ABERTO — turno que teve movimento no
 * Diário de Bordo e nunca foi fechado. Clicar num fechado abre o documento
 * congelado, com botão de baixar em PDF; clicar num aberto abre o Repasse ao
 * vivo daquela data/turno.
 *
 * ── Visibilidade ─────────────────────────────────────────────────────────
 *
 * Nenhuma regra nova. O fechado passa pelo mesmo canReadSnapshot() que o PDF
 * aplica (os grupos do autor têm que caber nos do leitor); o aberto vem da
 * contagem de notas, que já é segmentada por grupo compartilhado. Uma tela de
 * listagem é justamente onde uma regra "parecida" viraria vazamento — a lista
 * revelaria a EXISTÊNCIA do que a tela de detalhe recusa a mostrar.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosReportList extends CController {

    use TurnosReportBase;

    /** Janela padrão, em dias, quando a tela abre sem filtro na URL. */
    private const DIAS_PADRAO = 30;

    /** Teto de fechamentos lidos por consulta — ver listClosedReports(). */
    private const TETO_LINHAS = 500;

    protected function init(): void {
        // Página alcançável por GET: em GET o checkCsrfToken() do Zabbix não
        // dispensa a validação, ele FALHA nela (ver CLAUDE.md). Sem isto a
        // tela responde "Acesso negado" sempre.
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'from'   => 'string',
            'to'     => 'string',
            'status' => 'string',
        ]);
    }

    protected function checkPermissions(): bool {
        // Mesma porta do Repasse: qualquer usuário autenticado entra, e o que
        // ele enxerga é decidido linha a linha, não na entrada.
        return !CWebUser::isGuest();
    }

    protected function doAction(): void {
        $hoje = date('Y-m-d');
        $to   = $this->validateDate($this->getInput('to', $hoje));
        $from = $this->validateDate($this->getInput('from',
            date('Y-m-d', strtotime('-' . (self::DIAS_PADRAO - 1) . ' days', strtotime($hoje)))
        ));

        // Intervalo invertido é erro de digitação, não pedido de lista vazia:
        // devolver zero linhas mandaria a pessoa procurar um repasse que
        // existe. Troca-se a ordem e segue.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $status = $this->getInput('status', 'todos');
        if (!in_array($status, ['todos', 'fechados', 'abertos'], true)) {
            $status = 'todos';
        }

        $data = [
            'db_error'      => null,
            'notes_error'   => null,
            'from'          => $from,
            'to'            => $to,
            'status'        => $status,
            'rows'          => [],
            'truncated'     => false,
            'teto'          => self::TETO_LINHAS,
            'is_superadmin' => false,
        ];

        $db = $this->getDb();
        if (!$db) {
            $data['db_error'] = 'Erro ao conectar ao banco de dados.';
            $this->responder($data);
            return;
        }

        $userid       = (int) (CWebUser::$data['userid'] ?? 0);
        $ctx          = $this->resolveUserContext($db, $userid);
        $isSuperadmin = (bool) $ctx['is_superadmin'];

        $data['is_superadmin'] = $isSuperadmin;

        $fechados = $this->listClosedReports($db, $from, $to, $userid, $isSuperadmin, $ctx, self::TETO_LINHAS);
        $notas    = $this->countNotesByShift($db, $from, $to, $userid, $isSuperadmin);

        // Rótulo legível do turno ("Diurno (07:00–19:00)"). Turno desativado ou
        // removido depois do fechamento não está mais em queryShiftOptions —
        // nesse caso o código cru é o que sobra, e a view marca como inativo.
        $shiftOptions = $this->queryShiftOptions($db, $ctx)['options'];

        $db->close();

        if ($fechados['error']) {
            // Distinguir "nenhum repasse no período" de "a consulta quebrou" é
            // regra do módulo: a mensagem errada manda procurar no lugar errado.
            $data['db_error'] = 'Não foi possível ler os repasses fechados.'
                . ' Confira o log do PHP-FPM (prefixo [plantonistas]).';
        }

        if ($notas['error']) {
            // Precisa aparecer, e não pode ser confundido com "nenhum turno em
            // aberto": é a contagem de notas que DEFINE o turno aberto, então a
            // falha dela apaga a metade aberta da lista inteira — a tela diria
            // que está tudo fechado, que é a conclusão mais perigosa possível.
            $data['notes_error'] = 'A contagem do Diário de Bordo falhou: os turnos em aberto'
                . ' podem não estar listados e a coluna Notas fica zerada.'
                . ' Confira o log do PHP-FPM (prefixo [plantonistas]).';
        }

        $data['truncated'] = $fechados['truncated'];
        $data['rows']      = $this->montarLinhas(
            $fechados['rows'], $notas['rows'], $shiftOptions, $status
        );

        $this->responder($data);
    }

    /**
     * Junta fechamentos e turnos abertos numa lista só, já ordenada.
     *
     * Um turno é ABERTO quando teve nota e não tem NENHUM fechamento visível
     * naquela (data, turno) — por isso a chave é montada dos dois lados com o
     * mesmo formato ("data|código"), e não por comparação de rótulo.
     */
    private function montarLinhas(array $fechados, array $notas,
                                  array $shiftOptions, string $status): array {
        $linhas       = [];
        $temFechamento = [];
        // Nº de ordem do documento dentro do mesmo (data, turno): o mais
        // recente é o vigente, os demais são refechamentos anteriores. Sem
        // isso, três linhas idênticas na tela e nenhuma pista de qual vale.
        $porTurno = [];

        foreach ($fechados as $r) {
            $code  = (string) $r['shift_name'];
            $chave = $r['shift_date'] . '|' . $code;

            $temFechamento[$chave] = true;
            $porTurno[$chave]      = ($porTurno[$chave] ?? 0) + 1;

            $linhas[] = [
                'tipo'        => 'fechado',
                'id'          => (int) $r['id'],
                'date'        => $r['shift_date'],
                'code'        => $code,
                'shift_label' => $this->rotuloTurno($code, $shiftOptions, $notas, $r['shift_date']),
                'shift_ativo' => isset($shiftOptions[$code]),
                'closed_at'   => (string) $r['generated_at'],
                'closed_by'   => (string) $r['closed_by_label'],
                'noc_context' => $r['noc_context'] !== null ? (string) $r['noc_context'] : '',
                'ordem'       => $porTurno[$chave],
                'notas'       => (int) ($notas[$chave]['notas'] ?? 0),
                'last_note'   => (string) ($notas[$chave]['last_note'] ?? ''),
            ];
        }

        foreach ($notas as $chave => $n) {
            if (isset($temFechamento[$chave])) {
                continue;
            }

            $linhas[] = [
                'tipo'        => 'aberto',
                'id'          => 0,
                'date'        => $n['date'],
                'code'        => $n['code'],
                'shift_label' => $this->rotuloTurno($n['code'], $shiftOptions, $notas, $n['date']),
                'shift_ativo' => isset($shiftOptions[$n['code']]),
                'closed_at'   => '',
                'closed_by'   => '',
                'noc_context' => '',
                'ordem'       => 0,
                'notas'       => (int) $n['notas'],
                'last_note'   => (string) $n['last_note'],
            ];
        }

        // Quantos fechamentos o turno tem ao todo, para a etiqueta dizer
        // "fechamento 2 de 3" em vez de só um número. Precisa de uma segunda
        // passada: o total só é conhecido depois de percorrer tudo.
        //
        // A numeração exibida é CRONOLÓGICA (1 = o primeiro fechamento do
        // turno), enquanto 'ordem' conta do mais novo para o mais velho, que é
        // a ordem em que as linhas aparecem. Sem a inversão, o fechamento
        // original — que não é refazimento de nada — receberia o maior número.
        foreach ($linhas as &$l) {
            $total = $l['tipo'] === 'fechado' ? ($porTurno[$l['date'] . '|' . $l['code']] ?? 1) : 0;
            $l['ordem_total'] = $total;
            $l['ordem_cron']  = $total > 0 ? $total - $l['ordem'] + 1 : 0;
        }
        unset($l);

        if ($status !== 'todos') {
            $alvo   = $status === 'fechados' ? 'fechado' : 'aberto';
            $linhas = array_values(array_filter($linhas, fn($l) => $l['tipo'] === $alvo));
        }

        // Data desc; dentro do dia, turnos agrupados; dentro do turno, o
        // fechamento mais novo primeiro ('ordem' 1 é o vigente — a consulta já
        // devolve por generated_at DESC). Aberto e fechado nunca disputam a
        // mesma posição: um turno com fechamento não gera linha aberta.
        //
        // `?:` encadeado em vez de comparar dois arrays de uma vez: as chaves
        // não têm todas a mesma direção, e inverter uma delas dentro do array
        // (o velho truque de trocar $a por $b só naquele item) é o tipo de
        // detalhe que passa despercebido na próxima leitura.
        //
        // `strcmp` no código do turno, e NÃO `<=>`: o código é '24h' ou o id do
        // turno em texto ('9', '12'), e o `<=>` do PHP 8 compara duas strings
        // numéricas como NÚMERO e uma numérica contra uma não-numérica como
        // TEXTO. Misturando os dois no mesmo dia sai um ciclo
        // ('9' < '12' < '24h' < '9'), o comparador deixa de ser transitivo e o
        // usort devolve ordem indefinida — os refechamentos parariam de ficar
        // logo abaixo do fechamento vigente, que é a pista que a tela promete.
        usort($linhas, function ($a, $b) {
            return ($b['date'] <=> $a['date'])
                ?: strcmp((string) $a['code'], (string) $b['code'])
                ?: ($a['ordem'] <=> $b['ordem']);
        });

        return $linhas;
    }

    /**
     * Código do turno → rótulo legível.
     *
     * Ordem das tentativas: turno ativo (rótulo completo, com horário) → nome
     * gravado na nota (sobrevive ao turno renomeado ou desativado, porque é um
     * snapshot da época) → o código cru, que ao menos identifica.
     */
    private function rotuloTurno(string $code, array $shiftOptions,
                                 array $notas, string $date): string {
        if (isset($shiftOptions[$code])) {
            return (string) $shiftOptions[$code];
        }

        $nomeNota = $notas[$date . '|' . $code]['shift_name'] ?? '';
        if ($nomeNota !== '' && $nomeNota !== $code) {
            return $nomeNota;
        }

        return $code;
    }

    private function responder(array $data): void {
        $response = new CControllerResponseData($data);
        $response->setTitle(_('Repasses de Plantão'));
        $this->setResponse($response);
    }
}
