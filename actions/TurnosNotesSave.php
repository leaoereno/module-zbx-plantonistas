<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosNotesSave — AJAX: salvar nota no Diário de Bordo.
 *
 * A visibilidade da nota é determinada automaticamente na leitura
 * com base na tabela `rights` do Zabbix — sem nenhuma configuração manual.
 * A nota é gravada apenas com o analyst_userid do autor.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 */
class TurnosNotesSave extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        // 'shift' aceita os códigos legados OU o ID numérico de um turno
        // cadastrado em module_plantonistas_shifts (v2.5+).
        return $this->validateInput([
            'note'       => 'required|string',
            'shift'      => 'string',
            'shift_date' => 'string',
        ]);
    }

    private function isValidShiftParam(string $shift): bool {
        return in_array($shift, ['24h', 'manha', 'tarde', 'noite'], true) || ctype_digit($shift);
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

    /**
     * Para turnos cadastrados (ID numérico), busca o nome legível para
     * gravar em shift_name (mantém o histórico legível mesmo que o turno
     * seja renomeado/removido depois).
     */
    private function resolveShiftName(\mysqli $db, string $shift): array {
        if (!ctype_digit($shift)) {
            return [$shift, null];
        }
        try {
            $stmt = $db->prepare("SELECT name FROM module_plantonistas_shifts WHERE id = ?");
            $id = (int)$shift;
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row) {
                return [$row['name'], $id];
            }
        } catch (\Exception $e) {}
        return ['24h', null];
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $note       = trim($this->getInput('note', ''));
        $shiftRaw   = $this->getInput('shift', '24h');
        $shift_date = $this->validateDate($this->getInput('shift_date', date('Y-m-d')));

        if (!$this->isValidShiftParam($shiftRaw)) {
            $shiftRaw = '24h';
        }

        if ($note === '') {
            echo json_encode(['success' => false, 'message' => 'A nota não pode ser vazia.']);
            die();
        }

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.']);
            die();
        }

        try {
            $userid   = (int)(CWebUser::$data['userid'] ?? 0);
            $username = CWebUser::$data['username'] ?? 'unknown';
            $fullname = trim((CWebUser::$data['name'] ?? '') . ' ' . (CWebUser::$data['surname'] ?? ''))
                        ?: $username;

            [$shift_name, $shift_id] = $this->resolveShiftName($db, $shiftRaw);

            // Tenta INSERT com coluna shift_id (schema v2.5+)
            // Se a coluna não existir, faz fallback para schema v2.2–v2.4
            try {
                $stmt = $db->prepare(
                    "INSERT INTO module_plantonistas_shift_notes
                        (shift_date, shift_name, shift_id, analyst_userid, analyst_name, notes, noc_context, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())"
                );
                $stmt->bind_param('ssiiss', $shift_date, $shift_name, $shift_id, $userid, $fullname, $note);
            } catch (\Exception $e) {
                // Coluna shift_id não existe (schema < v2.5)
                $stmt = $db->prepare(
                    "INSERT INTO module_plantonistas_shift_notes
                        (shift_date, shift_name, analyst_userid, analyst_name, notes, noc_context, created_at)
                     VALUES (?, ?, ?, ?, ?, NULL, NOW())"
                );
                $stmt->bind_param('ssiss', $shift_date, $shift_name, $userid, $fullname, $note);
            }

            $stmt->execute();

            echo json_encode([
                'success' => true,
                'message' => 'Nota salva com sucesso!',
                'id'      => $db->insert_id,
            ]);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao salvar nota: ' . $e->getMessage(),
            ]);
        }

        $db->close();
        die();
    }
}
