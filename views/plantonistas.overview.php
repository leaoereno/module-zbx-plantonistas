<?php declare(strict_types = 1);
/**
 * @var array $data  [today, groups, shifts_by_group, overview]
 */

$today           = $data['today'];
$groups          = $data['groups'];
$shifts_by_group = $data['shifts_by_group'];
$overview        = $data['overview'];
$date_fmt = date('d/m/Y', strtotime($today));
$weekday  = ['Mon'=>'Segunda','Tue'=>'Terça','Wed'=>'Quarta',
             'Thu'=>'Quinta','Fri'=>'Sexta','Sat'=>'Sábado','Sun'=>'Domingo'];
$today_dow = $weekday[date('D', strtotime($today))] ?? date('D');

// Rótulo já vem montado do controller (UserLabel). Aqui só escapa, com
// reserva para o caso de a chave faltar.
function ovName(array $e, string $campo, string $fallback_user): string {
    $lbl = (string) ($e[$campo] ?? '');
    return htmlspecialchars($lbl !== '' ? $lbl : $fallback_user);
}

ob_start(); ?>
<style>
.ov-wrap  { padding: 4px 0 20px; }
.ov-date  { font-size: 13px; color: #8faabc; margin-bottom: 22px; }
.ov-grid  {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
    gap: 16px;
}
.ov-card  {
    background: #2b2b2b; border: 1px solid #4f4f4f;
    border-radius: 6px; padding: 18px 20px;
    position: relative; overflow: hidden;
}
.ov-card.covered      { border-left: 4px solid #59db8f; }
.ov-card.not-covered  { border-left: 4px solid #e45959; opacity: .75; }
.ov-card.partial-cover{ border-left: 4px solid #e99003; }

.ov-group { font-size: 11px; font-weight: 700; color: #6a8a9a;
            text-transform: uppercase; letter-spacing: .6px; margin-bottom: 8px; }
.ov-status-badge {
    position: absolute; top: 14px; right: 14px;
    font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
}
.ov-status-badge.ok      { background: #162616; color: #59db8f; border: 1px solid #2a5a2a; }
.ov-status-badge.nok     { background: #2a1010; color: #e45959; border: 1px solid #5a2a2a; }
.ov-status-badge.partial { background: #2a1a00; color: #e99003; border: 1px solid #7a4400; }

.ov-tech-name  { font-size: 18px; font-weight: 700; color: #f0f0f0; margin-bottom: 4px; }
.ov-tech-name.small { font-size: 14px; margin-bottom: 2px; }
.ov-tech-phone { font-size: 13px; color: #8faabc; margin-bottom: 12px; }
.ov-tech-phone a { color: #59db8f; text-decoration: none; }
.ov-tech-phone a:hover { text-decoration: underline; }

.ov-reserva-section { border-top: 1px solid #3a3a3a; padding-top: 10px; }
.ov-reserva-label { font-size: 10px; color: #666; text-transform: uppercase;
                    letter-spacing: .5px; margin-bottom: 4px; }
.ov-reserva-name  { font-size: 14px; font-weight: 600; color: #c8d8e4; }
.ov-reserva-phone { font-size: 12px; color: #8faabc; }
.ov-reserva-phone a { color: #59db8f; text-decoration: none; }

.ov-empty-msg { font-size: 14px; color: #e45959; font-style: italic; margin-top: 4px; }
.ov-empty-msg.small { font-size: 12px; margin-top: 0; margin-bottom: 12px; }
.ov-no-phone  { font-size: 12px; color: #555; font-style: italic; }

.ov-actions { margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap; }

/* Grupos com turnos: lista de turnos dentro do card */
.ov-shift-list { display: flex; flex-direction: column; gap: 10px; margin-top: 4px; }
.ov-shift-row  { border-top: 1px solid #3a3a3a; padding-top: 8px; }
.ov-shift-row:first-child { border-top: none; padding-top: 0; }
.ov-shift-name {
    font-size: 11px; font-weight: 700; color: #7a9ab4;
    text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px;
}
.ov-shift-time { font-weight: 400; color: #666; margin-left: 4px; text-transform: none; letter-spacing: 0; }
</style>

<div class="ov-wrap">
    <div class="ov-date">
        <?= $today_dow ?>, <?= $date_fmt ?>
    </div>

    <?php if (empty($groups)): ?>
        <div style="color:#666;font-style:italic;">Nenhum grupo disponível.</div>
    <?php else: ?>

    <?php if (!empty($data['can_manage'])): ?>
    <div class="ov-actions" style="margin-bottom:20px;">
        <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.list'">
            Gerenciar Escala
        </button>
    </div>
    <?php endif; ?>

    <div class="ov-grid">
    <?php foreach ($groups as $gid => $gname):
        $shifts_g     = $shifts_by_group[$gid] ?? [];
        $entries_g    = $overview[$gid] ?? [];
        $has_shifts_g = !empty($shifts_g);

        if (!$has_shifts_g) {
            $e          = $entries_g[0] ?? null;
            $covered    = !empty($e);
            $status_cls = $covered ? 'ok'      : 'nok';
            $status_lbl = $covered ? 'Com cobertura' : 'Sem cobertura';
            $card_cls   = $covered ? 'covered' : 'not-covered';
        } else {
            $filled = 0;
            foreach ($shifts_g as $sid => $s) {
                if (!empty($entries_g[$sid])) $filled++;
            }
            $total = count($shifts_g);
            if ($filled === 0) {
                $status_cls = 'nok'; $status_lbl = 'Sem cobertura'; $card_cls = 'not-covered';
            } elseif ($filled < $total) {
                $status_cls = 'partial'; $status_lbl = 'Cobertura parcial'; $card_cls = 'partial-cover';
            } else {
                $status_cls = 'ok'; $status_lbl = 'Com cobertura'; $card_cls = 'covered';
            }
        }
    ?>
    <div class="ov-card <?= $card_cls ?>">
        <div class="ov-group"><?= htmlspecialchars($gname) ?></div>
        <span class="ov-status-badge <?= $status_cls ?>"><?= $status_lbl ?></span>

        <?php if (!$has_shifts_g): ?>
            <?php if ($covered): ?>
                <div class="ov-tech-name"><?= ovName($e,'label',$e['username']) ?></div>
                <div class="ov-tech-phone">
                    <?php if ($e['phone']): ?>
                        <a href="tel:<?= htmlspecialchars($e['phone']) ?>"><?= htmlspecialchars($e['phone']) ?></a>
                    <?php else: ?>
                        <span class="ov-no-phone">sem telefone cadastrado</span>
                    <?php endif; ?>
                </div>

                <?php if ($e['userid_reserva']): ?>
                <div class="ov-reserva-section">
                    <div class="ov-reserva-label">Reserva</div>
                    <div class="ov-reserva-name"><?= ovName($e,'r_label',$e['r_username']) ?></div>
                    <div class="ov-reserva-phone">
                        <?php if ($e['r_phone']): ?>
                            <a href="tel:<?= htmlspecialchars($e['r_phone']) ?>"><?= htmlspecialchars($e['r_phone']) ?></a>
                        <?php else: ?>
                            <span class="ov-no-phone">sem telefone</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="ov-empty-msg">Nenhum plantonista escalado para hoje.</div>
            <?php endif; ?>

        <?php else: ?>
            <div class="ov-shift-list">
            <?php foreach ($shifts_g as $sid => $s):
                $e = $entries_g[$sid] ?? null;
            ?>
                <div class="ov-shift-row">
                    <div class="ov-shift-name">
                        <?= htmlspecialchars($s['name']) ?>
                        <span class="ov-shift-time"><?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?></span>
                    </div>
                    <?php if ($e): ?>
                        <div class="ov-tech-name small"><?= ovName($e,'label',$e['username']) ?></div>
                        <div class="ov-tech-phone" style="margin-bottom:0;">
                            <?php if ($e['phone']): ?>
                                <a href="tel:<?= htmlspecialchars($e['phone']) ?>"><?= htmlspecialchars($e['phone']) ?></a>
                            <?php else: ?>
                                <span class="ov-no-phone">sem telefone cadastrado</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="ov-empty-msg small">Sem cobertura neste turno.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>
<?php
$html = ob_get_clean();
$raw  = new class($html) {
    public function __construct(private string $h) {}
    public function toString(bool $d=true): string { return $this->h; }
};
(new CHtmlPage())->setTitle('Plantão Hoje')->addItem($raw)->show();
