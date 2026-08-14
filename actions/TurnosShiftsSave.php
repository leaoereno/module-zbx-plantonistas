<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosShiftsSave — AJAX: criar/atualizar um turno cadastrado.
 *
 * Restrita a Admin/Super Admin — Admin só pode gerenciar turnos das
 * equipes (usrgrp) às quais pertence.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosShiftsSave extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'id'         => 'string',
            'usrgrpid'   => 'required|string',
            'name'       => 'required|string',
            'start_time' => 'required|string',
            'end_time'   => 'required|string',
            'sort_order' => 'string',
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

        $userid   = (int)(CWebUser::$data['userid'] ?? 0);
        $ctx      = $this->resolveUserContext($db, $userid);

        if ($ctx['role_type'] < 2) {
            echo json_encode(['success' => false, 'message' => 'Acesso restrito a Admin/Super Admin.']);
            $db->close();
            die();
        }

        $id        = $this->getInput('id', '');
        $idInt     = ctype_digit($id) ? (int)$id : null;
        $usrgrpid  = (int)$this->getInput('usrgrpid', '0');
        $name      = $this->getInput('name', '');
        $start     = $this->getInput('start_time', '');
        $end       = $this->getInput('end_time', '');
        $sortOrder = (int)$this->getInput('sort_order', '0');

        $result = $this->saveShift($db, $ctx, $idInt, $usrgrpid, $name, $start, $end, $sortOrder);

        $db->close();
        echo json_encode($result);
        die();
    }
}
