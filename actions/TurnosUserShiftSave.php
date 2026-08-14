<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosUserShiftSave — AJAX: vincula (ou desvincula) um usuário a um turno.
 *
 * Vínculo fixo — 1 turno ativo por usuário (module_plantonistas_user_shift.userid é PK).
 * Enviar shift_id vazio remove o vínculo.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosUserShiftSave extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'userid'   => 'required|string',
            'shift_id' => 'string',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.']);
            die();
        }

        $userid = (int)(CWebUser::$data['userid'] ?? 0);
        $ctx    = $this->resolveUserContext($db, $userid);

        if ($ctx['role_type'] < 2) {
            echo json_encode(['success' => false, 'message' => 'Acesso restrito a Admin/Super Admin.']);
            $db->close();
            die();
        }

        $targetUserId = (int)$this->getInput('userid', '0');
        $shiftIdRaw   = $this->getInput('shift_id', '');
        $shiftId      = ctype_digit($shiftIdRaw) ? (int)$shiftIdRaw : null;

        $result = $this->saveUserShift($db, $ctx, $targetUserId, $shiftId);

        $db->close();
        echo json_encode($result);
        die();
    }
}
