<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseData,
    CWebUser;

class PhonesList extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'success' => 'string',
            'error'   => 'string',
        ];
        return $this->validateInput($fields);
    }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid  = (int) CWebUser::$data['userid'];
        $is_super_admin  = (CWebUser::$data['type'] >= USER_TYPE_SUPER_ADMIN);
        $current_roleid  = $this->getCurrentRoleid($current_userid);

        // ── Base da query ─────────────────────────────────────────────────
        $select =
            'SELECT DISTINCT' .
            '  u.userid, u.name, u.surname, u.username,' .
            '  COALESCE(p.phone, \'\') AS phone,' .
            '  g.usrgrpid, g.name AS group_name' .
            ' FROM users u' .
            ' JOIN users_groups ug ON ug.userid   = u.userid' .
            ' JOIN usrgrp       g  ON g.usrgrpid  = ug.usrgrpid' .
            ' LEFT JOIN module_plantonistas_phones p ON p.userid = u.userid';

        if ($is_super_admin) {
            // Super admin: vê TODOS os usuários do sistema
            $sql = $select . ' ORDER BY u.name, u.surname, g.name';
        } else {
            // Admin / Usuário: vê apenas quem compartilha grupo E mesmo papel (role)
            $group_ids = $this->getUserGroupIds($current_userid);

            if (empty($group_ids)) {
                $this->setResponse(new CControllerResponseData([
                    'users'   => [],
                    'success' => $this->getInput('success', ''),
                    'error'   => $this->getInput('error', ''),
                ]));
                return;
            }

            $ids_str = implode(',', $group_ids);
            $sql = $select .
                ' WHERE ug.usrgrpid IN (' . $ids_str . ')' .
                '   AND u.roleid = ' . $current_roleid .
                ' ORDER BY u.name, u.surname, g.name';
        }

        // ── Agregar grupos por usuário ────────────────────────────────────
        $users = [];
        $result = DBselect($sql);
        while ($row = DBfetch($result)) {
            $uid = $row['userid'];
            if (!isset($users[$uid])) {
                $users[$uid] = [
                    'userid'   => $uid,
                    'name'     => $row['name'],
                    'surname'  => $row['surname'],
                    'username' => $row['username'],
                    'phone'    => $row['phone'],
                    'groups'   => [],
                ];
            }
            $users[$uid]['groups'][$row['usrgrpid']] = $row['group_name'];
        }

        $response = new CControllerResponseData([
            'users'   => array_values($users),
            'success' => $this->getInput('success', ''),
            'error'   => $this->getInput('error', ''),
        ]);
        $response->setTitle('Telefones de Plantão');
        $this->setResponse($response);
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private function getCurrentRoleid(int $userid): int {
        $row = DBfetch(DBselect('SELECT roleid FROM users WHERE userid = ' . $userid));
        return $row ? (int) $row['roleid'] : 0;
    }

    private function getUserGroupIds(int $userid): array {
        $ids = [];
        $res = DBselect('SELECT usrgrpid FROM users_groups WHERE userid = ' . $userid);
        while ($row = DBfetch($res)) {
            $ids[] = (int) $row['usrgrpid'];
        }
        return $ids;
    }
}
