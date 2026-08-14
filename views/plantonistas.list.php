<?php declare(strict_types = 1);
/**
 * @var array $data
 */

$month         = $data['month'];
$year          = $data['year'];
$day_map       = $data['day_map'];   // [data => [shift_id => entrada]]
$users         = $data['users'];
$shifts        = $data['shifts'];    // [shift_id => ['id','name','start_time','end_time']]
$days_in_month = $data['days_in_month'];
$groups        = $data['groups'];
$usrgrpid      = $data['usrgrpid'];
$today_str     = date('Y-m-d');
$has_shifts    = !empty($shifts);

// Pré-computa os JSONs usados no <script> — mais seguro/legível que montar
// expressões PHP aninhadas dentro do bloco JS.
$shift_ids_json   = json_encode(array_map('strval', array_keys($shifts)));
$shift_labels_map = [];
foreach ($shifts as $sid => $s) {
    $shift_labels_map[(string)$sid] =
        $s['name'] . ' (' . substr($s['start_time'],0,5) . '–' . substr($s['end_time'],0,5) . ')';
}
$shift_labels_json = json_encode((object)$shift_labels_map);

$month_names = [
    1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
    7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro',
];
$weekday_names = ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'];

$prev_m = $month-1; $prev_y = $year;
if ($prev_m<1)  { $prev_m=12; $prev_y--; }
$next_m = $month+1; $next_y = $year;
if ($next_m>12) { $next_m=1;  $next_y++; }

$base  = '&usrgrpid='.$usrgrpid;
$purl  = 'zabbix.php?action=plantonistas.list&month='.$prev_m.'&year='.$prev_y.$base;
$nurl  = 'zabbix.php?action=plantonistas.list&month='.$next_m.'&year='.$next_y.$base;
$eurl  = 'zabbix.php?action=plantonistas.export&month='.$month.'&year='.$year.'&usrgrpid='.$usrgrpid;

$fw    = (int)(new DateTime(sprintf('%04d-%02d-01',$year,$month)))->format('N') - 1;

// Conta dias sem cobertura no mês inteiro (passados, hoje e futuros).
// "Sem cobertura" = nenhuma entrada naquele dia (nem legado, nem turno algum).
$days_uncovered = 0;
for ($d=1;$d<=$days_in_month;$d++) {
    $ds = sprintf('%04d-%02d-%02d',$year,$month,$d);
    if (empty($day_map[$ds])) $days_uncovered++;
}

