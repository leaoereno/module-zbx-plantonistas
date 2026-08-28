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
        // formatUserLabel() em vez de concatenar name+surname: cadastro com o
        // nome completo no campo surname exibia "Rafael Rafael Leao Ereno" no
        // "Analista:" do Diário de Bordo e no rodapé do relatório.
        $current_fullname = $this->formatUserLabel(
            CWebUser::$data['name']    ?? '',
            CWebUser::$data['surname'] ?? '',
            $current_user
        );

        $db         = $this->getDb();
        $db_error   = null;
        // Default de fábrica do Zabbix — sobrescrito abaixo se a conexão
        // abrir. Nomes/cores REAIS de severidade (ver querySeverities()).
        $severities = [
            0 => ['name' => 'Not classified', 'color' => '97AAB3'],
            1 => ['name' => 'Information',    'color' => '7499FF'],
            2 => ['name' => 'Warning',        'color' => 'FFC859'],
            3 => ['name' => 'Average',        'color' => 'FFA059'],
            4 => ['name' => 'High',           'color' => 'E97659'],
            5 => ['name' => 'Disaster',       'color' => 'E45959'],
        ];

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
                'pending_mentions' => [],
                'mention_history'  => [],
                'in_progress' => [], 'resolved' => [], 'actions' => [],
            ];
            $closed_report = null;
        } else {
            $ctx          = $this->resolveUserContext($db, $current_userid);
            $hostFilter   = $ctx['host_filter'];
            $isSuperadmin = $ctx['is_superadmin'];
            $roleType     = $ctx['role_type'];

            $shiftOptionsFull = $this->queryShiftOptions($db, $ctx);
            $shiftOptions     = $shiftOptionsFull['options'];
            $shift            = $this->normalizeShift($shiftRaw, $shiftOptions);

            [$ts_start, $ts_end] = $this->getShiftBounds($db, $date, $shift);

            $severities = $this->querySeverities($db);

            $mtta = $this->queryMTTA($db, $ts_start, $ts_end, $hostFilter);
            $mtta = $this->restrictMttaByRole($mtta, $roleType, $current_userid);

            $shiftAnalysts = ctype_digit($shift) ? $this->queryShiftAnalysts($db, (int)$shift) : [];

            $inherited   = $this->queryInheritedAlerts($db, $ts_start, $hostFilter);
            $unacked     = $this->queryUnackedAlerts($db, $ts_start, $ts_end, $hostFilter);
            $in_progress = $this->queryInProgressAlerts($db, $ts_start, $ts_end, $hostFilter);
            $resolved    = $this->queryResolvedAlerts($db, $ts_start, $ts_end, $hostFilter);
            // Coluna Ações das 4 tabelas acima: uma query só, pelos eventids
            // já trazidos por elas (ver queryEventActions()).
            $actions = $this->queryEventActions($db, array_merge(
                array_column($inherited, 'eventid'),
                array_column($unacked, 'eventid'),
                array_column($in_progress, 'eventid'),
                array_column($resolved, 'eventid')
            ));

            $data_pack = [
                'mtta'           => $mtta,
                'inherited'      => $inherited,
                'unacked'        => $unacked,
                'in_progress'    => $in_progress,
                'resolved'       => $resolved,
                'actions'        => $actions,
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
                // Menções [user] pendentes pro banner de notificação — busca
                // antes de fechar a conexão, some da tela quando o usuário
                // marca como lida (ver plantonistas.report.mentions.read).
                'pending_mentions' => $this->queryPendingMentions($db, $current_userid),
                // Histórico: pendentes E lidas. O banner é notificação e some
                // quando a pessoa marca como lida; sem o histórico, achar de
                // novo a nota de ontem não tinha por onde.
                'mention_history'  => $this->queryMentionHistory($db, $current_userid),
            ];
            // Fechamento do turno (issue #2): só o metadado, para a tela saber
            // que existe e por quem. O documento em si é renderizado pelo PDF,
            // a partir do snapshot — a tela continua consultando ao vivo.
            $closed_report = $this->findClosedReport($db, $date, $shift, $current_userid, $isSuperadmin, $ctx);
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
            'closed_report'     => $closed_report,
            // Nomes/cores reais de severidade (Administração > Geral) — ver
            // TurnosReportBase::querySeverities().
            'severities'        => $severities,
            // Token CSRF por action (em módulo o Zabbix confere contra a
            // action completa, não contra o prefixo — ver CLAUDE.md).
            'csrf_notes_save'    => \CCsrfTokenHelper::get('plantonistas.report.notes.save'),
            'csrf_mentions_read' => \CCsrfTokenHelper::get('plantonistas.report.mentions.read'),
            'csrf_report_close'  => \CCsrfTokenHelper::get('plantonistas.report.close'),
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Repasse de Plantão'));
        $this->setResponse($response);
    }
}
