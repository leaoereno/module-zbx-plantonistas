<?php
/**
 * View: plantonistas.report.list — lista de repasses (abertos e fechados).
 *
 * Recebe $data do TurnosReportList. Sem regra de negócio aqui: o controller já
 * entrega cada linha com tipo, rótulo de turno e contagem prontos.
 *
 * O JS é inline de propósito — o F5 BIG-IP de produção bloqueia .js estático
 * (ver CLAUDE.md). Aqui ele só existe para a barra de atalhos de período.
 */

$rows      = $data['rows'] ?? [];
$from      = (string) ($data['from'] ?? date('Y-m-d'));
$to        = (string) ($data['to'] ?? date('Y-m-d'));
$status    = (string) ($data['status'] ?? 'todos');
$truncated = !empty($data['truncated']);

/**
 * Y-m-d → d/m/Y. O valor cru fica nos links e no input date, que é protocolo.
 *
 * O ramo de reserva devolve o valor do banco, então sai escapado: hoje ele vem
 * sempre de uma coluna DATE e é inexplorável, mas "todo valor do banco passa
 * por htmlspecialchars() na view" é regra do módulo, não avaliação caso a caso.
 */
function rpl_dateBr(string $ymd): string {
    $ts = strtotime($ymd);
    return $ts === false ? htmlspecialchars($ymd) : date('d/m/Y', $ts);
}

/** Dia da semana abreviado em pt-BR — ajuda a bater o olho e achar o turno. */
function rpl_diaSemana(string $ymd): string {
    $ts = strtotime($ymd);
    if ($ts === false) {
        return '';
    }
    return ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][(int) date('w', $ts)];
}

$totalFechados = 0;
$totalAbertos  = 0;
foreach ($rows as $r) {
    if ($r['tipo'] === 'fechado') {
        $totalFechados++;
    }
    else {
        $totalAbertos++;
    }
}
?>

<link rel="stylesheet" href="modules/module-zbx-plantonistas/assets/fontawesome/css/all.min.css"/>

<?php include __DIR__ . '/_theme.php'; ?>

<div class="rp-native-container" id="rpListContainer">

<div class="rp-native-header">
    <div class="rp-nh-left">
        <i class="fas fa-folder-open" style="font-size:20px;"></i>
        <span class="rp-nh-title">Repasses de Plantão</span>
        <span class="rp-nh-sub"><?= rpl_dateBr($from) ?> a <?= rpl_dateBr($to) ?></span>
    </div>
    <div class="rp-nh-right">
        <form method="GET" action="zabbix.php" class="rp-nh-controls" id="rplFiltro">
            <input type="hidden" name="action" value="plantonistas.report.list">
            <input type="date" name="from" class="rp-nh-input" value="<?= htmlspecialchars($from) ?>"
                   title="Data inicial" onchange="this.form.submit()">
            <input type="date" name="to" class="rp-nh-input" value="<?= htmlspecialchars($to) ?>"
                   title="Data final" onchange="this.form.submit()">
            <select name="status" class="rp-nh-input" onchange="this.form.submit()" title="Filtrar por situação">
                <option value="todos"    <?= $status === 'todos'    ? 'selected' : '' ?>>Todos</option>
                <option value="fechados" <?= $status === 'fechados' ? 'selected' : '' ?>>Só fechados</option>
                <option value="abertos"  <?= $status === 'abertos'  ? 'selected' : '' ?>>Só abertos</option>
            </select>
        </form>
        <a href="javascript:void(0)" class="rp-nh-btn" onclick="rplPeriodo(7)" title="Últimos 7 dias">7d</a>
        <a href="javascript:void(0)" class="rp-nh-btn" onclick="rplPeriodo(30)" title="Últimos 30 dias">30d</a>
        <a href="javascript:void(0)" class="rp-nh-btn" onclick="rplPeriodo(90)" title="Últimos 90 dias">90d</a>
        <a href="zabbix.php?action=plantonistas.report.view" class="rp-nh-btn" title="Voltar ao Repasse de Plantão">
            <i class="fas fa-arrow-left"></i> Repasse do dia
        </a>
    </div>
</div>

