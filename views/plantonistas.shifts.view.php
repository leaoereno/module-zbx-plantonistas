<?php
/**
 * View: plantonistas.shifts.view — Tela de administração de Turnos
 * Restrita a Admin/Super Admin. Recebe $data do TurnosShiftsView controller.
 */

$groups       = $data['groups'] ?? [];
$legacyShifts = $data['legacy_shifts'] ?? [];
?>

<link rel="stylesheet" href="modules/module-zbx-plantonistas/assets/fontawesome/css/all.min.css"/>

<div class="rp-native-container" id="rpShiftsContainer">
<script>
    let IS_DARK_THEME = false;
    (function(){
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

<div class="rp-native-header">
    <div class="rp-nh-left">
        <i class="fas fa-user-clock" style="font-size:20px;"></i>
        <span class="rp-nh-title">Gerenciar Turnos</span>
        <span class="rp-nh-sub">Cadastro de turnos por equipe e vínculo de analistas</span>
    </div>
    <div class="rp-nh-right">
        <a href="zabbix.php?action=plantonistas.report.view" class="rp-nh-btn" title="Voltar ao Repasse de Plantão">
            <i class="fas fa-arrow-left"></i> Voltar ao Relatório
        </a>
    </div>
</div>

<?php if (!empty($data['db_error'])): ?>
<div class="rp-alert rp-alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($data['db_error']) ?></div>
<?php endif; ?>

<?php if (empty($groups)): ?>
<div class="rp-card"><div class="rp-card-body rp-empty">
    Nenhuma equipe (grupo de usuário) disponível para gerenciar turnos.
    <?php if (!$data['is_superadmin']): ?>
        Você precisa pertencer a pelo menos um grupo de usuário no Zabbix.
    <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if (count($groups) > 1): ?>
<div class="rp-tabs" id="rpGroupTabs">
    <?php foreach ($groups as $i => $g): ?>
    <div class="rp-tab <?= $i === 0 ? 'active' : '' ?>" data-tab-target="rp-group-<?= (int)$g['usrgrpid'] ?>">
        <i class="fas fa-users"></i> <?= htmlspecialchars($g['name']) ?>
        <span class="rp-badge" data-role="shift-count-badge" data-usrgrpid="<?= (int)$g['usrgrpid'] ?>"><?= count($g['shifts']) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($groups as $i => $g): ?>
<div class="rp-card rp-shift-group rp-tab-panel <?= (count($groups) > 1 && $i !== 0) ? 'rp-tab-panel-hidden' : '' ?>"
     id="rp-group-<?= (int)$g['usrgrpid'] ?>" data-usrgrpid="<?= (int)$g['usrgrpid'] ?>">
    <?php if (count($groups) === 1): ?>
    <div class="rp-card-head">
        <i class="fas fa-users"></i> <?= htmlspecialchars($g['name']) ?>
        <span class="rp-badge" data-role="shift-count-badge" data-usrgrpid="<?= (int)$g['usrgrpid'] ?>"><?= count($g['shifts']) ?> turno(s)</span>
    </div>
    <?php endif; ?>
    <div class="rp-card-desc">
        Turnos cadastrados manualmente para esta equipe. Edite um campo e clique no disquete da linha pra salvar
        (linhas com edição pendente ficam marcadas em amarelo até você salvar ou recarregar a página).
    </div>

    <div class="rp-legacy-shifts">
        <span class="rp-legacy-label"><i class="fas fa-info-circle"></i> Sempre disponíveis pra todo mundo, fixos (não editáveis aqui):</span>
        <?php foreach ($legacyShifts as $label): ?>
            <span class="rp-legacy-chip"><?= htmlspecialchars($label) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="rp-card-body">
        <table class="rp-table rp-shift-table">
            <thead>
                <tr><th>Nome</th><th>Início</th><th>Fim</th><th>Ordem</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($g['shifts'] as $s): ?>
                <tr data-shift-id="<?= (int)$s['id'] ?>">
                    <td><input type="text" class="rp-input rp-shift-name" value="<?= htmlspecialchars($s['name']) ?>" maxlength="50"></td>
                    <td><input type="time" class="rp-input rp-shift-start" value="<?= substr($s['start_time'],0,5) ?>"></td>
                    <td><input type="time" class="rp-input rp-shift-end" value="<?= substr($s['end_time'],0,5) ?>"></td>
                    <td><input type="number" class="rp-input rp-shift-order" style="width:60px" value="<?= (int)$s['sort_order'] ?>"></td>
                    <td class="td-center">
                        <button type="button" class="rp-action rp-action-save rp-shift-save" title="Salvar"><i class="fas fa-save"></i></button>
                        <button type="button" class="rp-action rp-action-danger rp-shift-delete" title="Remover"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tbody class="rp-shift-new-body">
                <tr class="rp-shift-new-row">
                    <td><input type="text" class="rp-input rp-shift-name" placeholder="Ex: Diurno, Turno 1..." maxlength="50"></td>
                    <td><input type="time" class="rp-input rp-shift-start" value="07:00"></td>
                    <td><input type="time" class="rp-input rp-shift-end" value="19:00"></td>
                    <td><input type="number" class="rp-input rp-shift-order" style="width:60px" value="0"></td>
                    <td class="td-center">
                        <button type="button" class="rp-action rp-action-save rp-shift-add" title="Adicionar turno"><i class="fas fa-plus-circle"></i></button>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="rp-shift-status"></div>
    </div>

    <div class="rp-card-head" style="border-top:1px solid var(--rp-border,#e0e0e0);">
        <i class="fas fa-id-badge"></i> Analistas da Equipe
    </div>
    <div class="rp-card-body">
        <?php if (!empty($g['users_error'])): ?>
            <div class="rp-alert rp-alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Não consegui carregar os analistas desta equipe (erro de consulta, não é que o grupo esteja vazio).
                Verifique o log do PHP-FPM (<code>[plantonistas] listUsersByGroup()</code>).
            </div>
        <?php elseif (empty($g['users'])): ?>
            <div class="rp-empty">Nenhum usuário Zabbix neste grupo.</div>
        <?php else: ?>
        <table class="rp-table">
            <thead><tr><th>Analista</th><th>Username</th><th>Turno</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($g['users'] as $u): ?>
                <tr data-userid="<?= (int)$u['userid'] ?>">
                    <td class="td-bold"><?= htmlspecialchars(trim($u['fullname']) ?: $u['username']) ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td>
                        <select class="rp-input rp-user-shift">
                            <option value="">— Sem turno —</option>
                            <?php foreach ($g['shifts'] as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= ((int)($u['shift_id'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?> (<?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="td-center">
                        <button type="button" class="rp-action rp-action-save rp-user-shift-save" title="Salvar vínculo"><i class="fas fa-save"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <div class="rp-user-shift-status"></div>
    </div>
</div>
<?php endforeach; ?>

</div><!-- /rpShiftsContainer -->

<script>
(function(){
    function post(action, params) {
        return fetch('zabbix.php?action=' + action, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: new URLSearchParams(params)
        }).then(r => r.json());
    }

    // Trava/destrava um conjunto de botões enquanto uma chamada assíncrona
    // está pendente — evita clique duplo disparando duas requisições antes
    // da resposta voltar (dobra de turno, tentativa dupla de remover etc.).
    function withBusy(buttons, fn) {
        buttons.forEach(b => { b.disabled = true; b.classList.add('is-busy'); });
        const restore = () => buttons.forEach(b => { b.disabled = false; b.classList.remove('is-busy'); });
        return fn().finally(restore);
    }

    // ── Abas de equipe ──────────────────────────────────────────
    document.querySelectorAll('#rpGroupTabs .rp-tab').forEach(function(tab){
        tab.addEventListener('click', function(){
            document.querySelectorAll('#rpGroupTabs .rp-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.rp-tab-panel').forEach(p => p.classList.add('rp-tab-panel-hidden'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tabTarget).classList.remove('rp-tab-panel-hidden');
        });
    });

    function shiftOptionLabel(name, start, end) {
        return name + ' (' + start + '–' + end + ')';
    }

    function updateBadge(usrgrpid, delta) {
        document.querySelectorAll('.rp-badge[data-role="shift-count-badge"][data-usrgrpid="' + usrgrpid + '"]').forEach(function(b){
            var n = (parseInt(b.textContent, 10) || 0) + delta;
            b.textContent = (document.querySelectorAll('#rpGroupTabs').length ? n : n + ' turno(s)');
        });
    }

    document.querySelectorAll('.rp-shift-group').forEach(function(group){
        const usrgrpid = group.dataset.usrgrpid;
        const status = group.querySelector('.rp-shift-status');

        function showStatus(msg, ok) {
            if (!status) return;
            status.textContent = msg;
            status.style.color = ok ? '#2e7d32' : '#c62828';
            setTimeout(() => { status.textContent = ''; }, 4000);
        }

        // Marca a linha como "alterada, ainda não salva" assim que qualquer
        // campo muda — sem isso, sair da linha sem clicar no disquete perdia
        // a edição sem nenhum aviso.
        function bindDirtyTracking(row) {
            row.querySelectorAll('.rp-input').forEach(function(inp){
                inp.addEventListener('input', function(){ row.classList.add('rp-row-dirty'); });
            });
        }
        group.querySelectorAll('.rp-shift-table tbody tr[data-shift-id]').forEach(bindDirtyTracking);

        function bindShiftRowButtons(row) {
            const saveBtn = row.querySelector('.rp-shift-save');
            const delBtn  = row.querySelector('.rp-shift-delete');

            saveBtn.addEventListener('click', function(){
                withBusy([saveBtn, delBtn].filter(Boolean), function(){
                    return post('plantonistas.shifts.save', {
                        id: row.dataset.shiftId || '',
                        usrgrpid: usrgrpid,
                        name: row.querySelector('.rp-shift-name').value,
                        start_time: row.querySelector('.rp-shift-start').value,
                        end_time: row.querySelector('.rp-shift-end').value,
                        sort_order: row.querySelector('.rp-shift-order').value || '0'
                    }).then(j => {
                        showStatus(j.message || (j.success ? 'Salvo.' : 'Erro.'), j.success);
                        if (j.success) {
                            row.classList.remove('rp-row-dirty');
                            const name  = row.querySelector('.rp-shift-name').value;
                            const start = row.querySelector('.rp-shift-start').value;
                            const end   = row.querySelector('.rp-shift-end').value;
                            updateUserShiftOption(usrgrpid, row.dataset.shiftId, shiftOptionLabel(name, start, end));
                        }
                    }).catch(() => showStatus('Erro de conexão.', false));
                });
            });

            delBtn.addEventListener('click', function(){
                if (!confirm('Remover este turno? Os analistas vinculados a ele ficarão sem turno.')) return;
                withBusy([saveBtn, delBtn], function(){
                    return post('plantonistas.shifts.delete', { id: row.dataset.shiftId }).then(j => {
                        showStatus(j.message || (j.success ? 'Removido.' : 'Erro.'), j.success);
                        if (j.success) {
                            removeUserShiftOption(usrgrpid, row.dataset.shiftId);
                            row.remove();
                            updateBadge(usrgrpid, -1);
                        }
                    }).catch(() => showStatus('Erro de conexão.', false));
                });
            });
        }
        group.querySelectorAll('.rp-shift-table tbody tr[data-shift-id]').forEach(bindShiftRowButtons);

        const addRow = group.querySelector('.rp-shift-new-row');
        const addBtn = addRow ? addRow.querySelector('.rp-shift-add') : null;
        if (addBtn) {
            addBtn.addEventListener('click', function(){
                const name = addRow.querySelector('.rp-shift-name').value.trim();
                if (!name) { showStatus('Informe um nome para o turno.', false); return; }
                const start = addRow.querySelector('.rp-shift-start').value;
                const end   = addRow.querySelector('.rp-shift-end').value;
                const order = addRow.querySelector('.rp-shift-order').value || '0';

                withBusy([addBtn], function(){
                    return post('plantonistas.shifts.save', {
                        id: '', usrgrpid: usrgrpid, name: name,
                        start_time: start, end_time: end, sort_order: order
                    }).then(j => {
                        showStatus(j.message || (j.success ? 'Turno criado.' : 'Erro.'), j.success);
                        if (!j.success) return;

                        // Transforma a linha "nova" em uma linha real (salvar + remover),
                        // sem recarregar a página — e libera uma linha nova em branco
                        // pra continuar cadastrando turnos em seguida.
                        const tbody = group.querySelector('.rp-shift-table tbody:not(.rp-shift-new-body)');
                        const newRow = document.createElement('tr');
                        newRow.dataset.shiftId = j.id;
                        newRow.innerHTML =
                            '<td><input type="text" class="rp-input rp-shift-name" value="' + escAttr(name) + '" maxlength="50"></td>' +
                            '<td><input type="time" class="rp-input rp-shift-start" value="' + escAttr(start) + '"></td>' +
                            '<td><input type="time" class="rp-input rp-shift-end" value="' + escAttr(end) + '"></td>' +
                            '<td><input type="number" class="rp-input rp-shift-order" style="width:60px" value="' + escAttr(order) + '"></td>' +
                            '<td class="td-center">' +
                                '<button type="button" class="rp-action rp-action-save rp-shift-save" title="Salvar"><i class="fas fa-save"></i></button>' +
                                '<button type="button" class="rp-action rp-action-danger rp-shift-delete" title="Remover"><i class="fas fa-trash"></i></button>' +
                            '</td>';
                        tbody.appendChild(newRow);
                        bindDirtyTracking(newRow);
                        bindShiftRowButtons(newRow);
                        addUserShiftOption(usrgrpid, j.id, shiftOptionLabel(name, start, end));
                        updateBadge(usrgrpid, 1);

                        addRow.querySelector('.rp-shift-name').value = '';
                        addRow.querySelector('.rp-shift-start').value = '07:00';
                        addRow.querySelector('.rp-shift-end').value = '19:00';
                        addRow.querySelector('.rp-shift-order').value = '0';
                    }).catch(() => showStatus('Erro de conexão.', false));
                });
            });
        }

        const uStatus = group.querySelector('.rp-user-shift-status');
        function bindUserShiftSave(btn) {
            btn.addEventListener('click', function(){
                const row = btn.closest('tr');
                const userid = row.dataset.userid;
                const shiftId = row.querySelector('.rp-user-shift').value;
                withBusy([btn], function(){
                    return post('plantonistas.usershift.save', { userid: userid, shift_id: shiftId }).then(j => {
                        if (uStatus) {
                            uStatus.textContent = j.message || (j.success ? 'Salvo.' : 'Erro.');
                            uStatus.style.color = j.success ? '#2e7d32' : '#c62828';
                            setTimeout(() => { uStatus.textContent = ''; }, 4000);
                        }
                    }).catch(() => {
                        if (uStatus) { uStatus.textContent = 'Erro de conexão.'; uStatus.style.color = '#c62828'; }
                    });
                });
            });
        }
        group.querySelectorAll('.rp-user-shift-save').forEach(bindUserShiftSave);

        // ── Sincroniza o <select> de turno de cada analista quando um turno
        // é criado, editado ou removido, sem precisar recarregar a página.
        function updateUserShiftOption(gid, shiftId, label) {
            if (String(gid) !== String(usrgrpid)) return;
            group.querySelectorAll('.rp-user-shift option[value="' + shiftId + '"]').forEach(function(opt){
                opt.textContent = label;
            });
        }
        function addUserShiftOption(gid, shiftId, label) {
            if (String(gid) !== String(usrgrpid)) return;
            group.querySelectorAll('.rp-user-shift').forEach(function(sel){
                const opt = document.createElement('option');
                opt.value = shiftId;
                opt.textContent = label;
                sel.appendChild(opt);
            });
        }
        function removeUserShiftOption(gid, shiftId) {
            if (String(gid) !== String(usrgrpid)) return;
            group.querySelectorAll('.rp-user-shift').forEach(function(sel){
                if (sel.value === String(shiftId)) sel.value = '';
                const opt = sel.querySelector('option[value="' + shiftId + '"]');
                if (opt) opt.remove();
            });
        }
    });

    function escAttr(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : s;
        return d.innerHTML.replace(/"/g, '&quot;');
    }
})();
</script>
