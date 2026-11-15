<?php
require_right('receptions');
$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pids = (array)($_POST['product_id'] ?? []);
    $rows = [];
    $errors = [];
    foreach ($pids as $i => $pid) {
        $pid = (int)$pid;
        $lot = trim((string)($_POST['numero_lot'][$i] ?? ''));
        $per = (string)($_POST['date_peremption'][$i] ?? '');
        $qty = intv($_POST['quantite'][$i] ?? 0);
        $pa = intv($_POST['prix_achat'][$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $name = val('SELECT nom FROM products WHERE id = ? AND pharmacy_id = ?', [$pid, ph()]);
        if (!$name) { $errors[] = 'Produit introuvable.'; continue; }
        if ($lot === '') $errors[] = "N° de lot manquant pour « $name ».";
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $per)) $errors[] = "Date de péremption manquante pour « $name ».";
        elseif ($per < date('Y-m-d')) $errors[] = "Le lot $lot de « $name » est déjà périmé.";
        $rows[] = compact('pid', 'lot', 'per', 'qty', 'pa');
    }
    if (!$rows) $errors[] = 'Ajoutez au moins une ligne.';
    if ($errors) {
        foreach (array_unique($errors) as $er) flash($er, 'err');
        redirect('receptions', ['new' => 1]);
    }
    $supplierId = (int)val('SELECT id FROM suppliers WHERE id = ? AND pharmacy_id = ?', [(int)post('supplier_id'), ph()]);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO receptions (pharmacy_id, supplier_id, reference, date_reception, user_id) VALUES (?,?,?,?,?)',
            [ph(), $supplierId ?: null, post('reference') ?: null, post('date_reception') ?: date('Y-m-d'), $me['id']]);
        $rid = (int)$pdo->lastInsertId();
        $total = 0;
        foreach ($rows as $r) {
            q('INSERT INTO lots (pharmacy_id, product_id, numero_lot, date_peremption, quantite, quantite_initiale, prix_achat, reception_id) VALUES (?,?,?,?,?,?,?,?)',
                [ph(), $r['pid'], $r['lot'], $r['per'], $r['qty'], $r['qty'], $r['pa'], $rid]);
            $lid = (int)$pdo->lastInsertId();
            q("INSERT INTO stock_movements (pharmacy_id, product_id, lot_id, type, quantite, reference, user_id) VALUES (?,?,?, 'entree', ?, ?, ?)",
                [ph(), $r['pid'], $lid, $r['qty'], 'Réception #' . $rid . (post('reference') ? ' ' . post('reference') : ''), $me['id']]);
            if ($r['pa'] > 0) q('UPDATE products SET prix_achat = ? WHERE id = ? AND pharmacy_id = ?', [$r['pa'], $r['pid'], ph()]);
            $total += $r['qty'] * $r['pa'];
        }
        q('UPDATE receptions SET total = ? WHERE id = ?', [$total, $rid]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    flash('Réception enregistrée : ' . count($rows) . ' lot(s), ' . money($total) . '.');
    redirect('receptions', ['view' => $rid]);
}

page_header('Réceptions fournisseurs', 'receptions');

if (get('new')):
    $suppliers = all('SELECT id, nom FROM suppliers WHERE pharmacy_id = ? AND actif = 1 ORDER BY nom', [ph()]); ?>
  <form method="post" class="card" id="reception">
    <?= csrf_field() ?>
    <h2>Nouvelle réception</h2>
    <div class="grid3">
      <label>Fournisseur
        <select name="supplier_id"><option value="0">—</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['nom']) ?></option><?php endforeach; ?>
        </select></label>
      <label>N° bon de livraison / facture<input name="reference"></label>
      <label>Date<input type="date" name="date_reception" value="<?= date('Y-m-d') ?>"></label>
    </div>
    <div class="search">
      <input id="rsearch" placeholder="Ajouter un médicament (nom, DCI ou code-barres)…" autocomplete="off">
      <div id="rresults" class="results"></div>
    </div>
    <table class="tbl" id="rlines">
      <thead><tr><th>Médicament</th><th>N° lot</th><th>Péremption</th><th>Quantité</th><th>Prix achat unit.</th><th></th></tr></thead>
      <tbody><tr class="empty"><td colspan="6">Recherchez un médicament pour l'ajouter.</td></tr></tbody>
    </table>
    <div class="row"><button class="btn primary">Enregistrer la réception</button><a class="btn" href="<?= url('receptions') ?>">Annuler</a></div>
  </form>
