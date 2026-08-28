<?php
/**
 * View: plantonistas.report.view — Renderiza DENTRO do Zabbix frame
 * Recebe $data do TurnosReportView controller via CControllerResponseData
 */

// Helper functions inline
function rp_sevLabel(int $sev, array $severities = []): string {
    // Nome REAL de Administração > Geral > Opções de exibição de acionadores
    // (config.severity_name_0..5, lido em TurnosReportBase::querySeverities()
    // e passado pelo controller em $data['severities']). O array abaixo só
    // entra em jogo se a consulta falhar — e mesmo assim é o default de
    // FÁBRICA do Zabbix, não mais "Minor"/"Major"/"Critical" (que nunca
    // existiram no Zabbix; o certo sempre foi "Average"/"High"/"Disaster").
    if (isset($severities[$sev]['name']) && $severities[$sev]['name'] !== '') {
        return $severities[$sev]['name'];
    }
    return [0=>'Not classified',1=>'Information',2=>'Warning',3=>'Average',4=>'High',5=>'Disaster'][$sev] ?? 'N/A';
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
/**
 * Rótulo do turno vinculado ao usuário (tela Gerenciar Turnos).
 * Mesmo formato do seletor de turno: "Diurno (06:00–14:00)".
 * Usuário sem vínculo → string vazia (a view mostra "Sem turno").
 */
function rp_userShiftLabel(array $row): string {
    $name = trim((string)($row['shift_name'] ?? ''));
    if ($name === '') return '';
    if (!empty($row['shift_start']) && !empty($row['shift_end'])) {
        $name .= ' (' . substr((string)$row['shift_start'], 0, 5)
               . '–' . substr((string)$row['shift_end'], 0, 5) . ')';
    }
    return $name;
}
/**
 * Y-m-d → d/m/Y para exibição. O valor cru continua onde é protocolo e não
 * texto: `value` do input type="date" (a spec do HTML exige ISO), querystring
 * dos links e a const NOTE_DATE do JS, que volta pro backend no save da nota.
 */
function rp_dateBr(?string $ymd): string {
    $ts = $ymd ? strtotime($ymd) : false;
    return $ts === false ? (string)$ymd : date('d/m/Y', $ts);
}
function rp_probLink(?string $h=null): string {
    $u = 'zabbix.php?action=problem.view&filter_set=1&filter_show=3';
    return $h ? $u.'&filter_name='.urlencode($h) : $u;
}
/**
 * Renderiza a coluna Ações das 4 tabelas de alarme (Herdados, Sem ACK, Em
 * Tratativas, Resolvidos) a partir de $data['actions'][$eventid]['items'],
 * já montado por TurnosReportBase::queryEventActions() — a view só formata,
 * não decide o que é ação nem consulta banco nenhum.
 *
 * $rp_sev entra só pro item type=severity, pra mostrar o nome REAL da
 * severidade (Administração > Geral), igual ao resto da tela.
 */
function rp_actionChips(array $items, array $rp_sev): string {
    if (empty($items)) {
        return '<span class="rp-muted">—</span>';
    }
    $chips = [];
    foreach ($items as $it) {
        $detail = trim(
            ($it['who'] ? $it['who'] . ' — ' : '')
            . date('d/m/Y H:i', (int)$it['when'])
        );
        if ($it['type'] === 'severity') {
            $detail .= ': ' . rp_sevLabel((int)($it['old_severity'] ?? 0), $rp_sev)
                     . ' → ' . rp_sevLabel((int)($it['new_severity'] ?? 0), $rp_sev);
        } elseif (!empty($it['message'])) {
            $detail .= ': ' . $it['message'];
        }
        $chips[] = '<span class="rp-act rp-act-' . htmlspecialchars((string)$it['type']) . '" title="'
                  . htmlspecialchars($detail) . '">' . htmlspecialchars((string)$it['label']) . '</span>';
    }
    return '<div class="rp-act-list">' . implode('', $chips) . '</div>';
}
/**
 * "Quem resolveu" da tabela Alarmes Resolvidos: fechamento manual (item
 * type=close no histórico de ações) x recuperação automática (trigger
 * voltou ao normal sozinha, sem ninguém clicar em nada).
 */
function rp_resolvedBy(?string $closedBy): string {
    return $closedBy !== null
        ? '<span class="rp-resolve-manual"><i class="fas fa-user-check"></i> ' . htmlspecialchars($closedBy) . '</span>'
        : '<span class="rp-resolve-auto"><i class="fas fa-sync-alt"></i> Automático</span>';
}

$date  = $data['date'];
$shift = $data['shift'];
// Nomes/cores reais de severidade — ver rp_sevLabel() e o bloco de <style>
// logo abaixo de _theme.php, que sobrescreve --sev-* com as cores de verdade.
$rp_sev = $data['severities'] ?? [];
// Coluna Ações das 4 tabelas de alarme — ver rp_actionChips() acima.
$rp_actions = $data['actions'] ?? [];
$chart_mtta_labels = json_encode(array_column($data['mtta_timeline'], 'hora'));
$chart_mtta_data   = json_encode(array_map('intval', array_column($data['mtta_timeline'], 'avg_mtta')));
$sev_data = json_encode([
    $data['sev_dist'][0]??0, $data['sev_dist'][1]??0, $data['sev_dist'][2]??0,
    $data['sev_dist'][3]??0, $data['sev_dist'][4]??0, $data['sev_dist'][5]??0
]);

// Calendar heatmap data (30 days)
$calendar_json = json_encode($data['calendar']);
?>

<?php
// ── Chart.js: arquivo estático, com modo inline de reserva ──────────────
//
// Este é o ÚNICO .js estático do módulo. Todo o resto do JavaScript é inline
// porque o F5 BIG-IP que fica na frente dos frontends bloqueia arquivo .js —
// e se ele bloquear este também, os dois gráficos do Repasse simplesmente não
// renderizam. Silenciosamente: `new Chart(...)` estoura no console e o resto
// da tela continua de pé.
//
// O padrão continua sendo o <script src>, que o navegador cacheia entre
// páginas — inlinear 204 KB em toda carga do Repasse seria pior no caso normal,
// que é o F5 deixando passar.
//
// Se ele NÃO deixar, não é preciso mexer em código: basta ligar a env
// `PLANTONISTAS_CHART_INLINE=1` no pool do PHP-FPM (mesmo lugar das outras
// opções do módulo) e a biblioteca passa a ser embutida na página. Está no
// README.
$chart_file = __DIR__ . '/../assets/js/chart.min.js';
$chart_quer = in_array(
    strtolower(trim((string) getenv('PLANTONISTAS_CHART_INLINE'))),
    ['1', 'on', 'true', 'yes'], true
);

// Ligado mas ilegível: sem este log o fallback seria silencioso E enganoso —
// voltaria para o <script src> do MESMO arquivo, o navegador tomaria 403, e a
// tela mandaria o operador ligar a env que ele já ligou. Causa mais provável:
// `git pull` como root sem o `chown -R apache:apache` depois.
if ($chart_quer && !is_readable($chart_file)) {
    error_log('[plantonistas] PLANTONISTAS_CHART_INLINE ligado, mas ' . $chart_file
            . ' não é legível pelo PHP (dono/permissão?) — usando <script src>.');
}

$chart_inline = $chart_quer && is_readable($chart_file);

if ($chart_inline) {
    // Embutir só é seguro porque este arquivo não contém `</script` nem
    // `<!--` — os dois fechariam o bloco no parser HTML. Conferido nesta
    // build (0 ocorrências de cada); TROCAR A VERSÃO DO CHART.JS EXIGE
    // conferir de novo:
    //   grep -c '</script' assets/js/chart.min.js
    //   grep -c '<!--'     assets/js/chart.min.js
    echo "<script>\n";
    readfile($chart_file);
    echo "\n</script>\n";
}
else {
    echo '<script src="modules/module-zbx-plantonistas/assets/js/chart.min.js"></script>' . "\n";
}
?>
<link rel="stylesheet" href="modules/module-zbx-plantonistas/assets/fontawesome/css/all.min.css"/>

<?php
// Mesma detecção de tema e mesma paleta das telas da família escala. Aqui ela
// entra por dois motivos: o Chart.js abaixo precisa saber se o tema é escuro
// (IS_DARK_THEME), e a unificação visual só vale se as duas famílias
// decidirem "claro ou escuro" pelo MESMO critério — duas heurísticas
// parecidas divergiriam num tema customizado, e o menu Plantão voltaria a
// ficar metade claro, metade escuro.
include __DIR__ . '/_theme.php';
?>
<?php
// Cores REAIS de severidade por cima do fallback fixo de turnos.report.css —
// mesma técnica do _theme.php (sobrescrever as CSS custom properties em vez
// de reescrever o asset estático: um .css não pode ser gerado por request, e
// mesmo que pudesse, o ganho de cache do <link> se perderia). O nome da
// classe (--sev-notclass, --sev-info, ...) é estrutural e não muda — só o
// valor. Sem cores em $rp_sev (consulta falhou), o CSS estático já cobre.
$rp_sev_class_by_index = [0=>'notclass',1=>'info',2=>'warn',3=>'avg',4=>'high',5=>'disaster'];
if ($rp_sev) {
    echo '<style>:root{';
    foreach ($rp_sev_class_by_index as $rp_idx => $rp_cls) {
        $rp_hex = $rp_sev[$rp_idx]['color'] ?? '';
        if ($rp_hex !== '') {
            echo '--sev-' . $rp_cls . ':#' . $rp_hex . ';';
        }
    }
    echo '}</style>' . "\n";
}
?>
<div class="rp-native-container" id="rpContainer">
<script>
    // Lida do atributo que o _theme.php já gravou, em vez de recalcular.
    const IS_DARK_THEME = document.documentElement.getAttribute('data-plt-theme') === 'dark';
</script>

<!-- HEADER BAR -->
<div class="rp-native-header">
    <div class="rp-nh-left">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
            <rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/>
        </svg>
        <span class="rp-nh-title">Repasse de Plantão</span>
        <span class="rp-nh-sub"><?= htmlspecialchars($data['shift_label'] ?? rp_shiftLabel($shift)) ?> — <?= rp_dateBr($date) ?></span>
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
        <?php
        // O botão aparece com menção pendente (laranja, chamando atenção) OU
        // com histórico (neutro, só para consultar). Antes ele só existia com
        // pendência, então o histórico não teria por onde ser aberto.
        // Condição IDÊNTICA à do painel, não uma parecida: se
        // queryMentionHistory() falhar ela devolve [] (o histórico é
        // acessório e não derruba o relatório), e um botão preso a
        // `pending_mentions` continuaria na tela sem nada para abrir — o
        // clique não faria absolutamente nada.
        $mh_total = count($data['mention_history'] ?? []);
        $mh_pend  = count($data['pending_mentions'] ?? []);
        if ($mh_total > 0):
        ?>
        <a href="javascript:void(0)" onclick="rpToggleMentions()" class="rp-nh-btn"
           <?= $mh_pend > 0 ? 'style="background:#e65100;"' : '' ?>
           title="<?= $mh_pend > 0 ? 'Você tem menções pendentes' : 'Menções recebidas' ?>">
            <i class="fas fa-at"></i> <?= $mh_pend > 0 ? (int)$mh_pend : (int)$mh_total ?>
        </a>
        <?php endif; ?>
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
        <button type="button" id="rpCloseShiftBtn" class="rp-nh-btn"
                title="Congela os números deste turno num documento imutável">
            <i class="fas fa-lock"></i> Fechar turno
        </button>
    </div>
</div>

<?php
// ── Turno fechado (issue #2) ────────────────────────────────────────────
// A tela continua consultando ao vivo; o fechamento é um DOCUMENTO à parte,
// renderizado pelo PDF a partir do snapshot. O aviso existe porque os números
// desta tela podem já divergir do documento — o housekeeper do Zabbix vai
// apagando os eventos do período.
$cr = $data['closed_report'] ?? null;
if ($cr):
?>
<div class="rp-card rp-closed-banner">
    <div class="rp-closed-body">
        <i class="fas fa-lock"></i>
        <span>
            <strong>Turno fechado</strong> em <?= htmlspecialchars((string)$cr['generated_at']) ?>
            por <?= htmlspecialchars((string)$cr['closed_by_label']) ?>
            <?php if ((int)$cr['total'] > 1): ?>
                <span class="rp-muted">(<?= (int)$cr['total'] ?> fechamentos deste turno; abaixo, o último)</span>
            <?php endif; ?>
        </span>
        <a class="rp-nh-btn" target="_blank"
           href="zabbix.php?action=plantonistas.report.pdf&report_id=<?= (int)$cr['id'] ?>"
           title="Abre o documento congelado, com os números do fechamento">
            <i class="fas fa-file-contract"></i> Ver repasse fechado
        </a>
    </div>
</div>
<?php endif; ?>

<?php 
// URL base do Problem View filtrado por período.
// Y-m-d H:i:s aqui NÃO é gap de pt-BR: é o formato que o filtro nativo do
// Zabbix aceita em from/to. Formatar como d/m/Y quebraria o link.
$f = date('Y-m-d H:i:s', $data['ts_start']);
$t = date('Y-m-d H:i:s', $data['ts_end']);
$pview_base = "zabbix.php?action=problem.view&filter_set=1&filter_show=3&from=".urlencode($f)."&to=".urlencode($t);
?>

<?php if ($data['db_error']): ?>
<div class="rp-alert rp-alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= $data['db_error'] ?></div>
<?php endif; ?>

<?php if (!empty($data['pending_mentions'])): ?>
<!-- MENÇÕES PENDENTES -->
<div class="rp-mention-banner" id="mentionBanner">
    <?php foreach ($data['pending_mentions'] as $pm):
        $pm_shift_param = !empty($pm['shift_id']) ? $pm['shift_id'] : ($pm['shift_name'] ?: '24h');
    ?>
    <div class="rp-mention-item">
        <i class="fas fa-at"></i>
        <span><strong><?= htmlspecialchars($pm['analyst_name']) ?></strong> mencionou você numa nota de
            <?= htmlspecialchars($pm['created_at']) ?> — turno <?= htmlspecialchars($pm['shift_name']) ?>
            (<?= date('d/m/Y', strtotime($pm['shift_date'])) ?>).</span>
        <a href="zabbix.php?action=plantonistas.report.view&date=<?= htmlspecialchars($pm['shift_date']) ?>&shift=<?= htmlspecialchars((string)$pm_shift_param) ?>#table-notes"
           class="rp-mention-view-link" data-mention-id="<?= (int)$pm['id'] ?>">Ver nota</a>
        <button type="button" class="rp-mention-dismiss" data-mention-id="<?= (int)$pm['id'] ?>" title="Marcar como lida">&times;</button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($data['mention_history'])): ?>
