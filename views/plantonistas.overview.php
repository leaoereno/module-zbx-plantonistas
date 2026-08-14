<?php declare(strict_types = 1);
/**
 * @var array $data  [today, groups, overview]
 */

$today    = $data['today'];
$groups   = $data['groups'];
$overview = $data['overview'];
$date_fmt = date('d/m/Y', strtotime($today));
$weekday  = ['Mon'=>'Segunda','Tue'=>'Terça','Wed'=>'Quarta',
             'Thu'=>'Quinta','Fri'=>'Sexta','Sat'=>'Sábado','Sun'=>'Domingo'];
$today_dow = $weekday[date('D', strtotime($today))] ?? date('D');

function ovName(string $n, string $s, string $u): string {
    $full = trim($n . ' ' . $s);
    return $full !== '' ? htmlspecialchars($full) : htmlspecialchars($u);
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
.ov-card.covered     { border-left: 4px solid #59db8f; }
.ov-card.not-covered { border-left: 4px solid #e45959; opacity: .75; }

.ov-group { font-size: 11px; font-weight: 700; color: #6a8a9a;
            text-transform: uppercase; letter-spacing: .6px; margin-bottom: 8px; }
.ov-status-badge {
    position: absolute; top: 14px; right: 14px;
    font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 10px;
}
.ov-status-badge.ok  { background: #162616; color: #59db8f; border: 1px solid #2a5a2a; }
.ov-status-badge.nok { background: #2a1010; color: #e45959; border: 1px solid #5a2a2a; }

.ov-tech-name  { font-size: 18px; font-weight: 700; color: #f0f0f0; margin-bottom: 4px; }
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
.ov-no-phone  { font-size: 12px; color: #555; font-style: italic; }

.ov-actions { margin-top: 16px; display: flex; gap: 8px; flex-wrap: wrap; }
</style>

<div class="ov-wrap">
    <div class="ov-date">
        <?= $today_dow ?>, <?= $date_fmt ?>
    </div>

    <?php if (empty($groups)): ?>
        <div style="color:#666;font-style:italic;">Nenhum grupo disponível.</div>
    <?php else: ?>

    <div class="ov-actions" style="margin-bottom:20px;">
        <button class="btn btn-alt" onclick="location.href='zabbix.php?action=plantonistas.list'">
            Gerenciar Escala
        </button>
    </div>

    <div class="ov-grid">
    <?php foreach ($groups as $gid => $gname):
        $e = $overview[$gid] ?? null;
        $covered = !empty($e);
    ?>
    <div class="ov-card <?= $covered ? 'covered' : 'not-covered' ?>">
        <div class="ov-group"><?= htmlspecialchars($gname) ?></div>
        <span class="ov-status-badge <?= $covered ? 'ok' : 'nok' ?>">
            <?= $covered ? 'Com cobertura' : 'Sem cobertura' ?>
        </span>

        <?php if ($covered): ?>
            <div class="ov-tech-name"><?= ovName($e['name'],$e['surname'],$e['username']) ?></div>
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
                <div class="ov-reserva-name"><?= ovName($e['r_name'],$e['r_surname'],$e['r_username']) ?></div>
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
