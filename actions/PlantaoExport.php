<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseData, CWebUser;

class PlantaoExport extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool {
        return $this->validateInput([
            'month'    => 'int32',
            'year'     => 'int32',
            'usrgrpid' => 'required|int32',
        ]);
    }

    public function checkPermissions(): bool {
        // Escala / Histórico / Telefones: somente Admin (2) e Super Admin (3).
        // Usuário comum (1) só tem Visão Geral e Repasse Plantão.
        return ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $now            = new \DateTime();
        $month          = (int) $this->getInput('month', $now->format('n'));
        $year           = (int) $this->getInput('year',  $now->format('Y'));
        $usrgrpid       = (int) $this->getInput('usrgrpid');

        // Grupo precisa ser do admin (não-super)
        if ($this->getUserType() < USER_TYPE_SUPER_ADMIN) {
            $ok = DBfetch(DBselect(
                'SELECT usrgrpid FROM users_groups WHERE userid=' . $current_userid .
                ' AND usrgrpid=' . $usrgrpid
            ));
            if (!$ok) { http_response_code(403); exit; }
        }

        $group_row = DBfetch(DBselect('SELECT name FROM usrgrp WHERE usrgrpid=' . $usrgrpid));
        $group_name = $group_row ? $group_row['name'] : 'grupo';

        $days   = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $d_from = sprintf('%04d-%02d-01', $year, $month);
        $d_to   = sprintf('%04d-%02d-%02d', $year, $month, $days);

        $month_names = [
            1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
            7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro',
        ];
        $weekdays = ['1'=>'Segunda','2'=>'Terça','3'=>'Quarta',
                     '4'=>'Quinta','5'=>'Sexta','6'=>'Sábado','7'=>'Domingo'];

        $entries = [];
        $res = DBselect(
            'SELECT DATE_FORMAT(s.schedule_date,\'%Y-%m-%d\') AS dt,' .
            '  u.name, u.surname, u.username,' .
            '  COALESCE(pm.phone,\'\') AS phone,' .
            '  COALESCE(ur.name,\'\') AS r_name,' .
            '  COALESCE(ur.surname,\'\') AS r_surn,' .
            '  COALESCE(ur.username,\'\') AS r_user,' .
            '  COALESCE(pr.phone,\'\') AS r_phone' .
            ' FROM module_plantonistas_schedule s' .
            ' JOIN users u ON u.userid = s.userid' .
            ' LEFT JOIN module_plantonistas_phones pm ON pm.userid = s.userid' .
            ' LEFT JOIN users ur ON ur.userid = s.userid_reserva' .
            ' LEFT JOIN module_plantonistas_phones pr ON pr.userid = s.userid_reserva' .
            ' WHERE s.usrgrpid = ' . $usrgrpid .
            '   AND s.schedule_date BETWEEN ' . zbx_dbstr($d_from) . ' AND ' . zbx_dbstr($d_to) .
            ' ORDER BY s.schedule_date'
        );
        while ($r = DBfetch($res)) $entries[$r['dt']] = $r;

        $filename = 'plantao_' . strtolower($group_name) . '_' .
                    $month_names[$month] . '_' . $year . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Data', 'Dia da Semana', 'Plantonista', 'Telefone Principal',
            'Reserva', 'Telefone Reserva', 'Cobertura'
        ], ';');

        for ($d = 1; $d <= $days; $d++) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $dt_obj   = new \DateTime($date_str);
            $dow      = $dt_obj->format('N');
            $e        = $entries[$date_str] ?? null;

            $tech_name  = $e ? trim($e['name'] . ' ' . $e['surname']) ?: $e['username'] : '';
            $res_name   = $e ? trim($e['r_name'] . ' ' . $e['r_surn']) ?: $e['r_user'] : '';
            $covered    = $e ? 'Sim' : 'Não';

            fputcsv($out, [
                date('d/m/Y', strtotime($date_str)),
                $weekdays[$dow] ?? '',
                $tech_name,
                $e ? $e['phone']   : '',
                $res_name,
                $e ? $e['r_phone'] : '',
                $covered,
            ], ';');
        }
        fclose($out);
        exit;
    }
}
