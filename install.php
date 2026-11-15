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
    'pharmacie_nom' => '', 'pharmacie_adresse' => 'Niamey, Niger', 'pharmacie_tel' => '',
    'admin_nom' => '', 'admin_login' => 'admin', 'admin_pass' => '', 'demo' => '',
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
    if ($f['pharmacie_nom'] === '') $errors[] = 'Renseignez le nom de la pharmacie.';
    if ($f['admin_nom'] === '' || $f['admin_login'] === '') $errors[] = 'Renseignez le compte gérant.';
    if (strlen($f['admin_pass']) < 8) $errors[] = 'Le mot de passe du gérant doit faire au moins 8 caractères.';
    if (!is_writable($root)) $errors[] = 'Le dossier de l\'application n\'est pas accessible en écriture (impossible de créer config.php).';

    if (!$errors) {
        try {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $f['db_host'], (int)$f['db_port'], $f['db_name']),
                $f['db_user'], $f['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $sql = file_get_contents("$root/app/schema.sql");
            foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
                $pdo->exec($stmt);
            }
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new RuntimeException('Cette base contient déjà des utilisateurs PharmaFlow. Utilisez une base vide.');
            }
            $st = $pdo->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)');
            foreach (['pharmacie_nom', 'pharmacie_adresse', 'pharmacie_tel'] as $k) $st->execute([$k, $f[$k]]);
            $st->execute(['ticket_pied', 'Merci de votre visite. Les médicaments ne sont ni repris ni échangés.']);
            $st->execute(['delai_alerte_peremption', '90']);
            $pdo->prepare('INSERT INTO users (nom, login, password_hash, role, peut_encaisser) VALUES (?,?,?,?,1)')
                ->execute([$f['admin_nom'], $f['admin_login'], password_hash($f['admin_pass'], PASSWORD_DEFAULT), 'gerant']);
            $adminId = (int)$pdo->lastInsertId();

            if ($f['demo'] === '1') {
                $pdo->exec("INSERT INTO suppliers (nom, telephone) VALUES ('Grossiste démo', '')");
                $sup = (int)$pdo->lastInsertId();
                $pdo->exec("INSERT INTO insurers (nom, taux) VALUES ('Assurance démo 80 %', 80), ('Mutuelle démo 50 %', 50)");
                $demo = [
                    // nom, dci, dosage, forme, achat, vente, ordonnance, stupefiant
                    ['Paracétamol 500', 'Paracétamol', '500 mg', 'Comprimé (boîte 16)', 350, 500, 0, 0],
                    ['Doliprane 1000', 'Paracétamol', '1000 mg', 'Comprimé (boîte 8)', 900, 1300, 0, 0],
                    ['Amoxicilline 500', 'Amoxicilline', '500 mg', 'Gélule (boîte 12)', 1200, 1800, 1, 0],
                    ['Coartem', 'Artéméther + Luméfantrine', '20/120 mg', 'Comprimé (boîte 24)', 2500, 3500, 1, 0],
                    ['Ibuprofène 400', 'Ibuprofène', '400 mg', 'Comprimé (boîte 20)', 700, 1000, 0, 0],
                    ['SRO', 'Sels de réhydratation orale', '20,5 g', 'Sachet', 100, 150, 0, 0],
                    ['Métronidazole 250', 'Métronidazole', '250 mg', 'Comprimé (boîte 20)', 600, 900, 1, 0],
                    ['Tramadol 50', 'Tramadol', '50 mg', 'Gélule (boîte 10)', 1500, 2200, 1, 1],
                ];
                $ip = $pdo->prepare('INSERT INTO products (nom, dci, dosage, forme, prix_achat, prix_vente, ordonnance, stupefiant, seuil_alerte) VALUES (?,?,?,?,?,?,?,?,10)');
                $pdo->prepare("INSERT INTO receptions (supplier_id, reference, date_reception, user_id) VALUES (?, 'DEMO-001', CURDATE(), ?)")->execute([$sup, $adminId]);
                $rec = (int)$pdo->lastInsertId();
                $il = $pdo->prepare('INSERT INTO lots (product_id, numero_lot, date_peremption, quantite, quantite_initiale, prix_achat, reception_id) VALUES (?,?,?,?,?,?,?)');
                $im = $pdo->prepare("INSERT INTO stock_movements (product_id, lot_id, type, quantite, reference, user_id) VALUES (?,?, 'entree', ?, 'Réception DEMO-001', ?)");
                $total = 0;
                foreach ($demo as $i => $d) {
                    $ip->execute($d);
                    $pid = (int)$pdo->lastInsertId();
                    foreach ([[40, '+2 months'], [60, '+14 months']] as $j => [$qty, $per]) {
                        $il->execute([$pid, sprintf('L%02d%s', $i + 1, $j ? 'B' : 'A'), date('Y-m-d', strtotime($per)), $qty, $qty, $d[4], $rec]);
                        $im->execute([$pid, (int)$pdo->lastInsertId(), $qty, $adminId]);
                        $total += $qty * $d[4];
                    }
                }
                $pdo->prepare('UPDATE receptions SET total = ? WHERE id = ?')->execute([$total, $rec]);
            }

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
    <a class="btn primary block" href="index.php?p=login">Se connecter</a>
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
    <h3>2. Pharmacie</h3>
    <div class="grid2">
      <label class="span2">Nom de la pharmacie<input name="pharmacie_nom" value="<?= h($f['pharmacie_nom']) ?>" required></label>
      <label>Adresse<input name="pharmacie_adresse" value="<?= h($f['pharmacie_adresse']) ?>"></label>
      <label>Téléphone<input name="pharmacie_tel" value="<?= h($f['pharmacie_tel']) ?>"></label>
    </div>
    <h3>3. Compte gérant</h3>
    <div class="grid2">
      <label class="span2">Nom complet<input name="admin_nom" value="<?= h($f['admin_nom']) ?>" required></label>
      <label>Identifiant<input name="admin_login" value="<?= h($f['admin_login']) ?>" required></label>
      <label>Mot de passe (8 caractères min.)<input type="password" name="admin_pass" required minlength="8"></label>
    </div>
    <label class="check"><input type="checkbox" name="demo" value="1" <?= $f['demo'] === '1' ? 'checked' : '' ?>> Ajouter des données de démonstration (8 médicaments, 2 assurances)</label>
    <button class="btn primary block">Installer PharmaFlow</button>
  </form>
<?php endif; ?>
</div></body></html>