<!-- HISTÓRICO DE MENÇÕES (pendentes e lidas) -->
<div class="rp-mention-history" id="mentionHistory" hidden>
    <div class="rp-mh-head">
        <span><i class="fas fa-at"></i> Menções recebidas</span>
        <span class="rp-muted"><?= count($data['mention_history']) ?> (últimas 100)</span>
        <button type="button" class="rp-mh-close" onclick="rpToggleMentions()" title="Fechar">&times;</button>
    </div>
    <div class="rp-mh-list">
        <?php foreach ($data['mention_history'] as $mh):
            // Mesma resolução do banner: turno cadastrado vai pelo id; turno
            // legado (24h/manha/...) vai pelo nome.
            $mh_shift = !empty($mh['shift_id']) ? $mh['shift_id'] : ($mh['shift_name'] ?: '24h');
            $mh_lida  = (int)$mh['is_read'] === 1;
        ?>
        <div class="rp-mh-item<?= $mh_lida ? '' : ' rp-mh-unread' ?>">
            <span class="rp-mh-dot" title="<?= $mh_lida ? 'Lida' : 'Não lida' ?>"></span>
            <span class="rp-mh-txt">
                <strong><?= htmlspecialchars($mh['analyst_name']) ?></strong>
                — <?= htmlspecialchars($mh['created_at']) ?>,
                turno <?= htmlspecialchars($mh['shift_name']) ?>
                (<?= date('d/m/Y', strtotime($mh['shift_date'])) ?>)
            </span>
            <a href="zabbix.php?action=plantonistas.report.view&date=<?= htmlspecialchars($mh['shift_date']) ?>&shift=<?= htmlspecialchars((string)$mh_shift) ?>#table-notes"
               class="rp-mention-view-link" data-mention-id="<?= (int)$mh['id'] ?>">Ver nota</a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- KPI CARDS -->
