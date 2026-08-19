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
    <button class="btn btn-alt" onclick="phnOpenImport()" title="Importar telefones em massa via CSV">
        ↑ Importar Telefones
    </button>
</div>

<!-- Modal de importação em massa -->
<div id="phn-import-bg" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;"
     onclick="phnCloseImport()"></div>
<div id="phn-import-modal" class="overlay-dialogue modal modal-popup modal-popup-medium"
     style="display:none;top:80px;left:50%;transform:translateX(-50%);z-index:1001;" role="dialog">
    <div class="overlay-dialogue-header">
        <h4>Importar Telefones</h4>
        <button class="btn-overlay-close" onclick="phnCloseImport()"></button>
    </div>
    <div class="overlay-dialogue-body" style="padding:18px 22px;background:#fff;">
        <p style="color:#000;font-size:13px;margin:0 0 10px;">
            Arquivo <strong>CSV</strong> ou <strong>XLSX</strong> com uma coluna de usuário e uma de telefone:
        </p>
        <p style="font-size:13px;margin:0 0 10px;line-height:2;">
            <span style="color:#3b9c3b;font-weight:600;">Usuario</span><span style="color:#000;"> (login do Zabbix, ex.: z148534) &nbsp;·&nbsp; </span><span style="color:#3b9c3b;font-weight:600;">Telefone</span><span style="color:#000;"> (com ou sem máscara)</span>
        </p>
        <p style="font-size:12px;color:#666;margin:0 0 16px;line-height:1.6;">
            💡 Use <strong>↓ Exportar Usuários</strong> para baixar o modelo já preenchido,
            edite a coluna Telefone e reimporte — a coluna Nome é ignorada.<br>
            Linha com telefone <strong>vazio não apaga</strong> o cadastro: para remover,
            limpe o campo na tabela abaixo e salve.<br>
            Só são atualizados usuários que você já pode editar aqui.
        </p>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <label style="flex:1;min-width:220px;background:#fff;border:1px solid #aaa;border-radius:3px;
                          padding:6px 10px;color:#000;font-size:13px;cursor:pointer;
                          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <input type="file" id="phn-import-file" accept=".csv,.tsv,.txt,.xlsx"
                       style="display:none;" onchange="phnImportFileChosen(this)">
                <span id="phn-import-filename">Escolher arquivo…</span>
            </label>
            <button type="button" class="btn" id="phn-import-btn"
                    onclick="phnSubmitImport()" disabled>Importar</button>
        </div>
        <div id="phn-import-hint" style="margin-top:10px;font-size:12px;min-height:18px;"></div>
    </div>
    <div class="overlay-dialogue-footer">
        <button type="button" class="btn-alt" onclick="phnCloseImport()">Cancelar</button>
    </div>
</div>

<script>
// Token CSRF da action de importação (o form é montado no JS, então não dá
// para colocar o hidden direto no HTML como no form de salvar).
const PHN_CSRF_IMPORT = <?= json_encode($data['csrf_import'] ?? '') ?>;

