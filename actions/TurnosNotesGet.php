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

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    private function getDb(): ?\mysqli {
        try {
            $server = $GLOBALS['DB']['SERVER']   ?? 'localhost';
            $port   = (int)($GLOBALS['DB']['PORT'] ?? 3306);
            $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
            $user   = $GLOBALS['DB']['USER']     ?? 'zabbix';
            $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $mysqli = new \mysqli($server, $user, $pass, $dbname, $port);
            $mysqli->set_charset('utf8mb4');
            return $mysqli;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function validateDate(string $date): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$y, $m, $d] = explode('-', $date);
            if (checkdate((int)$m, (int)$d, (int)$y)) {
                return $date;
            }
        }
        return date('Y-m-d');
    }

    private function getUserRoleType(\mysqli $db, int $userid): int {
        try {
            $stmt = $db->prepare(
                "SELECT r.type AS user_type
                 FROM users u
                 INNER JOIN role r ON r.roleid = u.roleid
                 WHERE u.userid = ?"
            );
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row !== false && $row !== null) {
                return (int)$row['user_type'];
            }
        } catch (\Exception $e) {}

        try {
            $stmt = $db->prepare("SELECT type AS user_type FROM users WHERE userid = ?");
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row ? (int)$row['user_type'] : 1;
        } catch (\Exception $e) {
            return 1;
        }
    }

    private function isSuperAdmin(\mysqli $db, int $userid): bool {
        // Somente role_type=3 (Super Admin role) tem visão irrestrita.
        return $this->getUserRoleType($db, $userid) === 3;
    }

    /**
     * Fragmento SQL: mesmo grupo de usuário entre leitor e autor.
     */
    private function sameGroupExists(int $userid, string $authorIdColumn): string {
        return "EXISTS (
            SELECT 1
            FROM users_groups ug_reader
            JOIN users_groups ug_author
                ON ug_author.usrgrpid = ug_reader.usrgrpid
            WHERE ug_reader.userid = $userid
              AND ug_author.userid  = $authorIdColumn
        )";
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
        $isSuperAdmin = $this->isSuperAdmin($db, $userid);

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
                'notes'   => $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
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
