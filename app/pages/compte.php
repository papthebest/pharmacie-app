<?php
$me = user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = (string)post('new');
    if (!password_verify((string)post('current'), $me['password_hash'])) flash('Mot de passe actuel incorrect.', 'err');
    elseif (strlen($new) < 6) flash('Nouveau mot de passe : 6 caractères minimum.', 'err');
    elseif ($new !== post('confirm')) flash('Les deux mots de passe ne correspondent pas.', 'err');
    else { q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]); flash('Mot de passe modifié.'); }
    redirect('compte');
}
page_header('Mon compte');
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <h2><?= e($me['nom']) ?> <small class="muted">(<?= e(ROLES[$me['role']]) ?>)</small></h2>
  <label>Mot de passe actuel<input type="password" name="current" required autocomplete="current-password"></label>
  <label>Nouveau mot de passe<input type="password" name="new" required minlength="6" autocomplete="new-password"></label>
  <label>Confirmer<input type="password" name="confirm" required minlength="6" autocomplete="new-password"></label>
  <button class="btn primary">Changer le mot de passe</button>
</form>
<?php page_footer();