<div class="rp-kpi-grid">
    <a href="<?= $pview_base ?>" target="_blank" class="rp-kpi rp-kpi-link" title="Total de eventos mapeados neste período. Clique para ver no Monitoramento.">
        <div class="rp-kpi-icon txt-blue"><i class="fas fa-info-circle"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= (int)$data['totals']['total'] ?></span><span class="rp-kpi-label">Total Eventos</span></div>
    </a>
    <a href="<?= $pview_base ?>&severities[]=4&severities[]=5" target="_blank" class="rp-kpi rp-kpi-link" title="Soma total de eventos <?= htmlspecialchars(rp_sevLabel(4, $rp_sev)) ?> e <?= htmlspecialchars(rp_sevLabel(5, $rp_sev)) ?>.">
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
    <a href="javascript:void(0)" onclick="document.getElementById('table-in-progress').scrollIntoView({behavior:'smooth'})" class="rp-kpi rp-kpi-link" title="Alarmes abertos durante o turno que já têm alguma ação registrada (ACK, mensagem, etc.).">
        <div class="rp-kpi-icon txt-blue"><i class="fas fa-tools"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= count($data['in_progress']) ?></span><span class="rp-kpi-label">Em Tratativas</span></div>
    </a>
    <a href="javascript:void(0)" onclick="document.getElementById('table-resolved').scrollIntoView({behavior:'smooth'})" class="rp-kpi rp-kpi-link" title="Alarmes resolvidos dentro da janela deste turno — histórico do turno.">
        <div class="rp-kpi-icon txt-green"><i class="fas fa-check-circle"></i></div>
        <div class="rp-kpi-body"><span class="rp-kpi-val"><?= count($data['resolved']) ?></span><span class="rp-kpi-label">Resolvidos</span></div>
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
        <table class="rp-table"><thead><tr><th>Início</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Idade</th><th>ACK</th><th>Ações</th><th></th></tr></thead><tbody>
        <?php foreach ($data['inherited'] as $r): $cls='row-'.rp_sevClass((int)$r['severity']); ?>
        <tr class="<?= $cls ?>">
            <td class="td-mono"><?= date('d/m H:i', (int)$r['clock']) ?></td>
            <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= htmlspecialchars(rp_sevLabel((int)$r['severity'], $rp_sev)) ?></span></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?> <i class="fas fa-external-link-alt"></i></a></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" class="rp-trigger-link"><?= htmlspecialchars($r['trigger_desc']) ?></a></td>
            <td class="td-bold"><?= rp_duration((int)$r['age_seconds']) ?></td>
            <td class="td-center"><?= $r['has_ack'] ? '<i class="fas fa-check-circle rp-ack-yes"></i>' : '<i class="fas fa-times-circle rp-ack-no"></i>' ?></td>
            <td><?= rp_actionChips($rp_actions[(int)$r['eventid']]['items'] ?? [], $rp_sev) ?></td>
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
        <table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Ações</th><th></th></tr></thead><tbody>
        <?php foreach ($data['unacked'] as $r): $cls='row-'.rp_sevClass((int)$r['severity']); ?>
        <tr class="<?= $cls ?>">
            <td class="td-mono"><?= date('H:i:s', (int)$r['clock']) ?></td>
            <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= htmlspecialchars(rp_sevLabel((int)$r['severity'], $rp_sev)) ?></span></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?></a></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" class="rp-trigger-link"><?= htmlspecialchars($r['trigger_desc']) ?></a></td>
            <td><?= rp_actionChips($rp_actions[(int)$r['eventid']]['items'] ?? [], $rp_sev) ?></td>
            <td class="td-center"><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" target="_blank" class="rp-action" title="Ver no Zabbix"><i class="fas fa-search"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- IN PROGRESS ALERTS (complemento de Sem ACK: já tem ação/ACK) -->
