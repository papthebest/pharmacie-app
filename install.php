<?php
declare(strict_types=1);
/* PharmaFlow — assistant d'installation (à supprimer après usage) */
date_default_timezone_set('Africa/Niamey');
$root = __DIR__;
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (is_file("$root/config.php") && is_file("$root/install.lock")) {
    http_response_code(403);
    exit('PharmaFlow est déjà installé. Supprimez install.php du serveur.');
}

$errors = [];
$done = false;
$f = array_merge([
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'admin_nom' => '', 'admin_login' => 'admin', 'admin_pass' => '',
], array_map(fn($v) => is_string($v) ? trim($v) : '', $_POST));

// Base déjà configurée sur le serveur (config.php présent, installation non terminée) :
// on reprend ses identifiants MySQL au lieu de les redemander.
$preset = is_file("$root/config.php") ? (require "$root/config.php") : null;
if (is_array($preset)) {
    foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'] as $k) {
        if (isset($preset[$k])) $f[$k] = (string)$preset[$k];
    }
} else {
    $preset = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($f['db_name'] === '' || $f['db_user'] === '') $errors[] = 'Renseignez la base de données et l\'utilisateur MySQL.';
    if ($f['admin_nom'] === '' || $f['admin_login'] === '') $errors[] = 'Renseignez le compte administrateur.';
    if (strlen($f['admin_pass']) < 10) $errors[] = 'Le mot de passe administrateur doit faire au moins 10 caractères.';
    if (!is_writable($root)) $errors[] = 'Le dossier de l\'application n\'est pas accessible en écriture (impossible de créer config.php).';

    if (!$errors) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $f['db_host'], (int)$f['db_port'], $f['db_name']),
                $f['db_user'], $f['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sql = file_get_contents("$root/app/schema.sql");
            foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
                $pdo->exec($stmt);
            }
            if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                throw new RuntimeException('PharmaFlow est déjà installé dans cette base. Utilisez une base vide.');
            }
            $pdo->prepare('INSERT INTO admins (nom, login, password_hash) VALUES (?,?,?)')
                ->execute([$f['admin_nom'], $f['admin_login'], password_hash($f['admin_pass'], PASSWORD_DEFAULT)]);

            $cfg = "<?php\nreturn " . var_export([
                'db_host' => $f['db_host'], 'db_port' => (int)$f['db_port'], 'db_name' => $f['db_name'],
                'db_user' => $f['db_user'], 'db_pass' => $f['db_pass'], 'debug' => false,
            ], true) . ";\n";
            file_put_contents("$root/config.php", $cfg);
            @chmod("$root/config.php", 0640);
            file_put_contents("$root/install.lock", date('c'));
            $done = true;
        } catch (Throwable $ex) {
            $errors[] = 'Erreur : ' . $ex->getMessage();
        }
    }
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation · PharmaFlow</title><link rel="stylesheet" href="assets/app.css"></head>
<body><div class="auth">
<?php if ($done): ?>
  <div class="card login">
    <div class="brand big"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small>Installation terminée</small></div></div>
    <p>L'application est installée. <strong>Supprimez maintenant le fichier <code>install.php</code></strong> du serveur.</p>
    <p>Connectez-vous à l'espace d'administration pour créer votre première pharmacie.</p>
    <a class="btn primary block" href="admin">Ouvrir l'administration</a>
  </div>
<?php else: ?>
  <form method="post" class="card login wide">
    <div class="brand big"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small>Installation</small></div></div>
    <?php foreach ($errors as $er): ?><div class="flash err"><?= h($er) ?></div><?php endforeach; ?>
    <h3>1. Base de données MySQL</h3>
    <?php if ($preset): ?>
    <p class="muted">Base de données déjà configurée sur ce serveur (<code><?= h($f['db_name']) ?></code>). Rien à saisir ici.</p>
    <?php else: ?>
    <p class="muted">Créez d'abord une base vide dans hPanel › Bases de données › MySQL.</p>
    <div class="grid2">
      <label>Serveur<input name="db_host" value="<?= h($f['db_host']) ?>" required></label>
      <label>Port<input name="db_port" value="<?= h($f['db_port']) ?>" required></label>
      <label>Nom de la base<input name="db_name" value="<?= h($f['db_name']) ?>" required placeholder="u123456789_pharma"></label>
      <label>Utilisateur<input name="db_user" value="<?= h($f['db_user']) ?>" required placeholder="u123456789_pharma"></label>
      <label class="span2">Mot de passe MySQL<input type="password" name="db_pass" value="<?= h($f['db_pass']) ?>"></label>
    </div>
    <?php endif; ?>
    <h3>2. Compte administrateur de la plateforme</h3>
    <p class="muted">Ce compte sert à créer les pharmacies et à gérer leurs abonnements. Il ne vend pas : chaque pharmacie a ses propres comptes.</p>
    <div class="grid2">
      <label class="span2">Nom complet<input name="admin_nom" value="<?= h($f['admin_nom']) ?>" required></label>
      <label>Identifiant<input name="admin_login" value="<?= h($f['admin_login']) ?>" required autocomplete="username"></label>
      <label>Mot de passe (10 caractères min.)<input type="password" name="admin_pass" required minlength="10" autocomplete="new-password"></label>
    </div>
    <button class="btn primary block">Installer PharmaFlow</button>
  </form>
<?php endif; ?>
</div></body></html>
