<?php
/* Espace super-administrateur : création et suivi des pharmacies clientes. */
$me = admin();
$a = get('a');

/** Statut lisible d'une pharmacie : [classe du badge, libellé]. */
function ph_status(array $p): array
{
    if ((int)$p['actif'] !== 1) return ['err', 'Suspendue'];
    $j = abonnement_jours($p);
    if ($j === null) return ['ok', 'Active'];
    if ($j < 0) return ['err', 'Expirée'];
    if ($j <= 7) return ['warn', 'Expire bientôt'];
    return ['ok', 'Active'];
}
function valid_date(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = post('action');

    /* ----- Créer une pharmacie et son gérant ----- */
    if ($act === 'create') {
        $nom = post('nom');
        $code = norm_code((string)post('code'));
        $fin = post('abonnement_fin');
        $gNom = post('gerant_nom');
        $gLogin = post('gerant_login');
        $gPass = (string)post('gerant_pass');
        $errors = [];
        if ($nom === '') $errors[] = 'Le nom de la pharmacie est obligatoire.';
        if (strlen($code) < 3) $errors[] = 'Le code pharmacie doit contenir 3 à 20 lettres ou chiffres.';
        elseif (val('SELECT id FROM pharmacies WHERE code = ?', [$code])) $errors[] = "Le code « $code » est déjà utilisé par une autre pharmacie.";
        if ($fin !== '' && !valid_date($fin)) $errors[] = 'Date de fin d\'abonnement invalide.';
        if ($gNom === '' || $gLogin === '') $errors[] = 'Renseignez le nom et l\'identifiant du gérant.';
        if (strlen($gPass) < 8) $errors[] = 'Le mot de passe du gérant doit faire au moins 8 caractères.';
        if ($errors) {
            foreach ($errors as $er) flash($er, 'err');
            $_SESSION['admin_form'] = array_diff_key($_POST, ['gerant_pass' => 1, '_csrf' => 1]);
            redirect('admin', ['a' => 'new']);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q('INSERT INTO pharmacies (code, nom, adresse, telephone, abonnement_fin, note) VALUES (?,?,?,?,?,?)',
                [$code, $nom, post('adresse') ?: null, post('telephone') ?: null, $fin ?: null, post('note') ?: null]);
            $pid = (int)$pdo->lastInsertId();
            foreach ([
                'pharmacie_nom' => $nom, 'pharmacie_adresse' => post('adresse'), 'pharmacie_tel' => post('telephone'),
                'ticket_pied' => 'Merci de votre visite. Les médicaments ne sont ni repris ni échangés.',
                'delai_alerte_peremption' => '90',
            ] as $k => $v) {
                q('INSERT INTO settings (pharmacy_id, k, v) VALUES (?,?,?)', [$pid, $k, $v]);
            }
            q('INSERT INTO users (pharmacy_id, nom, login, password_hash, role, peut_encaisser) VALUES (?,?,?,?,?,1)',
                [$pid, $gNom, $gLogin, password_hash($gPass, PASSWORD_DEFAULT), 'gerant']);
            $uid = (int)$pdo->lastInsertId();
            if (post('demo') === '1') {
                require APP . '/demo.php';
                demo_seed($pid, $uid);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        flash("Pharmacie « $nom » créée. Code pharmacie : $code · identifiant du gérant : $gLogin.");
        redirect('admin', ['a' => 'edit', 'id' => $pid]);
    }

    /* ----- Actions sur une pharmacie existante ----- */
    if (in_array($act, ['update', 'extend', 'reset_pass', 'delete'], true)) {
        $p = one('SELECT * FROM pharmacies WHERE id = ?', [(int)post('id')]);
        if (!$p) redirect('admin');
        $back = ['a' => 'edit', 'id' => $p['id']];

        if ($act === 'update') {
            $nom = post('nom');
            $fin = post('abonnement_fin');
            if ($nom === '') flash('Le nom de la pharmacie est obligatoire.', 'err');
            elseif ($fin !== '' && !valid_date($fin)) flash('Date de fin d\'abonnement invalide.', 'err');
            else {
                q('UPDATE pharmacies SET nom = ?, adresse = ?, telephone = ?, note = ?, actif = ?, abonnement_fin = ? WHERE id = ?',
                    [$nom, post('adresse') ?: null, post('telephone') ?: null, post('note') ?: null, post('actif') ? 1 : 0, $fin ?: null, $p['id']]);
                foreach (['pharmacie_nom' => $nom, 'pharmacie_adresse' => post('adresse'), 'pharmacie_tel' => post('telephone')] as $k => $v) {
                    q('REPLACE INTO settings (pharmacy_id, k, v) VALUES (?,?,?)', [$p['id'], $k, $v]);
                }
                flash('Pharmacie mise à jour.');
            }
        }

        if ($act === 'extend') {
            $mois = (int)post('mois');
            if (in_array($mois, [1, 3, 6, 12], true)) {
                // On prolonge à partir de l'échéance en cours, ou d'aujourd'hui si elle est dépassée.
                $base = max(date('Y-m-d'), (string)$p['abonnement_fin']);
                $fin = date('Y-m-d', strtotime("+$mois month", strtotime($base)));
                q('UPDATE pharmacies SET abonnement_fin = ? WHERE id = ?', [$fin, $p['id']]);
                flash("Abonnement prolongé de $mois mois : nouvelle échéance le " . dfr($fin) . '.');
            }
        }

        if ($act === 'reset_pass') {
            $pass = (string)post('password');
            $u = one("SELECT * FROM users WHERE id = ? AND pharmacy_id = ? AND role = 'gerant'", [(int)post('user_id'), $p['id']]);
            if (!$u) flash('Gérant introuvable.', 'err');
            elseif (strlen($pass) < 8) flash('Le nouveau mot de passe doit faire au moins 8 caractères.', 'err');
            else {
                q('UPDATE users SET password_hash = ?, actif = 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                flash("Mot de passe de {$u['nom']} ({$u['login']}) réinitialisé.");
            }
        }

        if ($act === 'delete') {
            if (norm_code((string)post('confirm')) !== $p['code']) {
                flash('Suppression annulée : le code saisi ne correspond pas.', 'err');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // sale_lines et sale_line_lots partent en cascade avec les ventes.
                    foreach (['sales', 'stock_movements', 'lots', 'receptions', 'cash_sessions', 'products', 'insurers', 'suppliers', 'users', 'settings'] as $t) {
                        q("DELETE FROM $t WHERE pharmacy_id = ?", [$p['id']]);
                    }
                    q('DELETE FROM pharmacies WHERE id = ?', [$p['id']]);
                    $pdo->commit();
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    throw $ex;
                }
                flash("Pharmacie « {$p['nom']} » et toutes ses données supprimées.");
                redirect('admin');
            }
        }
        redirect('admin', $back);
    }

    /* ----- Mot de passe du super-administrateur ----- */
    if ($act === 'password') {
        $new = (string)post('new');
        if (!password_verify((string)post('current'), $me['password_hash'])) flash('Mot de passe actuel incorrect.', 'err');
        elseif (strlen($new) < 8) flash('Nouveau mot de passe : 8 caractères minimum.', 'err');
        elseif ($new !== post('confirm')) flash('Les deux mots de passe ne correspondent pas.', 'err');
        else { q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]); flash('Mot de passe modifié.'); }
        redirect('admin', ['a' => 'compte']);
    }
    redirect('admin');
}

