<?php declare(strict_types = 1);
/**
 * @var array $data
 */
?>
<style>
.phn-wrap    { padding:20px 0;color:#f2f2f2; }
.phn-badge-ok { background:#162616;color:#59db8f;padding:2px 8px;border-radius:3px;
    font-size:11px;font-weight:600;border:1px solid #2a5a2a; }
.phn-badge-no { background:#252525;color:#666;padding:2px 8px;border-radius:3px;
    font-size:11px;border:1px solid #3a3a3a; }
.phn-groups  { display:flex;gap:4px;flex-wrap:wrap; }
.phn-tag     { background:#1a2a3a;color:#8faabc;padding:2px 7px;border-radius:3px;
    font-size:10px;border:1px solid #2a4a6a;white-space:nowrap; }
.phn-form    { display:flex;gap:6px;align-items:center; }
output .btn-overlay-close { position:absolute;top:6px;right:6px; }

/* Filtro */
.phn-filter-row { display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center; }
.phn-filter-row input { min-width:200px; }

/* Botão exportar — mesma aparência do btn-alt tipo button */
a.phn-export-btn {
    color: inherit !important;
    background-color: transparent !important;
    border-color: inherit !important;
    text-decoration: none !important;
}
</style>

<?php ob_start(); ?>

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

<div style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
    <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.list'">
        ← Voltar à Escala
    </button>
    <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.phones.export'" title="Baixar lista de usuários em CSV">
        ↓ Exportar Usuários
    </button>
</div>

<?php if (empty($data['users'])): ?>
    <div style="padding:20px;color:#666;font-style:italic;">Nenhum usuário encontrado.</div>
<?php else: ?>

<!-- Filtros -->
<div class="phn-filter-row">
    <input type="text" id="phn-filter-name" placeholder="Filtrar por nome ou usuário..."
           oninput="phnFilter()">
    <select id="phn-filter-phone" onchange="phnFilter()">
        <option value="">Todos</option>
        <option value="yes">Com telefone</option>
        <option value="no">Sem telefone</option>
    </select>
    <select id="phn-filter-group" onchange="phnFilter()">
        <option value="">Todos os grupos</option>
        <?php
        $all_groups = [];
        foreach ($data['users'] as $u) {
            foreach ($u['groups'] as $gid => $gname) {
                $all_groups[$gid] = $gname;
            }
        }
        asort($all_groups);
        foreach ($all_groups as $gid => $gname):
        ?>
        <option value="<?= (int)$gid ?>"><?= htmlspecialchars($gname) ?></option>
        <?php endforeach; ?>
    </select>
    <span id="phn-count" style="font-size:12px;color:#666;"></span>
</div>

<table class="list-table" id="phn-table">
    <thead>
        <tr>
            <th>Usuário</th>
            <th>Nome</th>
            <th>Grupos</th>
            <th>Telefone</th>
            <th>Atualizar</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($data['users'] as $u):
        $np = trim($u['name'].' '.$u['surname']);
        $group_ids_str = implode(',', array_keys($u['groups']));
        $has_phone = !empty($u['phone']) ? 'yes' : 'no';
    ?>
    <tr data-name="<?= htmlspecialchars(strtolower($np.' '.$u['username'])) ?>"
        data-phone="<?= $has_phone ?>"
        data-groups="<?= htmlspecialchars($group_ids_str) ?>">
        <td class="overflow-ellipsis"><?= htmlspecialchars($u['username']) ?></td>
        <td class="overflow-ellipsis">
            <?php if ($np !== ''): ?>
                <?= htmlspecialchars($np) ?>
            <?php else: ?>
                <span style="color:#666;font-style:italic">— sem nome —</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="phn-groups">
                <?php foreach ($u['groups'] as $gname): ?>
                    <span class="phn-tag"><?= htmlspecialchars($gname) ?></span>
                <?php endforeach; ?>
            </div>
        </td>
        <td>
            <?php if ($u['phone']): ?>
                <span class="phn-badge-ok"><?= htmlspecialchars($u['phone']) ?></span>
            <?php else: ?>
                <span class="phn-badge-no">não cadastrado</span>
            <?php endif; ?>
        </td>
        <td>
            <form method="post" action="zabbix.php?action=plantonistas.phones.save" class="phn-form">
                <input type="hidden" name="userid" value="<?= (int)$u['userid'] ?>">
                <input type="text" name="phone" id="phn-inp-<?= (int)$u['userid'] ?>"
                       value="<?= htmlspecialchars($u['phone']) ?>"
                       placeholder="(11) 99999-9999" maxlength="15"
                       style="width:140px;" autocomplete="off"
                       oninput="phnMask(this)">
                <button class="btn" type="submit">Salvar</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
function phnMask(inp) {
    // Remove tudo que não for dígito
    var v = inp.value.replace(/\D/g, '').substring(0, 11);
    // Formata: (DD) 9XXXX-XXXX  ou  (DD) XXXX-XXXX
    if (v.length === 0) { inp.value = ''; return; }
    var r = '(' + v.substring(0, 2);
    if (v.length > 2) r += ') ' + v.substring(2, v.length > 10 ? 7 : 6);
    if (v.length > (v.length > 10 ? 7 : 6)) r += '-' + v.substring(v.length > 10 ? 7 : 6);
    inp.value = r;
}
// Aplicar máscara nos valores já preenchidos ao carregar
document.querySelectorAll('input[id^="phn-inp-"]').forEach(function(inp) {
    if (inp.value) phnMask(inp);
});

function phnFilter() {
    var q     = document.getElementById('phn-filter-name').value.toLowerCase().trim();
    var phone = document.getElementById('phn-filter-phone').value;
    var grp   = document.getElementById('phn-filter-group').value;
    var rows  = document.querySelectorAll('#phn-table tbody tr');
    var vis   = 0;
    rows.forEach(function(tr) {
        var nm  = tr.getAttribute('data-name')||'';
        var ph  = tr.getAttribute('data-phone')||'';
        var gs  = tr.getAttribute('data-groups')||'';
        var ok  = true;
        if (q    && nm.indexOf(q)===-1) ok=false;
        if (phone && ph!==phone)        ok=false;
        if (grp  && gs.split(',').indexOf(grp)===-1) ok=false;
        tr.style.display=ok?'':'none';
        if (ok) vis++;
    });
    document.getElementById('phn-count').textContent=vis+' usuário(s)';
}
phnFilter();
</script>

<?php endif; ?>

<?php
$html = ob_get_clean();
$raw  = new class($html) {
    public function __construct(private string $h) {}
    public function toString(bool $d=true): string { return $this->h; }
};
(new CHtmlPage())->setTitle('Telefones de Plantão')->addItem($raw)->show();
