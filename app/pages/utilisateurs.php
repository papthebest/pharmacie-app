<?php
require_right('utilisateurs');
$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)post('id');
    $nom = post('nom');
    $login = post('login');
    $role = post('role');
    $pass = (string)post('password');
    $err = '';
    if ($nom === '' || $login === '' || !isset(ROLES[$role])) $err = 'Nom, identifiant et rôle sont obligatoires.';
    elseif (val('SELECT id FROM users WHERE login = ? AND id <> ?', [$login, $id])) $err = 'Cet identifiant est déjà pris.';
    elseif (!$id && strlen($pass) < 6) $err = 'Mot de passe : 6 caractères minimum.';
    elseif ($pass !== '' && strlen($pass) < 6) $err = 'Mot de passe : 6 caractères minimum.';
    elseif ($id === (int)$me['id'] && ($role !== 'gerant' || !post('actif'))) $err = 'Vous ne pouvez pas retirer vos propres droits de gérant.';
    if ($err) { flash($err, 'err'); redirect('utilisateurs', ['edit' => $id ?: 'new']); }
    $enc = post('peut_encaisser') ? 1 : 0;
    $actif = post('actif') ? 1 : 0;
    if ($id) {
        q('UPDATE users SET nom = ?, login = ?, role = ?, peut_encaisser = ?, actif = ? WHERE id = ?', [$nom, $login, $role, $enc, $actif, $id]);
        if ($pass !== '') q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
    } else {
        q('INSERT INTO users (nom, login, password_hash, role, peut_encaisser, actif) VALUES (?,?,?,?,?,?)',
            [$nom, $login, password_hash($pass, PASSWORD_DEFAULT), $role, $enc, $actif]);
    }
    flash('Utilisateur enregistré.');
    redirect('utilisateurs');
}

page_header('Utilisateurs', 'utilisateurs');
if (get('edit')):
    $u = get('edit') === 'new' ? ['id' => 0, 'nom' => '', 'login' => '', 'role' => 'vendeur', 'peut_encaisser' => 0, 'actif' => 1]
        : one('SELECT * FROM users WHERE id = ?', [(int)get('edit')]); ?>
  <form method="post" class="card narrow">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    <h2><?= $u['id'] ? 'Modifier ' . e($u['nom']) : 'Nouvel utilisateur' ?></h2>
    <label>Nom complet<input name="nom" value="<?= e($u['nom']) ?>" required></label>
    <label>Identifiant de connexion<input name="login" value="<?= e($u['login']) ?>" required autocomplete="off"></label>
    <label>Rôle<select name="role"><?php foreach (ROLES as $k => $l): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label><?= $u['id'] ? 'Nouveau mot de passe (laisser vide pour ne pas changer)' : 'Mot de passe' ?><input type="password" name="password" autocomplete="new-password" <?= $u['id'] ? '' : 'required' ?>></label>
    <label class="check"><input type="checkbox" name="peut_encaisser" value="1" <?= $u['peut_encaisser'] ? 'checked' : '' ?>> Peut aussi encaisser (petite pharmacie, poste unique)</label>
    <label class="check"><input type="checkbox" name="actif" value="1" <?= $u['actif'] ? 'checked' : '' ?>> Compte actif</label>
    <div class="row"><button class="btn primary">Enregistrer</button><a class="btn" href="<?= url('utilisateurs') ?>">Annuler</a></div>
  </form>
<?php page_footer(); return; endif;

$users = all('SELECT * FROM users ORDER BY actif DESC, role, nom');
?>
<div class="card">
  <div class="row between"><p class="muted">Gérant : tout · Pharmacien : comptoir, stupéfiants, stock, réceptions · Vendeur : comptoir · Caissier : caisse.</p>
    <a class="btn primary" href="<?= url('utilisateurs', ['edit' => 'new']) ?>">+ Nouvel utilisateur</a></div>
  <table class="tbl"><thead><tr><th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Encaisse</th><th>Statut</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): ?>
    <tr class="<?= $u['actif'] ? '' : 'off' ?>"><td><?= e($u['nom']) ?></td><td><?= e($u['login']) ?></td><td><?= e(ROLES[$u['role']]) ?></td>
      <td><?= ($u['peut_encaisser'] || in_array($u['role'], ['gerant', 'caissier'])) ? 'Oui' : '—' ?></td>
      <td><?= $u['actif'] ? '<span class="badge ok">Actif</span>' : '<span class="badge">Désactivé</span>' ?></td>
      <td><a href="<?= url('utilisateurs', ['edit' => $u['id']]) ?>">Modifier</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php page_footer();