ob_start(); ?>
<style>
/* ── Abas ─────────────────────────────────── */
.plt-tabs { display:flex;gap:4px;margin-bottom:18px;flex-wrap:wrap;
    border-bottom:1px solid #3a3a3a;padding-bottom:2px; }
.plt-tab  { padding:6px 16px;border-radius:3px 3px 0 0;cursor:pointer;
    font-size:13px;font-weight:600;text-decoration:none;
    background:#262626;color:#7a9ab4;
    border:1px solid #3a3a3a;border-bottom:none;
    position:relative;bottom:-1px;transition:background .12s; }
.plt-tab:hover  { background:#323232;color:#c8d8e4; }
.plt-tab.active { background:#1e1e1e;color:#59db8f;border-bottom:1px solid #1e1e1e; }

/* ── Nav ──────────────────────────────────── */
.plt-wrap { padding:0 0 20px;color:#f2f2f2; }
.plt-wrap output { margin:0 0 14px; }
.plt-nav  { display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap; }
.plt-nav .plt-month-label { font-size:15px;font-weight:600;flex:1;color:#c8d8e4; }
.plt-uncov-warning {
    display:inline-flex;align-items:center;gap:6px;
    background:#2a1a00;border:1px solid #7a4400;border-radius:3px;
    padding:5px 12px;font-size:12px;color:#e99003;
}

/* ── Calendário ───────────────────────────── */
.plt-cal { width:100%;border-collapse:collapse;table-layout:fixed; }
.plt-cal th { background:#303030;color:#b0c4d4;text-align:center;
    padding:8px 4px;font-size:12px;font-weight:600;border:1px solid #4f4f4f; }
.plt-cal td { border:1px solid #4f4f4f;vertical-align:top;padding:0;
    cursor:pointer;background:#2b2b2b;position:relative;
    min-height:110px;outline:none;transition:background .1s,border-color .1s;
    user-select:none; }
.plt-cell-inner { padding:7px 8px; }
.plt-cal td:hover     { background:#383838; }
.plt-cal td.empty     { background:#222;cursor:default; }
.plt-cal td.today     { background:#2e2200;border-color:#e99003; }
.plt-cal td.today:hover { background:#3a2b00; }
.plt-cal td.has-tech  { background:#162616; }
.plt-cal td.has-tech:hover { background:#1e3a1e; }
.plt-cal td.past      { opacity:.6; }
/* Sem cobertura (passado/hoje sem técnico) */
.plt-cal td.no-cover  { background:#241410 !important; }
.plt-cal td.no-cover:hover { background:#2e1a14 !important; }
.plt-no-cov-icon { position:absolute;top:5px;right:6px;font-size:13px;color:#7a4400; }
/* Selecionado */
.plt-cal td.selected  { background:#1a2d4a !important;border-color:#4a82c4 !important; }
.plt-cal td.selected:hover { background:#213660 !important; }
.plt-sel-check { position:absolute;top:4px;right:6px;
    font-size:14px;color:#4a82c4;display:none;line-height:1; }
.plt-cal td.selected .plt-sel-check { display:block; }
.plt-cal td.selected .plt-no-cov-icon { display:none; }

/* Conteúdo célula */
.plt-day-num   { font-size:13px;font-weight:700;color:#c8d8e4;padding-right:18px; }
.plt-today-badge { background:#e99003;color:#1a1000;border-radius:3px;
    padding:0 5px;font-size:10px;margin-left:5px;font-weight:700;vertical-align:middle; }
.plt-entry     { margin-top:6px;padding-top:6px; }
.plt-entry:first-child { margin-top:4px;padding-top:0;border-top:none; }
.plt-entry + .plt-entry { border-top:1px dashed #3f3f3f; }
.plt-shift-label { font-size:9px;color:#7a9ab4;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;margin-bottom:1px; }
.plt-tech      { font-size:11px;color:#59db8f;font-weight:600;margin-top:2px; }
.plt-phone     { font-size:10px;color:#8faabc;margin-top:2px; }
.plt-no-phone  { font-size:10px;color:#555;font-style:italic;margin-top:2px; }
.plt-res-label { font-size:9px;color:#666;margin-top:5px;
    text-transform:uppercase;letter-spacing:.4px; }
.plt-res-name  { font-size:11px;color:#59db8f;font-weight:600;margin-top:1px; }
.plt-res-phone { font-size:10px;color:#8faabc;margin-top:1px; }
.plt-del { display:inline-block;margin-top:4px;font-size:10px;
    color:#e45959;text-decoration:none; }
.plt-del:hover { text-decoration:underline;color:#ff7070; }

/* ── Tooltip ──────────────────────────────── */
#plt-tooltip {
    display:none;position:fixed;z-index:2000;
    background:#1e1e1e;border:1px solid #5a5a5a;border-radius:4px;
    padding:10px 14px;font-size:12px;color:#f0f0f0;
    max-width:260px;pointer-events:none;box-shadow:0 4px 16px rgba(0,0,0,.5);
    line-height:1.6;
}
#plt-tooltip .tt-name  { font-size:14px;font-weight:700;color:#59db8f;margin-bottom:3px; }
#plt-tooltip .tt-phone { color:#8faabc; }
#plt-tooltip .tt-sep   { border-top:1px solid #3a3a3a;margin:7px 0; }
#plt-tooltip .tt-label { font-size:10px;color:#666;text-transform:uppercase;
    letter-spacing:.4px;margin-bottom:2px; }
#plt-tooltip .tt-by    { font-size:11px;color:#666;margin-top:5px; }
#plt-tooltip .tt-warn  { color:#e99003;font-style:italic; }

/* ── Formulário ───────────────────────────── */
.plt-form-panel { margin-top:18px;background:#303030;
    border:1px solid #4f4f4f;border-radius:4px;padding:18px 22px; }
.plt-form-panel h3 { margin:0 0 14px;font-size:14px;font-weight:600;color:#f2f2f2; }
.plt-sel-bar {
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;
    padding:10px 14px;border-radius:3px;background:#252525;border:1px solid #404040;
}
.plt-sel-count { font-size:13px;font-weight:600;color:#c8d8e4; }
.plt-sel-count.active { color:#4a82c4; }
.plt-sel-dates { font-size:12px;color:#8faabc;flex:1; }
.plt-sel-clear { font-size:11px;color:#e45959;cursor:pointer;border:none;
    background:none;padding:2px 6px;border-radius:2px; }
.plt-sel-clear:hover { background:#3a2020; }
.plt-form-row { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px; }
.plt-form-row label { font-weight:600;font-size:13px;color:#9ab;white-space:nowrap; }
.plt-select-row { display:flex;align-items:stretch;flex:0 1 300px;min-width:220px; }
.plt-select-row .multiselect { flex:1;min-width:0;margin-right:0 !important;
    border-right:0 !important;border-top-right-radius:0 !important;border-bottom-right-radius:0 !important; }
.plt-select-row .btn { border-top-left-radius:0;border-bottom-left-radius:0;
    white-space:nowrap;flex-shrink:0;align-self:stretch; }
.plt-mswrap { flex:1;min-width:0;position:relative;margin-right:0 !important; }
.plt-ac-dd { display:none;position:absolute;z-index:1100;top:100%;left:0;right:0;
    background:#2b2b2b;border:1px solid #4f4f4f;border-top:none;
    max-height:220px;overflow-y:auto; }
.plt-ac-dd.open { display:block; }
.plt-ac-item { padding:7px 10px;font-size:13px;color:#f2f2f2;cursor:pointer;
    border-bottom:1px solid #383838; }
.plt-ac-item:last-child { border-bottom:none; }
.plt-ac-item:hover { background:#505050; }
.plt-ac-item mark { background:none;color:#e99003;font-weight:700; }
output .btn-overlay-close { position:absolute;top:6px;right:6px; }
#plt-modal { z-index:1001 !important; }

/* Campos de turno (um por turno cadastrado no grupo) */
.plt-shift-fields { display:flex;flex-wrap:wrap;gap:14px 18px;align-items:flex-end;margin-bottom:4px; }
.plt-shift-field  { display:flex;flex-direction:column;gap:5px; }
.plt-shift-field label { font-weight:600;font-size:13px;color:#9ab;white-space:nowrap; }
.plt-shift-time    { font-weight:400;color:#666;font-size:11px;margin-left:3px; }
.plt-shift-empty-hint { font-size:12px;color:#8faabc;margin-bottom:10px; }
/* .plt-select-row usa "flex:0 1 300px" pensado pra dentro de um flex ROW
   (.plt-form-row, modo legado) — ali o 300px vira largura. Dentro de
   .plt-shift-field, que é flex COLUMN, o mesmo valor vira ALTURA e o campo
   fica gigante (300px de altura). Reseta o eixo aqui: largura fixa via
   width, sem flex-basis herdado. */
.plt-shift-field .plt-select-row { flex:0 0 auto;width:300px; }

/* Mensagens sucesso e erro — texto sempre preto, fundo contrastante */
output.msg-good {
    background: #d4edda !important;
    border-left: 4px solid #28a745 !important;
    color: #000000 !important;
}
output.msg-good span,
output.msg-good * { color: #000000 !important; }

output.msg-bad {
    background: #f8d7da !important;
    border-left: 4px solid #dc3545 !important;
    color: #000000 !important;
}
output.msg-bad span,
output.msg-bad * { color: #000000 !important; }

/* Botão CSV — mesma cor dos demais btn-alt */
.plt-nav a.btn.btn-alt {
    color: #0275b8 !important;
    background-color: transparent !important;
    border-color: #0275b8 !important;
}
.plt-nav a.btn.btn-alt:hover {
    background-color: rgba(2, 117, 184, 0.08) !important;
}
</style>

<!-- Tooltip global -->
<div id="plt-tooltip"></div>

<!-- Backdrop -->
<div id="plt-modal-bg" class="overlay-bg" style="display:none;" onclick="pltCloseModal()"></div>

<!-- Modal seleção técnico -->
<div id="plt-modal" class="overlay-dialogue modal modal-popup modal-popup-medium"
     style="display:none;top:80px;left:50%;transform:translateX(-50%);" role="dialog">
    <div class="overlay-dialogue-header">
        <h4 id="plt-modal-title">Selecionar Técnico</h4>
        <button class="btn-overlay-close" onclick="pltCloseModal()"></button>
    </div>
    <div class="overlay-dialogue-body">
        <!-- Filtro no modal -->
        <div style="padding:0 0 10px;">
            <input type="text" id="plt-modal-search" placeholder="Filtrar por nome..."
                   style="width:100%;box-sizing:border-box;"
                   oninput="pltModalFilter(this.value)">
        </div>
        <table class="list-table" id="plt-modal-table">
            <thead><tr><th>Nome</th><th>Usuário</th><th>Telefone</th></tr></thead>
            <tbody id="plt-modal-tbody">
            <?php foreach ($users as $u):
                $np  = trim($u['name'].' '.$u['surname']);
                $lbl = $np !== '' ? $np : $u['username'];
                $disp = $lbl.($u['phone'] ? ' ('.$u['phone'].')' : '');
            ?>
            <tr data-name="<?= htmlspecialchars(strtolower($lbl)) ?>">
                <td>
                    <a href="javascript:void(0)"
                       onclick="pltPickUser(<?=(int)$u['userid']?>,<?=htmlspecialchars(json_encode($disp))?>)">
                        <?= htmlspecialchars($lbl) ?>
                        <?php if ($np===''): ?>
                            <span style="color:#666;font-size:10px;"> (sem nome)</span>
                        <?php endif; ?>
                    </a>
                </td>
                <td style="color:#8faabc;"><?= htmlspecialchars($u['username']) ?></td>
                <td>
                    <?php if ($u['phone']): ?>
                        <?= htmlspecialchars($u['phone']) ?>
                    <?php else: ?>
                        <span style="color:#666;font-style:italic">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="overlay-dialogue-footer">
        <button type="button" class="btn-alt" onclick="pltCloseModal()">Cancelar</button>
    </div>
</div>

<!-- Modal importação CSV/XLSX -->
<div id="plt-import-modal" class="overlay-dialogue modal modal-popup modal-popup-medium"
     style="display:none;top:80px;left:50%;transform:translateX(-50%);" role="dialog">
    <div class="overlay-dialogue-header">
        <h4>Importar Escala</h4>
        <button class="btn-overlay-close" onclick="pltCloseImport()"></button>
    </div>
    <div class="overlay-dialogue-body" style="padding:18px 22px;background:#fff;">
        <p style="background:#f0f4f8;border:1px solid #c8d8e4;border-radius:3px;
                  padding:7px 12px;font-size:13px;color:#000;margin:0 0 12px;">
            📋 Importando para o grupo: <strong><?=htmlspecialchars($groups[$usrgrpid]??'')?></strong>
        </p>
        <p style="color:#000;font-size:13px;margin:0 0 10px;">
            Selecione um arquivo <strong>CSV</strong> ou <strong>XLSX</strong> com as colunas:
        </p>
        <p style="font-size:13px;margin:0 0 10px;line-height:2;">
            <span style="color:#3b9c3b;font-weight:600;">data</span><span style="color:#000;"> (dd/mm/aaaa) &nbsp;·&nbsp; </span><span style="color:#3b9c3b;font-weight:600;">plantonista</span><span style="color:#000;"> (username ou nome completo) &nbsp;·&nbsp; </span><span style="color:#3b9c3b;font-weight:600;">reserva</span><span style="color:#000;"> (opcional)</span>
        </p>
        <p style="font-size:12px;color:#888;margin:0 0 16px;">
            💡 Use o botão ↓ CSV para baixar o modelo do mês atual, edite e reimporte.
            <?php if ($has_shifts): ?>
                A importação grava sem vínculo de turno (modo legado) mesmo para
                este grupo — cadastre os turnos manualmente pela tela abaixo.
            <?php endif; ?>
        </p>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <label style="flex:1;min-width:220px;background:#fff;border:1px solid #aaa;border-radius:3px;
                          padding:6px 10px;color:#000;font-size:13px;cursor:pointer;
                          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                   id="plt-import-label">
                <input type="file" id="plt-import-file" accept=".csv,.xlsx,.tsv"
                       style="display:none;" onchange="pltImportFileChosen(this)">
                <span id="plt-import-filename">Escolher arquivo…</span>
            </label>
            <button type="button" class="btn" id="plt-import-btn"
                    onclick="pltSubmitImport()" disabled>Importar</button>
        </div>
        <div id="plt-import-hint" style="margin-top:10px;font-size:12px;min-height:18px;"></div>
    </div>
    <div class="overlay-dialogue-footer">
        <button type="button" class="btn-alt" onclick="pltCloseImport()">Cancelar</button>
    </div>
</div>

<div class="plt-wrap">

<?php if ($data['success']): ?>
<output class="msg-good" role="contentinfo">
    <span><?= htmlspecialchars($data['success']) ?></span>
    <button class="btn-overlay-close" type="button" onclick="this.parentElement.remove()"></button>
</output>
<?php endif; ?>
<?php if ($data['error']): ?>
<output class="msg-bad" role="contentinfo">
    <span><?= htmlspecialchars($data['error']) ?></span>
    <button class="btn-overlay-close" type="button" onclick="this.parentElement.remove()"></button>
</output>
<?php endif; ?>

<?php if (empty($groups)): ?>
    <div style="padding:20px;color:#666;font-style:italic;">Você não pertence a nenhum grupo.</div>
<?php else: ?>

<?php if (count($groups)>1): ?>
<div class="plt-tabs">
    <?php foreach ($groups as $gid=>$gname): ?>
    <a class="plt-tab <?=$gid===$usrgrpid?'active':''?>"
       href="zabbix.php?action=plantonistas.list&month=<?=$month?>&year=<?=$year?>&usrgrpid=<?=$gid?>">
        <?= htmlspecialchars($gname) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div style="margin-bottom:14px;font-size:13px;font-weight:600;color:#c8d8e4;">
    Grupo: <?= htmlspecialchars(reset($groups)) ?>
</div>
<?php endif; ?>

<!-- Nav mensal -->
<div class="plt-nav">
    <button class="btn-icon zi-chevron-left" onclick="location.href='<?=$purl?>'"></button>
    <span class="plt-month-label"><?=$month_names[$month].' '.$year?></span>
    <button class="btn-icon zi-chevron-right" onclick="location.href='<?=$nurl?>'"></button>

    <?php if ($days_uncovered > 0): ?>
    <span class="plt-uncov-warning">
        ⚠ <?=$days_uncovered?> dia<?=$days_uncovered>1?'s':''?> sem cobertura
    </span>
    <?php endif; ?>

    <a href="<?=$eurl?>" class="btn btn-alt" title="Exportar CSV do mês">↓ CSV</a>
    <button class="btn btn-alt" onclick="pltOpenImport()" title="Importar escala via CSV ou XLSX">↑ Importar</button>
    <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.phones.list'">Telefones</button>
    <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.overview'">Visão Geral</button>
    <span style="font-size:11px;color:#555;">Clique para selecionar dias</span>
</div>

<!-- Calendário -->
<table class="plt-cal">
    <thead>
        <tr><?php foreach ($weekday_names as $wn): ?><th><?=$wn?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
<?php
$day=$cell=0;
echo '<tr>';
for ($i=0;$i<$fw;$i++) { echo '<td class="empty"></td>'; $cell++; }
$day=1;

while ($day<=$days_in_month) {
    $ds      = sprintf('%04d-%02d-%02d',$year,$month,$day);
    $is_t    = ($ds===$today_str);
    $is_p    = ($ds<$today_str);
    $entries = $day_map[$ds] ?? [];   // [shift_id => entrada]
    $no_cov  = empty($entries);

    // Payload por célula: tooltip (todas as entradas do dia) + dados pra
    // pré-preencher o formulário quando o dia é selecionado no calendário.
    $tt_items      = [];
    $entry_payload = [];
    foreach ($entries as $sid => $e) {
        $tech_label = trim($e['name'].' '.$e['surname']) ?: $e['username'];
        $res_label  = $e['userid_reserva'] ? (trim($e['reserva_name'].' '.$e['reserva_surname']) ?: '') : '';

        $tt_items[] = [
            'shift'     => $sid > 0 ? $e['shift_name'] : null,
            'tech'      => $tech_label,
            'phone'     => $e['phone'],
            'res'       => $e['userid_reserva'] ? $res_label : null,
            'res_phone' => $e['reserva_phone'],
        ];
        $entry_payload[$sid] = [
            'uid'   => (int)$e['userid'],
            'name'  => $tech_label.($e['phone']?' ('.$e['phone'].')':''),
            'uidR'  => $e['userid_reserva'] ? (int)$e['userid_reserva'] : null,
            'nameR' => $e['userid_reserva']
                ? ($res_label.($e['reserva_phone']?' ('.$e['reserva_phone'].')':''))
                : null,
        ];
    }
    $tt_data = $entries
        ? json_encode(['date'=>date('d/m/Y', strtotime($ds)), 'items'=>$tt_items])
        : null;

    $cls = ['plt-day'];
    if ($is_t)    $cls[]='today';
    if ($is_p)    $cls[]='past';
    if (!$no_cov) $cls[]='has-tech';
    if ($no_cov)  $cls[]='no-cover';
    $cls_str = ' class="'.implode(' ',$cls).'"';

    echo '<td'.$cls_str
        .' data-date="'.$ds.'"'
        .' data-tt="'.($tt_data !== null ? htmlspecialchars($tt_data, ENT_QUOTES) : '').'"'
        .' data-entries="'.htmlspecialchars(json_encode($entry_payload), ENT_QUOTES).'"'
        .' onmouseenter="pltShowTip(event,this)"'
        .' onmouseleave="pltHideTip()"'
        .' onclick="pltToggleDay(this)">';
    echo '<div class="plt-cell-inner">';
    echo '<span class="plt-sel-check">✓</span>';
    if ($no_cov) echo '<span class="plt-no-cov-icon" title="Sem cobertura">⚠</span>';
    echo '<div class="plt-day-num">'.$day;
    if ($is_t) echo '<span class="plt-today-badge">Hoje</span>';
    echo '</div>';

    foreach ($entries as $sid => $e) {
        $tech_label = trim($e['name'].' '.$e['surname']) ?: $e['username'];
        echo '<div class="plt-entry">';
        if ($sid > 0) {
            echo '<div class="plt-shift-label">'.htmlspecialchars($e['shift_name'] ?: 'Turno removido').'</div>';
        }
        echo '<div class="plt-tech">'.htmlspecialchars($tech_label).'</div>';
        if ($e['phone']) {
            echo '<div class="plt-phone">'.htmlspecialchars($e['phone']).'</div>';
        } else {
            echo '<div class="plt-no-phone">sem telefone</div>';
        }
        if ($e['userid_reserva']) {
            $res_label = trim($e['reserva_name'].' '.$e['reserva_surname']) ?: '';
            echo '<div class="plt-res-label">Reserva</div>';
            echo '<div class="plt-res-name">'.htmlspecialchars($res_label).'</div>';
            if ($e['reserva_phone'])
                echo '<div class="plt-res-phone">'.htmlspecialchars($e['reserva_phone']).'</div>';
        }
        $del = 'zabbix.php?action=plantonistas.delete&scheduleid='.(int)$e['scheduleid']
             .'&month='.$month.'&year='.$year.'&usrgrpid='.$usrgrpid;
        $is_today_entry = ($ds === $today_str);
        if ($is_today_entry) {
            echo '<span class="plt-del" style="color:#666;cursor:not-allowed;" '
               . 'title="Não é possível remover o plantão do dia atual">remover</span>';
        } else {
            echo '<a class="plt-del" href="'.$del.'" tabindex="-1"'
               .' onclick="event.stopPropagation();return confirm(\'Remover plantão de '
               .addslashes(htmlspecialchars($tech_label)).' deste dia?\')"'
               .'>remover</a>';
        }
        echo '</div>'; // /plt-entry
    }
    echo '</div></td>';
    $cell++; $day++;
    if ($cell%7===0 && $day<=$days_in_month) echo '</tr><tr>';
}
$rem=$cell%7;
if ($rem>0) for ($i=0;$i<(7-$rem);$i++) echo '<td class="empty"></td>';
echo '</tr>';
?>
    </tbody>
</table>

<!-- Formulário -->
<div class="plt-form-panel">
    <h3>Escalar Técnico &mdash;
        <span style="color:#8faabc;font-weight:400"><?=htmlspecialchars($groups[$usrgrpid]??'')?></span>
    </h3>
    <div class="plt-sel-bar">
        <span class="plt-sel-count" id="plt-sel-count">Nenhum dia selecionado</span>
        <span class="plt-sel-dates" id="plt-sel-dates"></span>
        <button type="button" class="plt-sel-clear" id="plt-sel-clear"
                onclick="pltClearSelection()" style="display:none">Limpar seleção</button>
    </div>
    <form method="post" action="zabbix.php?action=plantonistas.save" id="plt-form"
          onsubmit="return pltValidate()">
        <input type="hidden" name="month"        value="<?=$month?>">
        <input type="hidden" name="year"         value="<?=$year?>">
        <input type="hidden" name="usrgrpid"     value="<?=$usrgrpid?>">
        <input type="hidden" id="schedule_dates" name="schedule_dates" value="">

        <?php if (!$has_shifts): ?>
        <!-- Grupo sem turnos cadastrados em "Gerenciar Turnos" — modo legado -->
        <input type="hidden" id="userid"         name="userid"         value="">
        <input type="hidden" id="userid_reserva" name="userid_reserva" value="">
        <input type="hidden" id="shift_assignments" name="shift_assignments" value="">
        <div class="plt-form-row">
            <label>Técnico:</label>
            <div class="plt-select-row">
                <div class="multiselect plt-mswrap">
                    <input type="text" id="plt-sel-name" placeholder="Nenhum técnico selecionado"
                           autocomplete="off" oninput="pltAcFilter(this.value)">
                    <div class="plt-ac-dd" id="plt-ac-main"></div>
                </div>
                <button type="button" class="btn" onclick="pltOpenModal('main')">Selecionar</button>
            </div>
            <label style="margin-left:14px;">Reserva:</label>
            <div class="plt-select-row">
                <div class="multiselect plt-mswrap">
                    <input type="text" id="plt-sel-res" placeholder="Nenhum técnico selecionado"
                           autocomplete="off" oninput="pltAcFilterRes(this.value)">
                    <div class="plt-ac-dd" id="plt-ac-res"></div>
                </div>
                <button type="button" class="btn" onclick="pltOpenModal('res')">Selecionar</button>
            </div>
            <button type="submit" class="btn" style="margin-left:8px;">Salvar Plantão</button>
        </div>
        <?php else: ?>
        <!-- Grupo com turnos cadastrados — 1 seletor de técnico por turno ativo -->
        <input type="hidden" id="userid"         name="userid"         value="">
        <input type="hidden" id="userid_reserva" name="userid_reserva" value="">
        <input type="hidden" id="shift_assignments" name="shift_assignments" value="">
        <div class="plt-shift-empty-hint">
            Sem reserva por turno. Deixe um turno em branco pra não alterar quem já está escalado nele.
        </div>
        <div class="plt-shift-fields">
            <?php foreach ($shifts as $sid => $s): ?>
            <div class="plt-shift-field">
                <label><?= htmlspecialchars($s['name']) ?>
                    <span class="plt-shift-time"><?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?></span>:
                </label>
                <div class="plt-select-row">
                    <div class="multiselect plt-mswrap">
                        <input type="text" id="shift-name-<?=$sid?>" placeholder="Nenhum técnico selecionado"
                               autocomplete="off"
                               oninput="pltAcBuild('plt-ac-shift-<?=$sid?>','shift-uid-<?=$sid?>','shift-name-<?=$sid?>',this.value)">
                        <div class="plt-ac-dd" id="plt-ac-shift-<?=$sid?>"></div>
                    </div>
                    <button type="button" class="btn" onclick="pltOpenModal('shift-<?=$sid?>')">Selecionar</button>
                </div>
                <input type="hidden" id="shift-uid-<?=$sid?>" value="">
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn">Salvar Plantão</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php endif; ?>
</div>

<script>
var selDays        = new Set();
var pltCtx          = 'main';
var PLT_HAS_SHIFTS  = <?= $has_shifts ? 'true' : 'false' ?>;
var PLT_SHIFT_IDS    = <?= $shift_ids_json ?>;
var PLT_SHIFT_LABELS = <?= $shift_labels_json ?>;
var PLT_USERS  = <?= json_encode(array_values(array_map(function($u){
    $n = trim($u['name'].' '.$u['surname']);
    $l = $n!=='' ? $n : $u['username'];
    return ['userid'=>(int)$u['userid'],'name'=>$l,
            'display'=>$l.($u['phone']?' ('.$u['phone'].')':'')];
}, $users))) ?>;

// ── Tooltip ───────────────────────────────────────────────
var ttEl = document.getElementById('plt-tooltip');
function pltShowTip(e, td) {
    var raw = td.getAttribute('data-tt');
    if (!raw) return;
    var d; try { d=JSON.parse(raw); } catch(x){ return; }
    if (!d || !d.items || !d.items.length) return;
    var html = '';
    d.items.forEach(function(it, i){
        if (i>0) html += '<div class="tt-sep"></div>';
        if (it.shift) html += '<div class="tt-label">'+esc(it.shift)+'</div>';
        html += '<div class="tt-name">'+esc(it.tech)+'</div>';
        html += '<div class="tt-phone">'+(it.phone?'📞 '+esc(it.phone):'<span class="tt-warn">sem telefone</span>')+'</div>';
        if (it.res) {
            html += '<div class="tt-label" style="margin-top:5px;">Reserva</div>';
            html += '<div>'+esc(it.res)+'</div>';
            if (it.res_phone) html += '<div class="tt-phone">📞 '+esc(it.res_phone)+'</div>';
        }
    });
    html += '<div class="tt-by" style="margin-top:5px;color:#555;">'+esc(d.date)+'</div>';
    ttEl.innerHTML = html;
    ttEl.style.display = 'block';
    moveTip(e);
}
function moveTip(e) {
    var x=e.clientX+14, y=e.clientY+14;
    var w=ttEl.offsetWidth||220, h=ttEl.offsetHeight||80;
    if (x+w > window.innerWidth-10) x=e.clientX-w-10;
    if (y+h > window.innerHeight-10) y=e.clientY-h-10;
    ttEl.style.left=x+'px'; ttEl.style.top=y+'px';
}
function pltHideTip() { ttEl.style.display='none'; }
document.querySelectorAll('.plt-cal td[data-date]').forEach(function(td){
    td.addEventListener('mousemove', moveTip);
});
function esc(s){ var d=document.createElement('div');d.textContent=s||'';return d.innerHTML; }

// ── Seleção de dias ───────────────────────────────────────
function pltToggleDay(td) {
    var ds = td.dataset.date;
    if (selDays.has(ds)) {
        selDays.delete(ds);
    } else {
        selDays.add(ds);
        var raw = td.getAttribute('data-entries');
        var entries = {};
        if (raw) { try { entries = JSON.parse(raw) || {}; } catch(x) {} }
        pltPrefillFromEntries(entries);
    }
    pltRefresh(); pltUpdateBar();
}
function pltPrefillFromEntries(entries) {
    if (!PLT_HAS_SHIFTS) {
        var e = entries['0'];
        if (e) {
            document.getElementById('userid').value = e.uid;
            document.getElementById('plt-sel-name').value = e.name || '';
            if (e.uidR) {
                document.getElementById('userid_reserva').value = e.uidR;
                document.getElementById('plt-sel-res').value = e.nameR || '';
            }
        }
        return;
    }
    PLT_SHIFT_IDS.forEach(function(sid){
        var e = entries[sid];
        if (!e) return;
        var hid = document.getElementById('shift-uid-'+sid);
        var txt = document.getElementById('shift-name-'+sid);
        if (hid) hid.value = e.uid;
        if (txt) txt.value = e.name || '';
    });
}
function pltClearSelection() { selDays.clear(); pltRefresh(); pltUpdateBar(); }
function pltRefresh() {
    document.querySelectorAll('.plt-cal td[data-date]').forEach(function(td){
        td.classList.toggle('selected', selDays.has(td.dataset.date));
    });
}
function pltUpdateBar() {
    var arr=Array.from(selDays).sort(), n=arr.length;
    document.getElementById('schedule_dates').value=arr.join(',');
    var cEl=document.getElementById('plt-sel-count');
    var dEl=document.getElementById('plt-sel-dates');
    var xEl=document.getElementById('plt-sel-clear');
    if (!n) {
        cEl.textContent='Nenhum dia selecionado'; cEl.classList.remove('active');
        dEl.textContent=''; xEl.style.display='none';
    } else {
        cEl.textContent=n+(n===1?' dia selecionado':' dias selecionados');
        cEl.classList.add('active');
        dEl.textContent=arr.map(function(d){ return d.substr(8,2)+'/'+d.substr(5,2); }).join('  ·  ');
        xEl.style.display='';
    }
}
function pltValidate() {
    if (!selDays.size) { alert('Selecione ao menos um dia no calendário.'); return false; }
    if (PLT_HAS_SHIFTS) {
        var assignments = {};
        PLT_SHIFT_IDS.forEach(function(sid){
            var hid = document.getElementById('shift-uid-'+sid);
            if (hid && hid.value) assignments[sid] = hid.value;
        });
        if (!Object.keys(assignments).length) {
            alert('Selecione ao menos um técnico em algum turno.');
            return false;
        }
        document.getElementById('shift_assignments').value = JSON.stringify(assignments);
    } else {
        if (!document.getElementById('userid').value) { alert('Selecione um técnico.'); return false; }
    }
    return true;
}

// ── Modal ─────────────────────────────────────────────────
function pltOpenModal(ctx) {
    pltCtx=ctx;
    var title = 'Selecionar Técnico de Plantão';
    if (ctx==='res') {
        title = 'Selecionar Técnico Reserva';
    } else if (ctx.indexOf('shift-')===0) {
        var sid = ctx.substring(6);
        title = 'Selecionar Técnico — ' + (PLT_SHIFT_LABELS[sid] || 'Turno');
    }
    document.getElementById('plt-modal-title').textContent = title;
    document.getElementById('plt-modal-search').value='';
    pltModalFilter('');
    document.getElementById('plt-modal').style.display='';
    document.getElementById('plt-modal-bg').style.display='';
}
function pltCloseModal() {
    document.getElementById('plt-modal').style.display='none';
    document.getElementById('plt-modal-bg').style.display='none';
}
function pltPickUser(uid,name) {
    if (pltCtx==='res') {
        document.getElementById('userid_reserva').value=uid;
        document.getElementById('plt-sel-res').value=name;
    } else if (pltCtx.indexOf('shift-')===0) {
        var sid = pltCtx.substring(6);
        var hid = document.getElementById('shift-uid-'+sid);
        var txt = document.getElementById('shift-name-'+sid);
        if (hid) hid.value=uid;
        if (txt) txt.value=name;
    } else {
        document.getElementById('userid').value=uid;
        document.getElementById('plt-sel-name').value=name;
    }
    pltCloseModal();
}
function pltModalFilter(q) {
    var ql=q.toLowerCase();
    document.querySelectorAll('#plt-modal-tbody tr').forEach(function(tr){
        var name=tr.getAttribute('data-name')||'';
        tr.style.display=(!q||name.indexOf(ql)!==-1)?'':'none';
    });
}

// ── Autocomplete ──────────────────────────────────────────
function pltAcBuild(ddId,hidId,inpId,q) {
    var dd=document.getElementById(ddId);
    if (!q) { dd.innerHTML='';dd.classList.remove('open');return; }
    var ql=q.toLowerCase();
    var ms=PLT_USERS.filter(function(u){ return u.name.toLowerCase().indexOf(ql)!==-1; });
    if (!ms.length) { dd.innerHTML='';dd.classList.remove('open');return; }
    dd.innerHTML=ms.map(function(u){
        return '<div class="plt-ac-item" data-uid="'+u.userid+'" data-disp="'+u.display.replace(/"/g,'&quot;')+'">'
            +u.name.replace(new RegExp('('+q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+')','gi'),'<mark>$1</mark>')
            +'</div>';
    }).join('');
    dd.classList.add('open');
    dd.querySelectorAll('.plt-ac-item').forEach(function(el){
        el.addEventListener('mousedown',function(e){
            e.preventDefault();
            document.getElementById(hidId).value=this.dataset.uid;
            document.getElementById(inpId).value=this.dataset.disp;
            dd.innerHTML='';dd.classList.remove('open');
        });
    });
}
function pltAcFilter(q)    { pltAcBuild('plt-ac-main','userid',        'plt-sel-name',q); }
function pltAcFilterRes(q) { pltAcBuild('plt-ac-res', 'userid_reserva','plt-sel-res', q); }
['plt-sel-name','plt-sel-res'].forEach(function(id){
    var el = document.getElementById(id);
    if (!el) return;
    var dd=id==='plt-sel-name'?'plt-ac-main':'plt-ac-res';
    el.addEventListener('blur',function(){
        setTimeout(function(){ var d=document.getElementById(dd);d.innerHTML='';d.classList.remove('open'); },150);
    });
});
PLT_SHIFT_IDS.forEach(function(sid){
    var inp = document.getElementById('shift-name-'+sid);
    var dd  = document.getElementById('plt-ac-shift-'+sid);
    if (!inp || !dd) return;
    inp.addEventListener('blur', function(){
        setTimeout(function(){ dd.innerHTML=''; dd.classList.remove('open'); }, 150);
    });
});

// ── Importação CSV/XLSX ───────────────────────────────────
function pltOpenImport() {
    document.getElementById('plt-import-modal').style.display = '';
    document.getElementById('plt-modal-bg').style.display = '';
    document.getElementById('plt-import-hint').textContent = '';
    document.getElementById('plt-import-btn').disabled = true;
    document.getElementById('plt-import-file').value = '';
    document.getElementById('plt-import-filename').textContent = 'Escolher arquivo…';
}
function pltCloseImport() {
    document.getElementById('plt-import-modal').style.display = 'none';
    document.getElementById('plt-modal-bg').style.display = 'none';
}
function pltImportFileChosen(inp) {
    var f = inp.files[0];
    var hint = document.getElementById('plt-import-hint');
    var btn  = document.getElementById('plt-import-btn');
    var lbl  = document.getElementById('plt-import-filename');
    if (!f) { lbl.textContent = 'Escolher arquivo…'; btn.disabled = true; return; }
    var ext = f.name.split('.').pop().toLowerCase();
    lbl.textContent = f.name;
    if (['csv','xlsx','tsv'].indexOf(ext) === -1) {
        hint.style.color = '#e45959';
        hint.textContent = 'Formato não suportado. Use .csv ou .xlsx';
        btn.disabled = true;
    } else {
        hint.style.color = '#59db8f';
        hint.textContent = '✓ ' + f.name + ' (' + Math.round(f.size / 1024) + ' KB)';
        btn.disabled = false;
    }
}
function pltSubmitImport() {
    var f = document.getElementById('plt-import-file').files[0];
    if (!f) return;
    var btn  = document.getElementById('plt-import-btn');
    var hint = document.getElementById('plt-import-hint');
    btn.disabled = true;
    hint.style.color = '#8faabc';
    hint.textContent = '⏳ Lendo arquivo…';

    var reader = new FileReader();
    reader.onload = function(e) {
        hint.textContent = '⏳ Enviando…';
        // Extrair só o base64 (remover prefixo "data:...;base64,")
        var b64 = e.target.result.split(',')[1];
        // Criar form normal (application/x-www-form-urlencoded não suporta binário,
        // mas multipart via form dinâmico sim — e o submit nativo funciona)
        var form = document.createElement('form');
        form.method = 'post';
        form.action = 'zabbix.php?action=plantonistas.import&usrgrpid=<?=$usrgrpid?>&month=<?=$month?>&year=<?=$year?>';
        form.style.display = 'none';

        var addField = function(name, val) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = name; inp.value = val;
            form.appendChild(inp);
        };
        addField('import_file_b64',  b64);
        addField('import_file_name', f.name);
        document.body.appendChild(form);
        form.submit();
    };
    reader.onerror = function() {
        hint.style.color = '#e45959';
        hint.textContent = 'Erro ao ler o arquivo.';
        btn.disabled = false;
    };
    reader.readAsDataURL(f);
}
document.getElementById('plt-modal-bg').addEventListener('click', function() {
    pltCloseModal(); pltCloseImport();
});

</script>
<?php
$html=ob_get_clean();
$raw=new class($html){
    public function __construct(private string $h){}
    public function toString(bool $d=true):string{return $this->h;}
};
(new CHtmlPage())->setTitle('Escala de Plantão')->addItem($raw)->show();
