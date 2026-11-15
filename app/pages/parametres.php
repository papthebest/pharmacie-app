<?php
require_right('parametres');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('action');
    if ($act === 'settings') {
        foreach (['pharmacie_nom', 'pharmacie_adresse', 'pharmacie_tel', 'ticket_pied', 'delai_alerte_peremption'] as $k) {
            q('REPLACE INTO settings (pharmacy_id, k, v) VALUES (?, ?, ?)', [ph(), $k, post($k)]);
        }
        if (post('pharmacie_nom') !== '') {
            q('UPDATE pharmacies SET nom = ?, adresse = ?, telephone = ? WHERE id = ?',
                [post('pharmacie_nom'), post('pharmacie_adresse') ?: null, post('pharmacie_tel') ?: null, ph()]);
        }
        flash('Paramètres enregistrés.');
    }
    if ($act === 'insurer') {
        $id = (int)post('id');
        $d = [post('nom'), min(100, max(0, intv(post('taux')))), post('telephone') ?: null, post('actif') ? 1 : 0];
        if ($d[0] === '') flash('Nom obligatoire.', 'err');
        elseif ($id) q('UPDATE insurers SET nom = ?, taux = ?, telephone = ?, actif = ? WHERE id = ? AND pharmacy_id = ?', [...$d, $id, ph()]);
        else q('INSERT INTO insurers (nom, taux, telephone, actif, pharmacy_id) VALUES (?,?,?,?,?)', [...$d, ph()]);
        if ($d[0] !== '') flash('Assurance enregistrée.');
    }
    if ($act === 'supplier') {
        $id = (int)post('id');
        $d = [post('nom'), post('telephone') ?: null, post('adresse') ?: null, post('actif') ? 1 : 0];
        if ($d[0] === '') flash('Nom obligatoire.', 'err');
        elseif ($id) q('UPDATE suppliers SET nom = ?, telephone = ?, adresse = ?, actif = ? WHERE id = ? AND pharmacy_id = ?', [...$d, $id, ph()]);
        else q('INSERT INTO suppliers (nom, telephone, adresse, actif, pharmacy_id) VALUES (?,?,?,?,?)', [...$d, ph()]);
        if ($d[0] !== '') flash('Fournisseur enregistré.');
    }
    redirect('parametres');
}

$insurers = all('SELECT * FROM insurers WHERE pharmacy_id = ? ORDER BY actif DESC, nom', [ph()]);
$suppliers = all('SELECT * FROM suppliers WHERE pharmacy_id = ? ORDER BY actif DESC, nom', [ph()]);
page_header('Paramètres', 'parametres');
?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2>Pharmacie</h2>
  <div class="grid3">
    <label>Nom<input name="pharmacie_nom" value="<?= e(setting('pharmacie_nom')) ?>"></label>
    <label>Adresse<input name="pharmacie_adresse" value="<?= e(setting('pharmacie_adresse')) ?>"></label>
    <label>Téléphone<input name="pharmacie_tel" value="<?= e(setting('pharmacie_tel')) ?>"></label>
    <label class="span2">Message en bas du ticket<input name="ticket_pied" value="<?= e(setting('ticket_pied')) ?>"></label>
    <label>Alerte péremption (jours)<input name="delai_alerte_peremption" inputmode="numeric" value="<?= e(setting('delai_alerte_peremption', '90')) ?>"></label>
  </div>
  <button class="btn primary">Enregistrer</button>
</form>

<div class="grid2">
  <div class="card">
    <h2>Assurances & tiers payants</h2>
    <table class="tbl"><thead><tr><th>Nom</th><th class="r">Prise en charge</th><th>Tél.</th><th></th></tr></thead><tbody>
    <?php foreach ($insurers as $i): ?>
      <tr class="<?= $i['actif'] ? '' : 'off' ?>"><td><?= e($i['nom']) ?></td><td class="r"><?= (int)$i['taux'] ?> %</td><td><?= e($i['telephone']) ?></td>
        <td><button class="link" type="button" onclick='fillForm("fins", <?= json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Modifier</button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <form method="post" id="fins" class="sub">
      <?= csrf_field() ?><input type="hidden" name="action" value="insurer"><input type="hidden" name="id" value="0">
      <div class="grid3"><label>Nom<input name="nom" required></label><label>% pris en charge<input name="taux" inputmode="numeric" value="80"></label><label>Téléphone<input name="telephone"></label></div>
      <label class="check"><input type="checkbox" name="actif" value="1" checked> Active</label>
      <button class="btn primary">Enregistrer l'assurance</button>
    </form>
  </div>

  <div class="card">
    <h2>Fournisseurs / grossistes</h2>
    <table class="tbl"><thead><tr><th>Nom</th><th>Tél.</th><th>Adresse</th><th></th></tr></thead><tbody>
    <?php foreach ($suppliers as $s): ?>
      <tr class="<?= $s['actif'] ? '' : 'off' ?>"><td><?= e($s['nom']) ?></td><td><?= e($s['telephone']) ?></td><td><?= e($s['adresse']) ?></td>
        <td><button class="link" type="button" onclick='fillForm("fsup", <?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Modifier</button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <form method="post" id="fsup" class="sub">
      <?= csrf_field() ?><input type="hidden" name="action" value="supplier"><input type="hidden" name="id" value="0">
      <div class="grid3"><label>Nom<input name="nom" required></label><label>Téléphone<input name="telephone"></label><label>Adresse<input name="adresse"></label></div>
      <label class="check"><input type="checkbox" name="actif" value="1" checked> Actif</label>
      <button class="btn primary">Enregistrer le fournisseur</button>
    </form>
  </div>
</div>
<?php page_footer();
