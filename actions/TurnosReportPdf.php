<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosReportPdf — Gera versão standalone para impressão/PDF.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 */
class TurnosReportPdf extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'date'  => 'string',
            'shift' => 'string',
            'limit' => 'string',
            // Com report_id, renderiza o snapshot de um turno FECHADO em vez
            // de consultar o banco — é assim que o documento da issue #2 é
            // exibido, sem precisar de tela nova.
            'report_id' => 'int32',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    // ── Helpers de formatação ────────────────────────────────

    private function sevLabel(int $s, array $severities = []): string {
        // Nome REAL de Administração > Geral > Opções de exibição de
        // acionadores (ver TurnosReportBase::querySeverities()). O array
        // abaixo só entra se a consulta falhar, e mesmo assim é o default de
        // fábrica do Zabbix — nunca foi "Minor"/"Major"/"Critical".
        if (isset($severities[$s]['name']) && $severities[$s]['name'] !== '') {
            return $severities[$s]['name'];
        }
        return [0=>'Not classified',1=>'Information',2=>'Warning',3=>'Average',4=>'High',5=>'Disaster'][$s] ?? 'N/A';
    }

    private function sevClass(int $s): string {
        return [0=>'notclass',1=>'info',2=>'warn',3=>'avg',4=>'high',5=>'disaster'][$s] ?? 'info';
    }

    /**
     * Contagem exibível de uma das 4 tabelas de alarme: "50+" quando a
     * consulta bateu no teto de linhas. Mesma regra da tela ao vivo — ver
     * rp_alertCount() na view e TurnosReportBase::capAlertRows().
     */
    private function alertCount(array $rows, array $truncated, string $chave): string {
        return count($rows) . (!empty($truncated[$chave]) ? '+' : '');
    }

    /** Aviso impresso abaixo da tabela quando houve corte. */
    private function alertCut(array $truncated, string $chave): string {
        return empty($truncated[$chave])
            ? ''
            : '<div class="rp-muted" style="padding:6px 12px">Exibindo as ' . $this->alertRowLimit()
              . ' primeiras linhas: há mais alarmes neste recorte do que a tabela mostra.</div>';
    }

    private function fmtDuration(int $s): string {
        if ($s < 60)   return $s . 's';
        if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
        return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    }

    /**
     * Idade de alarme no formato da coluna "Duração" de Monitoramento > Problemas
     * (até três unidades: "1M 3d 4h", "2d 5h 50m", "3h 55m 20s"). Mesma
     * função nativa que a tela ao vivo usa em rp_age() — o `\` é obrigatório
     * porque `convertUnitsS()` é global e esta classe está em namespace.
     * fmtDuration() continua servindo MTTA/MTTR, que são duração medida.
     */
    private function fmtAge(int $s): string {
        if ($s < 1) {
            return '0s';
        }

        return function_exists('convertUnitsS') ? \convertUnitsS($s, true) : $this->fmtDuration($s);
    }

    /**
     * Nome VISÍVEL do host (`hosts.name`), que é o que o Zabbix mostra nas telas
     * de monitoramento; cai no técnico (`hosts.host`) só se o visível vier vazio.
     * Mesma regra de rp_hostLabel() na view ao vivo.
     */
    private function hostLabel(array $r): string {
        $visivel = trim((string)($r['host_name'] ?? ''));

        return $visivel !== '' ? $visivel : (string)($r['host'] ?? '');
    }

    /**
     * Mesma renderização de rp_actionChips() na view ao vivo — duplicada
     * aqui porque este arquivo não inclui a view compartilhada (é
     * echo/die() próprio, ver cabeçalho da classe e o Backlog do CLAUDE.md).
     * A QUERY e a decodificação do bitmask não são duplicadas — só o HTML.
     */
    private function actionChips(array $entrada, array $severities): string {
        $items = $entrada['items'] ?? [];
        if (empty($items)) {
            return '<span class="rp-muted">—</span>';
        }
        // Sem resumo (snapshot fechado antes desta versão), a notificação volta
        // a ser um selo por item — igual à tela ao vivo, e pelo mesmo motivo:
        // documento fechado não perde informação por melhoria posterior.
        $resumo = $entrada['notify_summary'] ?? null;

        // Agrupado por TIPO, como na tela ao vivo: um tipo que se repete vira um
        // selo com a contagem em vez de três selos iguais. ACK e "ACK removido"
        // são tipos diferentes e continuam separados — juntar colocar e tirar
        // num contador só esconderia a diferença entre os dois.
        $porTipo = [];
        foreach ($items as $indice => $it) {
            $tipo = (string)($it['type'] ?? '');
            // Notificação sai do laço: todas viram um selo só, igual à tela ao
            // vivo. No papel isso pesa ainda mais — a linha do alarme não
            // quebra em três por causa de trinta selos iguais.
            if ($tipo === 'notify') {
                if ($resumo !== null) {
                    continue;
                }
                // Sem resumo (snapshot antigo): uma por uma, como era. Agrupar
                // por tipo daria um contador com o rótulo da primeira mídia.
                $tipo = 'notify#' . $indice;
            }
            $detail = trim(
                ($it['who'] ? $it['who'] . ' — ' : '')
                . date('d/m/Y H:i', (int)$it['when'])
            );
            if ($tipo === 'severity') {
                $detail .= ': ' . $this->sevLabel((int)($it['old_severity'] ?? 0), $severities)
                         . ' → ' . $this->sevLabel((int)($it['new_severity'] ?? 0), $severities);
            } elseif (!empty($it['message'])) {
                $detail .= ': ' . $it['message'];
            }
            $porTipo[$tipo]['label']     = (string)$it['label'];
            $porTipo[$tipo]['detalhes'][] = $detail;
        }

        $chips = [];
        foreach ($porTipo as $chave => $grupo) {
            $qtd  = count($grupo['detalhes']);
            // A chave pode ter sufixo (notify#3); a CLASSE é só o tipo.
            $tipo = explode('#', (string)$chave)[0];
            $chips[] = '<span class="rp-act rp-act-' . htmlspecialchars($tipo) . '" title="'
                     . htmlspecialchars(implode(' · ', $grupo['detalhes'])) . '">'
                     . htmlspecialchars($grupo['label'] . ($qtd > 1 ? ' (' . $qtd . ')' : ''))
                     . '</span>';
        }

        if ($resumo) {
            $falhas = (int)$resumo['falhas'];
            $chips[] = '<span class="rp-act rp-act-' . ($falhas > 0 ? 'unack' : 'notify') . '" title="'
                     . htmlspecialchars($this->notifySummaryText($resumo)) . '">'
                     . htmlspecialchars('Notificações (' . (int)$resumo['total']
                        . $this->falhasSufixo($falhas) . ')')
                     . '</span>';
        }

        return '<div class="rp-act-list">' . implode('', $chips) . '</div>';
    }

    /** " · 3 falhas", " · 1 falha", ou nada. Mesmo texto da tela ao vivo. */
    private function falhasSufixo(int $falhas): string {
        if ($falhas <= 0) {
            return '';
        }

        return ' · ' . $falhas . ($falhas === 1 ? ' falha' : ' falhas');
    }

    /**
     * Resumo das notificações em UMA linha de texto: "E-mail 24 (3 falhas) ·
     * SMS 8 · Webhook 5".
     *
     * No papel não há pop-up, então o que na tela é uma caixa vira esta linha,
     * impressa na sub-linha de detalhe do alarme (ver actionDetailRow()).
     * Mesma ordem do resumo: mídia com falha primeiro.
     */
    private function notifySummaryText(array $resumo): string {
        $partes = [];
        foreach ($resumo['medias'] ?? [] as $m) {
            $partes[] = $m['nome'] . ' ' . (int)$m['total']
                      . ((int)$m['falhas'] > 0
                          ? ' (' . (int)$m['falhas'] . ((int)$m['falhas'] === 1 ? ' falha)' : ' falhas)')
                          : '');
        }

        return implode(' · ', $partes);
    }

    /**
     * Sub-linha de detalhe das ações — EXCLUSIVA do PDF.
     *
     * Na tela ao vivo o conteúdo da ação (mensagem do ACK, severidade
     * antiga → nova, status da notificação) vive no atributo `title` do chip,
     * ou seja, só aparece no hover. Tooltip não existe em papel: no PDF o
     * chip sozinho mostrava apenas o NOME da ação e o conteúdo se perdia.
     *
     * Em vez de espremer mais uma coluna numa tabela que já tem até 7 em A4
     * paisagem, o detalhe sai numa linha própria logo abaixo do alarme, com
     * colspan na largura toda — comporta mensagem longa sem quebrar o
     * alinhamento das colunas de cima.
     *
     * Não consulta banco nem redecodifica bitmask: consome o mesmo
     * $actions[$eventid]['items'] que actionChips() (ver
     * TurnosReportBase::queryEventActions()). Se o evento não tem ação,
     * devolve string vazia e nenhuma linha extra é impressa.
     *
     * @param int $colspan número de <th> da tabela em que a linha entra —
     *        6 Herdados, 5 Sem ACK, 5 Em Tratativas, 7 Resolvidos.
     */
    private function actionDetailRow(array $entrada, array $severities, int $colspan, string $sevCls): string {
        $items = $entrada['items'] ?? [];
        if (empty($items)) {
            return '';
        }

        // A sub-linha imprime CONTEÚDO, não contagem: mensagem escrita,
        // supressão e notificação que FALHOU (com o texto do erro). O resumo
        // por mídia chegou a sair aqui e foi retirado a pedido — quem lê o
        // documento quer o que aconteceu de diferente, e a contagem já está no
        // selo da linha do alarme, logo acima.
        $lines = [];
        foreach ($items as $it) {
            $type = (string)($it['type'] ?? '');

            // Conteúdo propriamente dito da ação. Cada tipo guarda o seu em
            // campo diferente — ver queryEventActions().
            $body = '';
            if ($type === 'severity') {
                $body = $this->sevLabel((int)($it['old_severity'] ?? 0), $severities)
                      . ' → ' . $this->sevLabel((int)($it['new_severity'] ?? 0), $severities);
                $body = htmlspecialchars($body);
            }
            elseif (trim((string)($it['message'] ?? '')) !== '') {
                // nl2br: mensagem de ACK vem com quebra de linha do textarea
                // do Zabbix e sem isso vira um parágrafo só no papel.
                $body = nl2br(htmlspecialchars(trim((string)$it['message'])));
            }

            // Ação sem conteúdo (ACK puro, supressão) não gera linha de
            // detalhe — o chip acima já diz tudo, repetir só ocuparia papel.
            if ($body === '') {
                continue;
            }

            // Notificação BEM-SUCEDIDA também não entra: um único evento pode
            // ter dezenas de alertas em `alerts`, e "Enviada" repetido 30
            // vezes enterraria a mensagem do analista, que é o que interessa
            // em quem está pegando o turno. Falha de envio fica — essa o
            // próximo plantonista precisa saber.
            if ($type === 'notify' && stripos($body, 'Falhou') !== 0) {
                continue;
            }

            $meta = [];
            if (!empty($it['who'])) {
                $meta[] = htmlspecialchars((string)$it['who']);
            }
            $meta[] = date('d/m/Y H:i', (int)($it['when'] ?? 0));

            $lines[] = '<div class="rp-actd-item">'
                     . '<span class="rp-act rp-act-' . htmlspecialchars($type) . '">'
                     . htmlspecialchars((string)($it['label'] ?? '')) . '</span>'
                     . '<span class="rp-actd-meta">' . implode(' — ', $meta) . '</span>'
                     . '<div class="rp-actd-body">' . $body . '</div>'
                     . '</div>';
        }

        if (empty($lines)) {
            return '';
        }

        return '<tr class="rp-actd-row"><td colspan="' . (int)$colspan . '"'
             . ' style="border-left:3px solid var(--sev-' . htmlspecialchars($sevCls) . ')">'
             . implode('', $lines) . '</td></tr>';
    }

    private function resolvedBy(?string $closedBy): string {
        return $closedBy !== null
            ? '<span class="rp-resolve-manual"><i class="fas fa-user-check"></i> ' . htmlspecialchars($closedBy) . '</span>'
            : '<span class="rp-resolve-auto"><i class="fas fa-sync-alt"></i> Automático</span>';
    }

    private function shiftLabel(string $sh, array $shiftOptions = []): string {
        if (array_key_exists($sh, $shiftOptions)) {
            return $shiftOptions[$sh];
        }
        return [
            'manha' => 'Manhã (07h–13h)',
            'tarde' => 'Tarde (13h–19h)',
            'noite' => 'Noite (19h–07h)',
            '24h'   => '24 Horas',
        ][$sh] ?? $sh;
    }

    // ── doAction ─────────────────────────────────────────────

    protected function doAction(): void {
        $date     = $this->validateDate($this->getInput('date', date('Y-m-d')));
        $shiftRaw = $this->getInput('shift', '24h');
        $limitStr = $this->getInput('limit', '5');
        $limit    = $limitStr === 'all' ? 0 : (int)$limitStr;

        $db = $this->getDb();
        if (!$db) {
            echo '<h1>Erro de conexão com o banco de dados.</h1>';
            die();
        }

        $userid       = (int)(CWebUser::$data['userid'] ?? 0);
        $nocCtx       = $this->resolveUserContext($db, $userid);
        $isSuperadmin = $nocCtx['is_superadmin'];
        $reportId     = (int) $this->getInput('report_id', 0);
        $closedMeta   = null;

        if ($reportId > 0) {
            // ── Documento de turno FECHADO ───────────────────────────────
            //
            // Nada é consultado: tudo vem do snapshot gravado no fechamento.
            // É esse o ponto da issue #2 — o repasse de uma data antiga passa
            // a mostrar os números do dia em que o turno acabou, e não o que
            // sobrou depois de o housekeeper apagar os eventos.
            //
            // A visibilidade é conferida dentro do loadClosedReport(), sobre o
            // contexto gravado no snapshot: Super Admin vê todos; fechamento
            // feito por Super Admin só ele lê; nos demais casos os grupos do
            // autor têm que caber nos do leitor. O documento congela a visão
            // de UMA pessoa — entregá-lo a quem enxerga menos furaria a
            // segmentação da tela.
            $closed     = $this->loadClosedReport($db, $reportId, $userid, $isSuperadmin, $nocCtx);
            // Cor/nome de severidade não é dado do turno fechado — é
            // preferência de exibição atual. Busca ao vivo mesmo aqui, antes
            // de fechar a conexão.
            $severities = $this->querySeverities($db);
            $db->close();

            if ($closed === null) {
                echo '<h1>Fechamento não encontrado.</h1>';
                echo '<p>O documento não existe ou não é visível para o seu usuário.</p>';
                die();
            }

            $closedMeta = $closed['meta'];
            $snap       = $closed['snapshot'];
            $d          = $snap['data'] ?? [];

            $date         = $snap['date']  ?? $date;
            $shift        = $snap['shift'] ?? $shiftRaw;
            $shiftOptions = [$shift => $snap['shift_label'] ?? $shift];
            $nocLabel     = $snap['noc_label'] ?? null;
            // Papel do LEITOR: decide os rótulos ("Seu MTTA" x "MTTA Global")
            // e tem que casar com a restrição aplicada logo abaixo.
            $roleType     = (int) $nocCtx['role_type'];

            // MTTA é restrito por PAPEL, e o papel gravado no snapshot é o de
            // quem fechou. Reaplicar com o papel do LEITOR — senão um User
            // enxergaria, pelo documento, o MTTA de todos os analistas, que
            // ele nunca veria na tela ao vivo. Nenhum filtro de grupo na
            // consulta cobre isso: a regra não é de grupo, é de papel.
            $mtta         = $this->restrictMttaByRole(
                                $d['mtta'] ?? [], (int) $nocCtx['role_type'], $userid
                            );
            $inherited    = $d['inherited']    ?? [];
            $unacked      = $d['unacked']      ?? [];
            // Snapshot v1 (antes de 2026-08-28) não tem essas 3 chaves — o
            // ?? [] cobre o documento antigo sem precisar migrar nada (ver
            // TurnosReportBase::snapshotVersion()).
            $in_progress  = $d['in_progress']  ?? [];
            $resolved     = $d['resolved']     ?? [];
            // Fechamento gravado antes desta mudança não tem a chave: sem
            // marca de corte, é o mesmo que dizer "não houve corte".
            $truncated    = $snap['truncated'] ?? [];
            $actions      = $d['actions']      ?? [];
            $top_hosts    = $d['top_hosts']    ?? [];
            $top_triggers = $d['top_triggers'] ?? [];
            $totals       = $d['totals']       ?? ['total'=>0,'critical'=>0,'average'=>0,'low'=>0];
            // Sanitiza também o que veio do snapshot: as notas foram gravadas
            // no JSON no dia do fechamento e não passam mais por queryNotes()
            // na leitura (ver sanitizeNotesForDisplay()).
            $notes        = $this->sanitizeNotesForDisplay($d['notes'] ?? []);
            // Recalculado sobre o MTTA já restrito: usar o global_mtta do
            // snapshot devolveria um agregado de todos os analistas no KPI.
            $gmtta        = $this->calcGlobalMTTA($mtta);
            // O rodapé mostra quem FECHOU, não quem está imprimindo: o
            // documento é dele.
            $user_fn      = $snap['closed_by'] ?? ($closedMeta['closed_by_label'] ?? '');
        }
        else {
            // As consultas do relatório não tinham tratamento: falha em
            // qualquer uma delas subia como exceção e o PDF virava erro 500 em
            // branco, sem mensagem e sem rastro no log do módulo.
            try {
                $hostFilter   = $nocCtx['host_filter'];
                $roleType     = $nocCtx['role_type'];
                $nocLabel     = !empty($nocCtx['display_groups'])
                                ? implode(' / ', $nocCtx['display_groups'])
                                : null;

                $shiftOptions = $this->queryShiftOptions($db, $nocCtx)['options'];
                $shift        = $this->normalizeShift($shiftRaw, $shiftOptions);

                [$ts_start, $ts_end] = $this->getShiftBounds($db, $date, $shift);

                $mtta         = $this->queryMTTA($db, $ts_start, $ts_end, $hostFilter);
                $mtta         = $this->restrictMttaByRole($mtta, $roleType, $userid);
                $inherited    = $this->queryInheritedAlerts($db, $ts_start, $hostFilter);
                $unacked      = $this->queryUnackedAlerts($db, $ts_start, $ts_end, $hostFilter);
                $in_progress  = $this->queryInProgressAlerts($db, $ts_start, $ts_end, $hostFilter);
                $resolved     = $this->queryResolvedAlerts($db, $ts_start, $ts_end, $hostFilter);
                // Corte no teto antes das ações e dos KPIs (ver capAlertRows()).
                $truncated    = [
                    'inherited'   => $this->capAlertRows($inherited),
                    'unacked'     => $this->capAlertRows($unacked),
                    'in_progress' => $this->capAlertRows($in_progress),
                    'resolved'    => $this->capAlertRows($resolved),
                ];
                $actions      = $this->queryEventActions($db, array_merge(
                    array_column($inherited, 'eventid'),
                    array_column($unacked, 'eventid'),
                    array_column($in_progress, 'eventid'),
                    array_column($resolved, 'eventid')
                ));
                $top_hosts    = $this->queryTopHosts($db, $ts_start, $ts_end, $limit, $hostFilter);
                $top_triggers = $this->queryTopTriggers($db, $ts_start, $ts_end, $limit, $hostFilter);
                $totals       = $this->queryEventTotals($db, $ts_start, $ts_end, $hostFilter);
                $notes        = $this->queryNotes($db, $date, $shift, $userid, $isSuperadmin);
                $severities   = $this->querySeverities($db);
                $db->close();

                $gmtta   = $this->calcGlobalMTTA($mtta);
                $user_fn = $this->formatUserLabel(
                    CWebUser::$data['name']    ?? '',
                    CWebUser::$data['surname'] ?? '',
                    CWebUser::$data['username'] ?? 'Admin'
                );
            } catch (\Throwable $e) {
                // Mensagem fixa, sem $e->getMessage(): a exceção do ZbxDb carrega
                // o SQL inteiro e isto aqui é saída para o navegador.
                error_log('[plantonistas] report.pdf falhou: ' . $e->getMessage());
                echo '<h1>Não foi possível gerar o relatório.</h1>';
                echo '<p>Uma das consultas ao banco falhou. Confira o log do PHP-FPM.</p>';
                die();
            }
        }

        $isUserRole = ($roleType < 2);
        $mttaKpiLabel  = $isUserRole ? 'Seu MTTA' : 'MTTA Global';
        $mttaCardTitle = $isUserRole ? 'Seu MTTA' : 'MTTA por Analista';

        // Título completo para o PDF inclui o contexto NOC.
        // Data com hífen (17-08-2026), não barra: o <title> é o que o navegador
        // sugere como NOME DO ARQUIVO ao salvar/imprimir em PDF, e barra não é
        // caractere válido em nome de arquivo — viria substituída por _ ou -
        // dependendo do sistema. Continua dia-mês-ano, padrão brasileiro.
        $pdfTitle = ($closedMeta !== null ? 'Repasse Fechado' : 'Repasse de Plantão')
            . ($nocLabel ? ' — ' . $nocLabel : '')
            . ' — ' . $this->shiftLabel($shift, $shiftOptions)
            . ' — ' . str_replace('/', '-', $this->formatDateBr($date));

        // Cores REAIS de severidade por cima do fallback fixo do CSS estático
        // — mesma técnica da tela normal (ver plantonistas.report.view.php).
        $sevClassByIndex = [0=>'notclass',1=>'info',2=>'warn',3=>'avg',4=>'high',5=>'disaster'];
        $sevColorOverride = '';
        if ($severities) {
            $sevColorOverride = ':root{';
            foreach ($sevClassByIndex as $sevIdx => $sevCls) {
                $sevHex = $severities[$sevIdx]['color'] ?? '';
                if ($sevHex !== '') {
                    $sevColorOverride .= '--sev-' . $sevCls . ':#' . $sevHex . ';';
                }
            }
            $sevColorOverride .= '}';
        }

        // ── HTML ─────────────────────────────────────────────
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
        echo '<title>' . htmlspecialchars($pdfTitle) . '</title>';
        // `print-color-adjust: exact` nas .row-*: elas não têm mais fundo (a
        // tinta de linha por severidade saiu), mas continuam com a BARRA da
        // esquerda, e é ela que precisa sair impressa — o navegador que estiver
        // configurado para não imprimir gráficos de fundo levaria a barra junto.
        echo '<style>' . file_get_contents(__DIR__ . '/../assets/css/turnos.report.css') . $sevColorOverride . '
            body{background:#fff!important;font-size:11px}
            .rp-native-container{max-width:100%;padding:20px 30px}
            .rp-native-header{background:#2b3c51!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-nh-btn,.rp-note-form{display:none!important}
            .sev-disaster,.sev-high,.sev-avg,.sev-warn,.sev-info,.sev-notclass,
            .bg-blue,.bg-red,.bg-orange,.bg-yellow,.bg-purple,.bg-green,
            .row-disaster,.row-high,.row-avg,.row-warn,.row-info,.row-notclass,
            .perf-good,.perf-ok,.perf-bad,
            .rp-badge-hist,.rp-kpi-icon{-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-card{break-inside:avoid;box-shadow:none;border:1px solid #ccc}
            .rp-noc-badge{display:inline-block;padding:2px 10px;border-radius:12px;
                background:#3b5998;color:#fff;font-size:10px;font-weight:600;margin-left:10px}

            /* Sub-linha com o conteúdo das ações — ver actionDetailRow().
               Estilo mora AQUI e não em turnos.report.css porque o CSS é
               compartilhado com a tela ao vivo, que continua com tooltip. */
            .rp-actd-row > td{background:#fafbfc;padding:6px 10px 8px 12px;
                border-top:none;break-inside:avoid;page-break-inside:avoid;
                -webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-actd-item{margin:3px 0 5px}
            .rp-actd-item:last-child{margin-bottom:0}
            .rp-actd-meta{font-size:9px;color:#6b7785;margin-left:6px}
            .rp-actd-body{font-size:10px;color:#2b3c51;margin:2px 0 0 2px;
                padding-left:8px;border-left:2px solid #dfe4ea;
                white-space:normal;word-break:break-word}

            /* Barra de ação do documento — existe só na TELA.
               Não reaproveita .rp-nh-btn de propósito: aquela classe é
               escondida logo acima com display:none!important, o que é certo
               para os controles do relatório ao vivo (filtro, fechar turno)
               e errado para um botão cuja função é justamente imprimir.
               O @media print abaixo é o que garante que ela não saia no papel
               nem no PDF gerado pelo navegador. */
            .rp-doc-bar{display:flex;gap:8px;justify-content:flex-end;
                margin:0 0 14px;flex-wrap:wrap}
            .rp-doc-btn{display:inline-flex;align-items:center;gap:6px;
                padding:7px 14px;border-radius:5px;font-size:12px;font-weight:600;
                text-decoration:none;cursor:pointer;border:1px solid #cfd8dc;
                background:#fff;color:#2b3c51!important;font-family:inherit}
            .rp-doc-btn-primary{background:#0275b8;border-color:#0275b8;color:#fff!important}
            @media print{.rp-doc-bar{display:none!important}}
            @page{size:A4 landscape;margin:10mm}
        </style>';
        echo '<link rel="stylesheet" href="modules/module-zbx-plantonistas/assets/fontawesome/css/all.min.css"/>';
        echo '</head><body>';
        echo '<div class="rp-native-container">';

        // Header
        echo '<div class="rp-native-header"><div class="rp-nh-left">';
        echo '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';
        echo '<span class="rp-nh-title">Repasse de Plantão</span>';
        if ($nocLabel) {
            echo '<span class="rp-noc-badge"><i class="fas fa-shield-alt"></i> ' . htmlspecialchars($nocLabel) . '</span>';
        }
        // htmlspecialchars no rótulo do turno: ele é montado com o NOME do
        // turno e o NOME do grupo de usuário (queryShiftOptions()), os dois
        // digitados por um Admin. A tela ao vivo escapa nos três pontos
        // equivalentes; só o PDF imprimia cru — e o PDF é o documento que todo
        // mundo abre, inclusive Super Admin.
        echo '<span class="rp-nh-sub">' . htmlspecialchars($this->shiftLabel($shift, $shiftOptions))
           . ' — ' . htmlspecialchars($this->formatDateBr($date)) . '</span>';
        echo '</div></div>';

        // ── Barra de ação (só na tela) ───────────────────────────────────
        //
        // A página já era a versão imprimível, mas nada nela dizia isso: o
        // usuário abria o documento e ficava sem saber como baixar. "Baixar
        // PDF" é window.print() porque o módulo não gera PDF no servidor —
        // quem produz o arquivo é o "Salvar como PDF" do próprio navegador,
        // com o <title> definido acima virando o nome do arquivo sugerido.
        // O @media print da folha acima tira esta barra do papel.
        $voltar = $closedMeta !== null
            ? 'zabbix.php?action=plantonistas.report.list'
                . '&from=' . urlencode((string) $date)
                . '&to=' . urlencode((string) $date)
                . '&status=fechados'
            : 'zabbix.php?action=plantonistas.report.list';

        echo '<div class="rp-doc-bar">';
        echo '<a class="rp-doc-btn" href="' . htmlspecialchars($voltar) . '">'
           . '<i class="fas fa-list"></i> Lista de repasses</a>';
        echo '<button type="button" class="rp-doc-btn rp-doc-btn-primary" onclick="window.print()">'
           . '<i class="fas fa-file-pdf"></i> Baixar PDF</button>';
        echo '</div>';

        // Faixa de documento fechado: deixa explícito que estes números são um
        // instantâneo, e de quem — o snapshot congela a visão de uma pessoa,
        // que pode não ser a de quem está lendo.
        if ($closedMeta !== null) {
            echo '<div class="rp-card" style="border-left:4px solid #2e7d32;margin-bottom:14px">';
            echo '<div style="padding:10px 14px">';
            echo '<strong><i class="fas fa-lock"></i> Turno fechado</strong> — repasse congelado em '
               . htmlspecialchars((string) $closedMeta['generated_at'])
               . ' por ' . htmlspecialchars((string) $closedMeta['closed_by_label'])
               . ' (fechamento #' . (int) $closedMeta['id'] . ').';
            echo '<div class="rp-muted" style="margin-top:4px">Os números abaixo são os do'
               . ' momento do fechamento e não mudam mais, mesmo depois de o housekeeper do'
               . ' Zabbix apagar os eventos do período.</div>';
            echo '</div></div>';
        }

        // KPIs
        echo '<div class="rp-kpi-grid">';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-blue"><i class="fas fa-bell"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . (int)$totals['total'] . '</span><span class="rp-kpi-label">Total Eventos</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-red"><i class="fas fa-fire"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . (int)$totals['critical'] . '</span><span class="rp-kpi-label">Críticos</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-orange"><i class="fas fa-clock"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . $this->fmtDuration($gmtta) . '</span><span class="rp-kpi-label">' . $mttaKpiLabel . '</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-yellow"><i class="fas fa-exclamation-circle"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . $this->alertCount($unacked, $truncated, 'unacked') . '</span><span class="rp-kpi-label">Sem ACK</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-purple"><i class="fas fa-history"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . $this->alertCount($inherited, $truncated, 'inherited') . '</span><span class="rp-kpi-label">Herdados</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-blue"><i class="fas fa-tools"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . $this->alertCount($in_progress, $truncated, 'in_progress') . '</span><span class="rp-kpi-label">Em Tratativas</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-green"><i class="fas fa-check-circle"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . $this->alertCount($resolved, $truncated, 'resolved') . '</span><span class="rp-kpi-label">Resolvidos</span></div></div>';
        echo '</div>';

        // MTTA por analista
        if ($mtta) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-stopwatch"></i> ' . $mttaCardTitle . '</div>';
            echo '<table class="rp-table"><thead><tr><th>Analista</th><th>ACKs</th><th>MTTA Médio</th><th>Mín</th><th>Máx</th></tr></thead><tbody>';
            foreach ($mtta as $m) {
                echo '<tr><td class="td-bold">' . htmlspecialchars($m['fullname']) . '</td>';
                echo '<td class="td-center">' . (int)$m['total_acks'] . '</td>';
                echo '<td class="td-mono">' . $this->fmtDuration((int)$m['avg_mtta']) . '</td>';
                echo '<td class="td-mono">' . $this->fmtDuration((int)$m['min_mtta']) . '</td>';
                echo '<td class="td-mono">' . $this->fmtDuration((int)$m['max_mtta']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Alertas herdados
        if ($inherited) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-history"></i> Alertas Herdados</div>';
            echo '<table class="rp-table"><thead><tr><th>Início</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Idade</th><th>Ações</th></tr></thead><tbody>';
            foreach ($inherited as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr class="row-' . $sc . '">';
                echo '<td class="td-mono">' . date('d/m H:i', (int)$r['clock']) . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . htmlspecialchars($this->sevLabel((int)$r['severity'], $severities)) . '</span></td>';
                echo '<td>' . htmlspecialchars($this->hostLabel($r)) . '</td>';
                echo '<td>' . htmlspecialchars($r['trigger_desc']) . '</td>';
                echo '<td class="td-bold">' . $this->fmtAge((int)$r['age_seconds']) . '</td>';
                echo '<td>' . $this->actionChips($actions[(int)$r['eventid']] ?? [], $severities) . '</td></tr>';
                // 6 colunas: Início, Severidade, Host, Problema, Idade, Ações.
                echo $this->actionDetailRow($actions[(int)$r['eventid']] ?? [], $severities, 6, $sc);
            }
            echo '</tbody></table>' . $this->alertCut($truncated, 'inherited') . '</div>';
        }

        // Alertas sem ACK
        if ($unacked) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-exclamation-triangle"></i> Alertas Sem ACK</div>';
            echo '<table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Ações</th></tr></thead><tbody>';
            // 5 colunas: Hora, Severidade, Host, Problema, Ações.
            $acDetailCols = 5;
            foreach ($unacked as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr class="row-' . $sc . '">';
                echo '<td class="td-mono">' . date('H:i:s', (int)$r['clock']) . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . htmlspecialchars($this->sevLabel((int)$r['severity'], $severities)) . '</span></td>';
                echo '<td>' . htmlspecialchars($this->hostLabel($r)) . '</td>';
                echo '<td>' . htmlspecialchars($r['trigger_desc']) . '</td>';
                echo '<td>' . $this->actionChips($actions[(int)$r['eventid']] ?? [], $severities) . '</td></tr>';
                echo $this->actionDetailRow($actions[(int)$r['eventid']] ?? [], $severities, $acDetailCols, $sc);
            }
            echo '</tbody></table>' . $this->alertCut($truncated, 'unacked') . '</div>';
        }

        // Alarmes em tratativas
        if ($in_progress) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-tools"></i> Alarmes em Tratativas</div>';
            echo '<table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Ações</th></tr></thead><tbody>';
            // 5 colunas: Hora, Severidade, Host, Problema, Ações.
            $acDetailCols = 5;
            foreach ($in_progress as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr class="row-' . $sc . '">';
                echo '<td class="td-mono">' . date('H:i:s', (int)$r['clock']) . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . htmlspecialchars($this->sevLabel((int)$r['severity'], $severities)) . '</span></td>';
                echo '<td>' . htmlspecialchars($this->hostLabel($r)) . '</td>';
                echo '<td>' . htmlspecialchars($r['trigger_desc']) . '</td>';
                echo '<td>' . $this->actionChips($actions[(int)$r['eventid']] ?? [], $severities) . '</td></tr>';
                echo $this->actionDetailRow($actions[(int)$r['eventid']] ?? [], $severities, $acDetailCols, $sc);
            }
            echo '</tbody></table>' . $this->alertCut($truncated, 'in_progress') . '</div>';
        }

        // Alarmes resolvidos (histórico do turno)
        if ($resolved) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-check-circle"></i> Alarmes Resolvidos</div>';
            echo '<table class="rp-table"><thead><tr><th>Resolvido</th><th>Severidade</th><th>Host</th><th>Problema</th><th>MTTR</th><th>Resolvido por</th><th>Ações</th></tr></thead><tbody>';
            foreach ($resolved as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                $closedBy = $actions[(int)$r['eventid']]['closed_by'] ?? null;
                echo '<tr class="row-' . $sc . '">';
                echo '<td class="td-mono">' . date('d/m H:i', (int)$r['r_clock']) . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . htmlspecialchars($this->sevLabel((int)$r['severity'], $severities)) . '</span></td>';
                echo '<td>' . htmlspecialchars($this->hostLabel($r)) . '</td>';
                echo '<td>' . htmlspecialchars($r['trigger_desc']) . '</td>';
                echo '<td class="td-bold">' . $this->fmtDuration((int)$r['resolve_seconds']) . '</td>';
                echo '<td>' . $this->resolvedBy($closedBy) . '</td>';
                echo '<td>' . $this->actionChips($actions[(int)$r['eventid']] ?? [], $severities) . '</td></tr>';
                // 7 colunas: Resolvido, Severidade, Host, Problema, MTTR,
                // Resolvido por, Ações.
                echo $this->actionDetailRow($actions[(int)$r['eventid']] ?? [], $severities, 7, $sc);
            }
            echo '</tbody></table>' . $this->alertCut($truncated, 'resolved') . '</div>';
        }

        // Top Hosts + Top Triggers
        echo '<div class="rp-noise-row">';
        if ($top_hosts) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-server"></i> Top Hosts</div>';
            echo '<table class="rp-table"><thead><tr><th>#</th><th>Host</th><th>Eventos</th><th>Sev.</th></tr></thead><tbody>';
            $i = 1;
            foreach ($top_hosts as $r) {
                $sc = $this->sevClass((int)$r['max_severity']);
                echo '<tr><td class="td-center">' . $i++ . '</td>';
                echo '<td>' . htmlspecialchars($this->hostLabel($r)) . '</td>';
                echo '<td class="td-center td-bold">' . (int)$r['event_count'] . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . htmlspecialchars($this->sevLabel((int)$r['max_severity'], $severities)) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($top_triggers) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-bolt"></i> Top Triggers</div>';
            echo '<table class="rp-table"><thead><tr><th>#</th><th>Trigger</th><th>Eventos</th><th>Sev.</th></tr></thead><tbody>';
            $i = 1;
            foreach ($top_triggers as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr><td class="td-center">' . $i++ . '</td>';
                echo '<td>' . htmlspecialchars($r['description']) . '</td>';
                echo '<td class="td-center td-bold">' . (int)$r['event_count'] . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . htmlspecialchars($this->sevLabel((int)$r['severity'], $severities)) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Diário de Bordo
        if ($notes) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-book-open"></i> Diário de Bordo</div><div class="rp-card-body">';
            foreach ($notes as $n) {
                echo '<div class="rp-note-item">';
                echo '<div class="rp-note-header"><strong>' . htmlspecialchars($n['analyst_name']) . '</strong> ';
                echo '<span class="rp-note-time">' . htmlspecialchars($n['created_at']) . '</span></div>';
                $noteHtml = (($n['notes_format'] ?? 'text') === 'html') ? $n['notes'] : nl2br(htmlspecialchars($n['notes']));
                echo '<div class="rp-note-content">' . $noteHtml . '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }

        // Rodapé
        echo '<div class="rp-native-footer">';
        echo '<span>Relatório gerado em ' . date('d/m/Y H:i:s') . ' por ' . htmlspecialchars($user_fn) . '</span>';
        // Versão lida do manifest, não literal. O número fixo "v2.5.0" veio do
        // fork e sobreviveu à unificação inteira: todo repasse fechado que
        // alguém imprimiu desde a v4.0.0 saiu com a versão errada — justamente
        // no artefato que fica arquivado. A tela ao vivo já lê o manifest.
        $pdfVersao = 'v?';
        $pdfManifest = @json_decode((string) @file_get_contents(__DIR__ . '/../manifest.json'), true);
        if (is_array($pdfManifest) && !empty($pdfManifest['version'])) {
            $pdfVersao = 'v' . $pdfManifest['version'];
        }
        echo '<span>Módulo Plantonistas ' . htmlspecialchars($pdfVersao) . '</span>';
        echo '</div>';

        echo '</div></body></html>';
        echo '<script>window.onload = function() { window.print(); };</script>';
        die();
    }
}
