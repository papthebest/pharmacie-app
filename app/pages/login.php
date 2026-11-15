<?php
if (user()) redirect('dashboard');

$error = (string)($_SESSION['login_msg'] ?? '');
unset($_SESSION['login_msg']);
$code = norm_code((string)($_COOKIE['pf_code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = norm_code((string)post('code'));
    $login = post('login');
    $_SESSION['login_fails'] = $_SESSION['login_fails'] ?? 0;
    if ($_SESSION['login_fails'] >= 8 && time() - ($_SESSION['login_last'] ?? 0) < 300) {
        $error = 'Trop de tentatives. Réessayez dans 5 minutes.';
    } else {
        $u = one('SELECT u.* FROM users u JOIN pharmacies p ON p.id = u.pharmacy_id
                  WHERE p.code = ? AND u.login = ? AND u.actif = 1', [$code, $login]);
        // Vérification à temps constant, même si le compte n'existe pas.
        $ok = password_verify((string)post('password'), $u['password_hash'] ?? DUMMY_HASH) && $u;
        if ($ok) {
            $block = pharmacy_block(one('SELECT * FROM pharmacies WHERE id = ?', [$u['pharmacy_id']]));
            if ($block === null) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['login_fails'] = 0;
                setcookie('pf_code', $code, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
                $dest = match ($u['role']) {
                    'caissier' => 'caisse',
                    'vendeur' => 'comptoir',
                    default => 'dashboard',
                };
                redirect($dest);
            }
            $error = $block;
        } else {
            $_SESSION['login_fails']++;
            $_SESSION['login_last'] = time();
            $error = 'Code pharmacie, identifiant ou mot de passe incorrect.';
        }
    }
}

page_header('Connexion');
?>
<form method="post" class="login card">
  <div class="brand big"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small>Gestion de pharmacie</small></div></div>
  <?= csrf_field() ?>
  <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
  <label>Code pharmacie<input name="code" required <?= $code === '' ? 'autofocus' : '' ?> autocomplete="organization" autocapitalize="characters" value="<?= e($code) ?>" placeholder="Ex. SANTE01"></label>
  <label>Identifiant<input name="login" required <?= $code !== '' ? 'autofocus' : '' ?> autocomplete="username" value="<?= e(post('login')) ?>"></label>
  <label>Mot de passe<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn primary block">Se connecter</button>
  <p class="muted" style="text-align:center;margin:14px 0 0"><small>Le code pharmacie vous est communiqué par PharmaFlow.</small></p>
</form>
<?php page_footer();
