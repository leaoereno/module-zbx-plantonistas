<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseRedirect, CUrl, CWebUser;

class PlantaoSave extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool {
        return $this->validateInput([
            'schedule_dates' => 'required|string',
            'userid'         => 'required|string',
            'userid_reserva' => 'string',
            'usrgrpid'       => 'required|int32',
            'month'          => 'int32',
            'year'           => 'int32',
        ]);
    }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        $userid         = (int) $this->getInput('userid');
        $userid_reserva = (int) $this->getInput('userid_reserva', '0');
        $usrgrpid       = (int) $this->getInput('usrgrpid');
        $month          = (int) $this->getInput('month', date('n'));
        $year           = (int) $this->getInput('year',  date('Y'));

        $redirect = (new CUrl('zabbix.php'))
            ->setArgument('action',   'plantonistas.list')
            ->setArgument('month',    $month)
            ->setArgument('year',     $year)
            ->setArgument('usrgrpid', $usrgrpid);

        // Super admin pode escalar em qualquer grupo.
        // Demais usuários precisam pertencer ao grupo.
        if (!$is_super && !$this->inGroup($current_userid, $usrgrpid)) {
            $this->err($redirect, 'Permissão negada: você não pertence a este grupo.');
            return;
        }

        // Técnico principal deve pertencer ao grupo (exceto Super Admin)
        if (!$is_super && !$this->inGroup($userid, $usrgrpid)) {
            $this->err($redirect, 'O técnico selecionado não pertence a este grupo.');
            return;
        }

        // Reserva deve pertencer ao grupo (se informado).
        // Super Admin pode escalar reserva de qualquer grupo.
        if ($userid_reserva > 0 && !$is_super && !$this->inGroup($userid_reserva, $usrgrpid)) {
            $this->err($redirect, 'O técnico reserva não pertence a este grupo.');
            return;
        }

        // Técnico principal não pode ser o mesmo que o reserva
        if ($userid_reserva > 0 && $userid === $userid_reserva) {
            $this->err($redirect, 'O técnico principal e o reserva não podem ser a mesma pessoa.');
            return;
        }

        // Parsear e validar datas
        $parts = array_filter(array_map('trim', explode(',', trim($this->getInput('schedule_dates', '')))));
        if (empty($parts)) {
            $this->err($redirect, 'Selecione ao menos um dia no calendário.');
            return;
        }

        $valid_dates = [];
        $bad         = [];
        foreach ($parts as $d) {
            $dt = \DateTime::createFromFormat('Y-m-d', $d);
            if ($dt && $dt->format('Y-m-d') === $d) {
                $valid_dates[] = $d;
            } else {
                $bad[] = $d;
            }
        }
        if ($bad) {
            $this->err($redirect, 'Datas inválidas: ' . implode(', ', $bad));
            return;
        }

        // Gravar cada dia
        $r_sql = $userid_reserva > 0 ? $userid_reserva : 'NULL';
        $r_set = $userid_reserva > 0
            ? ', userid_reserva=' . $userid_reserva
            : ', userid_reserva=NULL';

        try {
            foreach ($valid_dates as $date) {
                $existing = DBfetch(DBselect(
                    'SELECT scheduleid, userid, userid_reserva FROM module_plantonistas_schedule' .
                    ' WHERE usrgrpid=' . $usrgrpid . ' AND schedule_date=' . zbx_dbstr($date)
                ));

                if ($existing) {
                    DBexecute(
                        'UPDATE module_plantonistas_schedule SET userid=' . $userid . $r_set .
                        ', created_by=' . $current_userid . ', created_at=' . time() .
                        ' WHERE scheduleid=' . (int)$existing['scheduleid']
                    );
                    $this->logHistory(
                        (int)$existing['scheduleid'], $usrgrpid, $date, 'update',
                        (int)$existing['userid'], $userid,
                        $existing['userid_reserva'] ? (int)$existing['userid_reserva'] : null,
                        $userid_reserva > 0 ? $userid_reserva : null,
                        $current_userid
                    );
                } else {
                    DBexecute(
                        'INSERT INTO module_plantonistas_schedule' .
                        ' (usrgrpid,userid,userid_reserva,schedule_date,created_by,created_at)' .
                        ' VALUES (' . $usrgrpid . ',' . $userid . ',' . $r_sql . ',' .
                        zbx_dbstr($date) . ',' . $current_userid . ',' . time() . ')'
                    );
                    $new_id = DBfetch(DBselect(
                        'SELECT scheduleid FROM module_plantonistas_schedule' .
                        ' WHERE usrgrpid=' . $usrgrpid . ' AND schedule_date=' . zbx_dbstr($date)
                    ));
                    $this->logHistory(
                        $new_id ? (int)$new_id['scheduleid'] : 0, $usrgrpid, $date, 'create',
                        null, $userid, null,
                        $userid_reserva > 0 ? $userid_reserva : null,
                        $current_userid
                    );
                }
            }
        } catch (\Exception $e) {
            $this->err($redirect, 'Erro ao salvar: ' . $e->getMessage());
            return;
        }

        $count = count($valid_dates);
        $fmt   = implode(', ', array_map(
            fn($d) => substr($d, 8, 2) . '/' . substr($d, 5, 2),
            $valid_dates
        ));

        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('success',
                ($count === 1 ? '1 dia salvo' : $count . ' dias salvos') . ': ' . $fmt . '.'
            )
        ));
    }

    private function logHistory(
        int $scheduleid, int $usrgrpid, string $date, string $action,
        ?int $userid_old, ?int $userid_new,
        ?int $reserva_old, ?int $reserva_new,
        int $changed_by
    ): void {
        DBexecute(
            'INSERT INTO module_plantonistas_history' .
            ' (scheduleid,usrgrpid,schedule_date,action,userid_old,userid_new,' .
            '  reserva_old,reserva_new,changed_by,changed_at)' .
            ' VALUES (' .
                $scheduleid . ',' . $usrgrpid . ',' . zbx_dbstr($date) . ',' .
                zbx_dbstr($action) . ',' .
                ($userid_old  !== null ? $userid_old  : 'NULL') . ',' .
                ($userid_new  !== null ? $userid_new  : 'NULL') . ',' .
                ($reserva_old !== null ? $reserva_old : 'NULL') . ',' .
                ($reserva_new !== null ? $reserva_new : 'NULL') . ',' .
                $changed_by . ',' . time() .
            ')'
        );
    }

    private function err(CUrl $redirect, string $msg): void {
        $this->setResponse(new CControllerResponseRedirect(
            (clone $redirect)->setArgument('error', $msg)
        ));
    }

    private function inGroup(int $uid, int $gid): bool {
        return (bool) DBfetch(DBselect(
            'SELECT usrgrpid FROM users_groups WHERE userid=' . $uid . ' AND usrgrpid=' . $gid
        ));
    }
}
