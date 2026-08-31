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
<?php
// Paleta e detecção do tema ativo do Zabbix, compartilhadas pelas quatro
// telas da família escala. Precisa vir ANTES do <style> desta view: é ele que
// consome as variáveis --plt-*.
include __DIR__ . '/_theme.php';
?>
<style>
/* ── Abas ─────────────────────────────────── */
.plt-tabs { display:flex;gap:4px;margin-bottom:18px;flex-wrap:wrap;
    border-bottom:1px solid var(--plt-border);padding-bottom:2px; }
.plt-tab  { padding:6px 16px;border-radius:3px 3px 0 0;cursor:pointer;
    font-size:13px;font-weight:600;text-decoration:none;
    background:var(--plt-panel-alt);color:var(--plt-text-soft);
    border:1px solid var(--plt-border);border-bottom:none;
    position:relative;bottom:-1px;transition:background .12s; }
.plt-tab:hover  { background:var(--plt-bg-hover);color:var(--plt-text-soft); }
.plt-tab.active { background:var(--plt-panel-alt);color:var(--plt-ok);border-bottom:1px solid var(--plt-panel-alt); }

/* ── Nav ──────────────────────────────────── */
.plt-wrap { padding:0 0 20px;color:var(--plt-text); }
.plt-wrap output { margin:0 0 14px; }
.plt-nav  { display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap; }
.plt-nav .plt-month-label { font-size:15px;font-weight:600;flex:1;color:var(--plt-text-soft); }
.plt-uncov-warning {
    display:inline-flex;align-items:center;gap:6px;
    background:var(--plt-warn-bg);border:1px solid var(--plt-warn-border);border-radius:3px;
    padding:5px 12px;font-size:12px;color:var(--plt-warn);
}

/* ── Calendário ───────────────────────────── */
.plt-cal { width:100%;border-collapse:collapse;table-layout:fixed; }
.plt-cal th { background:var(--plt-panel);color:var(--plt-text-soft);text-align:center;
    padding:8px 4px;font-size:12px;font-weight:600;border:1px solid var(--plt-border); }
.plt-cal td { border:1px solid var(--plt-border);vertical-align:top;padding:0;
    cursor:pointer;background:var(--plt-bg);position:relative;
    min-height:110px;outline:none;transition:background .1s,border-color .1s;
    user-select:none; }
.plt-cell-inner { padding:7px 8px; }
.plt-cal td:hover     { background:var(--plt-bg-hover); }
.plt-cal td.empty     { background:var(--plt-bg-alt);cursor:default; }
.plt-cal td.today     { background:var(--plt-warn-bg);border-color:var(--plt-warn); }
.plt-cal td.today:hover { background:var(--plt-warn-bg); }
.plt-cal td.has-tech  { background:var(--plt-ok-bg); }
.plt-cal td.has-tech:hover { background:var(--plt-ok-bg); }
.plt-cal td.past      { opacity:.6; }
/* Sem cobertura (passado/hoje sem técnico) */
.plt-cal td.no-cover  { background:var(--plt-danger-bg) !important; }
.plt-cal td.no-cover:hover { background:var(--plt-danger-bg) !important; }
.plt-no-cov-icon { position:absolute;top:5px;right:6px;font-size:13px;color:var(--plt-warn-border); }
/* Selecionado */
.plt-cal td.selected  { background:var(--plt-accent-bg) !important;border-color:var(--plt-accent) !important; }
.plt-cal td.selected:hover { background:var(--plt-accent-bg) !important; }
.plt-sel-check { position:absolute;top:4px;right:6px;
    font-size:14px;color:var(--plt-accent);display:none;line-height:1; }
.plt-cal td.selected .plt-sel-check { display:block; }
.plt-cal td.selected .plt-no-cov-icon { display:none; }

/* Conteúdo célula */
.plt-day-num   { font-size:13px;font-weight:700;color:var(--plt-text-soft);padding-right:18px; }
.plt-today-badge { background:var(--plt-warn);color:var(--plt-warn-bg);border-radius:3px;
    padding:0 5px;font-size:10px;margin-left:5px;font-weight:700;vertical-align:middle; }
