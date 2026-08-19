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
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
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
        $shifts  = [];

        if ($usrgrpid > 0) {
            // Turnos cadastrados para este grupo em "Gerenciar Turnos". Grupo
            // sem nenhum turno ativo cai no modo legado (técnico + reserva
            // únicos, shift_id=0) — ver views/plantonistas.list.php.
            $res_shifts = DBselect(
                'SELECT id, name, start_time, end_time' .
                ' FROM module_plantonistas_shifts' .
                ' WHERE usrgrpid = ' . $usrgrpid . ' AND active = 1' .
                ' ORDER BY sort_order ASC, start_time ASC'
            );
            while ($row = DBfetch($res_shifts)) {
                $shifts[(int)$row['id']] = [
                    'id'         => (int)$row['id'],
                    'name'       => $row['name'],
                    'start_time' => $row['start_time'],
                    'end_time'   => $row['end_time'],
                ];
            }

            // day_map[data][shift_id] = entrada. shift_id=0 é o modo legado
            // (sem turno); grupos com turnos podem ter várias entradas por dia.
            $res_sched = DBselect(
                'SELECT ' . SqlFn::dateIso('s.schedule_date') . ' AS sched_date,' .
                '  s.scheduleid, s.shift_id, s.userid,' .
                '  u.name, u.surname, u.username,' .
                '  COALESCE(pm.phone, \'\') AS phone,' .
                // COALESCE(..., 0) e não IFNULL(..., ''): IFNULL é MySQL-only, e
                // o COALESCE do PostgreSQL recusa misturar BIGINT com '' (erro
                // de tipo). O 0 mantém o contrato com a view, que testa o campo
                // por truthiness — e '0' é falsy em PHP, igual à string vazia.
                // Também alinha com PlantaoOverview, que já usava 0 aqui.
                '  COALESCE(s.userid_reserva, 0) AS userid_reserva,' .
                '  COALESCE(ur.name, \'\') AS reserva_name,' .
                '  COALESCE(ur.surname, \'\') AS reserva_surname,' .
                '  COALESCE(pr.phone, \'\') AS reserva_phone,' .
                '  COALESCE(cs.name, \'\') AS shift_name' .
                ' FROM module_plantonistas_schedule s' .
                ' JOIN users u ON u.userid = s.userid' .
                ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = s.userid' .
                ' LEFT JOIN users ur ON ur.userid = s.userid_reserva' .
                ' LEFT JOIN module_plantonistas_phones pr ON pr.userid = s.userid_reserva' .
                ' LEFT JOIN module_plantonistas_shifts cs ON cs.id = s.shift_id' .
                ' WHERE s.usrgrpid = ' . $usrgrpid .
                '   AND s.schedule_date >= ' . zbx_dbstr($date_start) .
                '   AND s.schedule_date <= ' . zbx_dbstr($date_end) .
                ' ORDER BY s.schedule_date ASC, cs.sort_order ASC, cs.start_time ASC'
            );
            while ($row = DBfetch($res_sched)) {
                $shift_id = (int)$row['shift_id'];
                $day_map[$row['sched_date']][$shift_id] = [
                    'date'            => $row['sched_date'],
                    'scheduleid'      => $row['scheduleid'],
                    'shift_id'        => $shift_id,
                    'shift_name'      => $row['shift_name'],
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

            // Usuário desabilitado não aparece como opção de técnico. Critério
            // igual ao do Zabbix (CUser::getAccess usa MAX(users_status), e a
            // coluna Status da lista de usuários mostra "Disabled"): pertencer a
            // QUALQUER grupo desabilitado já desabilita a pessoa.
            //
            // Antes daqui era o inverso — EXISTS de grupo habilitado — e quem
            // estava em grupo misto (ex.: "NOC" + "Desligados") continuava
            // escalável no módulo mesmo já aparecendo como Disabled no Zabbix.
            // Sem roleid a lista do Zabbix também mostra "Disabled".
            $active_filter =
                ' AND NOT EXISTS (' .
                '   SELECT 1 FROM users_groups ugx' .
                '   JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid' .
                '   WHERE ugx.userid = u.userid AND gx.users_status = 1' .
                ' )' .
                ' AND u.roleid IS NOT NULL AND u.roleid <> 0';

            if ($is_super_admin) {
                $res_users = DBselect(
                    'SELECT DISTINCT u.userid, u.name, u.surname, u.username,' .
                    '  COALESCE(pm.phone, \'\') AS phone' .
                    ' FROM users u' .
                    ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = u.userid' .
                    " WHERE LOWER(u.username) <> 'guest'" . $active_filter .
                    ' ORDER BY u.name, u.surname'
                );
            } else {
                $res_users = DBselect(
                    'SELECT DISTINCT u.userid, u.name, u.surname, u.username,' .
                    '  COALESCE(pm.phone, \'\') AS phone' .
                    ' FROM users u' .
                    ' JOIN users_groups ug ON ug.userid = u.userid' .
                    ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = u.userid' .
                    ' WHERE ug.usrgrpid = ' . $usrgrpid . $active_filter .
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
            'shifts'        => $shifts,
            'users'         => $users,
            'groups'        => $groups,
            'usrgrpid'      => $usrgrpid,
            'success'       => $this->getInput('success', ''),
            'error'         => $this->getInput('error', ''),
            // Token CSRF por action: em módulo o Zabbix confere contra a
            // action completa, então cada form desta tela tem o seu.
            'csrf_save'     => \CCsrfTokenHelper::get('plantonistas.save'),
            'csrf_delete'   => \CCsrfTokenHelper::get('plantonistas.delete'),
            'csrf_import'   => \CCsrfTokenHelper::get('plantonistas.import'),
        ]);
        $response->setTitle('Escala de Plantao');
        $this->setResponse($response);
    }
}
