<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosNotesGet — AJAX: consultar notas do Diário de Bordo.
 *
 * Segmentação por grupo de usuário (users_groups).
 * Não depende de rights configuradas no Zabbix.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 */
class TurnosNotesGet extends CController {

    // Passou a usar o trait em vez de manter cópias próprias de getDb(),
    // validateDate(), getUserRoleType(), isSuperAdmin() e sameGroupExists().
    // Eram cinco métodos idênticos aos do trait — cinco lugares para corrigir
    // quando a regra de permissão mudasse, e um deles ficaria para trás.
    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        // Era `return true` com leitura direta de $_GET/$_POST logo abaixo —
        // a única action do módulo que não passava pelo validateInput() do
        // Zabbix. Não havia caller, mas a rota existe no manifest e continua
        // alcançável por URL: quem chega por ela agora passa pela mesma
        // validação das outras.
        return $this->validateInput([
            'shift'      => 'string',
            'shift_date' => 'string',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }






    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $shift_raw = $this->getInput('shift', '24h');
        $date_raw  = $this->getInput('shift_date', date('Y-m-d'));

        // 'shift' aceita os códigos legados OU o ID numérico de um turno
        // cadastrado em module_plantonistas_shifts (v2.5+). O nome gravado na nota
        // (shift_name) é resolvido em TurnosNotesSave no momento da escrita,
        // então aqui basta validar o formato do parâmetro recebido.
        $isValidShift = in_array($shift_raw, ['24h', 'manha', 'tarde', 'noite'], true)
                         || ctype_digit((string)$shift_raw);
        $shift      = $isValidShift ? $shift_raw : '24h';
        $shift_date = $this->validateDate($date_raw);

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro DB.', 'notes' => []]);
            die();
        }

        $userid       = (int)(CWebUser::$data['userid'] ?? 0);
        $isSuperAdmin = ($this->getUserRoleType($db, $userid) === 3);

        // Turnos cadastrados (v2.5+) são gravados com shift_id numérico;
        // turnos legados (24h/manha/tarde/noite) continuam filtrando por
        // shift_name, como antes.
        $filterByShiftId = ctype_digit((string)$shift);
        $shiftIdInt      = $filterByShiftId ? (int)$shift : 0;

        try {
            if ($isSuperAdmin) {
                if ($filterByShiftId) {
                    $stmt = $db->prepare(
                        "SELECT id, analyst_userid, analyst_name, notes, notes_format, created_at
                         FROM module_plantonistas_shift_notes
                         WHERE shift_date = ? AND shift_id = ?
                         ORDER BY created_at DESC"
                    );
                    $stmt->bind_param('si', $shift_date, $shiftIdInt);
                } else {
                    $stmt = $db->prepare(
                        "SELECT id, analyst_userid, analyst_name, notes, notes_format, created_at
                         FROM module_plantonistas_shift_notes
                         WHERE shift_date = ? AND shift_name = ?
                         ORDER BY created_at DESC"
                    );
                    $stmt->bind_param('ss', $shift_date, $shift);
                }
            } else {
                $sameGroup = $this->sameGroupExists($userid, 'csn.analyst_userid');
                if ($filterByShiftId) {
                    $stmt = $db->prepare(
                        "SELECT DISTINCT csn.id, csn.analyst_userid, csn.analyst_name,
                             csn.notes, csn.notes_format, csn.created_at
                         FROM module_plantonistas_shift_notes csn
                         WHERE csn.shift_date = ? AND csn.shift_id = ?
                           AND $sameGroup
                         ORDER BY csn.created_at DESC"
                    );
                    $stmt->bind_param('si', $shift_date, $shiftIdInt);
                } else {
                    $stmt = $db->prepare(
                        "SELECT DISTINCT csn.id, csn.analyst_userid, csn.analyst_name,
                             csn.notes, csn.notes_format, csn.created_at
                         FROM module_plantonistas_shift_notes csn
                         WHERE csn.shift_date = ? AND csn.shift_name = ?
                           AND $sameGroup
                         ORDER BY csn.created_at DESC"
                    );
                    $stmt->bind_param('ss', $shift_date, $shift);
                }
            }

            $stmt->execute();
            echo json_encode([
                'success' => true,
                // notes_format no SELECT + sanitização na leitura: sem eles
                // esta rota devolveria o HTML da nota cru, sem o consumidor
                // ter como saber se era texto puro ou HTML (ver
                // sanitizeNotesForDisplay()).
                'notes'   => $this->sanitizeNotesForDisplay($stmt->get_result()->fetch_all()),
            ]);
        } catch (\Throwable $e) {
            // Mensagem fixa: a exceção do ZbxDb traz o SQL inteiro.
            error_log('[plantonistas] notes.get falhou: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao consultar as notas. Confira o log do PHP-FPM.',
                'notes'   => [],
            ]);
        }

        $db->close();
        die();
    }
}