.plt-entry     { margin-top:6px;padding-top:6px; }
.plt-entry:first-child { margin-top:4px;padding-top:0;border-top:none; }
.plt-entry + .plt-entry { border-top:1px dashed var(--plt-border); }
.plt-shift-label { font-size:9px;color:var(--plt-text-muted);font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;margin-bottom:1px; }
.plt-tech      { font-size:11px;color:var(--plt-ok);font-weight:600;margin-top:2px; }
.plt-phone     { font-size:10px;color:var(--plt-text-muted);margin-top:2px; }
.plt-no-phone  { font-size:10px;color:var(--plt-text-faint);font-style:italic;margin-top:2px; }
.plt-res-label { font-size:9px;color:var(--plt-text-faint);margin-top:5px;
    text-transform:uppercase;letter-spacing:.4px; }
.plt-res-name  { font-size:11px;color:var(--plt-ok);font-weight:600;margin-top:1px; }
.plt-res-phone { font-size:10px;color:var(--plt-text-muted);margin-top:1px; }
.plt-del { display:inline-block;margin-top:4px;font-size:10px;
    color:var(--plt-danger);text-decoration:none; }
.plt-del:hover { text-decoration:underline;color:var(--plt-danger); }

/* ── Tooltip ──────────────────────────────── */
#plt-tooltip {
    display:none;position:fixed;z-index:2000;
    background:var(--plt-panel-alt);border:1px solid var(--plt-border-strong);border-radius:4px;
    padding:10px 14px;font-size:12px;color:var(--plt-text);
    max-width:260px;pointer-events:none;box-shadow:0 4px 16px rgba(0,0,0,.22);
    line-height:1.6;
}
#plt-tooltip .tt-name  { font-size:14px;font-weight:700;color:var(--plt-ok);margin-bottom:3px; }
#plt-tooltip .tt-phone { color:var(--plt-text-muted); }
#plt-tooltip .tt-sep   { border-top:1px solid var(--plt-border);margin:7px 0; }
#plt-tooltip .tt-label { font-size:10px;color:var(--plt-text-faint);text-transform:uppercase;
    letter-spacing:.4px;margin-bottom:2px; }
#plt-tooltip .tt-by    { font-size:11px;color:var(--plt-text-faint);margin-top:5px; }
#plt-tooltip .tt-warn  { color:var(--plt-warn);font-style:italic; }

/* ── Formulário ───────────────────────────── */
.plt-form-panel { margin-top:18px;background:var(--plt-panel);
    border:1px solid var(--plt-border);border-radius:4px;padding:18px 22px; }
.plt-form-panel h3 { margin:0 0 14px;font-size:14px;font-weight:600;color:var(--plt-text); }
.plt-sel-bar {
    display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;
    padding:10px 14px;border-radius:3px;background:var(--plt-panel-alt);border:1px solid var(--plt-border);
}
.plt-sel-count { font-size:13px;font-weight:600;color:var(--plt-text-soft); }
.plt-sel-count.active { color:var(--plt-accent); }
.plt-sel-dates { font-size:12px;color:var(--plt-text-muted);flex:1; }
.plt-sel-clear { font-size:11px;color:var(--plt-danger);cursor:pointer;border:none;
    background:none;padding:2px 6px;border-radius:2px; }
.plt-sel-clear:hover { background:var(--plt-danger-bg); }
.plt-form-row { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px; }
.plt-form-row label { font-weight:600;font-size:13px;color:var(--plt-text-muted);white-space:nowrap; }
.plt-select-row { display:flex;align-items:stretch;flex:0 1 300px;min-width:220px; }
.plt-select-row .multiselect { flex:1;min-width:0;margin-right:0 !important;
    border-right:0 !important;border-top-right-radius:0 !important;border-bottom-right-radius:0 !important; }
.plt-select-row .btn { border-top-left-radius:0;border-bottom-left-radius:0;
    white-space:nowrap;flex-shrink:0;align-self:stretch; }