<div class="rp-card" id="table-in-progress">
    <div class="rp-card-head"><i class="fas fa-tools"></i> Alarmes em Tratativas <span class="rp-badge"><?= count($data['in_progress']) ?></span></div>
    <div class="rp-card-desc">Alarmes abertos durante este turno que já têm pelo menos uma ação registrada (ACK, mensagem, etc.) — complemento de Alertas Sem ACK.</div>
    <?php if (empty($data['in_progress'])): ?>
        <div class="rp-card-body rp-empty">Nenhum alarme em tratativa neste turno.</div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Ações</th><th></th></tr></thead><tbody>
        <?php foreach ($data['in_progress'] as $r): $cls='row-'.rp_sevClass((int)$r['severity']); ?>
        <tr class="<?= $cls ?>">
            <td class="td-mono"><?= date('H:i:s', (int)$r['clock']) ?></td>
            <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= htmlspecialchars(rp_sevLabel((int)$r['severity'], $rp_sev)) ?></span></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?></a></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" class="rp-trigger-link"><?= htmlspecialchars($r['trigger_desc']) ?></a></td>
            <td><?= rp_actionChips($rp_actions[(int)$r['eventid']]['items'] ?? [], $rp_sev) ?></td>
            <td class="td-center"><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" target="_blank" class="rp-action" title="Ver no Zabbix"><i class="fas fa-search"></i></a></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>

