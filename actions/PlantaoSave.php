<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

use CController, CControllerResponseRedirect, CUrl, CWebUser;

/**
 * PlantaoSave — grava a escala de um ou mais dias.
 *
 * Dois modos, decididos pela presença de turnos cadastrados (module_plantonistas_shifts)
 * para o grupo em "Gerenciar Turnos":
 *
 *   - Legado (grupo sem turnos): campos userid/userid_reserva — 1 titular
 *     (+reserva opcional) por grupo/dia, shift_id=0. Comportamento idêntico
 *     ao de antes da v4.1.
 *   - Turnos (grupo com turnos ativos): campo shift_assignments, um JSON
 *     {shift_id: userid} montado no navegador a partir dos seletores da
 *     view — 1 titular por grupo/dia/turno, sem reserva. Turno deixado em
 *     branco no formulário não é alterado (permite editar só 1 turno por vez).
 */
class PlantaoSave extends CController {

    public function init(): void { $this->disableCsrfValidation(); }

    protected function checkInput(): bool {
        return $this->validateInput([
            'schedule_dates'    => 'required|string',
            'userid'            => 'string',
            'userid_reserva'    => 'string',
            'shift_assignments' => 'string',
            'usrgrpid'          => 'required|int32',
            'month'             => 'int32',
            'year'              => 'int32',
        ]);
    }

    public function checkPermissions(): bool {
        return ($this->getUserType() >= USER_TYPE_ZABBIX_USER);
    }

