<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseData,
    CWebUser;

/**
 * TurnosShiftsView — Tela de administração de Turnos.
 *
 * Restrita a Admin/Super Admin (role_type >= 2). Permite cadastrar turnos
 * por equipe (usrgrp) e vincular usuários a um turno fixo.
 *
 * Super Admin administra todas as equipes; Admin administra apenas as
 * equipes (grupos de usuário) às quais pertence.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosShiftsView extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        if (CWebUser::isGuest()) {
            return false;
        }

        $db = $this->getDb();
        if (!$db) {
            return false;
        }
        $userid   = (int)(CWebUser::$data['userid'] ?? 0);
        $roleType = $this->getUserRoleType($db, $userid);
        $db->close();

        // Somente Admin (2) ou Super Admin (3) gerenciam turnos.
        return $roleType >= 2;
    }

    protected function doAction(): void {
        $current_userid = (int)(CWebUser::$data['userid'] ?? 0);

        $db = $this->getDb();
        if (!$db) {
            $data = [
                'db_error' => 'Erro ao conectar ao banco de dados.',
                'groups'   => [],
                'is_superadmin' => false,
                'legacy_shifts' => [],
                'csrf_tokens'   => $this->csrfTokens(),
            ];
            $response = new CControllerResponseData($data);
            $response->setTitle(_('Gerenciar Turnos'));
            $this->setResponse($response);
            return;
        }

        $ctx = $this->resolveUserContext($db, $current_userid);

        $groups = [];
        foreach ($this->listManageableGroups($db, $ctx) as $g) {
            $usrgrpid  = (int)$g['usrgrpid'];
            $usersInfo = $this->listUsersByGroup($db, $usrgrpid);
            $groups[] = [
                'usrgrpid'   => $usrgrpid,
                'name'       => $g['name'],
                'shifts'     => $this->listShiftsByGroup($db, $usrgrpid),
                'users'      => $usersInfo['rows'],
                'users_error'=> $usersInfo['error'],
            ];
        }

        $db->close();

        $data = [
            'db_error'      => null,
            'groups'        => $groups,
            'is_superadmin' => $ctx['is_superadmin'],
            'legacy_shifts' => $this->legacyShiftLabels(),
            'csrf_tokens'   => $this->csrfTokens(),
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Gerenciar Turnos'));
        $this->setResponse($response);
    }

    /**
     * Tokens CSRF das actions de escrita chamadas por esta tela.
     *
     * O token do Zabbix é por sessão E por action. Em módulo
     * (`Modules\...`), o `CController::checkCsrfToken()` confere contra a
     * action COMPLETA — diferente do core, que agrupa pelo primeiro segmento
     * (`host` cobre `host.edit`, `host.delete`...). Por isso cada action
     * precisa do seu, e o JS escolhe pelo nome.
     */
    private function csrfTokens(): array {
        $actions = [
            'plantonistas.shifts.save',
            'plantonistas.shifts.delete',
            'plantonistas.usershift.save',
        ];

        $tokens = [];
        foreach ($actions as $a) {
            $tokens[$a] = \CCsrfTokenHelper::get($a);
        }

        return $tokens;
    }
}
