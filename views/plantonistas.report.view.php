<?php
/**
 * View: plantonistas.report.view — Renderiza DENTRO do Zabbix frame
 * Recebe $data do TurnosReportView controller via CControllerResponseData
 */

// Helper functions inline
function rp_sevLabel(int $sev): string {
    // Nomes de severidade alinhados à regra de negócio (Administração > Geral >
    // Opções de exibição de acionadores). TODO: buscar dinamicamente de
    // config.severity_name_0..5 em vez de fixo aqui.
    return [0=>'Not classified',1=>'Information',2=>'Warning',3=>'Minor',4=>'Major',5=>'Critical'][$sev] ?? 'N/A';
}
function rp_sevClass(int $sev): string {
    return [0=>'notclass',1=>'info',2=>'warn',3=>'avg',4=>'high',5=>'disaster'][$sev] ?? 'info';
}
function rp_duration(int $s): string {
    if ($s < 60) return $s.'s';
    if ($s < 3600) return floor($s/60).'m '.($s%60).'s';
    return floor($s/3600).'h '.floor(($s%3600)/60).'m';
}
function rp_shiftLabel(string $sh): string {
    return ['manha'=>'Manhã (07h–13h)','tarde'=>'Tarde (13h–19h)','noite'=>'Noite (19h–07h)','24h'=>'24 Horas'][$sh] ?? $sh;
}
function rp_isCustomShift($sh): bool {
    return ctype_digit((string)$sh);
}
function rp_probLink(?string $h=null): string {
    $u = 'zabbix.php?action=problem.view&filter_set=1&filter_show=3';
    return $h ? $u.'&filter_name='.urlencode($h) : $u;
}

$date  = $data['date'];
$shift = $data['shift'];
$chart_mtta_labels = json_encode(array_column($data['mtta_timeline'], 'hora'));
$chart_mtta_data   = json_encode(array_map('intval', array_column($data['mtta_timeline'], 'avg_mtta')));
$sev_data = json_encode([
    $data['sev_dist'][0]??0, $data['sev_dist'][1]??0, $data['sev_dist'][2]??0,
    $data['sev_dist'][3]??0, $data['sev_dist'][4]??0, $data['sev_dist'][5]??0
]);

// Calendar heatmap data (30 days)
$calendar_json = json_encode($data['calendar']);
?>

<script src="modules/module-zbx-plantonistas/assets/js/chart.min.js"></script>
<link rel="stylesheet" href="modules/module-zbx-plantonistas/assets/fontawesome/css/all.min.css"/>

<div class="rp-native-container" id="rpContainer">
<script>
    let IS_DARK_THEME = false;
    (function(){
        // FIX: a detecção antiga procurava um <link> com "dark-theme"/"hc-dark"
        // no href — mas o Zabbix pode referenciar esses arquivos no HTML mesmo
        // com outro tema ativo (ex.: preload de temas alternativos), gerando
        // falso positivo. Em vez de adivinhar pelo nome do arquivo, lê a cor de
        // fundo REAL já renderizada pelo tema ativo do Zabbix e calcula se é
        // clara ou escura — funciona com qualquer tema (Blue, Dark, HC, ou tema
        // customizado da empresa), sem depender de convenção de nome.
        try {
            const bg = getComputedStyle(document.body).backgroundColor;
            const rgb = bg.match(/\d+/g);
            if (rgb && rgb.length >= 3) {
                const [r, g, b] = rgb.map(Number);
                const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
                IS_DARK_THEME = luminance < 0.5;
            }
        } catch (e) {}
        if (IS_DARK_THEME) {
            document.documentElement.setAttribute('data-theme', 'dark-theme');
            document.body.classList.add('theme-dark-blue');
        }
    })();
</script>

