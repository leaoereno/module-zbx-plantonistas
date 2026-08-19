<?php

namespace Modules\Plantonistas\Actions;

use CController,
    CWebUser;

/**
 * TurnosReportPdf — Gera versão standalone para impressão/PDF.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 */
class TurnosReportPdf extends CController {

    use TurnosReportBase;

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([
            'date'  => 'string',
            'shift' => 'string',
            'limit' => 'string',
            // Com report_id, renderiza o snapshot de um turno FECHADO em vez
            // de consultar o banco — é assim que o documento da issue #2 é
            // exibido, sem precisar de tela nova.
            'report_id' => 'int32',
        ]);
    }

    protected function checkPermissions(): bool {
        return !CWebUser::isGuest();
    }

    // ── Helpers de formatação ────────────────────────────────

    private function sevLabel(int $s): string {
        // Nomes de severidade alinhados à regra de negócio (Administração > Geral
        // > Opções de exibição de acionadores). TODO: buscar dinamicamente de
        // config.severity_name_0..5 em vez de fixo aqui.
        return [0=>'Not classified',1=>'Information',2=>'Warning',3=>'Minor',4=>'Major',5=>'Critical'][$s] ?? 'N/A';
    }

    private function sevClass(int $s): string {
        return [0=>'notclass',1=>'info',2=>'warn',3=>'avg',4=>'high',5=>'disaster'][$s] ?? 'info';
    }

    private function fmtDuration(int $s): string {
        if ($s < 60)   return $s . 's';
        if ($s < 3600) return floor($s / 60) . 'm ' . ($s % 60) . 's';
        return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm';
    }

    private function shiftLabel(string $sh, array $shiftOptions = []): string {
        if (array_key_exists($sh, $shiftOptions)) {
            return $shiftOptions[$sh];
        }
        return [
            'manha' => 'Manhã (07h–13h)',
            'tarde' => 'Tarde (13h–19h)',
            'noite' => 'Noite (19h–07h)',
            '24h'   => '24 Horas',
        ][$sh] ?? $sh;
    }

    // ── doAction ─────────────────────────────────────────────

    protected function doAction(): void {
        $date     = $this->validateDate($this->getInput('date', date('Y-m-d')));
        $shiftRaw = $this->getInput('shift', '24h');
        $limitStr = $this->getInput('limit', '5');
        $limit    = $limitStr === 'all' ? 0 : (int)$limitStr;

        $db = $this->getDb();
        if (!$db) {
            echo '<h1>Erro de conexão com o banco de dados.</h1>';
            die();
        }

        $userid       = (int)(CWebUser::$data['userid'] ?? 0);
        $nocCtx       = $this->resolveUserContext($db, $userid);
        $isSuperadmin = $nocCtx['is_superadmin'];
        $reportId     = (int) $this->getInput('report_id', 0);
        $closedMeta   = null;

        if ($reportId > 0) {
            // ── Documento de turno FECHADO ───────────────────────────────
            //
            // Nada é consultado: tudo vem do snapshot gravado no fechamento.
            // É esse o ponto da issue #2 — o repasse de uma data antiga passa
            // a mostrar os números do dia em que o turno acabou, e não o que
            // sobrou depois de o housekeeper apagar os eventos.
            //
            // A visibilidade é conferida dentro do loadClosedReport(), sobre o
            // contexto gravado no snapshot: Super Admin vê todos; fechamento
            // feito por Super Admin só ele lê; nos demais casos os grupos do
            // autor têm que caber nos do leitor. O documento congela a visão
            // de UMA pessoa — entregá-lo a quem enxerga menos furaria a
            // segmentação da tela.
            $closed = $this->loadClosedReport($db, $reportId, $userid, $isSuperadmin, $nocCtx);
            $db->close();

            if ($closed === null) {
                echo '<h1>Fechamento não encontrado.</h1>';
                echo '<p>O documento não existe ou não é visível para o seu usuário.</p>';
                die();
            }

            $closedMeta = $closed['meta'];
            $snap       = $closed['snapshot'];
            $d          = $snap['data'] ?? [];

            $date         = $snap['date']  ?? $date;
            $shift        = $snap['shift'] ?? $shiftRaw;
            $shiftOptions = [$shift => $snap['shift_label'] ?? $shift];
            $nocLabel     = $snap['noc_label'] ?? null;
            // Papel do LEITOR: decide os rótulos ("Seu MTTA" x "MTTA Global")
            // e tem que casar com a restrição aplicada logo abaixo.
            $roleType     = (int) $nocCtx['role_type'];

            // MTTA é restrito por PAPEL, e o papel gravado no snapshot é o de
            // quem fechou. Reaplicar com o papel do LEITOR — senão um User
            // enxergaria, pelo documento, o MTTA de todos os analistas, que
            // ele nunca veria na tela ao vivo. Nenhum filtro de grupo na
            // consulta cobre isso: a regra não é de grupo, é de papel.
            $mtta         = $this->restrictMttaByRole(
                                $d['mtta'] ?? [], (int) $nocCtx['role_type'], $userid
                            );
            $inherited    = $d['inherited']    ?? [];
            $unacked      = $d['unacked']      ?? [];
            $top_hosts    = $d['top_hosts']    ?? [];
            $top_triggers = $d['top_triggers'] ?? [];
            $totals       = $d['totals']       ?? ['total'=>0,'critical'=>0,'average'=>0,'low'=>0];
            $notes        = $d['notes']        ?? [];
            // Recalculado sobre o MTTA já restrito: usar o global_mtta do
            // snapshot devolveria um agregado de todos os analistas no KPI.
            $gmtta        = $this->calcGlobalMTTA($mtta);
            // O rodapé mostra quem FECHOU, não quem está imprimindo: o
            // documento é dele.
            $user_fn      = $snap['closed_by'] ?? ($closedMeta['closed_by_label'] ?? '');
        }
        else {
            $hostFilter   = $nocCtx['host_filter'];
            $roleType     = $nocCtx['role_type'];
            $nocLabel     = !empty($nocCtx['display_groups'])
                            ? implode(' / ', $nocCtx['display_groups'])
                            : null;

            $shiftOptions = $this->queryShiftOptions($db, $nocCtx)['options'];
            $shift        = $this->normalizeShift($shiftRaw, $shiftOptions);

            [$ts_start, $ts_end] = $this->getShiftBounds($db, $date, $shift);

            $mtta         = $this->queryMTTA($db, $ts_start, $ts_end, $hostFilter);
            $mtta         = $this->restrictMttaByRole($mtta, $roleType, $userid);
            $inherited    = $this->queryInheritedAlerts($db, $ts_start, $hostFilter);
            $unacked      = $this->queryUnackedAlerts($db, $ts_start, $ts_end, $hostFilter);
            $top_hosts    = $this->queryTopHosts($db, $ts_start, $ts_end, $limit, $hostFilter);
            $top_triggers = $this->queryTopTriggers($db, $ts_start, $ts_end, $limit, $hostFilter);
            $totals       = $this->queryEventTotals($db, $ts_start, $ts_end, $hostFilter);
            $notes        = $this->queryNotes($db, $date, $shift, $userid, $isSuperadmin);
            $db->close();

            $gmtta   = $this->calcGlobalMTTA($mtta);
            $user_fn = $this->formatUserLabel(
                CWebUser::$data['name']    ?? '',
                CWebUser::$data['surname'] ?? '',
                CWebUser::$data['username'] ?? 'Admin'
            );
        }

        $isUserRole = ($roleType < 2);
        $mttaKpiLabel  = $isUserRole ? 'Seu MTTA' : 'MTTA Global';
        $mttaCardTitle = $isUserRole ? 'Seu MTTA' : 'MTTA por Analista';

        // Título completo para o PDF inclui o contexto NOC.
        // Data com hífen (17-08-2026), não barra: o <title> é o que o navegador
        // sugere como NOME DO ARQUIVO ao salvar/imprimir em PDF, e barra não é
        // caractere válido em nome de arquivo — viria substituída por _ ou -
        // dependendo do sistema. Continua dia-mês-ano, padrão brasileiro.
        $pdfTitle = ($closedMeta !== null ? 'Repasse Fechado' : 'Repasse de Plantão')
            . ($nocLabel ? ' — ' . $nocLabel : '')
            . ' — ' . $this->shiftLabel($shift, $shiftOptions)
            . ' — ' . str_replace('/', '-', $this->formatDateBr($date));

        // ── HTML ─────────────────────────────────────────────
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
        echo '<title>' . htmlspecialchars($pdfTitle) . '</title>';
        echo '<style>' . file_get_contents(__DIR__ . '/../assets/css/turnos.report.css') . '
            body{background:#fff!important;font-size:11px}
            .rp-native-container{max-width:100%;padding:20px 30px}
            .rp-native-header{background:#2b3c51!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-nh-btn,.rp-note-form{display:none!important}
            .sev-disaster,.sev-high,.sev-avg,.sev-warn,.sev-info,.sev-notclass,
            .bg-blue,.bg-red,.bg-orange,.bg-yellow,.bg-purple,.bg-green,
            .row-disaster,.row-high,.row-avg,.row-warn,.perf-good,.perf-ok,.perf-bad,
            .rp-badge-hist,.rp-kpi-icon{-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-card{break-inside:avoid;box-shadow:none;border:1px solid #ccc}
            .rp-noc-badge{display:inline-block;padding:2px 10px;border-radius:12px;
                background:#3b5998;color:#fff;font-size:10px;font-weight:600;margin-left:10px}
            @page{size:A4 landscape;margin:10mm}
        </style>';
        echo '<link rel="stylesheet" href="modules/module-zbx-plantonistas/assets/fontawesome/css/all.min.css"/>';
        echo '</head><body>';
        echo '<div class="rp-native-container">';

        // Header
        echo '<div class="rp-native-header"><div class="rp-nh-left">';
        echo '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';
        echo '<span class="rp-nh-title">Repasse de Plantão</span>';
        if ($nocLabel) {
            echo '<span class="rp-noc-badge"><i class="fas fa-shield-alt"></i> ' . htmlspecialchars($nocLabel) . '</span>';
        }
        echo '<span class="rp-nh-sub">' . $this->shiftLabel($shift, $shiftOptions) . ' — ' . htmlspecialchars($this->formatDateBr($date)) . '</span>';
        echo '</div></div>';

        // Faixa de documento fechado: deixa explícito que estes números são um
        // instantâneo, e de quem — o snapshot congela a visão de uma pessoa,
        // que pode não ser a de quem está lendo.
        if ($closedMeta !== null) {
            echo '<div class="rp-card" style="border-left:4px solid #2e7d32;margin-bottom:14px">';
            echo '<div style="padding:10px 14px">';
            echo '<strong><i class="fas fa-lock"></i> Turno fechado</strong> — repasse congelado em '
               . htmlspecialchars((string) $closedMeta['generated_at'])
               . ' por ' . htmlspecialchars((string) $closedMeta['closed_by_label'])
               . ' (fechamento #' . (int) $closedMeta['id'] . ').';
            echo '<div class="rp-muted" style="margin-top:4px">Os números abaixo são os do'
               . ' momento do fechamento e não mudam mais, mesmo depois de o housekeeper do'
               . ' Zabbix apagar os eventos do período.</div>';
            echo '</div></div>';
        }

        // KPIs
        echo '<div class="rp-kpi-grid">';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-blue"><i class="fas fa-bell"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . (int)$totals['total'] . '</span><span class="rp-kpi-label">Total Eventos</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-red"><i class="fas fa-fire"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . (int)$totals['critical'] . '</span><span class="rp-kpi-label">Críticos</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-orange"><i class="fas fa-clock"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . $this->fmtDuration($gmtta) . '</span><span class="rp-kpi-label">' . $mttaKpiLabel . '</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-yellow"><i class="fas fa-exclamation-circle"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . count($unacked) . '</span><span class="rp-kpi-label">Sem ACK</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-purple"><i class="fas fa-history"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">' . count($inherited) . '</span><span class="rp-kpi-label">Herdados</span></div></div>';
        echo '</div>';

        // MTTA por analista
        if ($mtta) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-stopwatch"></i> ' . $mttaCardTitle . '</div>';
            echo '<table class="rp-table"><thead><tr><th>Analista</th><th>ACKs</th><th>MTTA Médio</th><th>Mín</th><th>Máx</th></tr></thead><tbody>';
            foreach ($mtta as $m) {
                echo '<tr><td class="td-bold">' . htmlspecialchars($m['fullname']) . '</td>';
                echo '<td class="td-center">' . (int)$m['total_acks'] . '</td>';
                echo '<td class="td-mono">' . $this->fmtDuration((int)$m['avg_mtta']) . '</td>';
                echo '<td class="td-mono">' . $this->fmtDuration((int)$m['min_mtta']) . '</td>';
                echo '<td class="td-mono">' . $this->fmtDuration((int)$m['max_mtta']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Alertas herdados
        if ($inherited) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-history"></i> Alertas Herdados</div>';
            echo '<table class="rp-table"><thead><tr><th>Início</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Idade</th></tr></thead><tbody>';
            foreach ($inherited as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr class="row-' . $sc . '">';
                echo '<td class="td-mono">' . date('d/m H:i', (int)$r['clock']) . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . $this->sevLabel((int)$r['severity']) . '</span></td>';
                echo '<td>' . htmlspecialchars($r['host']) . '</td>';
                echo '<td>' . htmlspecialchars($r['trigger_desc']) . '</td>';
                echo '<td class="td-bold">' . $this->fmtDuration((int)$r['age_seconds']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Alertas sem ACK
        if ($unacked) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-exclamation-triangle"></i> Alertas Sem ACK</div>';
            echo '<table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th></tr></thead><tbody>';
            foreach ($unacked as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr class="row-' . $sc . '">';
                echo '<td class="td-mono">' . date('H:i:s', (int)$r['clock']) . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . $this->sevLabel((int)$r['severity']) . '</span></td>';
                echo '<td>' . htmlspecialchars($r['host']) . '</td>';
                echo '<td>' . htmlspecialchars($r['trigger_desc']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Top Hosts + Top Triggers
        echo '<div class="rp-noise-row">';
        if ($top_hosts) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-server"></i> Top Hosts</div>';
            echo '<table class="rp-table"><thead><tr><th>#</th><th>Host</th><th>Eventos</th><th>Sev.</th></tr></thead><tbody>';
            $i = 1;
            foreach ($top_hosts as $r) {
                $sc = $this->sevClass((int)$r['max_severity']);
                echo '<tr><td class="td-center">' . $i++ . '</td>';
                echo '<td>' . htmlspecialchars($r['host']) . '</td>';
                echo '<td class="td-center td-bold">' . (int)$r['event_count'] . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . $this->sevLabel((int)$r['max_severity']) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($top_triggers) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-bolt"></i> Top Triggers</div>';
            echo '<table class="rp-table"><thead><tr><th>#</th><th>Trigger</th><th>Eventos</th><th>Sev.</th></tr></thead><tbody>';
            $i = 1;
            foreach ($top_triggers as $r) {
                $sc = $this->sevClass((int)$r['severity']);
                echo '<tr><td class="td-center">' . $i++ . '</td>';
                echo '<td>' . htmlspecialchars($r['description']) . '</td>';
                echo '<td class="td-center td-bold">' . (int)$r['event_count'] . '</td>';
                echo '<td><span class="rp-sev sev-' . $sc . '">' . $this->sevLabel((int)$r['severity']) . '</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Diário de Bordo
        if ($notes) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-book-open"></i> Diário de Bordo</div><div class="rp-card-body">';
            foreach ($notes as $n) {
                echo '<div class="rp-note-item">';
                echo '<div class="rp-note-header"><strong>' . htmlspecialchars($n['analyst_name']) . '</strong> ';
                echo '<span class="rp-note-time">' . htmlspecialchars($n['created_at']) . '</span></div>';
                $noteHtml = (($n['notes_format'] ?? 'text') === 'html') ? $n['notes'] : nl2br(htmlspecialchars($n['notes']));
                echo '<div class="rp-note-content">' . $noteHtml . '</div>';
                echo '</div>';
            }
            echo '</div></div>';
        }

        // Rodapé
        echo '<div class="rp-native-footer">';
        echo '<span>Relatório gerado em ' . date('d/m/Y H:i:s') . ' por ' . htmlspecialchars($user_fn) . '</span>';
        echo '<span>Módulo Repasse v2.5.0</span>';
        echo '</div>';

        echo '</div></body></html>';
        echo '<script>window.onload = function() { window.print(); };</script>';
        die();
    }
}
