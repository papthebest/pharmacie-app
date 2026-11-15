<?php
require_right('stock');
$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lot = one('SELECT l.*, p.nom FROM lots l JOIN products p ON p.id = l.product_id WHERE l.id = ?', [(int)post('lot_id')]);
    if (!$lot) redirect('stock');
    $act = post('action');
    $new = $act === 'expire' ? 0 : max(0, intv(post('quantite')));
    $diff = $new - (int)$lot['quantite'];
    $motif = post('motif') ?: ($act === 'expire' ? 'Retrait lot périmé' : 'Inventaire');
    if ($diff !== 0) {
        db()->beginTransaction();
        q('UPDATE lots SET quantite = ? WHERE id = ?', [$new, $lot['id']]);
        q('INSERT INTO stock_movements (product_id, lot_id, type, quantite, reference, user_id) VALUES (?,?,?,?,?,?)',
            [$lot['product_id'], $lot['id'], $act === 'expire' ? 'peremption' : 'ajustement', $diff, $motif, $me['id']]);
        db()->commit();
        flash("Lot {$lot['numero_lot']} de « {$lot['nom']} » : " . ($diff > 0 ? '+' : '') . $diff . ' unité(s).');
    }
    redirect('stock', array_filter(['tab' => post('tab'), 'product' => post('product')]));
}

$tab = get('tab', 'alertes');
$pid = (int)get('product');
$jours = (int)setting('delai_alerte_peremption', '90');

page_header('Stock & lots', 'stock');
$tabs = ['alertes' => 'Stock bas', 'peremption' => "Périment sous $jours j", 'perimes' => 'Périmés à retirer', 'lots' => 'Tous les lots'];
if ($pid) $tab = 'lots';
?>
<div class="tabs">
  <?php foreach ($tabs as $k => $lbl): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('stock', ['tab' => $k]) ?>"><?= e($lbl) ?></a><?php endforeach; ?>
</div>
<div class="card">
<?php if ($tab === 'alertes'):
    $rows = all("SELECT p.id, p.nom, p.dosage, p.forme, p.seuil_alerte,
                 COALESCE((SELECT SUM(quantite) FROM lots l WHERE l.product_id = p.id AND l.date_peremption >= CURDATE()),0) AS stock
                 FROM products p WHERE p.actif = 1 HAVING stock <= p.seuil_alerte ORDER BY stock, p.nom"); ?>
  <p class="muted">Médicaments dont le stock vendable est inférieur ou égal au seuil d'alerte : à commander.</p>
  <table class="tbl"><thead><tr><th>Médicament</th><th>Forme</th><th class="r">Stock</th><th class="r">Seuil</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><strong><?= e($r['nom']) ?></strong> <?= e($r['dosage']) ?></td><td><?= e($r['forme']) ?></td>
      <td class="r"><span class="badge <?= (int)$r['stock'] === 0 ? 'err' : 'warn' ?>"><?= (int)$r['stock'] ?></span></td><td class="r"><?= (int)$r['seuil_alerte'] ?></td>
      <td><a href="<?= url('stock', ['product' => $r['id']]) ?>">Lots</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="5">Aucune alerte de stock.</td></tr><?php endif; ?>
  </tbody></table>
<?php else:
    $where = match ($tab) {
        'peremption' => 'l.quantite > 0 AND l.date_peremption >= CURDATE() AND l.date_peremption <= DATE_ADD(CURDATE(), INTERVAL ' . $jours . ' DAY)',
        'perimes' => 'l.quantite > 0 AND l.date_peremption < CURDATE()',
        default => $pid ? 'l.product_id = ' . $pid : 'l.quantite > 0',
    };
    $rows = all("SELECT l.*, p.nom, p.dosage, DATEDIFF(l.date_peremption, CURDATE()) AS jours
                 FROM lots l JOIN products p ON p.id = l.product_id WHERE $where ORDER BY l.date_peremption, p.nom LIMIT 500");
    if ($pid && $rows) echo '<h2>' . e($rows[0]['nom'] . ' ' . $rows[0]['dosage']) . '</h2>';
    if ($tab === 'perimes') echo '<p class="muted">Ces lots ne peuvent plus être vendus. Retirez-les du stock (le mouvement est tracé).</p>'; ?>
  <table class="tbl"><thead><tr><th>Médicament</th><th>Lot</th><th>Péremption</th><th class="r">Reste</th><th>Ajuster (inventaire)</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $j = (int)$r['jours']; ?>
    <tr><td><strong><?= e($r['nom']) ?></strong> <?= e($r['dosage']) ?></td><td><?= e($r['numero_lot']) ?></td>
      <td><?= dfr($r['date_peremption']) ?> <span class="badge <?= $j < 0 ? 'err' : ($j <= $jours ? 'warn' : 'ok') ?>"><?= $j < 0 ? 'périmé' : $j . ' j' ?></span></td>
      <td class="r"><?= (int)$r['quantite'] ?></td>
      <td>
        <form method="post" class="row inline">
          <?= csrf_field() ?><input type="hidden" name="lot_id" value="<?= $r['id'] ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="product" value="<?= $pid ?: '' ?>">
          <?php if ($j < 0): ?>
            <button class="btn danger sm" name="action" value="expire" onclick="return confirm('Retirer ce lot périmé du stock ?')">Retirer du stock</button>
          <?php else: ?>
            <input name="quantite" inputmode="numeric" value="<?= (int)$r['quantite'] ?>" class="xs">
            <input name="motif" placeholder="Motif" class="sm">
            <button class="btn sm" name="action" value="adjust">OK</button>
          <?php endif; ?>
        </form>
      </td></tr>
  <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="5">Aucun lot.</td></tr><?php endif; ?>
  </tbody></table>
<?php endif; ?>
</div>
<?php if ($pid):
    $mv = all('SELECT m.*, u.nom AS user, l.numero_lot FROM stock_movements m JOIN users u ON u.id = m.user_id LEFT JOIN lots l ON l.id = m.lot_id
               WHERE m.product_id = ? ORDER BY m.id DESC LIMIT 50', [$pid]); ?>
  <div class="card"><h2>Derniers mouvements</h2>
    <table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>Lot</th><th class="r">Qté</th><th>Référence</th><th>Par</th></tr></thead><tbody>
    <?php foreach ($mv as $m): ?><tr><td><?= dfr($m['created_at'], true) ?></td><td><?= e(ucfirst($m['type'])) ?></td><td><?= e($m['numero_lot']) ?></td>
      <td class="r <?= $m['quantite'] < 0 ? 'neg' : 'pos' ?>"><?= $m['quantite'] > 0 ? '+' : '' ?><?= (int)$m['quantite'] ?></td><td><?= e($m['reference']) ?></td><td><?= e($m['user']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif;
page_footer();
