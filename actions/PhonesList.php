<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseData,
    CWebUser;

class PhonesList extends CController {

    use PhonesFormat;
    use UserLabel;

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
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
    }

    protected function doAction(): void {
        $current_userid  = (int) CWebUser::$data['userid'];
        $is_super_admin  = (CWebUser::$data['type'] >= USER_TYPE_SUPER_ADMIN);

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

        // Usuário desabilitado não aparece. Critério igual ao do Zabbix
        // (MAX(users_status) em CUser::getAccess): pertencer a QUALQUER grupo
        // desabilitado já desabilita a pessoa — antes daqui era o inverso
        // (EXISTS de grupo habilitado), e quem estava em grupo misto continuava
        // listado no módulo já aparecendo como Disabled no Zabbix.
        $active_filter =
            ' NOT EXISTS (' .
            '   SELECT 1 FROM users_groups ugx' .
            '   JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid' .
            '   WHERE ugx.userid = u.userid AND gx.users_status = 1' .
            ' )' .
            ' AND u.roleid IS NOT NULL AND u.roleid <> 0';

        if ($is_super_admin) {
            // Super admin: vê TODOS os usuários ativos do sistema
            $sql = $select . ' WHERE ' . $active_filter . ' ORDER BY u.name, u.surname, g.name';
        } else {
            // Admin: vê quem compartilha grupo com ele.
            //
            // O filtro por PAPEL que existia aqui saiu (2026-08-19). Ele fazia
            // sentido enquanto Telefones era visível a todos; virou defeito
            // quando a tela passou a ser Admin+ (commit 9959722): a partir dali
            // "mesmo papel" significava que um Admin só via OUTROS ADMINS — e
            // quem ele precisa ligar às 3h da manhã é o analista, que é User.
            // Uma lista de contato de plantão que esconde justamente os
            // plantonistas não serve para nada. O resto do módulo (Escala,
            // Repasse, notas, presença, menções) sempre segmentou só por grupo.
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
                '   AND ' . $active_filter .
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
                    // `phone` é o valor cru do banco (só dígitos); `phone_fmt`
                    // é o mesmo número com máscara. A view usa o formatado —
                    // formatar aqui evita a view ter regra de negócio própria.
                    'phone'     => $row['phone'],
                    'phone_fmt' => $this->formatPhoneBr($row['phone']),
                    // Rótulo montado aqui, não na view: concatenar name+surname
                    // às cegas duplica o nome de quem tem o nome completo no
                    // campo surname (ver UserLabel).
                    'label'     => $this->userLabel($row['name'], $row['surname'], $row['username']),
                    'groups'   => [],
                ];
            }
            $users[$uid]['groups'][$row['usrgrpid']] = $row['group_name'];
        }

        $response = new CControllerResponseData([
            'users'   => array_values($users),
            'success' => $this->getInput('success', ''),
            'error'   => $this->getInput('error', ''),
            // Token CSRF por action: em módulo o Zabbix confere contra a
            // action completa, então save e import têm tokens diferentes.
            'csrf_save'   => \CCsrfTokenHelper::get('plantonistas.phones.save'),
            'csrf_import' => \CCsrfTokenHelper::get('plantonistas.phones.import'),
        ]);
        $response->setTitle('Telefones de Plantão');
        $this->setResponse($response);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function getUserGroupIds(int $userid): array {
        $ids = [];
        $res = DBselect('SELECT usrgrpid FROM users_groups WHERE userid = ' . $userid);
        while ($row = DBfetch($res)) {
            $ids[] = (int) $row['usrgrpid'];
        }
        return $ids;
    }
}
