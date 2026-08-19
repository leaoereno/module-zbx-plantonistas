<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosNotesSave — AJAX: salvar nota no Diário de Bordo.
 *
 * A visibilidade da nota é determinada automaticamente na leitura com base
 * em grupo de usuário compartilhado (ver TurnosReportBase::queryNotes()).
 * A nota é gravada apenas com o analyst_userid do autor.
 *
 * v4.2 — editor rico + menções: o campo "note" agora chega como HTML do
 * editor (contenteditable), sanitizado aqui via TurnosReportBase::
 * sanitizeNoteHtml() (allowlist de tags/atributos — o servidor nunca confia
 * no HTML vindo do cliente). Menções [user] encontradas no HTML viram
 * notificação em module_plantonistas_mentions.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 */
class TurnosNotesSave extends CController {

    use TurnosReportBase;

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

    /**
     * Para turnos cadastrados (ID numérico), busca o nome legível para
     * gravar em shift_name (mantém o histórico legível mesmo que o turno
     * seja renomeado/removido depois).
     */
    private function resolveShiftName(ZbxDb $db, string $shift): array {
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

        $noteRaw    = trim($this->getInput('note', ''));
        $shiftRaw   = $this->getInput('shift', '24h');
        $shift_date = $this->validateDate($this->getInput('shift_date', date('Y-m-d')));

        if (!$this->isValidShiftParam($shiftRaw)) {
            $shiftRaw = '24h';
        }

        if ($noteRaw === '') {
            echo json_encode(['success' => false, 'message' => 'A nota não pode ser vazia.']);
            die();
        }

        // Sanitiza o HTML do editor rico e extrai as menções [user] antes de
        // qualquer coisa tocar o banco — nunca confia no HTML cru do cliente.
        $sanitized = $this->sanitizeNoteHtml($noteRaw);
        $note      = $sanitized['html'];

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
            // formatUserLabel(): o nome do autor é PERSISTIDO em analyst_name,
            // então concatenar name+surname cru deixaria o nome duplicado
            // ("Rafael Rafael Leao Ereno") gravado no Diário de Bordo pra
            // sempre — aqui não tem cron que corrija depois.
            $fullname = $this->formatUserLabel(
                CWebUser::$data['name']    ?? '',
                CWebUser::$data['surname'] ?? '',
                $username
            );

            [$shift_name, $shift_id] = $this->resolveShiftName($db, $shiftRaw);

            // Tenta INSERT com colunas shift_id/notes_format (schema v4.2+)
            // Se não existirem (schema muito antigo), faz fallback pro schema legado.
            //
            // O execute() precisa estar DENTRO de cada ramo. Com a conexão
            // mysqli antiga, o prepare() validava a consulta no servidor e
            // lançava ali mesmo se a coluna não existisse; o ZbxDb só monta a
            // string, então o erro agora nasce no execute() — deixá-lo fora do
            // try faria o fallback nunca rodar.
            try {
                $stmt = $db->prepare(
                    "INSERT INTO module_plantonistas_shift_notes
                        (shift_date, shift_name, shift_id, analyst_userid, analyst_name, notes, notes_format, noc_context, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'html', NULL, NOW())"
                );
                $stmt->bind_param('ssiiss', $shift_date, $shift_name, $shift_id, $userid, $fullname, $note);
                $stmt->execute();
            } catch (\Throwable $e) {
                // Coluna shift_id ou notes_format não existe (schema < v2.5 / < v4.2)
                error_log('[plantonistas] INSERT de nota caiu no schema legado: ' . $e->getMessage());
                $stmt = $db->prepare(
                    "INSERT INTO module_plantonistas_shift_notes
                        (shift_date, shift_name, analyst_userid, analyst_name, notes, noc_context, created_at)
                     VALUES (?, ?, ?, ?, ?, NULL, NOW())"
                );
                $stmt->bind_param('ssiss', $shift_date, $shift_name, $userid, $fullname, $note);
                $stmt->execute();
            }

            $noteId = (int)$db->insert_id;

            if (!empty($sanitized['mentioned_userids'])) {
                $ctx = $this->resolveUserContext($db, $userid);
                $this->recordMentions($db, $noteId, $sanitized['mentioned_userids'], $userid, $ctx);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Nota salva com sucesso!',
                'id'      => $noteId,
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
