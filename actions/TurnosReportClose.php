<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosReportClose — AJAX: fecha o turno, congelando o repasse.
 *
 * Grava um snapshot JSON do relatório em module_plantonistas_shift_reports.
 * O documento é imutável: fechar de novo cria outra linha, nunca sobrescreve.
 *
 * Por que existe (issue #2): abrir o repasse de uma data antiga pode mostrar
 * números diferentes dos do dia, porque o housekeeper do Zabbix já apagou os
 * eventos. O fechamento é o repasse formal do turno, com os números do momento
 * em que ele acabou.
 *
 * Quem pode: qualquer usuário autenticado fecha um turno ainda não fechado —
 * é o repasse dele. **Fechar de novo um turno já fechado exige Admin+**, e
 * fica registrado por construção, porque o fechamento anterior continua lá.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class TurnosReportClose extends CController {

    use TurnosReportBase;

    protected function checkInput(): bool {
        return $this->validateInput([
            'date'  => 'string',
            'shift' => 'string',
            'limit' => 'string',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    protected function doAction(): void {
        header('Content-Type: application/json; charset=utf-8');

        $date     = $this->validateDate($this->getInput('date', date('Y-m-d')));
        $shiftRaw = $this->getInput('shift', '24h');
        $limitStr = $this->getInput('limit', '5');
        $limit    = $limitStr === 'all' ? 0 : (int) $limitStr;

        $db = $this->getDb();
        if (!$db) {
            echo json_encode(['success' => false, 'message' => 'Erro ao conectar ao banco de dados.']);
            die();
        }

        $userid = (int) (CWebUser::$data['userid'] ?? 0);

        try {
            $ctx          = $this->resolveUserContext($db, $userid);
            $hostFilter   = $ctx['host_filter'];
            $isSuperadmin = $ctx['is_superadmin'];
            $roleType     = $ctx['role_type'];

            $shiftOptions = $this->queryShiftOptions($db, $ctx)['options'];
            $shift        = $this->normalizeShift($shiftRaw, $shiftOptions);

            // Refechar exige Admin+ — ver o cabeçalho da classe.
            $jaFechados = $this->countClosedReports($db, $date, $shift);

            if ($jaFechados < 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Não foi possível verificar fechamentos anteriores deste'
                               . ' turno. Confira o log do PHP-FPM.',
                ]);
                $db->close();
                die();
            }

            if ($jaFechados > 0 && $this->getUserType() < USER_TYPE_ZABBIX_ADMIN) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Este turno já foi fechado. Refazer o fechamento é'
                               . ' restrito a Admin/Super Admin.',
                ]);
                $db->close();
                die();
            }

            [$ts_start, $ts_end] = $this->getShiftBounds($db, $date, $shift);

            $mtta = $this->restrictMttaByRole(
                $this->queryMTTA($db, $ts_start, $ts_end, $hostFilter),
                $roleType,
                $userid
            );

            // Mesmo conjunto que o PDF renderiza — é ele que exibe o documento
            // fechado depois, sem consultar o banco de novo.
            $snapshot = [
                'v'           => $this->snapshotVersion(),
                'date'        => $date,
                'shift'       => $shift,
                'shift_label' => $shiftOptions[$shift] ?? $shift,
                'limit'       => $limitStr,
                'noc_label'   => !empty($ctx['display_groups'])
                                    ? implode(' / ', $ctx['display_groups'])
                                    : null,
                'role_type'   => $roleType,
                // Contexto do autor: é o que permite decidir, na leitura, se
                // o documento pode ser entregue a outra pessoa sem furar a
                // segmentação da tela (ver canReadSnapshot()).
                'author_userid'        => $userid,
                'author_is_superadmin' => $isSuperadmin,
                'author_group_ids'     => array_map('intval', $ctx['group_ids'] ?? []),
                'closed_by'   => $this->formatUserLabel(
                                    CWebUser::$data['name']    ?? '',
                                    CWebUser::$data['surname'] ?? '',
                                    CWebUser::$data['username'] ?? ''
                                 ),
                'data'        => [
                    'mtta'         => $mtta,
                    'global_mtta'  => $this->calcGlobalMTTA($mtta),
                    'inherited'    => $this->queryInheritedAlerts($db, $ts_start, $hostFilter),
                    'unacked'      => $this->queryUnackedAlerts($db, $ts_start, $ts_end, $hostFilter),
                    'top_hosts'    => $this->queryTopHosts($db, $ts_start, $ts_end, $limit, $hostFilter),
                    'top_triggers' => $this->queryTopTriggers($db, $ts_start, $ts_end, $limit, $hostFilter),
                    'totals'       => $this->queryEventTotals($db, $ts_start, $ts_end, $hostFilter),
                    'notes'        => $this->queryNotes($db, $date, $shift, $userid, $isSuperadmin),
                ],
            ];

            $nocContext = !empty($ctx['display_groups']) ? $ctx['display_groups'][0] : null;
            $id = $this->saveClosedReport($db, $date, $shift, $snapshot, $userid, $nocContext);

            $db->close();

            if ($id === null) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Falha ao gravar o fechamento. Confira o log do PHP-FPM.',
                ]);
                die();
            }

            echo json_encode([
                'success'   => true,
                'id'        => $id,
                'refeito'   => $jaFechados > 0,
                'message'   => $jaFechados > 0
                                ? 'Turno fechado de novo. O fechamento anterior foi mantido.'
                                : 'Turno fechado. O repasse deste turno está congelado.',
            ]);
        } catch (\Throwable $e) {
            error_log('[plantonistas] report.close falhou: ' . $e->getMessage());
            // Mensagem fixa: o RuntimeException do ZbxDb carrega o SQL
            // inteiro (com o JSON do snapshot dentro), e SQL não vai para a UI.
            echo json_encode([
                'success' => false,
                'message' => 'Erro ao fechar o turno. Confira o log do PHP-FPM.',
            ]);
        }

        die();
    }
}