/* ================= Mon compte ================= */
if ($a === 'compte') {
    admin_header('Mon compte');
    ?>
    <form method="post" class="card narrow">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <h2><?= e($me['nom']) ?> <small class="muted">(<?= e($me['login']) ?>)</small></h2>
      <label>Mot de passe actuel<input type="password" name="current" required autocomplete="current-password"></label>
      <label>Nouveau mot de passe<input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
      <label>Confirmer<input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary">Changer le mot de passe</button>
    </form>
    <?php
    admin_footer();
    return;
}

/* ================= Nouvelle pharmacie ================= */
if ($a === 'new') {
    $old = $_SESSION['admin_form'] ?? [];
    unset($_SESSION['admin_form']);
    $o = fn($k, $d = '') => e($old[$k] ?? $d);
    admin_header('Nouvelle pharmacie');
    ?>
    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <h2>Pharmacie</h2>
      <div class="grid3">
        <label class="span2">Nom de la pharmacie *<input name="nom" value="<?= $o('nom') ?>" required autofocus></label>
        <label>Code pharmacie * <input name="code" value="<?= $o('code') ?>" required minlength="3" maxlength="20" pattern="[A-Za-z0-9]{3,20}" placeholder="Ex. SANTE01" autocapitalize="characters"></label>
        <label>Adresse<input name="adresse" value="<?= $o('adresse', 'Niamey, Niger') ?>"></label>
        <label>Téléphone<input name="telephone" value="<?= $o('telephone') ?>"></label>
        <label>Fin d'abonnement<input type="date" name="abonnement_fin" value="<?= $o('abonnement_fin', date('Y-m-d', strtotime('+1 month'))) ?>"></label>
        <label class="span2">Note interne (facultatif)<input name="note" value="<?= $o('note') ?>" placeholder="Contact, mode de paiement, remarque…"></label>
      </div>
      <p class="muted">Le code pharmacie est saisi par le personnel à chaque connexion : 3 à 20 lettres ou chiffres, sans espace. Laissez la date de fin vide pour un accès sans échéance.</p>
      <h2>Compte du gérant</h2>
      <div class="grid3">
        <label>Nom complet *<input name="gerant_nom" value="<?= $o('gerant_nom') ?>" required></label>
        <label>Identifiant *<input name="gerant_login" value="<?= $o('gerant_login', 'gerant') ?>" required autocomplete="off"></label>
        <label>Mot de passe * (8 caractères min.)<input type="password" name="gerant_pass" required minlength="8" autocomplete="new-password"></label>
      </div>
      <label class="check"><input type="checkbox" name="demo" value="1" <?= ($old['demo'] ?? '') === '1' ? 'checked' : '' ?>> Ajouter des données de démonstration (8 médicaments, 2 assurances, 1 fournisseur)</label>
      <div class="row"><button class="btn primary">Créer la pharmacie</button><a class="btn" href="<?= url('admin') ?>">Annuler</a></div>
    </form>
    <?php
    admin_footer();
    return;
}