<!-- RESOLVED ALERTS (histórico do turno) -->
<div class="rp-card" id="table-resolved">
    <div class="rp-card-head"><i class="fas fa-check-circle"></i> Alarmes Resolvidos <span class="rp-badge"><?= count($data['resolved']) ?></span></div>
    <div class="rp-card-desc">
        Alarmes cuja resolução caiu dentro deste turno — histórico do turno. Turnos antigos podem aparecer vazios aqui mesmo tendo tido resolução: o Zabbix limpa problemas resolvidos da tabela de origem depois de um tempo (housekeeper), independente deste módulo.
    </div>
    <?php if (empty($data['resolved'])): ?>
        <div class="rp-card-body rp-empty">Nenhum alarme resolvido dentro da janela deste turno.</div>
    <?php else: ?>
        <table class="rp-table"><thead><tr><th>Resolvido</th><th>Severidade</th><th>Host</th><th>Problema</th><th>MTTR</th><th>Resolvido por</th><th>Ações</th><th></th></tr></thead><tbody>
        <?php foreach ($data['resolved'] as $r): $cls='row-'.rp_sevClass((int)$r['severity']); $closedBy = $rp_actions[(int)$r['eventid']]['closed_by'] ?? null; ?>
        <tr class="<?= $cls ?>">
            <td class="td-mono"><?= date('d/m H:i', (int)$r['r_clock']) ?></td>
            <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= htmlspecialchars(rp_sevLabel((int)$r['severity'], $rp_sev)) ?></span></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['host']) ?>" target="_blank" class="rp-host-link"><?= htmlspecialchars($r['host']) ?></a></td>
            <td><a href="zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=<?= urlencode($r['trigger_desc']) ?>" class="rp-trigger-link"><?= htmlspecialchars($r['trigger_desc']) ?></a></td>
            <td class="td-bold"><?= rp_duration((int)$r['resolve_seconds']) ?></td>
            <td><?= rp_resolvedBy($closedBy) ?></td>
            <td><?= rp_actionChips($rp_actions[(int)$r['eventid']]['items'] ?? [], $rp_sev) ?></td>
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
                <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['max_severity']) ?>"><?= htmlspecialchars(rp_sevLabel((int)$r['max_severity'], $rp_sev)) ?></span></td>
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
                <td><span class="rp-sev sev-<?= rp_sevClass((int)$r['severity']) ?>"><?= htmlspecialchars(rp_sevLabel((int)$r['severity'], $rp_sev)) ?></span></td>
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
        <table class="rp-table"><thead><tr><th>Analista</th><th>Username</th><th>Turno</th><th>Primeira Atividade</th><th>Última Atividade</th><th>Tempo Online</th></tr></thead><tbody>
        <?php foreach ($data['presence'] as $p): ?>
        <?php $p_shift = rp_userShiftLabel($p); ?>
        <tr>
            <td class="td-bold"><?= htmlspecialchars($p['fullname']) ?></td>
            <td><?= htmlspecialchars($p['username']) ?></td>
            <td>
                <?php if ($p_shift !== ''): ?>
                    <?= htmlspecialchars($p_shift) ?>
                <?php else: ?>
                    <span class="rp-muted" title="Analista sem turno vinculado em Gerenciar Turnos">Sem turno</span>
                <?php endif; ?>
            </td>
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
<div class="rp-card" id="table-notes">
    <div class="rp-card-head"><i class="fas fa-book-open"></i> Diário de Bordo</div>
    <div class="rp-card-desc">Registre aqui ocorrências do turno, pendências para o próximo plantão e ações tomadas — serve de repasse para quem assumir o plantão seguinte.
        Digite <code>@</code> pra mencionar um usuário, <code>_h</code> pra um host ou <code>_hg</code> pra um grupo de hosts — mostra a lista completa referente ao seu grupo, filtra ao continuar digitando.
        Dá pra buscar por nome, sobrenome ou login: <code>@Rafael Leao</code> e <code>@z148534</code> chegam na mesma pessoa.</div>
    <div class="rp-card-body">
        <form id="turnosNoteForm" class="rp-note-form">
            <div class="rp-note-meta">
                <span><strong>Analista:</strong> <?= htmlspecialchars($data['current_fullname']) ?></span>
                <span><strong>Turno:</strong> <?= htmlspecialchars($data['shift_label'] ?? rp_shiftLabel($shift)) ?></span>
                <span><strong>Data:</strong> <?= rp_dateBr($date) ?></span>
            </div>
            <div class="rp-editor-toolbar">
                <button type="button" class="rp-editor-btn" data-cmd="bold" title="Negrito"><i class="fas fa-bold"></i></button>
                <button type="button" class="rp-editor-btn" data-cmd="italic" title="Itálico"><i class="fas fa-italic"></i></button>
                <button type="button" class="rp-editor-btn" data-cmd="insertUnorderedList" title="Lista"><i class="fas fa-list-ul"></i></button>
                <button type="button" class="rp-editor-btn" data-cmd="createLink" title="Link"><i class="fas fa-link"></i></button>
            </div>
            <div class="rp-editor-wrap">
                <div id="noteText" class="rp-editor" contenteditable="true" data-placeholder="Descreva as ocorrências do turno, pendências, ações tomadas..."></div>
                <div id="mentionDropdown" class="rp-mention-dropdown" style="display:none;"></div>
            </div>
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
                    <span class="rp-note-time"><?= htmlspecialchars($n['created_at']) ?></span>
                </div>
                <div class="rp-note-content"><?= (($n['notes_format'] ?? 'text') === 'html') ? $n['notes'] : nl2br(htmlspecialchars($n['notes'])) ?></div>
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
// Nomes/cores reais (Administração > Geral > Opções de exibição de
// acionadores) — mesma fonte ($rp_sev) usada nas tabelas acima. Fallback é o
// default de fábrica do Zabbix, só usado se a consulta a `config` falhar.
// JSON_HEX_TAG: nome de severidade é texto livre digitado por um Super Admin
// em Administração > Geral — sem o flag, um nome contendo "</script>" sairia
// cru dentro do bloco e fecharia a tag no meio do JSON.
const SEV_LABELS = <?= json_encode([
    rp_sevLabel(0, $rp_sev), rp_sevLabel(1, $rp_sev), rp_sevLabel(2, $rp_sev),
    rp_sevLabel(3, $rp_sev), rp_sevLabel(4, $rp_sev), rp_sevLabel(5, $rp_sev),
], JSON_HEX_TAG) ?>;
const SEV_COLORS = <?= json_encode([
    '#' . ($rp_sev[0]['color'] ?? '97AAB3'), '#' . ($rp_sev[1]['color'] ?? '7499FF'),
    '#' . ($rp_sev[2]['color'] ?? 'FFC859'), '#' . ($rp_sev[3]['color'] ?? 'FFA059'),
    '#' . ($rp_sev[4]['color'] ?? 'E97659'), '#' . ($rp_sev[5]['color'] ?? 'E45959'),
]) ?>;
const SEV_DATA = <?= $sev_data ?>;
const NOTE_SHIFT = '<?= $shift ?>';
const NOTE_DATE = '<?= $date ?>';
const CALENDAR_DATA = <?= $calendar_json ?>;
// Tokens CSRF das actions de escrita chamadas por esta tela.
const CSRF_NOTES_SAVE    = <?= json_encode($data['csrf_notes_save'] ?? '') ?>;
const CSRF_MENTIONS_READ = <?= json_encode($data['csrf_mentions_read'] ?? '') ?>;
const CSRF_REPORT_CLOSE  = <?= json_encode($data['csrf_report_close'] ?? '') ?>;
const REPORT_LIMIT       = <?= json_encode((string)($data['limit'] ?? '5')) ?>;
const ALREADY_CLOSED     = <?= !empty($data['closed_report']) ? 'true' : 'false' ?>;

// ── Charts ──
//
// Se a biblioteca não carregou (F5 bloqueando o .js), a falha NÃO pode ser
// silenciosa: sem esta guarda o `new Chart(...)` estoura no console, os dois
// cards ficam vazios, e ninguém liga uma coisa à outra.
if (typeof Chart === 'undefined') {
    ['chartMtta', 'chartSev'].forEach(function (id) {
        var c = document.getElementById(id);
        if (!c || !c.parentElement) return;
        c.parentElement.classList.add('rp-chart-off');
        c.parentElement.innerHTML =
            '<div class="rp-empty">Gráficos indisponíveis: a biblioteca de gráficos '
          + 'não carregou. Se o frontend está atrás de um proxy que bloqueia arquivos '
          + '.js, ligue <code>PLANTONISTAS_CHART_INLINE=1</code> no PHP-FPM (ver README).</div>';
    });
    // O botão "mudar formato" fica no cabeçalho do card, que é IRMÃO do corpo
    // substituído acima — sem isto ele sobrevive ao lado da mensagem de erro
    // como um botão que não faz nada (o toggle tem guarda e não quebra, mas
    // confunde).
    document.querySelectorAll('.rp-chart-btn').forEach(function (b) { b.hidden = true; });
}

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
    // Cores reais = config.severity_color_0..5 (Administração > Geral >
    // Opções de exibição de acionadores), vindas de SEV_COLORS — ver
    // TurnosReportBase::querySeverities().
    const sevBgColors = SEV_COLORS;
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
        // Chave em Y-m-d montada com os componentes LOCAIS. toISOString()
        // converte pra UTC: em UTC-3, das 21h em diante ele devolve a data de
        // amanhã, e o heatmap inteiro (contagem, célula de hoje, link do
        // relatório) deslizava um dia à noite — justamente no turno da noite.
        const key = d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
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