<!-- HEADER BAR -->
<div class="rp-native-header">
    <div class="rp-nh-left">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
            <rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>
        </svg>
        <span class="rp-nh-title">Repasse de Plantão</span>
        <span class="rp-nh-sub"><?= htmlspecialchars($data['shift_label'] ?? rp_shiftLabel($shift)) ?> — <?= $date ?></span>
        <?php if ($data['is_history']): ?>
            <span class="rp-badge-hist">HISTÓRICO</span>
        <?php endif; ?>
    </div>
    <div class="rp-nh-right">
        <form method="GET" action="zabbix.php" class="rp-nh-controls">
            <input type="hidden" name="action" value="plantonistas.report.view">
            <input type="date" name="date" class="rp-nh-input" value="<?= $date ?>" onchange="this.form.submit()">
            <select name="shift" class="rp-nh-input" onchange="this.form.submit()">
                <?php foreach (($data['shift_options'] ?? []) as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= ((string)$k===(string)$shift)?'selected':'' ?>><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="limit" class="rp-nh-input" onchange="this.form.submit()">
                <option value="5" <?= $data['limit']==='5'?'selected':'' ?>>Top 5</option>
                <option value="10" <?= $data['limit']==='10'?'selected':'' ?>>Top 10</option>
                <option value="all" <?= $data['limit']==='all'?'selected':'' ?>>Todos</option>
            </select>
        </form>
        <div class="rp-nh-time"><i class="far fa-clock"></i> Gerado às <?= date('H:i') ?></div>
        <?php if (!empty($data['can_manage_shifts'])): ?>
        <a href="zabbix.php?action=plantonistas.shifts.view" class="rp-nh-btn" title="Cadastrar turnos e vincular analistas">
            <i class="fas fa-user-clock"></i> Gerenciar Turnos
        </a>
        <?php endif; ?>
        <button type="button" onclick="location.reload()" class="rp-nh-btn" style="background:var(--z-blue); padding:6px 10px;" title="Recarregar Relatório">
            <i class="fas fa-sync-alt"></i>
        </button>
        <a href="zabbix.php?action=plantonistas.report.pdf&date=<?= $date ?>&shift=<?= $shift ?>&limit=<?= $data['limit'] ?>" target="_blank"
           class="rp-nh-btn" title="Gerar PDF (abre em nova aba)">
            <i class="fas fa-file-pdf"></i> Gerar PDF
        </a>
    </div>
</div>

<?php 
// Build base URL for Problem View filtering by time
$f = date('Y-m-d H:i:s', $data['ts_start']);
$t = date('Y-m-d H:i:s', $data['ts_end']);
$pview_base = "zabbix.php?action=problem.view&filter_set=1&filter_show=3&from=".urlencode($f)."&to=".urlencode($t);
?>

<?php if ($data['db_error']): ?>
<div class="rp-alert rp-alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= $data['db_error'] ?></div>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="rp-kpi-grid">
    <a href="<?= $pview_base ?>" target="_blank" class="rp-kpi rp-kpi-link" title="Total de eventos mapeados neste período. Clique para ver no Monitoramento.">
        <div class="rp-kpi-icon txt-blue"><i class="fas fa-info-circle"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= (int)$data['totals']['total'] ?></span><span class="rp-kpi-label">Total Eventos</span></div>
    </a>
    <a href="<?= $pview_base ?>&severities[]=4&severities[]=5" target="_blank" class="rp-kpi rp-kpi-link" title="Soma total de eventos Major e Critical.">
        <div class="rp-kpi-icon txt-red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= (int)$data['totals']['critical'] ?></span><span class="rp-kpi-label">Críticos</span></div>
    </a>
    <?php $isUserRole = ($data['role_type'] ?? 1) < 2; ?>
    <div class="rp-kpi" title="MTTA (Mean Time To Acknowledge): tempo médio entre a abertura do alerta e o primeiro reconhecimento (ACK)<?= $isUserRole ? ' pelo seu usuário' : ' por um analista' ?>. Meta: abaixo de 60 minutos.">
        <div class="rp-kpi-icon txt-orange"><i class="far fa-clock"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= rp_duration($data['global_mtta']) ?></span><span class="rp-kpi-label"><?= $isUserRole ? 'Seu MTTA' : 'MTTA Global' ?></span></div>
    </div>
    <a href="javascript:void(0)" onclick="document.getElementById('table-unacked').scrollIntoView({behavior:'smooth'})" class="rp-kpi rp-kpi-link" title="Eventos do plantão atual que ainda não foram reconhecidos.">
        <div class="rp-kpi-icon txt-yellow"><i class="fas fa-eye-slash"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= count($data['unacked']) ?></span><span class="rp-kpi-label">Sem ACK</span></div>
    </a>
    <a href="javascript:void(0)" onclick="document.getElementById('table-inherited').scrollIntoView({behavior:'smooth'})" class="rp-kpi rp-kpi-link" title="Alertas que não foram tratados no último plantão.">
        <div class="rp-kpi-icon txt-purple"><i class="fas fa-reply-all"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= count($data['inherited']) ?></span><span class="rp-kpi-label">Herdados</span></div>
    </a>
    <a href="javascript:void(0)" onclick="document.getElementById('table-presence').scrollIntoView({behavior:'smooth'})" class="rp-kpi rp-kpi-link" title="Analistas rastreados como ativos durante a janela de horário do plantão selecionado.">
        <div class="rp-kpi-icon txt-green"><i class="fas fa-users"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= count($data['presence']) ?></span><span class="rp-kpi-label">Analistas Online</span></div>
    </a>
</div>

<!-- CALENDAR HEATMAP -->
<div class="rp-card">
    <div class="rp-card-head"><i class="fas fa-calendar-alt"></i> Volume de Alertas — Últimos 30 Dias</div>
    <div class="rp-card-body">
        <div class="rp-heatmap" id="calendarHeatmap"></div>
        <div class="rp-heatmap-legend">
            <span>Menos</span>
            <span class="rp-hm-swatch" style="background:#ebedf0"></span>
            <span class="rp-hm-swatch" style="background:#9be9a8"></span>
            <span class="rp-hm-swatch" style="background:#40c463"></span>
            <span class="rp-hm-swatch" style="background:#30a14e"></span>
            <span class="rp-hm-swatch" style="background:#E45959"></span>
            <span>Mais</span>
            <span style="margin-left:20px;color:#888;font-size:11px"><i class="fas fa-mouse-pointer"></i> Clique no dia para ver o relatório</span>
        </div>
    </div>
</div>

<!-- CHARTS -->
<div class="rp-charts-row">
    <div class="rp-card rp-card-chart">
        <div class="rp-card-head"><i class="fas fa-chart-line"></i> MTTA por Hora</div>
        <div class="rp-card-body"><div class="rp-chart-wrap"><canvas id="chartMtta"></canvas></div></div>
    </div>
    <div class="rp-card rp-card-chart">
        <div class="rp-card-head" style="justify-content:space-between;">
            <div><i class="fas fa-chart-pie"></i> Distribuição por Severidade</div>
            <button class="rp-chart-btn" onclick="toggleSevChart()" title="Mudar formato do gráfico"><i class="fas fa-exchange-alt"></i></button>
        </div>
        <div class="rp-card-body"><div class="rp-chart-wrap"><canvas id="chartSev"></canvas></div></div>
    </div>
</div>

<!-- MTTA PER USER -->
<div class="rp-card">
    <div class="rp-card-head"><i class="fas fa-stopwatch"></i> <?= $isUserRole ? 'Seu MTTA' : 'MTTA por Analista' ?> <span class="rp-badge"><?= count($data['mtta']) ?> analista<?= count($data['mtta'])===1?'':'s' ?></span></div>
    <div class="rp-card-desc">
        <?php if ($isUserRole): ?>
            MTTA (Mean Time To Acknowledge) — tempo médio entre a abertura do evento e o seu primeiro reconhecimento (ACK). O MTTA de outros analistas é visível apenas para Admin/Super Admin. Meta: abaixo de 60 minutos.
        <?php else: ?>
            MTTA (Mean Time To Acknowledge) — tempo médio entre a abertura do evento e o primeiro reconhecimento (ACK) de cada analista. Meta: abaixo de 60 minutos.
        <?php endif; ?>
    </div>
    <?php if (empty($data['mtta'])): ?>
        <div class="rp-card-body rp-empty"><?= $isUserRole ? 'Você não registrou nenhum ACK neste período.' : 'Nenhum ACK registrado neste período.' ?></div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Analista</th><th>Username</th><th>ACKs</th><th>MTTA Médio</th><th>Mín</th><th>Máx</th><th>Performance</th></tr></thead><tbody>
        <?php foreach ($data['mtta'] as $m):
            $avg = (int)$m['avg_mtta'];
            $pcls = $avg<300?'perf-good':($avg<900?'perf-ok':'perf-bad');
            $plbl = $avg<300?'Excelente':($avg<900?'Aceitável':'Atenção');
        ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars($m['fullname']) ?></td>
            <td><?= htmlspecialchars($m['username']) ?></td>
            <td class="td-center"><?= $m['total_acks'] ?></td>
            <td class="td-mono"><?= rp_duration($avg) ?></td>
            <td class="td-mono"><?= rp_duration((int)$m['min_mtta']) ?></td>
            <td class="td-mono"><?= rp_duration((int)$m['max_mtta']) ?></td>
            <td><span class="rp-perf <?= $pcls ?>"><?= $plbl ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- INHERITED ALERTS -->
<div class="rp-card" id="table-inherited">
    <div class="rp-card-head"><i class="fas fa-history"></i> Alertas Herdados <span class="rp-badge"><?= count($data['inherited']) ?></span></div>
    <div class="rp-card-desc">Alertas que não foram tratados no último plantão.</div>
    <?php if (empty($data['inherited'])): ?>
        <div class="rp-card-body rp-empty">Nenhum alerta herdado pendente.</div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Início</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Idade</th><th>ACK</th><th></th></tr></thead><tbody>
        <?php foreach ($data['inherited'] as $r): $cls='row-'.rp_sevClass((int)$r['severity']); ?>
        <tr class="<?= $cls ?>">
            <td class="td-mono"><?= date('d/m H:i', (int)$r['clock']) ?></td>
            <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= rp_sevLabel((int)$r['severity']) ?></span></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?> <i class="fas fa-external-link-alt"></i></a></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" class="rp-trigger-link"><?= htmlspecialchars($r['trigger_desc']) ?></a></td>
            <td class="td-bold"><?= rp_duration((int)$r['age_seconds']) ?></td>
            <td class="td-center"><?= $r['has_ack'] ? '<i class="fas fa-check-circle rp-ack-yes"></i>' : '<i class="fas fa-times-circle rp-ack-no"></i>' ?></td>
            <td class="td-center"><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" target="_blank" class="rp-action" title="Ver no Zabbix"><i class="fas fa-search"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- UNACKED ALERTS -->
<div class="rp-card" id="table-unacked">
    <div class="rp-card-head"><i class="fas fa-exclamation-triangle"></i> Alertas Sem ACK <span class="rp-badge rp-badge-warn"><?= count($data['unacked']) ?></span></div>
    <?php if (empty($data['unacked'])): ?>
        <div class="rp-card-body rp-empty">Todos os alertas foram reconhecidos. <i class="fas fa-check"></i></div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th><th></th></tr></thead><tbody>
        <?php foreach ($data['unacked'] as $r): $cls='row-'.rp_sevClass((int)$r['severity']); ?>
        <tr class="<?= $cls ?>">
            <td class="td-mono"><?= date('H:i:s', (int)$r['clock']) ?></td>
            <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= rp_sevLabel((int)$r['severity']) ?></span></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?></a></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" class="rp-trigger-link"><?= htmlspecialchars($r['trigger_desc']) ?></a></td>
            <td class="td-center"><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" target="_blank" class="rp-action" title="Ver no Zabbix"><i class="fas fa-search"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- NOISE ANALYSIS -->
<div class="rp-noise-row">
    <div class="rp-card">
        <div class="rp-card-head"><i class="fas fa-server"></i> Top Hosts</div>
        <?php if (empty($data['top_hosts'])): ?>
            <div class="rp-card-body rp-empty">Sem dados.</div>
        <?php else: ?>
            <table class="rp-table"><thead><tr><th>#</th><th>Host</th><th>Eventos</th><th>Pior Sev.</th></tr></thead><tbody>
            <?php $i=1; foreach ($data['top_hosts'] as $r): ?>
            <tr>
                <td class="td-center td-bold"><?= $i++ ?></td>
                <td><a href="<?= rp_probLink($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?></a></td>
                <td class="td-center td-bold"><?= $r['event_count'] ?></td>
                <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['max_severity']) ?>"><?= rp_sevLabel((int)$r['max_severity']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </div>
    <div class="rp-card">
        <div class="rp-card-head"><i class="fas fa-bolt"></i> Top Triggers</div>
        <?php if (empty($data['top_triggers'])): ?>
            <div class="rp-card-body rp-empty">Sem dados.</div>
        <?php else: ?>
            <table class="rp-table"><thead><tr><th>#</th><th>Trigger</th><th>Eventos</th><th>Severidade</th></tr></thead><tbody>
            <?php $i=1; foreach ($data['top_triggers'] as $r): ?>
            <tr>
                <td class="td-center td-bold"><?= $i++ ?></td>
                <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['description']) ?>" target="_blank" class="rp-host-link" title="Pesquisar histórico deste problema no Zabbix"><?= htmlspecialchars($r['description']) ?>&nbsp;<i class="fas fa-external-link-alt" style="font-size:9px;opacity:0.5;"></i></a></td>
                <td class="td-center td-bold"><?= $r['event_count'] ?></td>
                <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= rp_sevLabel((int)$r['severity']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </div>
</div>

<!-- PRESENÇA -->
<div class="rp-card" id="table-presence">
    <div class="rp-card-head"><i class="fas fa-user-clock"></i> Presença de Analistas <span class="rp-badge"><?= count($data['presence']) ?></span></div>
    <?php if (empty($data['presence'])): ?>
        <div class="rp-card-body rp-empty">Nenhum dado de presença. Execute o cron <code>cron_presence_tracker.php</code>.</div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Analista</th><th>Username</th><th>Primeira Atividade</th><th>Última Atividade</th><th>Tempo Online</th></tr></thead><tbody>
        <?php foreach ($data['presence'] as $p): ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars($p['fullname']) ?></td>
            <td><?= htmlspecialchars($p['username']) ?></td>
            <td class="td-mono"><?= $p['first_seen'] ?></td>
            <td class="td-mono"><?= $p['last_seen'] ?></td>
            <td class="td-bold"><?= rp_duration((int)$p['online_minutes'] * 60) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<?php if (rp_isCustomShift($shift)): ?>
<!-- ANALISTAS ESCALADOS NO TURNO -->
<div class="rp-card" id="table-shift-analysts">
    <div class="rp-card-head"><i class="fas fa-id-badge"></i> Analistas Escalados neste Turno <span class="rp-badge"><?= count($data['shift_analysts']) ?></span></div>
    <div class="rp-card-desc">Analistas cadastrados na tela de <strong>Gerenciar Turnos</strong> como pertencentes ao turno <?= htmlspecialchars($data['shift_label'] ?? '') ?>.</div>
    <?php if (empty($data['shift_analysts'])): ?>
        <div class="rp-card-body rp-empty">Nenhum analista vinculado a este turno ainda.<?= !empty($data['can_manage_shifts']) ? ' <a href="zabbix.php?action=plantonistas.shifts.view">Cadastrar agora</a>.' : '' ?></div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Analista</th><th>Username</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($data['shift_analysts'] as $a): ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars(trim($a['fullname']) ?: $a['username']) ?></td>
            <td><?= htmlspecialchars($a['username']) ?></td>
            <td>
                <?php if (!empty($a['is_online'])): ?>
                    <span class="rp-perf perf-good"><i class="fas fa-circle" style="font-size:8px;"></i> Online</span>
                <?php else: ?>
                    <span class="rp-perf perf-bad"><i class="far fa-circle" style="font-size:8px;"></i> Offline</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- DIÁRIO DE BORDO -->
<div class="rp-card">
    <div class="rp-card-head"><i class="fas fa-book-open"></i> Diário de Bordo</div>
    <div class="rp-card-desc">Registre aqui ocorrências do turno, pendências para o próximo plantão e ações tomadas — serve de repasse para quem assumir o plantão seguinte.</div>
    <div class="rp-card-body">
        <form id="turnosNoteForm" class="rp-note-form">
            <div class="rp-note-meta">
                <span><strong>Analista:</strong> <?= htmlspecialchars($data['current_fullname']) ?></span>
                <span><strong>Turno:</strong> <?= htmlspecialchars($data['shift_label'] ?? rp_shiftLabel($shift)) ?></span>
                <span><strong>Data:</strong> <?= $date ?></span>
            </div>
            <textarea id="noteText" class="rp-textarea" rows="4" placeholder="Descreva as ocorrências do turno, pendências, ações tomadas..."></textarea>
            <div class="rp-note-actions">
                <button type="submit" class="rp-btn rp-btn-primary"><i class="fas fa-save"></i> Salvar Nota</button>
                <span id="noteSaveStatus" class="rp-note-status"></span>
            </div>
        </form>
        <?php if (!empty($data['notes'])): ?>
        <div class="rp-notes-list">
            <div class="rp-notes-title">Notas Anteriores</div>
            <?php foreach ($data['notes'] as $n): ?>
            <div class="rp-note-item">
                <div class="rp-note-header">
                    <strong><?= htmlspecialchars($n['analyst_name']) ?></strong>
                    <span class="rp-note-time"><?= $n['created_at'] ?></span>
                </div>
                <div class="rp-note-content"><?= nl2br(htmlspecialchars($n['notes'])) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- FOOTER -->
<div class="rp-native-footer">
    <span>Relatório gerado em <?= date('d/m/Y H:i:s') ?> por <?= htmlspecialchars($data['current_fullname']) ?></span>
    <span>Módulo Repasse Plantão v2.5.0</span>
</div>

</div><!-- /rp-native-container -->

<script>
const MTTA_LABELS = <?= $chart_mtta_labels ?>;
const MTTA_DATA = <?= $chart_mtta_data ?>;
const CURRENT_FULLNAME = '<?= addslashes($data['current_fullname']) ?>';
const SEV_LABELS = ['Not classified','Information','Warning','Minor','Major','Critical'];
const SEV_DATA = <?= $sev_data ?>;
const NOTE_SHIFT = '<?= $shift ?>';
const NOTE_DATE = '<?= $date ?>';
const CALENDAR_DATA = <?= $calendar_json ?>;

// ── Charts ──
const maxMtta = Math.max(...MTTA_DATA);
const mttaSuggestedMax = (maxMtta > 0 && maxMtta < 3600) ? 3600 : undefined;

if (document.getElementById('chartMtta')) {
    new Chart(document.getElementById('chartMtta'), {
        type:'bar', data:{labels:MTTA_LABELS, datasets:[{label:'MTTA Médio',data:MTTA_DATA,
            backgroundColor: IS_DARK_THEME ? '#02a0ff' : '#0275b8', borderRadius:3}]},
        options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){const s=c.parsed.y;
                return s<60?s+'s':s<3600?Math.floor(s/60)+'m '+(s%60)+'s':Math.floor(s/3600)+'h '+Math.floor((s%3600)/60)+'m';}}}},
            scales:{x:{grid:{display:false},ticks:{font:{size:11},color: IS_DARK_THEME ? '#aaa' : '#999'}},
                y:{grid:{color: IS_DARK_THEME ? 'rgba(255,255,255,0.06)' : '#f0f0f0'}, suggestedMax: mttaSuggestedMax,
                   beginAtZero:true,ticks:{font:{size:11},color: IS_DARK_THEME ? '#aaa' : '#999',
                    callback:function(v){return v<60?v+'s':Math.floor(v/60)+'m';}}}}}
    });
}