function phnOpenImport() {
    document.getElementById('phn-import-bg').style.display = 'block';
    document.getElementById('phn-import-modal').style.display = 'block';
}
function phnCloseImport() {
    document.getElementById('phn-import-bg').style.display = 'none';
    document.getElementById('phn-import-modal').style.display = 'none';
    document.getElementById('phn-import-hint').textContent = '';
}
function phnImportFileChosen(inp) {
    var f = inp.files[0];
    document.getElementById('phn-import-filename').textContent = f ? f.name : 'Escolher arquivo…';
    document.getElementById('phn-import-btn').disabled = !f;
}
function phnSubmitImport() {
    var f = document.getElementById('phn-import-file').files[0];
    if (!f) return;
    var btn  = document.getElementById('phn-import-btn');
    var hint = document.getElementById('phn-import-hint');
    btn.disabled = true;              // anti duplo clique
    hint.style.color = '#666';
    hint.textContent = '⏳ Lendo arquivo…';

    var reader = new FileReader();
    reader.onload = function(e) {
        hint.textContent = '⏳ Enviando…';
        // Mesmo padrão do import da Escala: base64 em campo hidden, porque a
        // rota do Zabbix nesta action não trata multipart.
        var form = document.createElement('form');
        form.method = 'post';
        form.action = 'zabbix.php?action=plantonistas.phones.import';
        form.style.display = 'none';
        var add = function(name, val) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = name; i.value = val;
            form.appendChild(i);
        };
        add('import_file_b64',  e.target.result.split(',')[1]);
        add('import_file_name', f.name);
        add('_csrf_token',      PHN_CSRF_IMPORT);
        document.body.appendChild(form);
        form.submit();
    };
    reader.onerror = function() {
        hint.style.color = '#dc3545';
        hint.textContent = 'Erro ao ler o arquivo.';
        btn.disabled = false;
    };
    reader.readAsDataURL(f);
}
</script>

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
        $np = $u['label'] ?? trim($u['name'].' '.$u['surname']);
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
                <span class="phn-badge-ok"><?= htmlspecialchars($u['phone_fmt']) ?></span>
            <?php else: ?>
                <span class="phn-badge-no">não cadastrado</span>
            <?php endif; ?>
        </td>
        <td>
            <form method="post" action="zabbix.php?action=plantonistas.phones.save" class="phn-form">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($data['csrf_save'] ?? '') ?>">
                <input type="hidden" name="userid" value="<?= (int)$u['userid'] ?>">
                <input type="text" name="phone" id="phn-inp-<?= (int)$u['userid'] ?>"
                       value="<?= htmlspecialchars($u['phone_fmt']) ?>"
                       placeholder="(11) 99999-9999" maxlength="16"
                       style="width:140px;" autocomplete="off"
                       onblur="phnMask(this)">
                <button class="btn" type="submit">Salvar</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<script>
// Máscara espelhando PhonesFormat::formatPhoneBr() do PHP — as duas regras
// TÊM que concordar, senão a linha muda de formato ao recarregar a página.
//
// Roda no blur, não no input: a versão anterior formatava a cada tecla e
// assumia DDD sempre, então quem digitasse um ramal de 5 dígitos ou um fixo
// de 8 sem DDD via o número virar "(12) 345" no meio da digitação. O dado
// gravado nunca foi afetado (o backend só guarda dígitos), mas a tela mentia.
// Formatar quando o campo perde o foco deixa digitar em paz.
function phnMask(inp) {
    // NÃO truncar em 11 dígitos: o value daqui é o que vai no submit, então
    // cortar dígitos gravaria um telefone MUTILADO no banco (um número com
    // +55 colado, 13 dígitos, viraria "(55) 11987-6543" e salvaria errado).
    // Fora dos padrões conhecidos, devolve os dígitos inteiros — igual ao PHP.
    var d = inp.value.replace(/\D/g, '');
    var n = d.length;
    if (n === 11 && d.charAt(0) === '0') {
        // 0800 e afins: nenhum DDD começa com 0
        inp.value = d.substr(0, 4) + ' ' + d.substr(4, 3) + '-' + d.substr(7);
    } else if (n === 11) {
        inp.value = '(' + d.substr(0, 2) + ') ' + d.substr(2, 5) + '-' + d.substr(7);
    } else if (n === 10) {
        inp.value = '(' + d.substr(0, 2) + ') ' + d.substr(2, 4) + '-' + d.substr(6);
    } else if (n === 9) {
        inp.value = d.substr(0, 5) + '-' + d.substr(5);
    } else if (n === 8) {
        inp.value = d.substr(0, 4) + '-' + d.substr(4);
    } else {
        inp.value = d; // ramal, 0800, cadastro fora de padrão: deixa cru
    }
}

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
