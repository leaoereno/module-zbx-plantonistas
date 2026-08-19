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

        // ── Permissão: super admin tudo; demais precisam de grupo em comum ──
        if (!$is_super_admin) {
            $ok = DBfetch(DBselect(
                'SELECT ug1.usrgrpid' .
                ' FROM users_groups ug1' .
                ' JOIN users_groups ug2 ON ug2.usrgrpid = ug1.usrgrpid' .
                ' WHERE ug1.userid = ' . $userid .
                '   AND ug2.userid = ' . $current_userid .
                ' LIMIT 1'
            ));

            if (!$ok) {
                $this->setResponse(new CControllerResponseRedirect(
                    $redirect->setArgument('error',
                        'Permissão negada: o usuário não pertence a nenhum grupo seu.')
                ));
                return;
            }

            // ── Não editar quem tem papel mais alto ──────────────────────
            //
            // Ver o telefone de todo mundo do grupo é o objetivo da tela (a
            // pessoa que se liga às 3h é o analista, papel User). ESCREVER é
            // outra coisa: sem esta guarda, um Admin passaria a alterar o
            // cadastro de um Super Admin, o que o filtro por papel — retirado
            // por ser errado na leitura — impedia por acidente.
            $alvo = DBfetch(DBselect(
                'SELECT r.type' .
                ' FROM users u JOIN role r ON r.roleid = u.roleid' .
                ' WHERE u.userid = ' . $userid
            ));

            if ($alvo && (int) $alvo['type'] > (int) CWebUser::$data['type']) {
                $this->setResponse(new CControllerResponseRedirect(
                    $redirect->setArgument('error',
                        'Permissão negada: este usuário tem papel mais alto que o seu.')
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

}