// Severity chart with click-to-filter
const sevMap = [0,1,2,3,4,5]; // severity index to value
if (document.getElementById('chartSev')) {
    // Chart.js v4 bug: doughnut with borderWidth:0 and a single non-zero slice
    // renders blank. Fix: use borderWidth:2 with matching borderColor so the
    // border is invisible but the arc path is correctly stroked and filled.
    // Cores = config.severity_color_0..5 (Administração > Geral > Opções de
    // exibição de acionadores). Fixo por enquanto — TODO buscar dinamicamente.
    const sevBgColors = ['#97AAB3','#00FFFF','#7499FF','#FFC859','#FFA059','#E45959'];
    const sevTotal = SEV_DATA.reduce((a,b)=>a+b,0);
    window.sevChart = new Chart(document.getElementById('chartSev'), {
        type:'doughnut', data:{labels:SEV_LABELS, datasets:[{data:SEV_DATA,
            backgroundColor: sevBgColors,
            borderColor: sevBgColors,
            borderWidth: sevTotal > 0 ? 2 : 0,
            hoverOffset: 6}]},
        options:{responsive:true,maintainAspectRatio:false,cutout:'55%',
            plugins:{
                legend:{position:'right',labels:{font:{size:11},padding:12,color: IS_DARK_THEME ? '#ccc' : '#666',usePointStyle:true,pointStyle:'circle',
                    generateLabels:function(chart){
                        const d=chart.data; return d.labels.map(function(l,i){
                            return {text:l+' ('+d.datasets[0].data[i]+')',fillStyle:d.datasets[0].backgroundColor[i],
                                strokeStyle:'transparent',lineWidth:0,index:i,hidden:false,pointStyle:'circle'};
                        });
                    }
                }},
                tooltip:{callbacks:{label:function(c){const t=c.dataset.data.reduce((a,b)=>a+b,0);
                    if (window.sevChart && window.sevChart.config.type === 'bar') return c.label+': '+c.parsed;
                    return c.label+': '+c.parsed+' ('+(t>0?Math.round(c.parsed/t*100):0)+'%)';}}}
            },
            onClick:function(e,el){
                if(el.length>0){const sev=sevMap[el[0].index];
                    window.open('zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_severities[]='+sev,'_blank');
                }
            }}
    });
    document.getElementById('chartSev').style.cursor='pointer';
}

