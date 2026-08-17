<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseData, CWebUser;

class PlantaoHistory extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool {
        return $this->validateInput([
            'usrgrpid' => 'int32',
            'page'     => 'int32',
        ]);
    }

    public function checkPermissions(): bool {
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        // Grupos disponíveis para o admin
        $groups = [];
        if ($is_super) {
            $res = DBselect('SELECT usrgrpid, name FROM usrgrp ORDER BY name');
        } else {
            $res = DBselect(
                'SELECT g.usrgrpid, g.name FROM usrgrp g' .
                ' JOIN users_groups ug ON ug.usrgrpid = g.usrgrpid' .
                ' WHERE ug.userid = ' . $current_userid . ' ORDER BY g.name'
            );
        }
        while ($r = DBfetch($res)) $groups[(int)$r['usrgrpid']] = $r['name'];

        $usrgrpid = (int) $this->getInput('usrgrpid', array_key_first($groups) ?? 0);
        if (!array_key_exists($usrgrpid, $groups)) $usrgrpid = array_key_first($groups) ?? 0;

        $per_page = 50;
        $page     = max(1, (int) $this->getInput('page', 1));
        $offset   = ($page - 1) * $per_page;

        $where = $usrgrpid > 0 ? ' WHERE h.usrgrpid = ' . $usrgrpid : '';

        $total_row = DBfetch(DBselect('SELECT COUNT(*) AS cnt FROM module_plantonistas_history h' . $where));
        $total     = $total_row ? (int)$total_row['cnt'] : 0;

        $rows = [];
        $res  = DBselect(
            'SELECT h.*,' .
            '  u_new.name AS n_name, u_new.surname AS n_surn, u_new.username AS n_user,' .
            '  u_old.name AS o_name, u_old.surname AS o_surn, u_old.username AS o_user,' .
            '  r_new.name AS rn_name, r_new.surname AS rn_surn, r_new.username AS rn_user,' .
            '  r_old.name AS ro_name, r_old.surname AS ro_surn, r_old.username AS ro_user,' .
            '  cb.name AS cb_name, cb.surname AS cb_surn, cb.username AS cb_user,' .
            '  g.name AS group_name' .
            ' FROM module_plantonistas_history h' .
            ' LEFT JOIN users u_new ON u_new.userid = h.userid_new' .
            ' LEFT JOIN users u_old ON u_old.userid = h.userid_old' .
            ' LEFT JOIN users r_new ON r_new.userid = h.reserva_new' .
            ' LEFT JOIN users r_old ON r_old.userid = h.reserva_old' .
            ' LEFT JOIN users cb    ON cb.userid    = h.changed_by' .
            ' LEFT JOIN usrgrp g   ON g.usrgrpid   = h.usrgrpid' .
            $where .
            ' ORDER BY h.changed_at DESC' .
            ' LIMIT ' . $per_page . ' OFFSET ' . $offset
        );
        while ($r = DBfetch($res)) $rows[] = $r;

        $response = new CControllerResponseData([
            'groups'   => $groups,
            'usrgrpid' => $usrgrpid,
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ]);
        $response->setTitle('Histórico de Plantão');
        $this->setResponse($response);
    }
}