.plt-mswrap { flex:1;min-width:0;position:relative;margin-right:0 !important; }
.plt-ac-dd { display:none;position:absolute;z-index:1100;top:100%;left:0;right:0;
    background:var(--plt-bg);border:1px solid var(--plt-border);border-top:none;
    max-height:220px;overflow-y:auto; }
.plt-ac-dd.open { display:block; }
.plt-ac-item { padding:7px 10px;font-size:13px;color:var(--plt-text);cursor:pointer;
    border-bottom:1px solid var(--plt-bg-hover); }
.plt-ac-item:last-child { border-bottom:none; }
.plt-ac-item:hover { background:var(--plt-bg-hover); }
.plt-ac-item mark { background:none;color:var(--plt-warn);font-weight:700; }
output .btn-overlay-close { position:absolute;top:6px;right:6px; }
#plt-modal { z-index:1001 !important; }

/* Campos de turno (um por turno cadastrado no grupo) */
.plt-shift-fields { display:flex;flex-wrap:wrap;gap:14px 18px;align-items:flex-end;margin-bottom:4px; }
.plt-shift-field  { display:flex;flex-direction:column;gap:5px; }
.plt-shift-field label { font-weight:600;font-size:13px;color:var(--plt-text-muted);white-space:nowrap; }
.plt-shift-time    { font-weight:400;color:var(--plt-text-faint);font-size:11px;margin-left:3px; }
.plt-shift-empty-hint { font-size:12px;color:var(--plt-text-muted);margin-bottom:10px; }
/* .plt-select-row usa "flex:0 1 300px" pensado pra dentro de um flex ROW
   (.plt-form-row, modo legado) — ali o 300px vira largura. Dentro de
   .plt-shift-field, que é flex COLUMN, o mesmo valor vira ALTURA e o campo
   fica gigante (300px de altura). Reseta o eixo aqui: largura fixa via
   width, sem flex-basis herdado. */
.plt-shift-field .plt-select-row { flex:0 0 auto;width:300px; }

/* Mensagens de sucesso e erro.
   Estas regras existiam com cor fixa (fundo claro, texto preto) porque a tela
   era escura por fora e a caixa nativa do Zabbix ficava ilegível sobre ela.
   Agora seguem a paleta: no tema claro o texto é escuro, no escuro é claro. */
output.msg-good {
    background: var(--plt-ok-bg) !important;
    border-left: 4px solid var(--plt-ok) !important;
    color: var(--plt-text) !important;
}
output.msg-good span,
output.msg-good * { color: var(--plt-text) !important; }

output.msg-bad {
    background: var(--plt-danger-bg) !important;
    border-left: 4px solid var(--plt-danger) !important;
    color: var(--plt-text) !important;
}
output.msg-bad span,
output.msg-bad * { color: var(--plt-text) !important; }