// ── Editor rico + menções @usuário / _h host / _hg grupo de hosts ──
const editor   = document.getElementById('noteText');
const dropdown = document.getElementById('mentionDropdown');
let mentionState = null; // {type, range} da menção em edição no momento
// _hg tem que vir ANTES de _h na alternância: os dois começam com "_h" e a
// 1ª alternativa que bater na posição vence. Ambíguo só em "_hg<texto>" logo
// em seguida (sem espaço) — resolve como hostgroup, não host+query "g...".
// Ver CLAUDE.md, seção "Editor rico + menções".
const MENTION_TRIGGER_TYPE = { '_hg': 'hostgroup', '_h': 'host', '@': 'user' };

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : s;
    return d.innerHTML;
}

let mentionResults = [];  // resultados na tela agora
let mentionActive  = -1;  // índice do item sob navegação por teclado

const MENTION_META = {
    user:      { icon: 'fa-user',        title: 'Usuários' },
    host:      { icon: 'fa-server',      title: 'Hosts' },
    hostgroup: { icon: 'fa-layer-group', title: 'Grupos de hosts' }
};

function closeMentionDropdown() {
    if (!dropdown) return;
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
    dropdown.classList.remove('rp-flip-up');
    mentionState   = null;
    mentionResults = [];
    mentionActive  = -1;
}

// O dropdown é position:fixed (o .rp-card tem overflow:hidden e recortava a
// lista em absolute), então aqui as coordenadas são de VIEWPORT — sem
// subtrair o offset do wrapper. Inverte pra cima se não couber embaixo e
// segura nas bordas laterais pra não vazar da janela.
function positionDropdown() {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || !editor) return;

    let rect = sel.getRangeAt(0).getBoundingClientRect();
    // Caret colapsado no começo de uma linha pode devolver rect zerado —
    // aí o editor serve de âncora.
    if (!rect || (rect.top === 0 && rect.left === 0 && rect.width === 0)) {
        rect = editor.getBoundingClientRect();
    }

    const gap  = 6;
    const dw   = dropdown.offsetWidth  || 260;
    const dh   = dropdown.offsetHeight || 300;
    const vw   = window.innerWidth;
    const vh   = window.innerHeight;

    const spaceBelow = vh - rect.bottom;
    const flipUp     = spaceBelow < dh + gap && rect.top > spaceBelow;

    dropdown.classList.toggle('rp-flip-up', flipUp);
    dropdown.style.top  = (flipUp ? Math.max(gap, rect.top - dh - gap)
                                  : rect.bottom + gap) + 'px';
    dropdown.style.left = Math.min(Math.max(gap, rect.left), vw - dw - gap) + 'px';
}

function setMentionActive(idx) {
    const opts = dropdown.querySelectorAll('.rp-mention-opt');
    if (!opts.length) return;
    // Circular: passar do fim volta ao começo, e vice-versa.
    mentionActive = (idx + opts.length) % opts.length;
    opts.forEach(function (el, i) {
        el.classList.toggle('rp-mention-active', i === mentionActive);
    });
    const el = opts[mentionActive];
    if (el && el.scrollIntoView) {
        el.scrollIntoView({ block: 'nearest' });
    }
}

