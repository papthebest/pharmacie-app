<?php
require_right('produits');

$fields = ['nom', 'dci', 'dosage', 'forme', 'laboratoire', 'code_barre'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)post('id');
    $d = [];
    foreach ($fields as $f) $d[$f] = post($f) === '' ? null : post($f);
    if (!$d['nom']) { flash('Le nom est obligatoire.', 'err'); redirect('produits', $id ? ['edit' => $id] : ['new' => 1]); }
    $d['prix_achat'] = max(0, intv(post('prix_achat')));
    $d['prix_vente'] = max(0, intv(post('prix_vente')));
    $d['seuil_alerte'] = max(0, intv(post('seuil_alerte')));
    $d['ordonnance'] = post('ordonnance') ? 1 : 0;
    $d['stupefiant'] = post('stupefiant') ? 1 : 0;
    if ($d['stupefiant']) $d['ordonnance'] = 1;
    $d['actif'] = post('actif', '1') ? 1 : 0;
    if ($d['code_barre'] && val('SELECT id FROM products WHERE pharmacy_id = ? AND code_barre = ? AND id <> ?', [ph(), $d['code_barre'], $id])) {
        flash('Ce code-barres est déjà utilisé par un autre produit.', 'err');
        redirect('produits', $id ? ['edit' => $id] : ['new' => 1]);
    }
    if ($id) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
        q("UPDATE products SET $set WHERE id = ? AND pharmacy_id = ?", [...array_values($d), $id, ph()]);
        flash('Médicament mis à jour.');
    } else {
        $d = ['pharmacy_id' => ph()] + $d;
        $cols = implode(', ', array_keys($d));
        q("INSERT INTO products ($cols) VALUES (" . rtrim(str_repeat('?,', count($d)), ',') . ')', array_values($d));
        flash('Médicament ajouté. Enregistrez une réception pour lui donner du stock.');
    }
    redirect('produits');
}

$edit = get('edit') ? one('SELECT * FROM products WHERE id = ? AND pharmacy_id = ?', [(int)get('edit'), ph()]) : (get('new') ? ['id' => 0, 'seuil_alerte' => 10, 'actif' => 1] : null);

page_header('Médicaments', 'produits');

if ($edit !== null):
    $v = fn($k) => e($edit[$k] ?? ''); ?>
  <form method="post" class="card narrow">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <h2><?= $edit['id'] ? 'Modifier' : 'Nouveau médicament' ?></h2>
    <div class="grid2">
      <label class="span2">Nom commercial *<input name="nom" value="<?= $v('nom') ?>" required autofocus></label>
      <label>DCI (principe actif)<input name="dci" value="<?= $v('dci') ?>"></label>
      <label>Dosage<input name="dosage" value="<?= $v('dosage') ?>" placeholder="500 mg"></label>
      <label>Forme / conditionnement<input name="forme" value="<?= $v('forme') ?>" placeholder="Comprimé (boîte 20)"></label>
      <label>Laboratoire<input name="laboratoire" value="<?= $v('laboratoire') ?>"></label>
      <label>Code-barres / CIP<input name="code_barre" value="<?= $v('code_barre') ?>"></label>
      <label>Seuil d'alerte stock<input name="seuil_alerte" inputmode="numeric" value="<?= $v('seuil_alerte') ?>"></label>
      <label>Prix d'achat (F)<input name="prix_achat" inputmode="numeric" value="<?= $v('prix_achat') ?>"></label>
      <label>Prix de vente (F) *<input name="prix_vente" inputmode="numeric" value="<?= $v('prix_vente') ?>" required></label>
    </div>
    <label class="check"><input type="checkbox" name="ordonnance" value="1" <?= !empty($edit['ordonnance']) ? 'checked' : '' ?>> Délivré uniquement sur ordonnance</label>
    <label class="check"><input type="checkbox" name="stupefiant" value="1" <?= !empty($edit['stupefiant']) ? 'checked' : '' ?>> Stupéfiant / psychotrope (registre, délivrance par un pharmacien)</label>
    <label class="check"><input type="hidden" name="actif" value="0"><input type="checkbox" name="actif" value="1" <?= !empty($edit['actif']) ? 'checked' : '' ?>> Actif (visible au comptoir)</label>
    <div class="row"><button class="btn primary">Enregistrer</button><a class="btn" href="<?= url('produits') ?>">Annuler</a></div>
  </form>
<?php page_footer(); return; endif;

$s = get('q');
$w = 'p.pharmacy_id = ?';
$params = [ph()];
if ($s !== '') {
    $w .= ' AND (p.nom LIKE ? OR p.dci LIKE ? OR p.code_barre = ?)';
    array_push($params, '%' . $s . '%', '%' . $s . '%', $s);
}
$rows = all("SELECT p.*,
             (SELECT COALESCE(SUM(quantite),0) FROM lots l WHERE l.product_id = p.id AND l.date_peremption >= CURDATE()) AS stock,
             (SELECT MIN(date_peremption) FROM lots l WHERE l.product_id = p.id AND l.quantite > 0 AND l.date_peremption >= CURDATE()) AS proch
             FROM products p WHERE $w
             ORDER BY p.actif DESC, p.nom LIMIT 300", $params);
?>
<div class="card">
  <div class="row between">
    <form class="row"><input type="hidden" name="p" value="produits"><input name="q" value="<?= e($s) ?>" placeholder="Nom, DCI ou code-barres"><button class="btn">Rechercher</button></form>
    <a class="btn primary" href="<?= url('produits', ['new' => 1]) ?>">+ Nouveau médicament</a>
  </div>
  <table class="tbl">
    <thead><tr><th>Médicament</th><th>DCI</th><th>Forme</th><th class="r">Prix vente</th><th class="r">Stock</th><th>Proch. péremption</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $low = (int)$r['stock'] <= (int)$r['seuil_alerte']; ?>
      <tr class="<?= $r['actif'] ? '' : 'off' ?>">
        <td><strong><?= e($r['nom']) ?></strong> <small class="muted"><?= e($r['dosage']) ?></small>
          <?= $r['ordonnance'] ? '<span class="badge">Ord.</span>' : '' ?><?= $r['stupefiant'] ? '<span class="badge err">Stup.</span>' : '' ?></td>
        <td><?= e($r['dci']) ?></td><td><?= e($r['forme']) ?></td>
        <td class="r"><?= money($r['prix_vente']) ?></td>
        <td class="r"><span class="<?= $low ? 'badge warn' : '' ?>"><?= (int)$r['stock'] ?></span></td>
        <td><?= dfr($r['proch']) ?></td>
        <td><a href="<?= url('produits', ['edit' => $r['id']]) ?>">Modifier</a> · <a href="<?= url('stock', ['product' => $r['id']]) ?>">Lots</a></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="7">Aucun médicament.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php page_footer();
