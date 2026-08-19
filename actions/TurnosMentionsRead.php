<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosMentionsRead — AJAX: marca uma notificação de menção [user] como
 * lida (banner de aviso no Repasse Plantão).
 *
 * Sempre escopado ao próprio usuário logado (WHERE mentioned_userid = ...
 * dentro de markMentionRead()) — não dá pra marcar como lida a notificação
 * de outra pessoa trocando o id no request.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosMentionsRead extends CController {

    use TurnosReportBase;

    protected function checkInput(): bool {
        return $this->validateInput([
            'mention_id' => 'required|string',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $mentionIdRaw = $this->getInput('mention_id', '');
        if (!ctype_digit($mentionIdRaw)) {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
            die();
        }

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.']);
            die();
        }

        $userid = (int)(CWebUser::$data['userid'] ?? 0);
        $ok     = $this->markMentionRead($db, (int)$mentionIdRaw, $userid);

        $db->close();
        echo json_encode(['success' => $ok]);
        die();
    }
}