<?php if (!empty($data['db_error'])): ?>
<div class="rp-alert rp-alert-danger">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars((string) $data['db_error']) ?>
</div>
<?php endif; ?>

<?php if (!empty($data['notes_error'])): ?>
<div class="rp-alert rp-alert-warn">
    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars((string) $data['notes_error']) ?>
</div>
<?php endif; ?>

<?php if ($truncated): ?>
<div class="rp-alert rp-alert-warn">
    <i class="fas fa-info-circle"></i>
    O período tem mais de <?= (int) ($data['teto'] ?? 500) ?> fechamentos e a lista foi cortada.
    Reduza o intervalo de datas para ver os mais antigos — o corte acontece antes do filtro de
    visibilidade, então repasses seus podem ter ficado de fora.
</div>
<?php endif; ?>

<div class="rp-card">
    <div class="rp-card-head">
        <i class="fas fa-clipboard-list"></i> Repasses no período
        <!-- Contadores com as MESMAS pills das linhas. .rp-badge-warn é
             vermelho de severidade alta no CSS do Repasse — usá-lo aqui daria
             ao "N abertos" a cara de alarme crítico ao lado das pills âmbar. -->
        <span class="rpl-pill rpl-pill-closed"><?= (int) $totalFechados ?> fechado<?= $totalFechados === 1 ? '' : 's' ?></span>
        <span class="rpl-pill rpl-pill-open"><?= (int) $totalAbertos ?> aberto<?= $totalAbertos === 1 ? '' : 's' ?></span>
    </div>
    <div class="rp-card-desc">
        <strong>Fechado</strong> é o documento congelado no momento em que o turno acabou — é ele que vale como
        repasse formal, e abre com botão de baixar em PDF. <strong>Aberto</strong> é o turno que teve movimento
        no Diário de Bordo e nenhum fechamento <em>visível para você</em>; abre o Repasse ao vivo, cujos números
        mudam conforme o housekeeper do Zabbix vai apagando os eventos do período.
        <?php if (!$data['is_superadmin']): ?>
            Um turno fechado por alguém de outra equipe pode aparecer como aberto — o documento existe,
            mas a segmentação por grupo não o entrega a você.
        <?php endif; ?>
    </div>
    <div class="rp-card-body">
        <?php if (empty($rows)): ?>
            <div class="rp-empty">
                <?php if (!empty($data['db_error'])): ?>
                    Não foi possível carregar a lista — veja o aviso acima.
                <?php else: ?>
                    Nenhum repasse neste período.
                    <?php if (!$data['is_superadmin']): ?>
                        Lembrando que você só enxerga o que foi fechado por alguém dos seus grupos.
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <table class="rp-table rpl-table">
            <thead>
                <tr>
                    <th style="width:130px">Data</th>
                    <th>Turno</th>
                    <th style="width:110px">Situação</th>
                    <th style="width:160px">Fechado em</th>
                    <th>Fechado por</th>
                    <th style="width:110px" title="Notas do Diário de Bordo hoje, no turno inteiro — não é o que ficou congelado em cada documento">Notas do turno</th>
                    <th style="width:230px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $fechado = $r['tipo'] === 'fechado';
                // Link do repasse ao vivo: date/shift em Y-m-d e código cru —
                // é a mesma querystring que a tela do Repasse já lê.
                $urlAoVivo = 'zabbix.php?action=plantonistas.report.view'
                           . '&date=' . urlencode($r['date'])
                           . '&shift=' . urlencode($r['code']);
                $urlDoc    = 'zabbix.php?action=plantonistas.report.pdf&report_id=' . (int) $r['id'];
            ?>
                <tr class="<?= $fechado ? '' : 'rpl-row-open' ?>">
                    <td>
                        <strong><?= rpl_dateBr($r['date']) ?></strong>
                        <span class="rp-muted"><?= rpl_diaSemana($r['date']) ?></span>
                    </td>
                    <td>
                        <?= htmlspecialchars((string) $r['shift_label']) ?>
                        <?php if (!$r['shift_ativo']): ?>
                            <!-- "não listado" e não "desativado": o seletor de turnos só
                                 traz os turnos das equipes de quem está olhando (exceto
                                 Super Admin), então o turno pode estar ativo e ser de
                                 outro grupo. Afirmar a causa errada é pior que descrevê-la. -->
                            <span class="rp-muted"
                                  title="Turno não listado nas suas equipes: desativado, removido ou de outro grupo">
                                (não listado)
                            </span>
                        <?php endif; ?>
                        <?php if ($r['noc_context'] !== ''): ?>
                            <span class="rpl-ctx"><?= htmlspecialchars((string) $r['noc_context']) ?></span>
                        <?php endif; ?>
                        <?php if ($fechado && (int) $r['ordem_total'] > 1): ?>
                            <span class="rpl-pill <?= (int) $r['ordem'] === 1 ? 'rpl-pill-cur' : 'rpl-pill-old' ?>"
                                  title="<?= (int) $r['ordem'] === 1
                                        ? 'Fechamento vigente deste turno'
                                        : 'Fechamento anterior — o vigente é o mais recente, no topo do grupo' ?>">
                                fechamento <?= (int) $r['ordem_cron'] ?> de <?= (int) $r['ordem_total'] ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($fechado): ?>
                            <span class="rpl-pill rpl-pill-closed"><i class="fas fa-lock"></i> Fechado</span>
                        <?php else: ?>
                            <span class="rpl-pill rpl-pill-open"><i class="fas fa-lock-open"></i> Aberto</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $fechado ? htmlspecialchars((string) $r['closed_at']) : '<span class="rp-muted">—</span>' ?></td>
                    <td><?= $fechado ? htmlspecialchars((string) $r['closed_by']) : '<span class="rp-muted">—</span>' ?></td>
                    <td>
                        <?php
                        // A contagem é por TURNO (data + código), não por documento: ela
                        // vem do estado atual do Diário de Bordo, enquanto cada
                        // fechamento congelou as notas que existiam naquele instante.
                        // Repetir o mesmo número em cada refechamento faria a coluna
                        // somar 15 para 5 notas — por isso só a linha vigente a exibe.
                        $mostraNotas = !$fechado || (int) $r['ordem'] === 1;
                        ?>
                        <?php if ($mostraNotas && (int) $r['notas'] > 0): ?>
                            <span class="rp-badge" title="Última nota: <?= htmlspecialchars((string) $r['last_note']) ?>">
                                <?= (int) $r['notas'] ?>
                            </span>
                        <?php elseif ($mostraNotas): ?>
                            <span class="rp-muted">—</span>
                        <?php else: ?>
                            <span class="rp-muted" title="A contagem aparece na linha do fechamento vigente deste turno">·</span>
                        <?php endif; ?>
                    </td>
                    <td class="rpl-actions">
                        <?php if ($fechado): ?>
                            <a class="rpl-btn rpl-btn-primary" href="<?= $urlDoc ?>" target="_blank"
                               title="Abre o documento congelado, com botão de baixar em PDF">
                                <i class="fas fa-file-contract"></i> Abrir documento
                            </a>
                        <?php endif; ?>
                        <a class="rpl-btn" href="<?= $urlAoVivo ?>"
                           title="<?= $fechado
                                    ? 'Abre o repasse ao vivo desta data/turno (números atuais, não os do fechamento)'
                                    : 'Abre o repasse ao vivo — é lá que se fecha o turno' ?>">
                            <i class="fas fa-external-link-alt"></i> <?= $fechado ? 'Ao vivo' : 'Abrir repasse' ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /rp-native-container -->

<script>
// Atalhos de período. Reescrevem os dois campos de data e reenviam o mesmo
// formulário — assim a URL resultante é idêntica à de quem escolheu na mão, e
// continua funcionando como link salvo/favorito.
function rplPeriodo(dias) {
    var f = document.getElementById('rplFiltro');
    if (!f) return;

    // Data montada por componentes locais, NUNCA por toISOString(): em UTC-3,
    // das 21h em diante o toISOString devolve a data de amanhã e o período
    // inteiro desliza um dia. Já mordeu este módulo no heatmap do Repasse.
    var hoje = new Date();
    var ini  = new Date();
    ini.setDate(ini.getDate() - (dias - 1));

    var iso = function (d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var x = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + x;
    };

    f.elements.from.value = iso(ini);
    f.elements.to.value   = iso(hoje);
    f.submit();
}
</script>
