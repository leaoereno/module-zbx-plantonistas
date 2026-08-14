<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosShiftsDelete — AJAX: remover um turno cadastrado.
 *
 * Também remove os vínculos usuário→turno associados (module_plantonistas_user_shift).
 * Notas antigas do Diário de Bordo que referenciam o turno mantêm o
 * shift_name gravado (histórico preservado, shift_id fica órfão).
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosShiftsDelete extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'id' => 'required|string',
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

        $idRaw = $this->getInput('id', '');
        if (!ctype_digit($idRaw)) {
            echo json_encode(['success' => false, 'message' => 'ID de turno inválido.']);
            $db->close();
            die();
        }

        $result = $this->deleteShift($db, $ctx, (int)$idRaw);

        $db->close();
        echo json_encode($result);
        die();
    }
}