    protected function doAction(): void {
        $current_userid = (int) CWebUser::$data['userid'];
        $is_super       = ($this->getUserType() >= USER_TYPE_SUPER_ADMIN);

        $usrgrpid = (int) $this->getInput('usrgrpid');
        $month    = (int) $this->getInput('month', date('n'));
        $year     = (int) $this->getInput('year',  date('Y'));

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

        // ── Monta as atribuições a salvar: [shift_id => dados] ──────────────
        $assignments = [];

        $raw_assignments = trim($this->getInput('shift_assignments', ''));
        if ($raw_assignments !== '') {
            // Modo turnos: um técnico por turno cadastrado, sem reserva.
            $decoded = json_decode($raw_assignments, true);
            if (!is_array($decoded)) {
                $this->err($redirect, 'Dados de turno inválidos.');
                return;
            }
            foreach ($decoded as $shift_id_raw => $uid_raw) {
                $shift_id = (int) $shift_id_raw;
                $uid      = (int) $uid_raw;
                if ($shift_id <= 0 || $uid <= 0) {
                    continue; // turno deixado em branco — não altera o que já existe
                }
                $shift = $this->getShiftForGroup($shift_id, $usrgrpid);
                if ($shift === null) {
                    $this->err($redirect, 'Turno inválido para este grupo.');
                    return;
                }
                if (!$is_super && !$this->inGroup($uid, $usrgrpid)) {
                    $this->err($redirect, 'Um dos técnicos selecionados não pertence a este grupo.');
                    return;
                }
                $assignments[$shift_id] = [
                    'userid'         => $uid,
                    'userid_reserva' => null,
                    'shift_name'     => $shift['name'],
                ];
            }
        } else {
            // Modo legado: técnico + reserva únicos, shift_id=0.
            $userid = (int) $this->getInput('userid', '0');
            if ($userid > 0) {
                $userid_reserva = (int) $this->getInput('userid_reserva', '0');

                if (!$is_super && !$this->inGroup($userid, $usrgrpid)) {
                    $this->err($redirect, 'O técnico selecionado não pertence a este grupo.');
                    return;
                }
                if ($userid_reserva > 0 && !$is_super && !$this->inGroup($userid_reserva, $usrgrpid)) {
                    $this->err($redirect, 'O técnico reserva não pertence a este grupo.');
                    return;
                }
                if ($userid_reserva > 0 && $userid === $userid_reserva) {
                    $this->err($redirect, 'O técnico principal e o reserva não podem ser a mesma pessoa.');
                    return;
                }

                $assignments[0] = [
                    'userid'         => $userid,
                    'userid_reserva' => $userid_reserva > 0 ? $userid_reserva : null,
                    'shift_name'     => '',
                ];
            }
        }

        if (empty($assignments)) {
            $this->err($redirect, 'Selecione ao menos um técnico.');
            return;
        }

        // ── Gravar cada dia × cada turno preenchido ─────────────────────────
        try {
            foreach ($valid_dates as $date) {
                foreach ($assignments as $shift_id => $a) {
                    $this->saveOne($usrgrpid, (int) $shift_id, $date, $a, $current_userid);
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

    /**
     * Cria ou atualiza a entrada de escala de um grupo/dia/turno (upsert por
     * usrgrpid+schedule_date+shift_id) e loga a alteração no histórico.
     */
    private function saveOne(int $usrgrpid, int $shift_id, string $date, array $a, int $current_userid): void {
        $userid         = $a['userid'];
        $userid_reserva = $a['userid_reserva'];
        $shift_name     = $a['shift_name'];

        $r_sql = $userid_reserva ? $userid_reserva : 'NULL';
        $r_set = $userid_reserva ? (', userid_reserva=' . $userid_reserva) : ', userid_reserva=NULL';

        $existing = DBfetch(DBselect(
            'SELECT scheduleid, userid, userid_reserva FROM module_plantonistas_schedule' .
            ' WHERE usrgrpid=' . $usrgrpid . ' AND schedule_date=' . zbx_dbstr($date) .
            ' AND shift_id=' . $shift_id
        ));

        if ($existing) {
            DBexecute(
                'UPDATE module_plantonistas_schedule SET userid=' . $userid . $r_set .
                ', created_by=' . $current_userid . ', created_at=' . time() .
                ' WHERE scheduleid=' . (int)$existing['scheduleid']
            );
            $this->logHistory(
                (int)$existing['scheduleid'], $usrgrpid, $shift_id, $shift_name, $date, 'update',
                (int)$existing['userid'], $userid,
                $existing['userid_reserva'] ? (int)$existing['userid_reserva'] : null,
                $userid_reserva,
                $current_userid
            );
        } else {
            DBexecute(
                'INSERT INTO module_plantonistas_schedule' .
                ' (usrgrpid,shift_id,userid,userid_reserva,schedule_date,created_by,created_at)' .
                ' VALUES (' . $usrgrpid . ',' . $shift_id . ',' . $userid . ',' . $r_sql . ',' .
                zbx_dbstr($date) . ',' . $current_userid . ',' . time() . ')'
            );
            $new_id = DBfetch(DBselect(
                'SELECT scheduleid FROM module_plantonistas_schedule' .
                ' WHERE usrgrpid=' . $usrgrpid . ' AND schedule_date=' . zbx_dbstr($date) .
                ' AND shift_id=' . $shift_id
            ));
            $this->logHistory(
                $new_id ? (int)$new_id['scheduleid'] : 0, $usrgrpid, $shift_id, $shift_name, $date, 'create',
                null, $userid, null, $userid_reserva,
                $current_userid
            );
        }
    }

    /**
     * Confirma que o turno existe, está ativo e pertence ao grupo informado
     * — evita que um shift_id de outro grupo seja gravado via manipulação
     * do POST. Retorna a linha (com o nome, usado como snapshot no histórico).
     */
    private function getShiftForGroup(int $shiftId, int $usrgrpid): ?array {
        $row = DBfetch(DBselect(
            'SELECT id, name FROM module_plantonistas_shifts' .
            ' WHERE id=' . $shiftId . ' AND usrgrpid=' . $usrgrpid . ' AND active=1'
        ));
        return $row ?: null;
    }

    private function logHistory(
        int $scheduleid, int $usrgrpid, int $shift_id, string $shift_name, string $date, string $action,
        ?int $userid_old, ?int $userid_new,
        ?int $reserva_old, ?int $reserva_new,
        int $changed_by
    ): void {
        DBexecute(
            'INSERT INTO module_plantonistas_history' .
            ' (scheduleid,usrgrpid,shift_id,shift_name,schedule_date,action,userid_old,userid_new,' .
            '  reserva_old,reserva_new,changed_by,changed_at)' .
            ' VALUES (' .
                $scheduleid . ',' . $usrgrpid . ',' . $shift_id . ',' . zbx_dbstr($shift_name) . ',' .
                zbx_dbstr($date) . ',' .
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
