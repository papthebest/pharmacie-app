<?php
if (admin()) redirect('admin');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['adm_fails'] = $_SESSION['adm_fails'] ?? 0;
    if ($_SESSION['adm_fails'] >= 5 && time() - ($_SESSION['adm_last'] ?? 0) < 600) {
        $error = 'Trop de tentatives. Réessayez dans 10 minutes.';
    } else {
        $a = one('SELECT * FROM admins WHERE login = ?', [post('login')]);
        if (password_verify((string)post('password'), $a['password_hash'] ?? DUMMY_HASH) && $a) {
            session_regenerate_id(true);
            $_SESSION['aid'] = (int)$a['id'];
            $_SESSION['adm_fails'] = 0;
            redirect('admin');
        }
        $_SESSION['adm_fails']++;
        $_SESSION['adm_last'] = time();
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}

admin_header('Connexion');
?>
<form method="post" class="login card">
  <div class="brand big"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small>Administration de la plateforme</small></div></div>
  <?= csrf_field() ?>
  <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <label>Identifiant<input name="login" required autofocus autocomplete="username" value="<?= e(post('login')) ?>"></label>
  <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary block">Se connecter</button>
  <p class="muted" style="text-align:center;margin:14px 0 0"><small><a href="<?= url('login') ?>">Accès pharmacie</a></small></p>
</form>
<?php admin_footer();
