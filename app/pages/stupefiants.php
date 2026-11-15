<?php
require_right('stupefiants');
$du = get('du', date('Y-m-01'));
$au = get('au', date('Y-m-d'));
$rows = all("SELECT m.created_at, m.type, m.quantite, m.reference, p.nom, p.dosage, l.numero_lot, u.nom AS user,
                    s.numero, s.patient_nom, s.medecin, s.ordonnance_num, s.ordonnance_date
             FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN users u ON u.id = m.user_id
             LEFT JOIN lots l ON l.id = m.lot_id
             LEFT JOIN sales s ON m.type = 'vente' AND s.numero = SUBSTRING(m.reference, 5)
             WHERE p.stupefiant = 1 AND DATE(m.created_at) BETWEEN ? AND ?
             ORDER BY p.nom, m.created_at", [$du, $au]);
page_header('Registre des stupéfiants', 'stupefiants');
?>
<div class="card">
  <form class="row wrap"><input type="hidden" name="p" value="stupefiants">
    <label>Du<input type="date" name="du" value="<?= e($du) ?>"></label><label>Au<input type="date" name="au" value="<?= e($au) ?>"></label>
    <button class="btn">Afficher</button><button type="button" class="btn" onclick="window.print()">Imprimer</button></form>
  <p class="muted">Entrées et sorties des produits classés stupéfiants / psychotropes, avec l'ordonnance correspondante.</p>
  <table class="tbl">
    <thead><tr><th>Date</th><th>Produit</th><th>Lot</th><th>Mouvement</th><th class="r">Qté</th><th>Ordonnance</th><th>Patient</th><th>Prescripteur</th><th>Opérateur</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= dfr($r['created_at'], true) ?></td><td><?= e($r['nom'] . ' ' . $r['dosage']) ?></td><td><?= e($r['numero_lot']) ?></td>
        <td><?= e(ucfirst($r['type'])) ?><br><small class="muted"><?= e($r['reference']) ?></small></td>
        <td class="r <?= $r['quantite'] < 0 ? 'neg' : 'pos' ?>"><?= $r['quantite'] > 0 ? '+' : '' ?><?= (int)$r['quantite'] ?></td>
        <td><?= e($r['ordonnance_num'] ?? '') ?> <?= $r['ordonnance_date'] ? dfr($r['ordonnance_date']) : '' ?></td>
        <td><?= e($r['patient_nom'] ?? '') ?></td><td><?= e($r['medecin'] ?? '') ?></td><td><?= e($r['user']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="9">Aucun mouvement de stupéfiant sur la période.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php page_footer();
