<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CControllerResponseData,
    CWebUser;

/**
 * TurnosReportView — Controller principal do relatório de plantão.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 */
class TurnosReportView extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        // 'shift' aceita os códigos legados (24h/manha/tarde/noite) OU o ID
        // numérico de um turno cadastrado em module_plantonistas_shifts (v2.5+) — a
        // validação fina (turno existe e pertence a um grupo do usuário)
        // acontece em doAction() via normalizeShift().
        $ret = $this->validateInput([
            'date'  => 'string',
            'shift' => 'string',
            'limit' => 'string',
        ]);
        if (!$ret) {
            $this->setResponse(new CControllerResponseData(['error' => 'Parâmetros inválidos.']));
        }
        return $ret;
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    protected function doAction(): void {
        $date     = $this->validateDate($this->getInput('date', date('Y-m-d')));
        $shiftRaw = $this->getInput('shift', '24h');
        $limitStr = $this->getInput('limit', '5');
        $limit    = $limitStr === 'all' ? 0 : (int)$limitStr;

        $current_user     = CWebUser::$data['username']  ?? 'admin';
        $current_userid   = (int)(CWebUser::$data['userid'] ?? 0);
        $current_fullname = trim(
            (CWebUser::$data['name'] ?? '') . ' ' . (CWebUser::$data['surname'] ?? '')
        ) ?: $current_user;

        $db       = $this->getDb();
        $db_error = null;

        $legacyOptions = [
            '24h'   => '24 Horas (Dia Inteiro)',
            'manha' => 'Manhã (07h–13h)',
            'tarde' => 'Tarde (13h–19h)',
            'noite' => 'Noite (19h–07h)',
        ];

        if (!$db) {
            $db_error     = 'Erro ao conectar ao banco de dados.';
            $ctx          = ['is_superadmin' => false, 'host_filter' => '', 'display_groups' => [], 'group_ids' => [], 'role_type' => 1, 'userid' => $current_userid];
            $shift        = array_key_exists($shiftRaw, $legacyOptions) ? $shiftRaw : '24h';
            $shiftOptions = $legacyOptions;
            [$ts_start, $ts_end] = [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
            $data_pack = [
                'mtta' => [], 'inherited' => [], 'unacked' => [], 'top_hosts' => [],
                'top_triggers' => [], 'totals' => ['total'=>0,'critical'=>0,'average'=>0,'low'=>0],
                'presence' => [], 'notes' => [], 'mtta_timeline' => [],
                'sev_dist' => [], 'calendar' => [], 'shift_analysts' => [], 'limit' => $limitStr,
            ];
        } else {
            $ctx          = $this->resolveUserContext($db, $current_userid);
            $hostFilter   = $ctx['host_filter'];
            $isSuperadmin = $ctx['is_superadmin'];
            $roleType     = $ctx['role_type'];

            $shiftOptionsFull = $this->queryShiftOptions($db, $ctx);
            $shiftOptions     = $shiftOptionsFull['options'];
            $shift            = $this->normalizeShift($shiftRaw, $shiftOptions);

            [$ts_start, $ts_end] = $this->getShiftBounds($db, $date, $shift);

            $mtta = $this->queryMTTA($db, $ts_start, $ts_end, $hostFilter);
            $mtta = $this->restrictMttaByRole($mtta, $roleType, $current_userid);

            $shiftAnalysts = ctype_digit($shift) ? $this->queryShiftAnalysts($db, (int)$shift) : [];

            $data_pack = [
                'mtta'           => $mtta,
                'inherited'      => $this->queryInheritedAlerts($db, $ts_start, $hostFilter),
                'unacked'        => $this->queryUnackedAlerts($db, $ts_start, $ts_end, $hostFilter),
                'top_hosts'      => $this->queryTopHosts($db, $ts_start, $ts_end, $limit, $hostFilter),
                'top_triggers'   => $this->queryTopTriggers($db, $ts_start, $ts_end, $limit, $hostFilter),
                'totals'         => $this->queryEventTotals($db, $ts_start, $ts_end, $hostFilter),
                'presence'       => $this->queryPresence($db, $ts_start, $ts_end, $current_userid, $isSuperadmin),
                'notes'          => $this->queryNotes($db, $date, $shift, $current_userid, $isSuperadmin),
                'mtta_timeline'  => $this->queryMttaTimeline($db, $ts_start, $ts_end, $hostFilter),
                'sev_dist'       => $this->querySeverityDistribution($db, $ts_start, $ts_end, $hostFilter),
                'calendar'       => $this->queryCalendarHeatmap($db, $hostFilter),
                'shift_analysts' => $shiftAnalysts,
                'limit'          => $limitStr,
            ];
            $db->close();
        }

        // Label de exibição: nomes dos grupos de usuário Zabbix
        $noc_label = !empty($ctx['display_groups'])
            ? implode(' / ', $ctx['display_groups'])
            : null;
        $roleType = $ctx['role_type'] ?? 1;

        $data = $data_pack + [
            'date'              => $date,
            'shift'             => $shift,
            'shift_options'     => $shiftOptions,
            'shift_label'       => $shiftOptions[$shift] ?? $shift,
            'ts_start'          => $ts_start,
            'ts_end'            => $ts_end,
            'db_error'          => $db_error,
            'global_mtta'       => $this->calcGlobalMTTA($data_pack['mtta']),
            'is_history'        => ($date !== date('Y-m-d')),
            'current_user'      => $current_user,
            'current_userid'    => $current_userid,
            'current_fullname'  => $current_fullname,
            'noc_label'         => $noc_label,
            'is_superadmin'     => $ctx['is_superadmin'],
            'role_type'         => $roleType,
            'can_manage_shifts' => $roleType >= 2,
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Repasse de Plantão'));
        $this->setResponse($response);
    }
}