function toggleSevChart() {
    if (!window.sevChart) return;
    const isDoughnut = window.sevChart.config.type === 'doughnut';
    window.sevChart.config.type = isDoughnut ? 'bar' : 'doughnut';
    if(isDoughnut) {
        window.sevChart.options.cutout = undefined;
        window.sevChart.options.plugins.legend.display = false;
        window.sevChart.options.scales = {
            x:{grid:{display:false},ticks:{font:{size:11},color: IS_DARK_THEME ? '#aaa' : '#999'}},
            y:{grid:{color: IS_DARK_THEME ? 'rgba(255,255,255,0.06)' : '#f0f0f0'},beginAtZero:true,ticks:{font:{size:11},color: IS_DARK_THEME ? '#aaa' : '#999',stepSize:1}}
        };
    } else {
        window.sevChart.options.cutout = '55%';
        window.sevChart.options.plugins.legend.display = true;
        window.sevChart.options.scales = {x:{display:false},y:{display:false}};
    }
    window.sevChart.update();
}

// ── Calendar Heatmap (clickable with month labels) ──
(function(){
    const container = document.getElementById('calendarHeatmap');
    if (!container) return;
    const data = CALENDAR_DATA;
    const today = new Date();
    const months = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    let html = '<div class="rp-hm-grid">';
    let maxCnt = 1;
    let lastMonth = -1;
    Object.values(data).forEach(d => { if (parseInt(d.cnt) > maxCnt) maxCnt = parseInt(d.cnt); });
    for (let i = 29; i >= 0; i--) {
        const d = new Date(today); d.setDate(d.getDate() - i);
        const key = d.toISOString().slice(0,10);
        const info = data[key];
        const cnt = info ? parseInt(info.cnt) : 0;
        const crit = info ? parseInt(info.critical) : 0;
        const dayNum = d.getDate();
        const mon = d.getMonth();
        let color = IS_DARK_THEME ? 'rgba(255,255,255,0.06)' : '#ebedf0';
        if (cnt > 0) {
            const ratio = cnt / maxCnt;
            if (ratio > 0.8) color = '#E45959';
            else if (ratio > 0.6) color = '#30a14e';
            else if (ratio > 0.4) color = '#40c463';
            else if (ratio > 0.2) color = '#9be9a8';
            else color = '#c6e48b';
        }
        const isSelected = (key === NOTE_DATE);
        const border = isSelected ? 'border:2px solid var(--rp-blue);' : '';
        const dayLabel = String(dayNum).padStart(2,'0') + '/' + String(mon+1).padStart(2,'0');
        const monthLabel = (lastMonth !== mon) ? '<span class="rp-hm-month">' + months[mon] + '</span>' : '';
        lastMonth = mon;
        const tooltip = dayLabel+': '+cnt+' eventos'+(crit>0?' ('+crit+' críticos)':'')+'\nClique para ver relatório';
        html += monthLabel +
            '<div class="rp-hm-cell'+(isSelected?' rp-hm-selected':'')+'" style="background:'+color+';cursor:pointer;'+border+'" '+
            'title="'+tooltip+'" onclick="window.location.href=\'zabbix.php?action=plantonistas.report.view&date='+key+'&shift=24h\'">' +
            '<span class="rp-hm-day">'+String(dayNum).padStart(2,'0')+'</span></div>';
    }
    html += '</div>';
    container.innerHTML = html;
})();