<?php page_footer(); return; endif;

if (get('view')):
    $r = one('SELECT r.*, s.nom AS fournisseur, u.nom AS user FROM receptions r LEFT JOIN suppliers s ON s.id = r.supplier_id JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.pharmacy_id = ?', [(int)get('view'), ph()]);
    if (!$r) { echo '<div class="card">Réception introuvable.</div>'; page_footer(); return; }
    $lots = all('SELECT l.*, p.nom, p.dosage FROM lots l JOIN products p ON p.id = l.product_id WHERE l.reception_id = ?', [$r['id']]); ?>
  <div class="card">
    <h2>Réception #<?= (int)$r['id'] ?> — <?= e($r['fournisseur'] ?? 'Sans fournisseur') ?></h2>
    <p class="muted"><?= dfr($r['date_reception']) ?> · Réf. <?= e($r['reference'] ?: '—') ?> · Saisie par <?= e($r['user']) ?></p>
    <table class="tbl"><thead><tr><th>Médicament</th><th>Lot</th><th>Péremption</th><th class="r">Qté</th><th class="r">Prix achat</th><th class="r">Montant</th></tr></thead><tbody>
      <?php foreach ($lots as $l): ?><tr><td><?= e($l['nom'] . ' ' . $l['dosage']) ?></td><td><?= e($l['numero_lot']) ?></td><td><?= dfr($l['date_peremption']) ?></td>
        <td class="r"><?= (int)$l['quantite_initiale'] ?></td><td class="r"><?= money($l['prix_achat']) ?></td><td class="r"><?= money($l['prix_achat'] * $l['quantite_initiale']) ?></td></tr><?php endforeach; ?>
      <tr class="sum"><td colspan="5">Total</td><td class="r"><?= money($r['total']) ?></td></tr>
    </tbody></table>
    <a class="btn" href="<?= url('receptions') ?>">← Retour</a>
  </div>
<?php page_footer(); return; endif;

$list = all('SELECT r.*, s.nom AS fournisseur, u.nom AS user, (SELECT COUNT(*) FROM lots l WHERE l.reception_id = r.id) AS nb
             FROM receptions r LEFT JOIN suppliers s ON s.id = r.supplier_id JOIN users u ON u.id = r.user_id WHERE r.pharmacy_id = ? ORDER BY r.id DESC LIMIT 100', [ph()]);
?>
<div class="card">
  <div class="row between"><h2>Dernières réceptions</h2><a class="btn primary" href="<?= url('receptions', ['new' => 1]) ?>">+ Nouvelle réception</a></div>
  <table class="tbl"><thead><tr><th>#</th><th>Date</th><th>Fournisseur</th><th>Référence</th><th class="r">Lots</th><th class="r">Montant</th><th>Saisie par</th></tr></thead><tbody>
  <?php foreach ($list as $r): ?>
    <tr><td><a href="<?= url('receptions', ['view' => $r['id']]) ?>">#<?= (int)$r['id'] ?></a></td><td><?= dfr($r['date_reception']) ?></td><td><?= e($r['fournisseur'] ?? '—') ?></td>
      <td><?= e($r['reference'] ?: '—') ?></td><td class="r"><?= (int)$r['nb'] ?></td><td class="r"><?= money($r['total']) ?></td><td><?= e($r['user']) ?></td></tr>
  <?php endforeach; if (!$list): ?><tr class="empty"><td colspan="7">Aucune réception.</td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php page_footer();
