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
        return true;
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }






    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $shift_raw = $_GET['shift']      ?? $_POST['shift']      ?? '24h';
        $date_raw  = $_GET['shift_date'] ?? $_POST['shift_date'] ?? date('Y-m-d');

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
                        "SELECT id, analyst_userid, analyst_name, notes, created_at
                         FROM module_plantonistas_shift_notes
                         WHERE shift_date = ? AND shift_id = ?
                         ORDER BY created_at DESC"
                    );
                    $stmt->bind_param('si', $shift_date, $shiftIdInt);
                } else {
                    $stmt = $db->prepare(
                        "SELECT id, analyst_userid, analyst_name, notes, created_at
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
                             csn.notes, csn.created_at
                         FROM module_plantonistas_shift_notes csn
                         WHERE csn.shift_date = ? AND csn.shift_id = ?
                           AND $sameGroup
                         ORDER BY csn.created_at DESC"
                    );
                    $stmt->bind_param('si', $shift_date, $shiftIdInt);
                } else {
                    $stmt = $db->prepare(
                        "SELECT DISTINCT csn.id, csn.analyst_userid, csn.analyst_name,
                             csn.notes, csn.created_at
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
                'notes'   => $stmt->get_result()->fetch_all(),
            ]);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro: ' . $e->getMessage(),
                'notes'   => [],
            ]);
        }

        $db->close();
        die();
    }
}
