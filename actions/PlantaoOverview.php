<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseData, CWebUser;

class PlantaoOverview extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool { return true; }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);
        $today          = date('Y-m-d');

        // Grupos visíveis: super vê todos; demais só os seus
        if ($is_super) {
            $grp_res = DBselect('SELECT usrgrpid, name FROM usrgrp ORDER BY name');
        } else {
            $grp_res = DBselect(
                'SELECT g.usrgrpid, g.name FROM usrgrp g' .
                ' JOIN users_groups ug ON ug.usrgrpid = g.usrgrpid' .
                ' WHERE ug.userid = ' . $current_userid .
                ' ORDER BY g.name'
            );
        }

        $group_ids = [];
        $groups    = [];
        while ($row = DBfetch($grp_res)) {
            $group_ids[]                    = (int) $row['usrgrpid'];
            $groups[(int)$row['usrgrpid']] = $row['name'];
        }

        $shifts_by_group = []; // [usrgrpid => [shift_id => turno]]
        $overview        = []; // [usrgrpid => [shift_id => entrada]]

        if (!empty($group_ids)) {
            $ids = implode(',', $group_ids);

            // Turnos ativos de cada grupo visível — grupo sem turno nenhum
            // continua no modo legado (1 card com titular/reserva).
            $res_shifts = DBselect(
                'SELECT id, usrgrpid, name, start_time, end_time' .
                ' FROM module_plantonistas_shifts' .
                ' WHERE usrgrpid IN (' . $ids . ') AND active = 1' .
                ' ORDER BY usrgrpid ASC, sort_order ASC, start_time ASC'
            );
            while ($row = DBfetch($res_shifts)) {
                $shifts_by_group[(int)$row['usrgrpid']][(int)$row['id']] = [
                    'id'         => (int)$row['id'],
                    'name'       => $row['name'],
                    'start_time' => $row['start_time'],
                    'end_time'   => $row['end_time'],
                ];
            }

            $res = DBselect(
                'SELECT s.usrgrpid, s.shift_id,' .
                '  s.userid, u.name, u.surname, u.username,' .
                '  COALESCE(pm.phone,\'\') AS phone,' .
                '  IFNULL(s.userid_reserva,0) AS userid_reserva,' .
                '  COALESCE(ur.name,\'\') AS r_name,' .
                '  COALESCE(ur.surname,\'\') AS r_surname,' .
                '  COALESCE(ur.username,\'\') AS r_username,' .
                '  COALESCE(pr.phone,\'\') AS r_phone' .
                ' FROM module_plantonistas_schedule s' .
                ' JOIN users u ON u.userid = s.userid' .
                ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = s.userid' .
                ' LEFT JOIN users ur ON ur.userid = s.userid_reserva' .
                ' LEFT JOIN module_plantonistas_phones pr ON pr.userid = s.userid_reserva' .
                ' WHERE s.usrgrpid IN (' . $ids . ')' .
                '   AND s.schedule_date = ' . zbx_dbstr($today)
            );
            while ($row = DBfetch($res)) {
                $overview[(int)$row['usrgrpid']][(int)$row['shift_id']] = $row;
            }
        }

        $response = new CControllerResponseData([
            'today'           => $today,
            'groups'          => $groups,
            'shifts_by_group' => $shifts_by_group,
            'overview'        => $overview,
        ]);
        $response->setTitle('Visão Geral — Plantão Hoje');
        $this->setResponse($response);
    }
}
