<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseRedirect,
    CUrl,
    CWebUser;

class PhonesSave extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'userid' => 'required|string',
            'phone'  => 'required|string',
        ];
        return $this->validateInput($fields);
    }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $userid         = (int) $this->getInput('userid');
        $phone = trim($this->getInput('phone'));
        // Salvar apenas dígitos — remove máscara, espaços e caracteres especiais
        $phone = preg_replace('/\D/', '', $phone);
        $is_super_admin = (CWebUser::$data['type'] >= USER_TYPE_SUPER_ADMIN);

        $redirect = (new CUrl('zabbix.php'))->setArgument('action', 'plantonistas.phones.list');

        // ── Permissão: super admin tudo; demais precisam de grupo + papel igual ──
        if (!$is_super_admin) {
            $current_roleid = $this->getCurrentRoleid($current_userid);

            // O alvo deve compartilhar ao menos um grupo E ter o mesmo papel
            $ok = DBfetch(DBselect(
                'SELECT ug1.usrgrpid' .
                ' FROM users_groups ug1' .
                ' JOIN users_groups ug2 ON ug2.usrgrpid = ug1.usrgrpid' .
                ' JOIN users u ON u.userid = ug1.userid' .
                ' WHERE ug1.userid = ' . $userid .
                '   AND ug2.userid = ' . $current_userid .
                '   AND u.roleid   = ' . $current_roleid .
                ' LIMIT 1'
            ));

            if (!$ok) {
                $this->setResponse(new CControllerResponseRedirect(
                    $redirect->setArgument('error',
                        'Permissão negada: o usuário não pertence à sua estrutura (grupo + papel).')
                ));
                return;
            }
        }

        if (empty($phone)) {
            DBexecute('DELETE FROM module_plantonistas_phones WHERE userid = ' . $userid);
            $redirect->setArgument('success', 'Telefone removido.');
        } else {
            $exists = DBfetch(DBselect(
                'SELECT userid FROM module_plantonistas_phones WHERE userid = ' . $userid
            ));
            if ($exists) {
                DBexecute(
                    'UPDATE module_plantonistas_phones SET phone = ' . zbx_dbstr($phone) .
                    ' WHERE userid = ' . $userid
                );
            } else {
                DBexecute(
                    'INSERT INTO module_plantonistas_phones (userid, phone)' .
                    ' VALUES (' . $userid . ', ' . zbx_dbstr($phone) . ')'
                );
            }
            $redirect->setArgument('success', 'Telefone atualizado.');
        }

        $this->setResponse(new CControllerResponseRedirect($redirect));
    }

    private function getCurrentRoleid(int $userid): int {
        $row = DBfetch(DBselect('SELECT roleid FROM users WHERE userid = ' . $userid));
        return $row ? (int) $row['roleid'] : 0;
    }
}
