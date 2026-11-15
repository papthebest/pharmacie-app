<?php
require_right('rapports');
$du = get('du', date('Y-m-01'));
$au = get('au', date('Y-m-d'));
$p = [$du, $au];
$base = "s.statut = 'payee' AND DATE(s.paid_at) BETWEEN ? AND ?";

$tot = one("SELECT COUNT(*) nb, COALESCE(SUM(total),0) ca, COALESCE(SUM(part_patient),0) pat, COALESCE(SUM(part_assurance),0) ass FROM sales s WHERE $base", $p);
$cout = (int)val("SELECT COALESCE(SUM(sll.quantite * lo.prix_achat),0) FROM sales s JOIN sale_lines l ON l.sale_id = s.id
                  JOIN sale_line_lots sll ON sll.sale_line_id = l.id JOIN lots lo ON lo.id = sll.lot_id WHERE $base", $p);
$modes = all("SELECT mode_paiement m, COUNT(*) nb, SUM(part_patient) t FROM sales s WHERE $base GROUP BY mode_paiement", $p);
$caissiers = all("SELECT u.nom, COUNT(*) nb, SUM(s.part_patient) t FROM sales s JOIN users u ON u.id = s.caissier_id WHERE $base GROUP BY u.id ORDER BY t DESC", $p);
$vendeurs = all("SELECT u.nom, COUNT(*) nb, SUM(s.total) t FROM sales s JOIN users u ON u.id = s.vendeur_id WHERE $base GROUP BY u.id ORDER BY t DESC", $p);
$top = all("SELECT pr.nom, pr.dosage, SUM(l.quantite) q, SUM(l.total) t FROM sales s JOIN sale_lines l ON l.sale_id = s.id JOIN products pr ON pr.id = l.product_id
            WHERE $base GROUP BY pr.id ORDER BY t DESC LIMIT 15", $p);
$ass = all("SELECT i.nom, COUNT(*) nb, SUM(s.part_assurance) t FROM sales s JOIN insurers i ON i.id = s.insurer_id WHERE $base AND s.part_assurance > 0 GROUP BY i.id ORDER BY t DESC", $p);
$annul = (int)val("SELECT COUNT(*) FROM sales s WHERE s.statut = 'annulee' AND DATE(s.cancelled_at) BETWEEN ? AND ?", $p);
$valStock = (int)val("SELECT COALESCE(SUM(quantite * prix_achat),0) FROM lots WHERE quantite > 0 AND date_peremption >= CURDATE()");

page_header('Rapports', 'rapports');
?>
<div class="card">
  <form class="row wrap"><input type="hidden" name="p" value="rapports">
    <label>Du<input type="date" name="du" value="<?= e($du) ?>"></label><label>Au<input type="date" name="au" value="<?= e($au) ?>"></label>
    <button class="btn">Afficher</button>
    <button type="button" class="btn" onclick="window.print()">Imprimer</button>
  </form>
</div>
<div class="kpis">
  <div class="kpi"><small>Chiffre d'affaires</small><strong><?= money($tot['ca']) ?></strong></div>
  <div class="kpi"><small>Marge brute estimée</small><strong><?= money((int)$tot['ca'] - $cout) ?></strong></div>
  <div class="kpi"><small>Ventes</small><strong><?= (int)$tot['nb'] ?></strong></div>
  <div class="kpi"><small>À facturer aux assurances</small><strong><?= money($tot['ass']) ?></strong></div>
  <div class="kpi"><small>Bons annulés</small><strong><?= $annul ?></strong></div>
  <div class="kpi"><small>Valeur du stock (achat)</small><strong><?= money($valStock) ?></strong></div>
</div>
<div class="grid2">
  <div class="card"><h2>Encaissements par mode</h2><table class="tbl">
    <?php foreach ($modes as $m): ?><tr><td><?= e(MODES[$m['m']] ?? '—') ?></td><td class="r"><?= (int)$m['nb'] ?> vente(s)</td><td class="r"><?= money($m['t']) ?></td></tr><?php endforeach; ?>
    <tr class="sum"><td>Total patients</td><td></td><td class="r"><?= money($tot['pat']) ?></td></tr></table></div>
  <div class="card"><h2>Créances assurances</h2><table class="tbl">
    <?php foreach ($ass as $a): ?><tr><td><?= e($a['nom']) ?></td><td class="r"><?= (int)$a['nb'] ?> bon(s)</td><td class="r"><?= money($a['t']) ?></td></tr><?php endforeach;
    if (!$ass): ?><tr class="empty"><td>Aucune vente assurée.</td></tr><?php endif; ?></table></div>
  <div class="card"><h2>Par caissier</h2><table class="tbl">
    <?php foreach ($caissiers as $c): ?><tr><td><?= e($c['nom']) ?></td><td class="r"><?= (int)$c['nb'] ?></td><td class="r"><?= money($c['t']) ?></td></tr><?php endforeach; ?></table></div>
  <div class="card"><h2>Par vendeur</h2><table class="tbl">
    <?php foreach ($vendeurs as $v): ?><tr><td><?= e($v['nom']) ?></td><td class="r"><?= (int)$v['nb'] ?></td><td class="r"><?= money($v['t']) ?></td></tr><?php endforeach; ?></table></div>
</div>
<div class="card"><h2>Top 15 des médicaments</h2>
  <table class="tbl"><thead><tr><th>Médicament</th><th class="r">Quantité</th><th class="r">Chiffre d'affaires</th></tr></thead><tbody>
  <?php foreach ($top as $t): ?><tr><td><?= e($t['nom'] . ' ' . $t['dosage']) ?></td><td class="r"><?= (int)$t['q'] ?></td><td class="r"><?= money($t['t']) ?></td></tr><?php endforeach;
  if (!$top): ?><tr class="empty"><td colspan="3">Aucune vente sur la période.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php page_footer();