let mentionDebounce = null;
function searchMentions(type, q) {
    clearTimeout(mentionDebounce);
    mentionDebounce = setTimeout(function () {
        fetch('zabbix.php?action=plantonistas.report.mentions.search&type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (j) { renderMentionResults(type, j.results || []); })
            .catch(function () { closeMentionDropdown(); });
    }, 150);
}

function renderMentionResults(type, results) {
    if (!mentionState) return;

    const meta = MENTION_META[type] || MENTION_META.user;
    mentionResults = results;
    mentionActive  = -1;

    if (!results.length) {
        // Query com espaço e zero resultado = a pessoa provavelmente parou de
        // procurar alguém e voltou a escrever a nota. Fecha em vez de deixar
        // "Nenhum resultado" pendurado no meio do texto. Sem espaço, mantém a
        // mensagem: ainda é uma busca em andamento.
        if (mentionState.query && mentionState.query.indexOf(' ') !== -1) {
            closeMentionDropdown();
            return;
        }
        dropdown.innerHTML = '<div class="rp-mention-empty">Nenhum resultado.</div>';
        dropdown.style.display = 'block';
        positionDropdown();
        return;
    }

    const head = '<div class="rp-mention-head">'
        + '<i class="fas ' + meta.icon + '"></i> ' + meta.title
        + '<span class="rp-mention-count">' + results.length + '</span>'
        + '</div>';

    const opts = results.map(function (r, i) {
        // Label vazio não deveria mais chegar aqui (o backend cai no username),
        // mas se chegar, mostra algo em vez de uma linha em branco clicável.
        const label = (r.label && r.label.trim() !== '') ? r.label : '(sem nome)';
        const sub   = r.sub ? '<span class="rp-mention-sub">' + escHtml(r.sub) + '</span>' : '';
        return '<div class="rp-mention-opt" data-idx="' + i + '">'
            + '<span class="rp-mention-ico"><i class="fas ' + meta.icon + '"></i></span>'
            + '<span class="rp-mention-txt">'
            + '<span class="rp-mention-label">' + escHtml(label) + '</span>' + sub
            + '</span></div>';
    }).join('');

    const foot = '<div class="rp-mention-foot">'
        + '<kbd>&uarr;</kbd> <kbd>&darr;</kbd> navegar &middot; '
        + '<kbd>Enter</kbd> inserir &middot; <kbd>Esc</kbd> fechar</div>';

    dropdown.innerHTML = head + opts + foot;
    dropdown.style.display = 'block';
    dropdown.scrollTop = 0;
    positionDropdown();

    dropdown.querySelectorAll('.rp-mention-opt').forEach(function (el, i) {
        el.addEventListener('mousedown', function (e) {
            e.preventDefault(); // não perder a seleção do editor antes de inserir
            insertMention(type, results[i]);
        });
        el.addEventListener('mouseenter', function () { setMentionActive(i); });
    });
}

function insertMention(type, item) {
    if (!mentionState) return;
    const range = mentionState.range;
    range.deleteContents();

    let node;
    if (type === 'user') {
        node = document.createElement('span');
        node.className = 'rp-mention-chip rp-mention-user';
        node.setAttribute('data-mention-userid', item.id);
        node.setAttribute('data-mention-name', item.label);
        node.textContent = '@' + item.label;
    } else {
        node = document.createElement('a');
        node.className = 'rp-mention-chip rp-mention-link';
        node.setAttribute('href', item.url);
        node.textContent = item.label;
    }
    node.setAttribute('contenteditable', 'false');
    range.insertNode(node);

    const space = document.createTextNode(' ');
    node.after(space);
    const newRange = document.createRange();
    newRange.setStartAfter(space);
    newRange.collapse(true);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(newRange);

    closeMentionDropdown();
    editor.focus();
}

function getCaretTextBeforeCursor() {
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return '';
    const range = sel.getRangeAt(0).cloneRange();
    range.collapse(true);
    range.setStart(editor, 0);
    return range.toString();
}

if (editor) {
    editor.addEventListener('input', function () {
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0 || !sel.isCollapsed) { closeMentionDropdown(); return; }

        const textBefore = getCaretTextBeforeCursor();
        // Gatilho só conta no início do texto ou depois de espaço — não dispara
        // no meio de uma palavra (ex.: "user@dominio.com" digitado como texto solto).
        //
        // A busca aceita ATÉ 5 PALAVRAS (4 espaços) porque ninguém procura
        // colega pelo username: procura por "Erica Felix de Oliveira". Parar no
        // primeiro espaço, como antes, tornava impossível buscar por nome
        // completo — e parar cedo demais é pior que não parar, porque a lista
        // fecharia na cara de quem digitou o nome inteiro. O que segura o
        // dropdown de perseguir uma frase é renderMentionResults(): busca com
        // espaço e zero resultado fecha a lista, sinal de que virou texto comum.
        const m = textBefore.match(/(?<=^|\s)(_hg|_h|@)([^\s]{0,40}(?:[ ][^\s]{0,40}){0,4})$/);
        if (!m) { closeMentionDropdown(); return; }

        const type = MENTION_TRIGGER_TYPE[m[1]];
        const query = m[2];
        const matchLen = m[0].length;
        const range = sel.getRangeAt(0).cloneRange();
        if (range.endOffset - matchLen < 0) { closeMentionDropdown(); return; }
        range.setStart(range.endContainer, range.endOffset - matchLen);

        mentionState = { type: type, range: range, query: query };
        searchMentions(type, query);
    });

    editor.addEventListener('keydown', function (e) {
        const open = dropdown && dropdown.style.display !== 'none';

        if (e.key === 'Escape') { closeMentionDropdown(); return; }
        if (!open || !mentionResults.length) return;

        // Com a lista aberta, as setas navegam o dropdown em vez de mover o
        // caret; Enter/Tab inserem o item destacado. Sem item destacado,
        // Enter cai no comportamento normal do editor (quebra de linha).
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setMentionActive(mentionActive + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setMentionActive(mentionActive - 1);
        } else if ((e.key === 'Enter' || e.key === 'Tab') && mentionActive >= 0) {
            e.preventDefault();
            insertMention(mentionState.type, mentionResults[mentionActive]);
        }
    });

    document.addEventListener('click', function (e) {
        if (dropdown && dropdown.style.display !== 'none' && !dropdown.contains(e.target) && e.target !== editor) {
            closeMentionDropdown();
        }
    });

    // position:fixed não acompanha scroll: sem isto o dropdown ficaria
    // parado enquanto o texto se move. capture=true pega também o scroll
    // de containers internos do Zabbix, não só o da janela.
    window.addEventListener('scroll', function () {
        if (dropdown && dropdown.style.display !== 'none') positionDropdown();
    }, true);
    window.addEventListener('resize', function () {
        if (dropdown && dropdown.style.display !== 'none') positionDropdown();
    });

    // ── Imagem e anexo: bloqueio explícito ──────────────────────────────
    //
    // Decisão: o Diário de Bordo é texto formatado + menções, sem anexo. Sem
    // este bloqueio o comportamento fica INDEFINIDO em vez de proibido: colar
    // um print insere um <img src="data:image/png;base64,..."> de centenas de
    // KB no contenteditable, o usuário vê a imagem na tela, salva — e a
    // sanitização do servidor descarta a tag (img não está na allowlist).
    // A nota volta sem a imagem, sem nenhuma mensagem, com cara de bug.
    //
    // Colar texto formatado de outro lugar continua funcionando; o que é
    // barrado é só arquivo e imagem.
    function avisarSemAnexo() {
        const st = document.getElementById('noteSaveStatus');
        if (!st) return;
        st.style.color = 'var(--sev-disaster)';
        st.textContent = 'O Diário de Bordo aceita só texto e menções — imagem e anexo não são gravados.';
        setTimeout(function () {
            if (st.textContent.indexOf('imagem e anexo') !== -1) {
                st.textContent = '';
                st.style.color = '';   // senão a próxima mensagem herda o vermelho
            }
        }, 6000);
    }

    // Só é anexo o que NÃO traz representação de texto junto.
    //
    // Esta guarda de texto é a parte que importa: copiar um trecho de planilha
    // (Excel, Google Sheets, tabela do próprio Zabbix) coloca no clipboard
    // text/plain, text/html E um bitmap image/png ao mesmo tempo. Olhando só
    // para "tem item image/*", a colagem de TEXTO seria descartada — que é
    // justamente o uso mais comum num repasse de NOC.
    function temArquivo(dt) {
        if (!dt) return false;

        var tipos = Array.prototype.slice.call(dt.types || []);
        if (tipos.indexOf('text/plain') !== -1 || tipos.indexOf('text/html') !== -1) {
            return false;
        }
        // 'Files' em `types` é o sinal confiável no dragover, onde `files`
        // ainda está vazio por decisão de segurança do navegador.
        if (tipos.indexOf('Files') !== -1) return true;
        if (dt.files && dt.files.length) return true;

        return Array.prototype.some.call(dt.items || [], function (it) {
            return it.kind === 'file';
        });
    }

    editor.addEventListener('paste', function (e) {
        if (temArquivo(e.clipboardData)) {
            e.preventDefault();
            avisarSemAnexo();
        }
    });

    // `dragover` precisa do preventDefault também: sem ele o navegador não
    // considera o editor um alvo válido e abre o arquivo solto na aba,
    // perdendo a nota que estava sendo escrita.
    editor.addEventListener('dragover', function (e) {
        if (temArquivo(e.dataTransfer)) e.preventDefault();
    });
    editor.addEventListener('drop', function (e) {
        if (temArquivo(e.dataTransfer)) {
            e.preventDefault();
            avisarSemAnexo();
        }
    });

    document.querySelectorAll('.rp-editor-btn').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); }); // mantém a seleção do editor
        btn.addEventListener('click', function () {
            const cmd = btn.dataset.cmd;
            if (cmd === 'createLink') {
                const url = prompt('URL do link:');
                if (url) document.execCommand('createLink', false, url);
            } else {
                document.execCommand(cmd, false, null);
            }
            editor.focus();
        });
    });
}

