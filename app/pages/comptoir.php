<?php
require_right('comptoir');
$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cart = json_decode((string)post('cart'), true);
    $errors = [];
    if (!is_array($cart) || !$cart) $errors[] = 'Le bon est vide.';
    $lines = [];
    $needOrd = false;
    foreach ((array)$cart as $c) {
        $pid = (int)($c['id'] ?? 0);
        $qty = (int)($c['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $p = one('SELECT * FROM products WHERE id = ? AND actif = 1', [$pid]);
        if (!$p) { $errors[] = 'Produit introuvable.'; continue; }
        if ((int)$p['stupefiant'] === 1 && !can('stupefiant_vente')) $errors[] = "« {$p['nom']} » est un stupéfiant : seul un pharmacien peut le délivrer.";
        if ((int)$p['ordonnance'] === 1) $needOrd = true;
        $lines[$pid] = ['p' => $p, 'qty' => ($lines[$pid]['qty'] ?? 0) + $qty];
    }
    foreach ($lines as $pid => $l) {
        $dispo = stock_dispo($pid) - stock_reserve($pid);
        if ($l['qty'] > $dispo) $errors[] = "Stock insuffisant pour « {$l['p']['nom']} » : {$dispo} disponible(s).";
    }
    $ord = [
        'num' => post('ordonnance_num'), 'medecin' => post('medecin'),
        'patient' => post('patient_nom'), 'date' => post('ordonnance_date'),
    ];
    if ($needOrd && ($ord['medecin'] === '' || $ord['patient'] === '' || $ord['date'] === '')) {
        $errors[] = 'Ce bon contient des médicaments sur ordonnance : renseignez le médecin, le patient et la date de l\'ordonnance.';
    }
    $insurer = null;
    if ((int)post('insurer_id') > 0) {
        $insurer = one('SELECT * FROM insurers WHERE id = ? AND actif = 1', [(int)post('insurer_id')]);
        if (!$insurer) $errors[] = 'Assurance introuvable.';
        elseif (post('num_assure') === '') $errors[] = 'Renseignez le numéro d\'assuré.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $prefix = 'B' . date('ymd') . '-';
            $last = val('SELECT numero FROM sales WHERE numero LIKE ? ORDER BY numero DESC LIMIT 1 FOR UPDATE', [$prefix . '%']);
            $numero = $prefix . str_pad((string)($last ? (int)substr($last, -3) + 1 : 1), 3, '0', STR_PAD_LEFT);
            $total = 0;
            foreach ($lines as $l) $total += $l['qty'] * (int)$l['p']['prix_vente'];
            $taux = $insurer ? (int)$insurer['taux'] : 0;
            $partAss = (int)round($total * $taux / 100);
            q('INSERT INTO sales (numero, vendeur_id, client_nom, insurer_id, taux_assurance, num_assure, total, part_assurance, part_patient,
                                  ordonnance_num, ordonnance_date, medecin, patient_nom) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $numero, $me['id'], post('client_nom') ?: null, $insurer['id'] ?? null, $taux, $insurer ? post('num_assure') : null,
                $total, $partAss, $total - $partAss,
                $ord['num'] ?: null, $ord['date'] ?: null, $ord['medecin'] ?: null, $ord['patient'] ?: null,
            ]);
            $sid = (int)$pdo->lastInsertId();
            foreach ($lines as $pid => $l) {
                $pu = (int)$l['p']['prix_vente'];
                q('INSERT INTO sale_lines (sale_id, product_id, quantite, prix_unit, total) VALUES (?,?,?,?,?)', [$sid, $pid, $l['qty'], $pu, $pu * $l['qty']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        if (post('direct') === '1' && can('caisse')) redirect('caisse', ['id' => $sid]);
        flash("Bon {$numero} créé — total " . money($total) . ($partAss ? ' (à payer par le patient : ' . money($total - $partAss) . ')' : '') . '. Envoyez le client à la caisse.');
        redirect('comptoir', ['bon' => $sid]);
    }
    foreach ($errors as $er) flash($er, 'err');
    $_SESSION['comptoir_form'] = $_POST;
    redirect('comptoir');
}

$old = $_SESSION['comptoir_form'] ?? [];
unset($_SESSION['comptoir_form']);
$insurers = all('SELECT id, nom, taux FROM insurers WHERE actif = 1 ORDER BY nom');
$lastBon = get('bon') ? one('SELECT numero, total, part_patient FROM sales WHERE id = ? AND vendeur_id = ?', [(int)get('bon'), $me['id']]) : null;
$mine = all("SELECT s.id, s.numero, s.statut, s.total, s.part_patient, s.created_at, s.client_nom, s.patient_nom
             FROM sales s WHERE s.vendeur_id = ? AND DATE(s.created_at) = CURDATE() ORDER BY s.id DESC LIMIT 12", [$me['id']]);

page_header('Comptoir', 'comptoir');
?>
<?php if ($lastBon): ?>
  <div class="bonbig card">
    <div><small>Numéro du bon à donner au client</small><strong><?= e($lastBon['numero']) ?></strong></div>
    <div><small>À payer à la caisse</small><strong><?= money($lastBon['part_patient']) ?></strong></div>
    <a class="btn" target="_blank" href="<?= url('ticket', ['id' => (int)get('bon'), 'type' => 'bon']) ?>">Imprimer le bon</a>
  </div>
<?php endif; ?>

<form method="post" id="comptoir" class="cols" data-can-stup="<?= can('stupefiant_vente') ? 1 : 0 ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="cart" id="cart-field" value="<?= e($old['cart'] ?? '[]') ?>">
  <section class="card grow">
    <div class="search">
      <input id="psearch" placeholder="Rechercher un médicament (nom, DCI) ou scanner le code-barres…" autocomplete="off" autofocus>
      <div id="presults" class="results"></div>
    </div>
    <table class="tbl" id="cart">
      <thead><tr><th>Médicament</th><th class="r">Prix</th><th class="c">Qté</th><th class="r">Total</th><th></th></tr></thead>
      <tbody><tr class="empty"><td colspan="5">Aucun produit. Recherchez ou scannez un médicament.</td></tr></tbody>
    </table>
  </section>

  <aside class="card side-panel">
    <label>Client (facultatif)<input name="client_nom" value="<?= e($old['client_nom'] ?? '') ?>"></label>

    <label>Assurance / tiers payant
      <select name="insurer_id" id="insurer">
        <option value="0" data-taux="0">— Aucune —</option>
        <?php foreach ($insurers as $i): ?>
          <option value="<?= $i['id'] ?>" data-taux="<?= (int)$i['taux'] ?>" <?= (int)($old['insurer_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['nom']) ?> (<?= (int)$i['taux'] ?> %)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label id="assure-wrap" hidden>N° d'assuré<input name="num_assure" value="<?= e($old['num_assure'] ?? '') ?>"></label>

    <fieldset id="ord" class="ord">
      <legend>Ordonnance <span class="badge warn" id="ord-req" hidden>obligatoire</span></legend>
      <div class="grid2">
        <label>N° ordonnance<input name="ordonnance_num" value="<?= e($old['ordonnance_num'] ?? '') ?>"></label>
        <label>Date<input type="date" name="ordonnance_date" value="<?= e($old['ordonnance_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>"></label>
        <label class="span2">Médecin prescripteur<input name="medecin" value="<?= e($old['medecin'] ?? '') ?>"></label>
        <label class="span2">Patient<input name="patient_nom" value="<?= e($old['patient_nom'] ?? '') ?>"></label>
      </div>
    </fieldset>

    <div class="totals">
      <div><span>Total</span><strong id="t-total">0 F</strong></div>
      <div id="t-ass-row" hidden><span>Part assurance</span><strong id="t-ass">0 F</strong></div>
      <div class="big"><span>À payer par le patient</span><strong id="t-pat">0 F</strong></div>
    </div>
    <button class="btn primary block" id="submit-bon">Créer le bon → caisse</button>
    <?php if (can('caisse')): ?>
      <button class="btn block" name="direct" value="1">Créer et encaisser maintenant</button>
    <?php endif; ?>
  </aside>
</form>

<section class="card">
  <h2>Mes bons d'aujourd'hui</h2>
  <table class="tbl">
    <thead><tr><th>N°</th><th>Heure</th><th>Client / patient</th><th class="r">Total</th><th class="r">Part patient</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($mine as $s): ?>
      <tr><td><strong><?= e($s['numero']) ?></strong></td><td><?= date('H:i', strtotime($s['created_at'])) ?></td>
        <td><?= e($s['client_nom'] ?: $s['patient_nom'] ?: '—') ?></td><td class="r"><?= money($s['total']) ?></td><td class="r"><?= money($s['part_patient']) ?></td>
        <td><?= status_badge($s['statut']) ?></td>
        <td><a href="<?= url('ticket', ['id' => $s['id'], 'type' => 'bon']) ?>" target="_blank">Bon</a></td></tr>
    <?php endforeach; if (!$mine): ?><tr class="empty"><td colspan="7">Aucun bon aujourd'hui.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
<?php page_footer();