// ── Note Form ──
const noteForm = document.getElementById('turnosNoteForm');
if (noteForm) {
    noteForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const ta = document.getElementById('noteText'), st = document.getElementById('noteSaveStatus');
        const note = ta.value.trim();
        if (!note) { st.textContent='Escreva algo antes de salvar.'; st.style.color='#c62828'; return; }
        st.textContent='Salvando...'; st.style.color='#666';
        fetch('zabbix.php?action=plantonistas.report.notes.save', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:new URLSearchParams({note:note,shift:NOTE_SHIFT,shift_date:NOTE_DATE})
        }).then(r=>r.json()).then(j=>{
            if(j.success){
                st.textContent=j.message; st.style.color='#2e7d32'; ta.value='';
                let list = document.querySelector('.rp-notes-list');
                if (!list) {
                    list = document.createElement('div'); list.className='rp-notes-list';
                    list.innerHTML='<div class="rp-notes-title">Notas Anteriores</div>';
                    noteForm.insertAdjacentElement('afterend', list);
                }
                const d = new Date(), t = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')+' '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')+':'+String(d.getSeconds()).padStart(2,'0');
                const item = document.createElement('div'); item.className='rp-note-item';
                item.innerHTML=`<div class="rp-note-header"><strong>${CURRENT_FULLNAME}</strong><span class="rp-note-time">${t}</span></div><div class="rp-note-content">${note.replace(/\n/g,'<br>')}</div>`;
                if(list.children.length>1) { list.insertBefore(item, list.children[1]); } else { list.appendChild(item); }
                setTimeout(() => { st.textContent=''; }, 3000);
            }
            else { st.textContent=j.message||'Erro.'; st.style.color='#c62828'; }
        }).catch(()=>{st.textContent='Erro de conexão.';st.style.color='#c62828';});
    });
}
</script>
