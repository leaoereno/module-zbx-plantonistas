<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseData,
    CWebUser;

class PlantaoList extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'month'    => 'int32',
            'year'     => 'int32',
            'usrgrpid' => 'int32',
            'success'  => 'string',
            'error'    => 'string',
        ]);
    }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super_admin = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        $groups = [];
        if ($is_super_admin) {
            $res = DBselect('SELECT usrgrpid, name FROM usrgrp ORDER BY name');
        } else {
            $res = DBselect(
                'SELECT ug.usrgrpid, g.name' .
                ' FROM users_groups ug' .
                ' JOIN usrgrp g ON g.usrgrpid = ug.usrgrpid' .
                ' WHERE ug.userid = ' . $current_userid .
                ' ORDER BY g.name'
            );
        }
        while ($row = DBfetch($res)) {
            $groups[(int)$row['usrgrpid']] = $row['name'];
        }

        // Grupo padrão = grupo do usuário logado, não o primeiro em ordem
        // alfabética de TODOS os grupos do Zabbix (o que para Super Admin
        // podia cair num grupo qualquer sem nenhuma relação com o time dele).
        $my_groups = [];
        $res_my = DBselect(
            'SELECT ug.usrgrpid' .
            ' FROM users_groups ug' .
            ' JOIN usrgrp g ON g.usrgrpid = ug.usrgrpid' .
            ' WHERE ug.userid = ' . $current_userid .
            ' ORDER BY g.name'
        );
        while ($row = DBfetch($res_my)) {
            $my_groups[] = (int)$row['usrgrpid'];
        }
        $default_usrgrpid = $my_groups[0] ?? (array_key_first($groups) ?? 0);

        $usrgrpid = (int)$this->getInput('usrgrpid', $default_usrgrpid);
        if (!array_key_exists($usrgrpid, $groups)) {
            $usrgrpid = $default_usrgrpid;
        }

        $now   = new \DateTime();
        $month = (int)$this->getInput('month', $now->format('n'));
        $year  = (int)$this->getInput('year',  $now->format('Y'));

        if ($month < 1)  { $month = 12; $year--; }
        if ($month > 12) { $month = 1;  $year++; }

        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $date_start    = sprintf('%04d-%02d-01', $year, $month);
        $date_end      = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

        $day_map = [];
        $users   = [];

        if ($usrgrpid > 0) {
            $res_sched = DBselect(
                'SELECT DATE_FORMAT(s.schedule_date, \'%Y-%m-%d\') AS sched_date,' .
                '  s.scheduleid, s.userid,' .
                '  u.name, u.surname, u.username,' .
                '  COALESCE(pm.phone, \'\') AS phone,' .
                '  IFNULL(s.userid_reserva, \'\') AS userid_reserva,' .
                '  COALESCE(ur.name, \'\') AS reserva_name,' .
                '  COALESCE(ur.surname, \'\') AS reserva_surname,' .
                '  COALESCE(pr.phone, \'\') AS reserva_phone' .
                ' FROM module_plantonistas_schedule s' .
                ' JOIN users u ON u.userid = s.userid' .
                ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = s.userid' .
                ' LEFT JOIN users ur ON ur.userid = s.userid_reserva' .
                ' LEFT JOIN module_plantonistas_phones pr ON pr.userid = s.userid_reserva' .
                ' WHERE s.usrgrpid = ' . $usrgrpid .
                '   AND s.schedule_date >= ' . zbx_dbstr($date_start) .
                '   AND s.schedule_date <= ' . zbx_dbstr($date_end)
            );
            while ($row = DBfetch($res_sched)) {
                $day_map[$row['sched_date']] = [
                    'date'            => $row['sched_date'],
                    'scheduleid'      => $row['scheduleid'],
                    'userid'          => $row['userid'],
                    'name'            => $row['name'],
                    'surname'         => $row['surname'],
                    'username'        => $row['username'],
                    'phone'           => $row['phone'],
                    'userid_reserva'  => $row['userid_reserva'],
                    'reserva_name'    => $row['reserva_name'],
                    'reserva_surname' => $row['reserva_surname'],
                    'reserva_phone'   => $row['reserva_phone'],
                ];
            }

            if ($is_super_admin) {
                $res_users = DBselect(
                    'SELECT DISTINCT u.userid, u.name, u.surname, u.username,' .
                    '  COALESCE(pm.phone, \'\') AS phone' .
                    ' FROM users u' .
                    ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = u.userid' .
                    " WHERE u.username != 'guest'" .
                    ' ORDER BY u.name, u.surname'
                );
            } else {
                $res_users = DBselect(
                    'SELECT DISTINCT u.userid, u.name, u.surname, u.username,' .
                    '  COALESCE(pm.phone, \'\') AS phone' .
                    ' FROM users u' .
                    ' JOIN users_groups ug ON ug.userid = u.userid' .
                    ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = u.userid' .
                    ' WHERE ug.usrgrpid = ' . $usrgrpid .
                    ' ORDER BY u.name, u.surname'
                );
            }
            while ($row = DBfetch($res_users)) {
                $users[$row['userid']] = $row;
            }
        }

        $response = new CControllerResponseData([
            'month'         => $month,
            'year'          => $year,
            'days_in_month' => $days_in_month,
            'day_map'       => $day_map,
            'users'         => $users,
            'groups'        => $groups,
            'usrgrpid'      => $usrgrpid,
            'success'       => $this->getInput('success', ''),
            'error'         => $this->getInput('error', ''),
        ]);
        $response->setTitle('Escala de Plantao');
        $this->setResponse($response);
    }
}