/* Botão CSV — mesma cor dos demais btn-alt */
.plt-nav a.btn.btn-alt {
    color: var(--plt-accent) !important;
    background-color: transparent !important;
    border-color: var(--plt-accent) !important;
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
                // Rótulo vem pronto do controller (UserLabel), já com o
                // username como reserva — a view não remonta nome de usuário.
                $np  = (string) ($u['label'] ?? '');
                $lbl = $np !== '' ? $np : $u['username'];
                $disp = $lbl.($u['phone'] ? ' ('.$u['phone'].')' : '');
            ?>
            <tr data-name="<?= htmlspecialchars(strtolower($lbl)) ?>">
                <td>
                    <a href="javascript:void(0)"
                       onclick="pltPickUser(<?=(int)$u['userid']?>,<?=htmlspecialchars(json_encode($disp))?>)">
                        <?= htmlspecialchars($lbl) ?>
                        <?php if ($np===''): ?>
                            <span style="color:var(--plt-text-faint);font-size:10px;"> (sem nome)</span>
                        <?php endif; ?>
                    </a>
                </td>
                <td style="color:var(--plt-text-muted);"><?= htmlspecialchars($u['username']) ?></td>
                <td>
                    <?php if ($u['phone']): ?>
                        <?= htmlspecialchars($u['phone']) ?>
                    <?php else: ?>
                        <span style="color:var(--plt-text-faint);font-style:italic">—</span>
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
    <div class="overlay-dialogue-body" style="padding:18px 22px;background:var(--plt-panel);">
        <p style="background:var(--plt-accent-bg);border:1px solid var(--plt-border);border-radius:3px;
                  padding:7px 12px;font-size:13px;color:var(--plt-text);margin:0 0 12px;">
            📋 Importando para o grupo: <strong><?=htmlspecialchars($groups[$usrgrpid]??'')?></strong>
        </p>
        <p style="color:var(--plt-text);font-size:13px;margin:0 0 10px;">
            Selecione um arquivo <strong>CSV</strong> ou <strong>XLSX</strong> com as colunas:
        </p>
        <p style="font-size:13px;margin:0 0 10px;line-height:2;">
            <span style="color:var(--plt-ok);font-weight:600;">data</span><span style="color:var(--plt-text);"> (dd/mm/aaaa) &nbsp;·&nbsp; </span><span style="color:var(--plt-ok);font-weight:600;">plantonista</span><span style="color:var(--plt-text);"> (username ou nome completo) &nbsp;·&nbsp; </span><span style="color:var(--plt-ok);font-weight:600;">turno</span><span style="color:var(--plt-text);"> (opcional) &nbsp;·&nbsp; </span><span style="color:var(--plt-ok);font-weight:600;">reserva</span><span style="color:var(--plt-text);"> (opcional)</span>
        </p>
        <p style="font-size:12px;color:var(--plt-text-faint);margin:0 0 16px;">
            💡 Use o botão ↓ CSV para baixar o modelo do mês atual, edite e reimporte.
            <?php if ($has_shifts): ?>
                Este grupo tem turnos: preencha a coluna <strong>turno</strong> com o
                nome cadastrado. Linha sem turno é importada assim mesmo, em modo
                legado — e aparece no calendário sem rótulo.
            <?php endif; ?>
        </p>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <label style="flex:1;min-width:220px;background:var(--plt-bg);border:1px solid var(--plt-border-strong);border-radius:3px;
                          padding:6px 10px;color:var(--plt-text);font-size:13px;cursor:pointer;
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
    <div style="padding:20px;color:var(--plt-text-faint);font-style:italic;">Você não pertence a nenhum grupo.</div>
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
<div style="margin-bottom:14px;font-size:13px;font-weight:600;color:var(--plt-text-soft);">
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
    <span style="font-size:11px;color:var(--plt-text-faint);">Clique para selecionar dias</span>
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
        // Rótulos vêm prontos do controller (UserLabel): concatenar aqui
        // duplicaria o nome de quem tem o nome completo no campo surname.
        $tech_label = $e['label'] ?: $e['username'];
        // '(removido)' e não string vazia: userid_reserva aponta pra um
        // usuário que existia quando a escala foi salva, mas o LEFT JOIN não
        // achou mais ninguém (conta excluída do Zabbix) — rótulo em branco
        // parecia bug de dados faltando, e não "esse usuário não existe mais".
        $res_label  = $e['userid_reserva'] ? ($e['reserva_label'] ?: '(removido)') : '';

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
        $tech_label = $e['label'] ?: $e['username'];
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
            $res_label = $e['reserva_label'] ?: '(removido)';
            echo '<div class="plt-res-label">Reserva</div>';
            echo '<div class="plt-res-name">'.htmlspecialchars($res_label).'</div>';
            if ($e['reserva_phone'])
                echo '<div class="plt-res-phone">'.htmlspecialchars($e['reserva_phone']).'</div>';
        }
        $is_today_entry = ($ds === $today_str);
        if ($is_today_entry) {
            echo '<span class="plt-del" style="color:var(--plt-text-faint);cursor:not-allowed;" '
               . 'title="Não é possível remover o plantão do dia atual">remover</span>';
        } else {
            // Remover é POST, não link GET. Dois motivos: a validação CSRF do
            // Zabbix só roda em POST (em GET ela sempre falha), e um link GET
            // que apaga dado é justamente o que uma página maliciosa consegue
            // disparar de fora. O submit real sai do form oculto plt-del-form.
            //
            // Nome vai em data-label, não interpolado num onclick: com
            // ENT_QUOTES (default do PHP 8.1+) o htmlspecialchars() já
            // transforma a aspa simples em &#039;, o addslashes() seguinte não
            // vê mais nada para escapar, e o navegador decodifica a entidade ao
            // parsear o atributo — um "Sant'Ana" quebrava o JS do onclick e
            // deixava o botão inerte. Em atributo de dado a entidade é inócua.
            echo '<a class="plt-del" href="#" tabindex="-1"'
               .' data-schedid="'.(int)$e['scheduleid'].'"'
               .' data-label="'.htmlspecialchars($tech_label, ENT_QUOTES).'"'
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
        <span style="color:var(--plt-text-muted);font-weight:400"><?=htmlspecialchars($groups[$usrgrpid]??'')?></span>
    </h3>
    <div class="plt-sel-bar">
        <span class="plt-sel-count" id="plt-sel-count">Nenhum dia selecionado</span>
        <span class="plt-sel-dates" id="plt-sel-dates"></span>
        <button type="button" class="plt-sel-clear" id="plt-sel-clear"
                onclick="pltClearSelection()" style="display:none">Limpar seleção</button>
    </div>
    <!-- Form oculto do "remover": POST em vez do antigo link GET (ver o
         comentário no botão remover, acima). -->
    <form method="post" action="zabbix.php?action=plantonistas.delete" id="plt-del-form"
          style="display:none">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['csrf_delete'] ?? '') ?>">
        <input type="hidden" name="month"    value="<?=$month?>">
        <input type="hidden" name="year"     value="<?=$year?>">
        <input type="hidden" name="usrgrpid" value="<?=$usrgrpid?>">
        <input type="hidden" name="scheduleid" id="plt-del-id" value="">
    </form>

    <form method="post" action="zabbix.php?action=plantonistas.save" id="plt-form"
          onsubmit="return pltValidate()">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['csrf_save'] ?? '') ?>">
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
        <div id="plt-post-hint" style="margin-top:10px;font-size:12px;min-height:16px;"></div>
    </form>
