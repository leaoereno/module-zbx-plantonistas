<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosSettingsSave — AJAX: grava os parâmetros do módulo editáveis pela tela.
 *
 * Hoje são os dois limites de MTTA que decidem o selo da coluna Performance
 * ("Excelente" abaixo do primeiro, "Aceitável" abaixo do segundo, "Atenção"
 * daí em diante).
 *
 * ── Por que isto existe ──────────────────────────────────────────────────
 *
 * Os limites viviam escritos na view, e a descrição do MESMO card anunciava
 * outra meta ("abaixo de 60 minutos") — analista com 20 minutos aparecia como
 * "Atenção" estando abaixo do que a tela prometia. Além de unificar os dois
 * números, eles agora se ajustam sem deploy: cada operação tem a sua meta, e
 * pedir alteração de código para mudar um número é o tipo de fricção que
 * termina com todo mundo ignorando o selo.
 *
 * ── Permissão e CSRF ─────────────────────────────────────────────────────
 *
 * Super Admin apenas, conferido no SERVIDOR (a tela esconde o botão para os
 * demais, mas esconder botão nunca foi controle de acesso — a action tem URL).
 * Escreve no banco, então exige POST + `_csrf_token` da action COMPLETA: em
 * módulo o Zabbix não agrupa token por prefixo. Sem `disableCsrfValidation()`,
 * de propósito.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosSettingsSave extends CController {

    use TurnosReportBase;

    /** Teto de 24h: acima disso o selo perde sentido, e o campo vira digitação errada. */
    private const LIMITE_MAX = 86400;

    protected function checkInput(): bool {
        return $this->validateInput([
            'mtta_good' => 'required|int32',
            'mtta_ok'   => 'required|int32',
            // Metas por equipe, em JSON: [{usrgrpid, good, ok}, …]. Ausente
            // significa "nenhuma" — e nenhuma APAGA as que existiam, que é
            // como o botão de remover linha funciona sem action de exclusão.
            'equipes'   => 'string',
        ]);
    }

    protected function checkPermissions(): bool {
        return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    /**
     * Saneia as metas por equipe vindas da tela.
     *
     * @return array<int,array{good:int,ok:int}>|null null quando alguma linha é
     *         inválida — recusa o lote inteiro em vez de gravar metade, para
     *         não deixar a configuração num estado que ninguém pediu
     */
    private function parseEquipes(string $json): ?array {
        if (trim($json) === '') {
            return [];
        }

        $bruto = json_decode($json, true);
        if (!is_array($bruto)) {
            error_log('[plantonistas] settings.save: JSON de equipes inválido, ignorado.');

            return [];
        }

        $saida = [];
        foreach ($bruto as $linha) {
            if (!is_array($linha)) {
                continue;
            }

            $usrgrpid = (int)($linha['usrgrpid'] ?? 0);
            $good     = (int)($linha['good'] ?? 0);
            $ok       = (int)($linha['ok'] ?? 0);

            if ($usrgrpid <= 0) {
                continue;
            }

            if ($good < 60 || $ok < 60 || $good > self::LIMITE_MAX || $ok > self::LIMITE_MAX || $good >= $ok) {
                return null;
            }

            // 24/7 ausente = true, que é o comportamento de sempre: o corte de
            // MTTA por turno é opt-in, ninguém tem número reduzido sem que
            // alguém tenha declarado que a equipe não cobre a madrugada.
            $saida[$usrgrpid] = [
                'good'   => $good,
                'ok'     => $ok,
                'always' => !array_key_exists('always', $linha) || (bool)$linha['always'],
            ];

            // Teto de equipes: a configuração é por empresa, não por analista.
            if (count($saida) >= 100) {
                break;
            }
        }

        return $saida;
    }

    /**
     * Responde JSON e ENCERRA a requisição.
     *
     * O `die()` não é decoração: o manifest declara `layout.javascript` para
     * esta action, e o `ZBase::processResponseFinal()` faz
     *
     *     if ($router->getLayout() !== null) {
     *         if (!($response instanceof CControllerResponseData)) {
     *             throw new Exception('Unexpected response for action …');
     *
     * Ou seja, quem echoa JSON e volta para o framework sem `setResponse()`
     * ganha a exceção do Zabbix DEPOIS do JSON já impresso: o corpo da
     * resposta vira JSON + HTML de erro, o `r.json()` do navegador estoura e a
     * tela mostra "Erro de conexão" — sem nada no log, porque do lado do PHP
     * nada falhou. Foi exatamente o que aconteceu ao salvar a primeira equipe.
     *
     * As outras actions AJAX do módulo (TurnosNotesSave, TurnosUserShiftSave)
     * terminam com `$db->close(); die();` pelo mesmo motivo.
     */
    private function responder(array $payload, ?ZbxDb $db = null): void {
        echo json_encode($payload);

        if ($db !== null) {
            $db->close();
        }

        die();
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $good = (int)$this->getInput('mtta_good');
        $ok   = (int)$this->getInput('mtta_ok');

        if ($good < 60 || $ok < 60 || $good > self::LIMITE_MAX || $ok > self::LIMITE_MAX) {
            $this->responder([
                'success' => false,
                'message' => 'Os limites devem estar entre 1 minuto e 24 horas.',
            ]);
        }

        // "Excelente" tem de ser MENOR que "Aceitável": invertidos, a faixa do
        // meio some e todo mundo cai em Atenção — exatamente o sintoma que
        // originou esta tela. Recusar aqui é melhor que salvar e o operador
        // descobrir depois, olhando um relatório todo vermelho.
        if ($good >= $ok) {
            $this->responder([
                'success' => false,
                'message' => 'O limite de "Excelente" precisa ser menor que o de "Aceitável".',
            ]);
        }

        // ── Metas por equipe ────────────────────────────────────────────
        //
        // Cada empresa atendida tem time próprio, então a meta acompanha o
        // grupo de usuário do analista e vale linha a linha, sem depender de
        // filtro na tela (ver TurnosReportBase::mttaThresholds()).
        $equipes = $this->parseEquipes((string)$this->getInput('equipes', ''));
        if ($equipes === null) {
            $this->responder([
                'success' => false,
                'message' => 'Em alguma equipe, "Excelente" não é menor que "Aceitável" — ou o limite está fora de 1 minuto a 24 horas.',
            ]);
        }

        $db = $this->getDb();
        if (!$db) {
            $this->responder(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.']);
        }

        $gravou = $this->saveSetting($db, 'mtta_good', (string)$good)
               && $this->saveSetting($db, 'mtta_ok', (string)$ok);

        foreach ($equipes as $usrgrpid => $par) {
            $gravou = $this->saveSetting($db, 'mtta_good.' . $usrgrpid, (string)$par['good']) && $gravou;
            $gravou = $this->saveSetting($db, 'mtta_ok.'   . $usrgrpid, (string)$par['ok'])   && $gravou;
            $gravou = $this->saveSetting($db, 'mtta_247.'  . $usrgrpid, $par['always'] ? '1' : '0') && $gravou;
        }

        // Equipe que não veio no envio teve a meta REMOVIDA na tela: apagar é o
        // que faz o botão de remover linha funcionar. Feito depois de gravar,
        // para uma falha no meio não deixar a tela sem meta nenhuma.
        $this->deleteSettingsExcept($db, array_keys($equipes));

        $this->responder($gravou
            ? ['success' => true, 'message' => 'Limites atualizados.']
            : ['success' => false, 'message' => 'Não foi possível gravar. Veja o log do PHP-FPM.'], $db);
    }
}
