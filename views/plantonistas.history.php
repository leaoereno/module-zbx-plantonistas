<?php declare(strict_types = 1);
/**
 * @var array $data [groups, usrgrpid, rows, total, page, per_page]
 */

$groups   = $data['groups'];
$usrgrpid = $data['usrgrpid'];
$rows     = $data['rows'];
$total    = $data['total'];
$page     = $data['page'];
$per_page = $data['per_page'];
$pages    = (int) ceil($total / $per_page);

// Rótulo já vem montado do controller (UserLabel, que já cai no username
// quando name e surname estão vazios). Aqui só escapa.
function hName(string $label): string {
    return htmlspecialchars($label);
}

$action_labels = [
    'create' => ['label'=>'Criado',    'cls'=>'hist-create'],
    'update' => ['label'=>'Alterado',  'cls'=>'hist-update'],
    'delete' => ['label'=>'Removido',  'cls'=>'hist-delete'],
];

ob_start(); ?>
<?php
// Paleta e detecção do tema ativo do Zabbix, compartilhadas pelas quatro
// telas da família escala. Precisa vir ANTES do <style> desta view: é ele que
// consome as variáveis --plt-*.
include __DIR__ . '/_theme.php';
?>
<style>
.hist-wrap  { padding: 4px 0 20px; }
.hist-tabs  {
    display: flex; gap: 4px; margin-bottom: 18px;
    flex-wrap: wrap; border-bottom: 1px solid var(--plt-border); padding-bottom: 2px;
}
.hist-tab {
    padding: 6px 16px; border-radius: 3px 3px 0 0;
    font-size: 13px; font-weight: 600; text-decoration: none;
    background: var(--plt-panel-alt); color: var(--plt-text-soft);
    border: 1px solid var(--plt-border); border-bottom: none;
    position: relative; bottom: -1px;
}
.hist-tab:hover  { background: var(--plt-bg-hover); color: var(--plt-text-soft); }
.hist-tab.active { background: var(--plt-panel-alt); color: var(--plt-ok); border-bottom: 1px solid var(--plt-panel-alt); }

.hist-badge {
    display: inline-block; padding: 2px 8px; border-radius: 3px;
    font-size: 11px; font-weight: 700;
}
.hist-create { background: var(--plt-ok-bg); color: var(--plt-ok); border: 1px solid var(--plt-ok-border); }
.hist-update { background: var(--plt-tag-bg); color: var(--plt-accent); border: 1px solid var(--plt-tag-border); }
.hist-delete { background: var(--plt-danger-bg); color: var(--plt-danger); border: 1px solid var(--plt-danger-border); }

.hist-diff { font-size: 12px; color: var(--plt-text-muted); }
.hist-diff .old { color: var(--plt-danger); text-decoration: line-through; }
.hist-diff .arr { color: var(--plt-text-faint); margin: 0 4px; }
.hist-diff .new { color: var(--plt-ok); }