// ── Note Form ──
const noteForm = document.getElementById('turnosNoteForm');
if (noteForm && editor) {
    noteForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const st = document.getElementById('noteSaveStatus');
        if (!editor.textContent.trim()) { st.textContent='Escreva algo antes de salvar.'; st.style.color='#c62828'; return; }
        closeMentionDropdown();
        const noteHtml = editor.innerHTML;
        st.textContent='Salvando...'; st.style.color='#666';
        fetch('zabbix.php?action=plantonistas.report.notes.save', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:new URLSearchParams({note:noteHtml,shift:NOTE_SHIFT,shift_date:NOTE_DATE,_csrf_token:CSRF_NOTES_SAVE})
        }).then(function(r){
            // Falha de CSRF (sessão expirada com a aba aberta o turno inteiro,
            // caso nada raro nesta tela) responde HTML de "Acesso negado", não
            // JSON — sem esta guarda o usuário só veria "Erro de conexão".
            const ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('json') === -1) {
                return { success:false,
                    message:'Sessão expirada ou acesso negado. Copie o texto, recarregue a página (F5) e cole de novo.' };
            }
            return r.json();
        }).then(j=>{
            if(j.success){
                st.textContent=j.message; st.style.color='#2e7d32'; editor.innerHTML='';
                let list = document.querySelector('.rp-notes-list');
                if (!list) {
                    list = document.createElement('div'); list.className='rp-notes-list';
                    list.innerHTML='<div class="rp-notes-title">Notas Anteriores</div>';
                    noteForm.insertAdjacentElement('afterend', list);
                }
                const d = new Date(), t = String(d.getDate()).padStart(2,'0')+'/'+String(d.getMonth()+1).padStart(2,'0')+'/'+d.getFullYear()+' '+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
                const item = document.createElement('div'); item.className='rp-note-item';
                item.innerHTML=`<div class="rp-note-header"><strong>${CURRENT_FULLNAME}</strong><span class="rp-note-time">${t}</span></div><div class="rp-note-content">${noteHtml}</div>`;
                if(list.children.length>1) { list.insertBefore(item, list.children[1]); } else { list.appendChild(item); }
                setTimeout(() => { st.textContent=''; }, 3000);
            }
            else { st.textContent=j.message||'Erro.'; st.style.color='#c62828'; }
        }).catch(()=>{st.textContent='Erro de conexão.';st.style.color='#c62828';});
    });
}

// ── Fechar turno (issue #2) ──
const closeBtn = document.getElementById('rpCloseShiftBtn');
if (closeBtn) {
    closeBtn.addEventListener('click', function () {
        const aviso = ALREADY_CLOSED
            ? 'Este turno JÁ foi fechado. Fechar de novo cria um segundo documento'
              + ' (o anterior é mantido) e é restrito a Admin/Super Admin.\n\nContinuar?'
            : 'Fechar o turno congela os números atuais num documento imutável,'
              + ' que continua valendo mesmo depois de o Zabbix apagar os eventos'
              + ' do período.\n\nContinuar?';
        if (!confirm(aviso)) return;

        closeBtn.disabled = true;   // anti duplo clique: dois cliques criariam
        closeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fechando...'; // dois documentos

        fetch('zabbix.php?action=plantonistas.report.close', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams({
                date: NOTE_DATE, shift: NOTE_SHIFT, limit: REPORT_LIMIT,
                _csrf_token: CSRF_REPORT_CLOSE
            })
        }).then(function (r) {
            // Falha de CSRF responde HTML, não JSON — mesma guarda do save da nota.
            const ct = r.headers.get('content-type') || '';
            if (!r.ok || ct.indexOf('json') === -1) {
                return { success: false,
                    message: 'Sessão expirada ou acesso negado. Recarregue a página (F5).' };
            }
            return r.json();
        }).then(function (j) {
            alert(j.message || (j.success ? 'Turno fechado.' : 'Não foi possível fechar o turno.'));
            if (j.success) {
                location.reload();   // recarrega para a faixa de "turno fechado" aparecer
                return;
            }
            closeBtn.disabled = false;
            closeBtn.innerHTML = '<i class="fas fa-lock"></i> Fechar turno';
        }).catch(function () {
            alert('Erro de conexão ao fechar o turno.');
            closeBtn.disabled = false;
            closeBtn.innerHTML = '<i class="fas fa-lock"></i> Fechar turno';
        });
    });
}

// ── Banner de menções pendentes ──
document.querySelectorAll('.rp-mention-dismiss').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const id = btn.dataset.mentionId;
        const item = btn.closest('.rp-mention-item');
        fetch('zabbix.php?action=plantonistas.report.mentions.read', {
            method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams({mention_id: id, _csrf_token: CSRF_MENTIONS_READ})
        }).catch(function(){});
        if (item) item.remove();
    });
});
document.querySelectorAll('.rp-mention-view-link').forEach(function (a) {
    a.addEventListener('click', function () {
        const id = a.dataset.mentionId;
        // sendBeacon com URLSearchParams envia form-urlencoded, então o token
        // chega no $_POST igual ao do fetch.
        const body = new URLSearchParams({mention_id: id, _csrf_token: CSRF_MENTIONS_READ});
        if (navigator.sendBeacon) {
            navigator.sendBeacon('zabbix.php?action=plantonistas.report.mentions.read', body);
        } else {
            fetch('zabbix.php?action=plantonistas.report.mentions.read', {
                method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body: body
            }).catch(function(){});
        }
        // sem preventDefault — deixa o link navegar normalmente pra tela da nota
    });
});

// ── Histórico de menções ──
//
// Painel, não tela nova: quem está lendo o repasse não deveria sair dele para
// achar uma menção. O botão do cabeçalho abre e fecha.
function rpToggleMentions() {
    const painel = document.getElementById('mentionHistory');
    if (!painel) return;
    painel.hidden = !painel.hidden;
    if (!painel.hidden) painel.scrollIntoView({behavior: 'smooth', block: 'nearest'});
}
</script>