</div>

<?php endif; ?>
</div>

<script>
var selDays        = new Set();
var pltCtx          = 'main';
var PLT_HAS_SHIFTS  = <?= $has_shifts ? 'true' : 'false' ?>;
// Token CSRF da importação — o form dela é montado no JS, então não dá para
// deixar o hidden pronto no HTML como nos forms de salvar e remover.
var PLT_CSRF_IMPORT = <?= json_encode($data['csrf_import'] ?? '') ?>;

// Remover plantão: preenche e envia o form oculto plt-del-form (POST com
// token). Antes era um link GET, que a validação CSRF do Zabbix nunca
// aceitaria — e que qualquer página externa conseguia disparar.
var pltDeleting = false;
function pltDelete(scheduleid, label) {
    if (pltDeleting) return;   // duplo clique enviaria dois POSTs, e o segundo
                               // volta "Entrada não encontrada" logo depois de
                               // uma remoção que deu certo
    if (!confirm('Remover plantão de ' + label + ' deste dia?')) return;
    pltDeleting = true;
    document.getElementById('plt-del-id').value = scheduleid;
    // Também por fetch: aqui não há texto digitado a perder, mas a recusa por
    // CSRF é a mesma, e a mensagem de "recarregue a página" é muito mais útil
    // que a página "Acesso negado" do Zabbix.
    //
    // `alerta: true` porque o clique parte de uma célula do calendário, e o
    // #plt-post-hint fica lá embaixo, dentro do formulário de escalar — fora
    // da viewport. Sem o alert, a falha seria invisível: a página não muda,
    // o usuário clica de novo e nada acontece.
    // Libera o guard quando o fetch termina. Antes era um setTimeout de 4s às
    // cegas: com a página ficando de pé (não navega mais em caso de erro), o
    // operador via a falha e clicava de novo sem nada acontecer.
    pltPost(document.getElementById('plt-del-form'), null, true)
        .then(function () { pltDeleting = false; });
}