.hist-pagination { display: flex; gap: 4px; margin-top: 16px; flex-wrap: wrap; align-items: center; }
.hist-pagination a, .hist-pagination span {
    padding: 4px 10px; border-radius: 3px; font-size: 12px;
    border: 1px solid var(--plt-border); text-decoration: none; color: var(--plt-text-muted);
}
.hist-pagination a:hover { background: var(--plt-bg-hover); color: var(--plt-text); }
.hist-pagination .current { background: var(--plt-accent); color: #fff; border-color: var(--plt-accent); }
.hist-pagination .disabled { opacity: .4; pointer-events: none; }
</style>

<div class="hist-wrap">

<?php if (count($groups) > 1): ?>
<div class="hist-tabs">
    <?php foreach ($groups as $gid => $gname): ?>
    <a class="hist-tab <?= $gid === $usrgrpid ? 'active' : '' ?>"
       href="zabbix.php?action=plantonistas.history&usrgrpid=<?= $gid ?>">
        <?= htmlspecialchars($gname) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.list&usrgrpid=<?= $usrgrpid ?>'">
        ← Voltar à Escala
    </button>
    <span style="font-size:12px;color:var(--plt-text-faint);"><?= $total ?> registro(s)</span>
</div>

<?php if (empty($rows)): ?>
    <div style="padding:20px;color:var(--plt-text-faint);font-style:italic;">Nenhum histórico registrado.</div>
<?php else: ?>

<table class="list-table">
    <thead>
        <tr>
            <th>Data do Plantão</th>
            <th>Grupo</th>
            <th>Turno</th>
            <th>Ação</th>
            <th>Técnico</th>
            <th>Reserva</th>
            <th>Alterado por</th>
            <th>Quando</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $al       = $action_labels[$r['action']] ?? ['label'=>$r['action'],'cls'=>''];
        // "Havia alguém ali?" se decide pela coluna de id da própria tabela
        // de histórico, não pelo nome vindo do LEFT JOIN: o \DBfetch() do
        // Zabbix converte NULL na string '0' por padrão, então testar o nome
        // por `!== null` nunca dá falso — e a célula imprimia "0".
        $tech_old = (int)($r['userid_old']  ?? 0) > 0 ? hName($r['o_label']  ?? '') : null;
        $tech_new = (int)($r['userid_new']  ?? 0) > 0 ? hName($r['n_label']  ?? '') : null;
        $res_old  = (int)($r['reserva_old'] ?? 0) > 0 ? hName($r['ro_label'] ?? '') : null;
        $res_new  = (int)($r['reserva_new'] ?? 0) > 0 ? hName($r['rn_label'] ?? '') : null;
        $date_fmt = date('d/m/Y', strtotime($r['schedule_date']));
        $when_fmt = date('d/m/Y H:i', (int)$r['changed_at']);
        $by_name  = hName($r['cb_label'] ?? '');
    ?>
    <tr>
        <td><?= $date_fmt ?></td>
        <td><?= htmlspecialchars($r['group_name'] ?? '') ?></td>
        <td><?= !empty($r['shift_name']) ? htmlspecialchars($r['shift_name']) : '<span style="color:var(--plt-text-faint)">—</span>' ?></td>
        <td><span class="hist-badge <?= $al['cls'] ?>"><?= $al['label'] ?></span></td>
        <td class="hist-diff">
            <?php if ($r['action'] === 'update' && $tech_old !== $tech_new): ?>
                <span class="old"><?= $tech_old ?></span>
                <span class="arr">→</span>
                <span class="new"><?= $tech_new ?></span>
            <?php elseif ($r['action'] === 'delete'): ?>
                <span class="old"><?= $tech_old ?></span>
            <?php else: ?>
                <?= $tech_new ?>
            <?php endif; ?>
        </td>
        <td class="hist-diff">
            <?php if ($r['action'] === 'update' && $res_old !== $res_new): ?>
                <?php if ($res_old): ?><span class="old"><?= $res_old ?></span><span class="arr">→</span><?php endif; ?>
                <?= $res_new ?? '<span style="color:var(--plt-text-faint)">—</span>' ?>
            <?php elseif ($r['action'] === 'delete' && $res_old): ?>
                <span class="old"><?= $res_old ?></span>
            <?php else: ?>
                <?= $res_new ?? '<span style="color:var(--plt-text-faint)">—</span>' ?>
            <?php endif; ?>
        </td>
        <td><?= $by_name ?></td>
        <td style="color:var(--plt-text-muted);white-space:nowrap;"><?= $when_fmt ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($pages > 1): ?>
<div class="hist-pagination">
    <a class="<?= $page <= 1 ? 'disabled' : '' ?>"
       href="zabbix.php?action=plantonistas.history&usrgrpid=<?= $usrgrpid ?>&page=<?= $page-1 ?>">
       ‹ Anterior
    </a>
    <?php for ($p = max(1,$page-3); $p <= min($pages,$page+3); $p++): ?>
    <a class="<?= $p === $page ? 'current' : '' ?>"
       href="zabbix.php?action=plantonistas.history&usrgrpid=<?= $usrgrpid ?>&page=<?= $p ?>">
       <?= $p ?>
    </a>
    <?php endfor; ?>
    <a class="<?= $page >= $pages ? 'disabled' : '' ?>"
       href="zabbix.php?action=plantonistas.history&usrgrpid=<?= $usrgrpid ?>&page=<?= $page+1 ?>">
       Próximo ›
    </a>
    <span style="color:var(--plt-text-faint);">Página <?= $page ?> de <?= $pages ?></span>
</div>
<?php endif; ?>

<?php endif; ?>
</div>
<?php
$html = ob_get_clean();
$raw  = new class($html) {
    public function __construct(private string $h) {}
    public function toString(bool $d=true): string { return $this->h; }
};
(new CHtmlPage())->setTitle('Histórico de Plantão')->addItem($raw)->show();