/* ================= Fiche d'une pharmacie ================= */
if ($a === 'edit') {
    $p = one('SELECT * FROM pharmacies WHERE id = ?', [(int)get('id')]);
    if (!$p) redirect('admin');
    [$cls, $lbl] = ph_status($p);
    $j = abonnement_jours($p);
    $gerants = all("SELECT id, nom, login, actif FROM users WHERE pharmacy_id = ? AND role = 'gerant' ORDER BY id", [$p['id']]);
    $stats = one("SELECT (SELECT COUNT(*) FROM users WHERE pharmacy_id = ? AND actif = 1) AS users,
                         (SELECT COUNT(*) FROM products WHERE pharmacy_id = ?) AS produits,
                         (SELECT COUNT(*) FROM sales WHERE pharmacy_id = ? AND statut = 'payee') AS ventes,
                         (SELECT MAX(created_at) FROM sales WHERE pharmacy_id = ?) AS derniere", array_fill(0, 4, $p['id']));
    admin_header($p['nom']);
    ?>
    <div class="kpis">
      <div class="kpi"><small>Code pharmacie</small><strong><?= e($p['code']) ?></strong></div>
      <div class="kpi <?= $cls === 'ok' ? '' : 'alert' ?>"><small>Statut</small><strong><?= e($lbl) ?></strong></div>
      <div class="kpi"><small>Fin d'abonnement</small><strong><?= $p['abonnement_fin'] ? dfr($p['abonnement_fin']) : 'Sans échéance' ?></strong></div>
      <div class="kpi"><small>Utilisateurs actifs</small><strong><?= (int)$stats['users'] ?></strong></div>
      <div class="kpi"><small>Ventes encaissées</small><strong><?= (int)$stats['ventes'] ?></strong></div>
      <div class="kpi"><small>Dernière activité</small><strong><?= $stats['derniere'] ? dfr($stats['derniere']) : '—' ?></strong></div>
    </div>

    <div class="card">
      <h2>Abonnement</h2>
      <p class="muted"><?php if ($j === null): ?>Aucune échéance : l'accès reste ouvert tant que la pharmacie n'est pas suspendue.
        <?php elseif ($j < 0): ?>Expiré depuis <?= -$j ?> jour(s) : l'accès est bloqué.
        <?php else: ?>Encore <?= $j ?> jour(s). L'accès se bloque automatiquement le lendemain de l'échéance.<?php endif; ?>
        Après réception du paiement, prolongez ici.</p>
      <form method="post" class="row wrap">
        <?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <?php foreach ([1 => '+ 1 mois', 3 => '+ 3 mois', 6 => '+ 6 mois', 12 => '+ 1 an'] as $m => $l): ?>
          <button class="btn <?= $m === 1 ? 'primary' : '' ?>" name="mois" value="<?= $m ?>"><?= $l ?></button>
        <?php endforeach; ?>
      </form>
    </div>

    <form method="post" class="card">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <h2>Informations</h2>
      <div class="grid3">
        <label class="span2">Nom de la pharmacie<input name="nom" value="<?= e($p['nom']) ?>" required></label>
        <label>Fin d'abonnement (vide = sans échéance)<input type="date" name="abonnement_fin" value="<?= e($p['abonnement_fin']) ?>"></label>
        <label>Adresse<input name="adresse" value="<?= e($p['adresse']) ?>"></label>
        <label>Téléphone<input name="telephone" value="<?= e($p['telephone']) ?>"></label>
        <label>Note interne<input name="note" value="<?= e($p['note']) ?>"></label>
      </div>
      <label class="check"><input type="checkbox" name="actif" value="1" <?= $p['actif'] ? 'checked' : '' ?>> Compte actif (décochez pour suspendre l'accès de toute la pharmacie)</label>
      <div class="row"><button class="btn primary">Enregistrer</button><a class="btn" href="<?= url('admin') ?>">← Retour à la liste</a></div>
    </form>

    <div class="card">
      <h2>Gérant(s)</h2>
      <p class="muted">Le gérant crée lui-même les autres comptes (pharmaciens, vendeurs, caissiers). En cas d'oubli, définissez-lui un nouveau mot de passe ici.</p>
      <table class="tbl"><thead><tr><th>Nom</th><th>Identifiant</th><th>Statut</th><th>Nouveau mot de passe</th></tr></thead><tbody>
      <?php foreach ($gerants as $g): ?>
        <tr><td><?= e($g['nom']) ?></td><td><?= e($g['login']) ?></td>
          <td><?= $g['actif'] ? '<span class="badge ok">Actif</span>' : '<span class="badge">Désactivé</span>' ?></td>
          <td><form method="post" class="row inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="reset_pass"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$g['id'] ?>">
            <input type="password" name="password" minlength="8" required placeholder="8 caractères min." autocomplete="new-password">
            <button class="btn sm">Réinitialiser</button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>

    <details class="card danger-zone">
      <summary><strong>Supprimer définitivement cette pharmacie</strong></summary>
      <form method="post" class="narrow">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <p>Toutes les données de <strong><?= e($p['nom']) ?></strong> seront effacées : utilisateurs, médicaments, stock, ventes et caisse (<?= (int)$stats['produits'] ?> médicament(s), <?= (int)$stats['ventes'] ?> vente(s)). Cette action est irréversible. Pour simplement bloquer l'accès, suspendez plutôt le compte.</p>
        <label>Pour confirmer, saisissez le code <code><?= e($p['code']) ?></code><input name="confirm" required autocomplete="off"></label>
        <button class="btn danger">Supprimer la pharmacie et ses données</button>
      </form>
    </details>
    <?php
    admin_footer();
    return;
}

/* ================= Liste des pharmacies ================= */
$rows = all("SELECT p.*,
             (SELECT COUNT(*) FROM users u WHERE u.pharmacy_id = p.id AND u.actif = 1) AS users,
             (SELECT COUNT(*) FROM sales s WHERE s.pharmacy_id = p.id AND s.statut = 'payee' AND s.paid_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS ventes30,
             (SELECT MAX(s.created_at) FROM sales s WHERE s.pharmacy_id = p.id) AS derniere
             FROM pharmacies p ORDER BY p.actif DESC, p.nom");
$n = ['ok' => 0, 'warn' => 0, 'err' => 0];
foreach ($rows as $r) $n[ph_status($r)[0]]++;
admin_header('Pharmacies');
?>
<div class="kpis">
  <div class="kpi"><small>Pharmacies</small><strong><?= count($rows) ?></strong></div>
  <div class="kpi"><small>Actives</small><strong><?= $n['ok'] + $n['warn'] ?></strong></div>
  <div class="kpi <?= $n['warn'] ? 'alert' : '' ?>"><small>Expirent sous 7 jours</small><strong><?= $n['warn'] ?></strong></div>
  <div class="kpi <?= $n['err'] ? 'alert' : '' ?>"><small>Expirées ou suspendues</small><strong><?= $n['err'] ?></strong></div>
</div>
<div class="card">
  <div class="row between"><h2>Pharmacies clientes</h2><a class="btn primary" href="<?= url('admin', ['a' => 'new']) ?>">+ Nouvelle pharmacie</a></div>
  <table class="tbl">
    <thead><tr><th>Pharmacie</th><th>Code</th><th>Statut</th><th>Fin d'abonnement</th><th class="r">Utilisateurs</th><th class="r">Ventes (30 j)</th><th>Dernière activité</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$cls, $lbl] = ph_status($r); $j = abonnement_jours($r); ?>
      <tr><td><strong><?= e($r['nom']) ?></strong><?= $r['note'] ? '<br><small class="muted">' . e($r['note']) . '</small>' : '' ?></td>
        <td><code><?= e($r['code']) ?></code></td>
        <td><span class="badge <?= $cls ?>"><?= e($lbl) ?></span></td>
        <td><?= $r['abonnement_fin'] ? dfr($r['abonnement_fin']) . ($j >= 0 ? ' <small class="muted">(' . $j . ' j)</small>' : '') : 'Sans échéance' ?></td>
        <td class="r"><?= (int)$r['users'] ?></td><td class="r"><?= (int)$r['ventes30'] ?></td>
        <td><?= $r['derniere'] ? dfr($r['derniere']) : '—' ?></td>
        <td><a href="<?= url('admin', ['a' => 'edit', 'id' => $r['id']]) ?>">Gérer</a></td></tr>
    <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="8">Aucune pharmacie. Créez la première avec « Nouvelle pharmacie ».</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_footer();
