<?php
require __DIR__ . '/app/bootstrap.php';

$page = preg_replace('/[^a-z_]/', '', $_GET['p'] ?? 'dashboard') ?: 'dashboard';

/* ---------- Espace super-administrateur (plateforme) ---------- */
if (str_starts_with($page, 'admin')) {
    if ($page !== 'admin_login' && !admin()) redirect('admin_login');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();
    $file = APP . '/pages/' . $page . '.php';
    if (!is_file($file)) redirect('admin');
    require $file;
    exit;
}

/* ---------- Espace pharmacie ---------- */
$public = ['login'];
if (!user() && !in_array($page, $public, true)) {
    if ($page === 'api') json_out(['error' => 'Session expirée, reconnectez-vous.'], 401);
    redirect('login');
}
// Pharmacie suspendue ou abonnement expiré : on ferme la session en cours.
if (user() && $page !== 'logout' && ($block = pharmacy_block(pharmacy())) !== null) {
    unset($_SESSION['uid']);
    if ($page === 'api') json_out(['error' => $block], 403);
    $_SESSION['login_msg'] = $block;
    redirect('login');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

$file = APP . '/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    page_header('Page introuvable');
    echo '<div class="card"><p>Cette page n\'existe pas.</p></div>';
    page_footer();
    exit;
}
require $file;
