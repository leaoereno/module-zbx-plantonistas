<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseRedirect, CUrl, CWebUser;

class PlantaoDelete extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool {
        return $this->validateInput([
            'scheduleid' => 'required|int32',
            'usrgrpid'   => 'int32',
            'month'      => 'int32',
            'year'       => 'int32',
        ]);
    }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);
        $scheduleid     = (int) $this->getInput('scheduleid');
        $month          = (int) $this->getInput('month', date('n'));
        $year           = (int) $this->getInput('year',  date('Y'));
        $usrgrpid       = (int) $this->getInput('usrgrpid', 0);
        $today          = date('Y-m-d');

        $redirect = (new CUrl('zabbix.php'))
            ->setArgument('action',   'plantonistas.list')
            ->setArgument('month',    $month)
            ->setArgument('year',     $year)
            ->setArgument('usrgrpid', $usrgrpid);

        $sched = DBfetch(DBselect(
            'SELECT s.scheduleid, s.usrgrpid, s.userid, s.userid_reserva, s.shift_id,' .
            '  COALESCE(cs.name, \'\') AS shift_name,' .
            '  DATE_FORMAT(s.schedule_date,\'%Y-%m-%d\') AS schedule_date' .
            ' FROM module_plantonistas_schedule s' .
            ' LEFT JOIN module_plantonistas_shifts cs ON cs.id = s.shift_id' .
            ' WHERE s.scheduleid=' . $scheduleid
        ));

        if (!$sched) {
            $this->setResponse(new CControllerResponseRedirect(
                (clone $redirect)->setArgument('error', 'Entrada de plantão não encontrada.')
            ));
            return;
        }

        // ── Proteção: não remover plantão do dia corrente ─────────────────
        if ($sched['schedule_date'] === $today) {
            $this->setResponse(new CControllerResponseRedirect(
                (clone $redirect)->setArgument('error',
                    'Não é permitido remover o plantão do dia atual (' .
                    date('d/m/Y') . '). Reatribua a outro técnico se necessário.')
            ));
            return;
        }

        // ── Permissão de grupo ────────────────────────────────────────────
        if (!$is_super) {
            $perm = DBfetch(DBselect(
                'SELECT usrgrpid FROM users_groups' .
                ' WHERE userid=' . $current_userid . ' AND usrgrpid=' . (int)$sched['usrgrpid']
            ));
            if (!$perm) {
                $this->setResponse(new CControllerResponseRedirect(
                    (clone $redirect)->setArgument('error',
                        'Permissão negada: você não pertence ao grupo deste plantão.')
                ));
                return;
            }
        }

        // ── Log histórico antes de deletar ────────────────────────────────
        DBexecute(
            'INSERT INTO module_plantonistas_history' .
            ' (scheduleid,usrgrpid,shift_id,shift_name,schedule_date,action,userid_old,userid_new,' .
            '  reserva_old,reserva_new,changed_by,changed_at)' .
            ' VALUES (' .
                $scheduleid . ',' . (int)$sched['usrgrpid'] . ',' . (int)$sched['shift_id'] . ',' .
                zbx_dbstr($sched['shift_name']) . ',' .
                zbx_dbstr($sched['schedule_date']) . ',' . zbx_dbstr('delete') . ',' .
                (int)$sched['userid'] . ',NULL,' .
                ($sched['userid_reserva'] ? (int)$sched['userid_reserva'] : 'NULL') . ',NULL,' .
                $current_userid . ',' . time() .
            ')'
        );

        DBexecute('DELETE FROM module_plantonistas_schedule WHERE scheduleid=' . $scheduleid);

        $this->setResponse(new CControllerResponseRedirect(
            $redirect->setArgument('success', 'Plantão removido.')
        ));
    }
}
