<?php
if (user()) redirect('dashboard');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = post('login');
    $_SESSION['login_fails'] = $_SESSION['login_fails'] ?? 0;
    if ($_SESSION['login_fails'] >= 8 && time() - ($_SESSION['login_last'] ?? 0) < 300) {
        $error = 'Trop de tentatives. Réessayez dans 5 minutes.';
    } else {
        $u = one('SELECT * FROM users WHERE login = ? AND actif = 1', [$login]);
        if ($u && password_verify((string)post('password'), $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['login_fails'] = 0;
            $dest = match ($u['role']) {
                'caissier' => 'caisse',
                'vendeur' => 'comptoir',
                default => 'dashboard',
            };
            redirect($dest);
        }
        $_SESSION['login_fails']++;
        $_SESSION['login_last'] = time();
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}

page_header('Connexion');
?>
<form method="post" class="login card">
  <div class="brand big"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small><?= e(setting('pharmacie_nom', 'Gestion de pharmacie')) ?></small></div></div>
  <?= csrf_field() ?>
  <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <label>Identifiant<input name="login" required autofocus autocomplete="username" value="<?= e(post('login')) ?>"></label>
  <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary block">Se connecter</button>
</form>
<?php page_footer();
