<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseRedirect,
    CUrl,
    CWebUser;

class PhonesSave extends CController {

    protected function checkInput(): bool {
        $fields = [
            'userid' => 'required|string',
            'phone'  => 'required|string',
        ];
        return $this->validateInput($fields);
    }

    public function checkPermissions(): bool {
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
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
            // Upsert numa ida ao banco, em vez do SELECT-then-UPDATE-or-INSERT
            // que estava aqui: entre o SELECT e o INSERT havia uma janela em
            // que outra requisição podia inserir a mesma linha, e o segundo
            // INSERT estouraria a PK. É a mesma forma que o PhonesImport já
            // usava; agora as duas telas gravam telefone do mesmo jeito.
            DBexecute(
                'INSERT INTO module_plantonistas_phones (userid, phone)' .
                ' VALUES (' . $userid . ', ' . zbx_dbstr($phone) . ')' .
                SqlFn::upsert('userid', ['phone'])
            );
            $redirect->setArgument('success', 'Telefone atualizado.');
        }

        $this->setResponse(new CControllerResponseRedirect($redirect));
    }

    private function getCurrentRoleid(int $userid): int {
        $row = DBfetch(DBselect('SELECT roleid FROM users WHERE userid = ' . $userid));
        return $row ? (int) $row['roleid'] : 0;
    }
}