// Listener na fase de CAPTURA: o link de remover fica dentro de um <td> com
// onclick="pltToggleDay(this)", e handler inline roda no bubbling. Na captura
// este roda antes e consegue impedir que o clique também selecione o dia.
document.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('.plt-del[data-schedid]') : null;
    if (!a) return;
    ev.preventDefault();
    ev.stopPropagation();
    pltDelete(a.dataset.schedid, a.dataset.label || '');
}, true);
var PLT_SHIFT_IDS    = <?= $shift_ids_json ?>;
var PLT_SHIFT_LABELS = <?= $shift_labels_json ?>;
var PLT_USERS  = <?= json_encode(array_values(array_map(function($u){
    // Idem: rótulo montado no controller.
    $l = (string) ($u['label'] ?? '');
    $l = $l !== '' ? $l : $u['username'];
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
    html += '<div class="tt-by" style="margin-top:5px;color:var(--plt-text-faint);">'+esc(d.date)+'</div>';
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

    // Envio por fetch em vez de POST nativo — ver pltPost().
    pltPost(document.getElementById('plt-form'));
    return false;
}

// ── Envio resiliente a sessão expirada ──────────────────────
//
// O POST nativo do formulário perde tudo que foi digitado quando o token CSRF
// não vale mais: o Zabbix responde a página "Acesso negado" e a escala
// selecionada, o técnico escolhido e os turnos preenchidos vão junto. Nesta
// tela isso não é raro — a aba costuma ficar aberta o turno inteiro, e o token
// deriva do secret da sessão.
//
// Com fetch, a página não sai do lugar: em caso de falha aparece um aviso e
// tudo continua ali para reenviar depois do F5.
//
// Sucesso devolve `{success: true, redirect: <url>}` e o JS navega; erro de
// validação devolve `{success: false, message: ...}` e a tela NÃO recarrega.
//
// A action responde JSON quando o request traz `X-Requested-With`
// (actions/AjaxRedirect.php), então o fetch NÃO precisa seguir redirect: uma
// versão anterior seguia, e aí o navegador baixava a página de destino inteira
// só para descartá-la e baixá-la de novo no `location.href` — dois renders por
// salvamento.
function pltPost(form, hintId, alerta) {
    var hint = document.getElementById(hintId || 'plt-post-hint');
    var btns = form.querySelectorAll('button[type=submit]');
    btns.forEach(function (b) { b.disabled = true; });   // anti duplo clique

    if (hint) { hint.style.color = 'var(--plt-text-muted)'; hint.textContent = 'Salvando…'; }

    function falhou(msg) {
        btns.forEach(function (b) { b.disabled = false; });
        if (hint) { hint.style.color = 'var(--plt-danger)'; hint.textContent = msg; }
        // O hint pode estar fora da viewport (é o caso do remover, disparado
        // de uma célula do calendário): aí a mensagem precisa se impor.
        if (alerta) alert(msg);
    }

    var dados = new FormData(form);
    // É este campo que faz a action responder JSON em vez de redirect (ver
    // actions/AjaxRedirect.php). Vai no CORPO, não em cabeçalho: o frontend
    // fica atrás de um F5, e cabeçalho é o que proxy remove.
    dados.append('plt_ajax', '1');

    return fetch(form.action, { method: 'POST', body: dados })
        .then(function (r) {
            // Redirect seguido = a action respondeu no modo antigo (o campo
            // não chegou). Deu certo: navega, em vez de mentir "não salvou".
            if (r.redirected) { location.href = r.url; return null; }

            // Falha de CSRF acontece ANTES do doAction(), no roteador do
            // Zabbix, que responde a página HTML "Acesso negado". Sem esta
            // checagem o r.json() estouraria erro de parse.
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('json') === -1) {
                falhou(r.status === 403 || r.ok
                    ? 'Sessão expirada ou acesso negado. Recarregue a página (F5) e '
                    + 'salve de novo — nada do que você preencheu foi perdido.'
                    : 'O servidor respondeu ' + r.status + '. Confira o log do PHP-FPM.');
                return null;
            }
            return r.json();
        })
        .then(function (d) {
            if (!d) return;
            // Navega sempre que vier destino — inclusive no sucesso PARCIAL
            // (gravou e tem aviso), que sem isso deixaria invisível o que foi
            // gravado. Erro de validação não traz destino e fica na tela, com
            // tudo ainda preenchido.
            if (d.redirect) { location.href = d.redirect; return; }
            falhou(d.message || 'Não foi possível salvar.');
        }, function () {
            // Handler de rejeição do PRÓPRIO fetch, não `.catch` no fim da
            // cadeia: com `.catch` um TypeError do bloco de sucesso acima
            // viraria "erro de conexão, nada foi salvo" — afirmação que o
            // navegador não tem como sustentar depois de o POST ter chegado.
            falhou('Erro de conexão. Nada foi salvo; tente de novo.');
        });
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
        hint.style.color = 'var(--plt-danger)';
        hint.textContent = 'Formato não suportado. Use .csv ou .xlsx';
        btn.disabled = true;
    } else {
        hint.style.color = 'var(--plt-ok)';
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
    hint.style.color = 'var(--plt-text-muted)';
    hint.textContent = '⏳ Lendo arquivo…';

    var reader = new FileReader();
    reader.onload = function(e) {
        hint.textContent = '⏳ Enviando…';
        // Só o base64 (fora o prefixo "data:...;base64,"). O upload vai por
        // campo, não por multipart: a rota do Zabbix nesta action não trata
        // multipart.
        var b64 = e.target.result.split(',')[1];

        var dados = new FormData();
        dados.append('import_file_b64',  b64);
        dados.append('import_file_name', f.name);
        dados.append('_csrf_token',      PLT_CSRF_IMPORT);
        dados.append('plt_ajax',         '1');

        // Por fetch, e não por form.submit() nativo: com o token expirado o
        // submit nativo levava para a página "Acesso negado" do Zabbix e a
        // seleção do arquivo se perdia — o operador tinha que escolher tudo
        // de novo sem entender o que aconteceu. Aqui o modal fica aberto e o
        // arquivo continua escolhido.
        // `reenviar`: só reabilita o botão quando repetir o MESMO arquivo pode
        // dar certo (rede, sessão). Num erro de conteúdo — cabeçalho não
        // reconhecido, técnico inexistente — reenviar dá o mesmo erro, e com o
        // arquivo ainda selecionado o operador clicaria de novo. Pior: o
        // logHistory do import grava linha nova a cada passada.
        var falhaImport = function (msg, reenviar) {
            hint.style.color = 'var(--plt-danger)';
            hint.textContent = msg;
            if (reenviar) {
                btn.disabled = false;
            } else {
                document.getElementById('plt-import-file').value = '';
                document.getElementById('plt-import-filename').textContent = 'Escolher arquivo…';
            }
        };

        fetch('zabbix.php?action=plantonistas.import&usrgrpid=<?=$usrgrpid?>&month=<?=$month?>&year=<?=$year?>', {
            method: 'POST',
            body: dados
        })
            .then(function (r) {
                if (r.redirected) { location.href = r.url; return null; }

                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('json') === -1) {
                    falhaImport(r.status === 403 || r.ok
                        ? 'Sessão expirada ou acesso negado. Recarregue a página (F5) e importe de novo.'
                        : 'O servidor respondeu ' + r.status + '. Confira o log do PHP-FPM.', true);
                    return null;
                }
                return r.json();
            })
            .then(function (d) {
                if (!d) return;
                // Navega sempre que vier destino — inclusive na importação
                // PARCIAL ("30 linhas importadas. Avisos (3): …"), que é o
                // desfecho mais comum e precisa mostrar o que entrou.
                if (d.redirect) { location.href = d.redirect; return; }
                falhaImport(d.message || 'Não foi possível importar.', false);
            }, function () {
                falhaImport('Erro de conexão. Nada foi importado.', true);
            });
    };
    reader.onerror = function() {
        hint.style.color = 'var(--plt-danger)';
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
