<?php
const NAV = [
    ['dashboard',   'Tableau de bord', 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10'],
    ['comptoir',    'Comptoir',        'M3 6h18M3 6l2 13h14l2-13M9 10v6M15 10v6'],
    ['caisse',      'Caisse',          'M4 7h16v12H4zM8 7V4h8v3M8 12h8M8 15h5'],
    ['ventes',      'Ventes',          'M5 4h14v16l-3-2-2 2-2-2-2 2-2-2-3 2zM9 9h6M9 13h6'],
    ['produits',    'Médicaments',     'M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7z'],
    ['stock',       'Stock & lots',    'M3 7l9-4 9 4-9 4zM3 7v10l9 4 9-4V7M12 11v10'],
    ['receptions',  'Réceptions',      'M12 3v12M7 10l5 5 5-5M4 19h16'],
    ['stupefiants', 'Registre stupéfiants', 'M6 3h12v18H6zM9 8h6M9 12h6M9 16h3'],
    ['rapports',    'Rapports',        'M4 20V10M10 20V4M16 20v-7M22 20H2'],
    ['parametres',  'Paramètres',      'M12 8a4 4 0 100 8 4 4 0 000-8zM12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M5 19l2-2M17 7l2-2'],
    ['utilisateurs','Utilisateurs',    'M8 11a4 4 0 100-8 4 4 0 000 8zM2 21v-1a6 6 0 0112 0v1M17 11a3 3 0 100-6M22 21v-1a5 5 0 00-4-5'],
];

function icon(string $d, int $s = 18): string
{
    return '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

function page_header(string $title, string $active = ''): void
{
    $u = user();
    $pharma = setting('pharmacie_nom', 'PharmaFlow');
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($pharma) ?></title>
<link rel="stylesheet" href="assets/app.css?v=2">
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#0F766E">
<meta name="csrf" content="<?= csrf_token() ?>">
</head>
<body>
<?php if ($u): ?>
<div class="shell">
  <aside class="side" id="side">
    <div class="brand"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small><?= e($pharma) ?></small></div></div>
    <nav>
      <?php foreach (NAV as [$key, $label, $ic]):
          if (!can($key)) continue; ?>
        <a href="<?= url($key) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <a href="<?= url('compte') ?>" class="who" title="Mon compte"><strong><?= e($u['nom']) ?></strong><small><?= e(ROLES[$u['role']]) ?></small></a>
      <a href="<?= url('logout') ?>" title="Se déconnecter">⎋</a>
    </div>
  </aside>
  <main class="main">
    <header class="top">
      <button class="burger" onclick="document.getElementById('side').classList.toggle('open')" aria-label="Menu">☰</button>
      <h1><?= e($title) ?></h1>
      <span class="clock" id="clock"></span>
    </header>
    <?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?>
      <div class="flash <?= e($t) ?>"><?= e($m) ?></div>
    <?php endforeach; unset($_SESSION['flash']);
    $jr = abonnement_jours(pharmacy());
    if ($jr !== null && $jr <= 7 && $u['role'] === 'gerant'): ?>
      <div class="flash err">Abonnement PharmaFlow : <?= $jr <= 0 ? 'il expire aujourd\'hui' : 'il expire dans ' . $jr . ' jour' . ($jr > 1 ? 's' : '') ?> (le <?= dfr(pharmacy()['abonnement_fin']) ?>). Contactez PharmaFlow pour le renouveler, sinon l'accès sera bloqué.</div>
    <?php endif; ?>
<?php else: ?>
<div class="auth">
<?php endif;
}

function page_footer(): void
{
    if (user()): ?>
  </main>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<script src="assets/app.js?v=1"></script>
</body>
</html>
<?php
}

/* ---------- Espace super-administrateur ---------- */
function admin_header(string $title): void
{
    $a = admin();
    ?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · Administration PharmaFlow</title>
<link rel="stylesheet" href="assets/app.css?v=2">
<meta name="theme-color" content="#0B3B37">
</head>
<body>
<?php if ($a): ?>
<header class="admbar">
  <div class="brand"><span class="logo">✚</span><div><strong>PharmaFlow</strong><small>Administration de la plateforme</small></div></div>
  <nav>
    <a href="<?= url('admin') ?>">Pharmacies</a>
    <a href="<?= url('admin', ['a' => 'compte']) ?>"><?= e($a['nom']) ?></a>
    <a href="<?= url('admin_logout') ?>">Déconnexion</a>
  </nav>
</header>
<main class="adm">
  <h1><?= e($title) ?></h1>
  <?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?>
    <div class="flash <?= e($t) ?>"><?= e($m) ?></div>
  <?php endforeach; unset($_SESSION['flash']); ?>
<?php else: ?>
<div class="auth">
<?php endif;
}

function admin_footer(): void
{
    echo admin() ? "</main>\n" : "</div>\n";
    echo "</body>\n</html>\n";
}
