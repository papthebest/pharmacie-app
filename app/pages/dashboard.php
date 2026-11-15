<?php
require_right('dashboard');
$me = user();
$jours = (int)setting('delai_alerte_peremption', '90');

$today = one("SELECT COUNT(*) AS nb, COALESCE(SUM(total),0) AS ca, COALESCE(SUM(part_assurance),0) AS ass
              FROM sales WHERE pharmacy_id = ? AND statut = 'payee' AND DATE(paid_at) = CURDATE()", [ph()]);
$pending = (int)val("SELECT COUNT(*) FROM sales WHERE pharmacy_id = ? AND statut = 'en_attente'", [ph()]);
$low = (int)val("SELECT COUNT(*) FROM (SELECT p.id, p.seuil_alerte,
                 COALESCE((SELECT SUM(quantite) FROM lots l WHERE l.product_id = p.id AND l.date_peremption >= CURDATE()),0) AS st
                 FROM products p WHERE p.pharmacy_id = ? AND p.actif = 1) x WHERE st <= seuil_alerte", [ph()]);
$soon = (int)val("SELECT COUNT(*) FROM lots WHERE pharmacy_id = ? AND quantite > 0 AND date_peremption BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $jours DAY)", [ph()]);
$expired = (int)val("SELECT COUNT(*) FROM lots WHERE pharmacy_id = ? AND quantite > 0 AND date_peremption < CURDATE()", [ph()]);

$days = [];
for ($i = 13; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i day"))] = 0;
foreach (all("SELECT DATE(paid_at) d, SUM(total) t FROM sales WHERE pharmacy_id = ? AND statut = 'payee' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(paid_at)", [ph()]) as $r) {
    $days[$r['d']] = (int)$r['t'];
}
$max = max(1, max($days));

page_header('Tableau de bord', 'dashboard');
?>
<div class="kpis">
  <div class="kpi"><small>Chiffre d'affaires du jour</small><strong><?= money($today['ca']) ?></strong></div>
  <div class="kpi"><small>Ventes du jour</small><strong><?= (int)$today['nb'] ?></strong></div>
  <div class="kpi"><small>Panier moyen</small><strong><?= money($today['nb'] ? $today['ca'] / $today['nb'] : 0) ?></strong></div>
  <div class="kpi <?= $pending ? 'alert' : '' ?>"><small>Bons en attente de paiement</small><strong><?= $pending ?></strong></div>
</div>

<?php if (can('stock')): ?>
<div class="kpis">
  <a class="kpi link <?= $low ? 'alert' : '' ?>" href="<?= url('stock', ['tab' => 'alertes']) ?>"><small>Médicaments en stock bas</small><strong><?= $low ?></strong></a>
  <a class="kpi link <?= $soon ? 'alert' : '' ?>" href="<?= url('stock', ['tab' => 'peremption']) ?>"><small>Lots qui périment sous <?= $jours ?> jours</small><strong><?= $soon ?></strong></a>
  <a class="kpi link <?= $expired ? 'danger' : '' ?>" href="<?= url('stock', ['tab' => 'perimes']) ?>"><small>Lots périmés à retirer</small><strong><?= $expired ?></strong></a>
</div>
<?php endif; ?>

<div class="card">
  <h2>Ventes des 14 derniers jours</h2>
  <div class="bars">
    <?php foreach ($days as $d => $t): ?>
      <div class="bar" title="<?= dfr($d) ?> : <?= money($t) ?>">
        <span class="v"><?= $t ? number_format($t / 1000, 0, ',', ' ') . 'k' : '' ?></span>
        <i style="height:<?= round($t / $max * 100, 1) ?>%"></i>
        <span class="d"><?= date('d/m', strtotime($d)) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="quick">
  <?php if (can('comptoir')): ?><a class="btn primary big" href="<?= url('comptoir') ?>">Nouveau bon au comptoir</a><?php endif; ?>
  <?php if (can('caisse')): ?><a class="btn big" href="<?= url('caisse') ?>">Aller à la caisse</a><?php endif; ?>
  <?php if (can('receptions')): ?><a class="btn big" href="<?= url('receptions', ['new' => 1]) ?>">Enregistrer une réception</a><?php endif; ?>
</div>
<?php page_footer();
