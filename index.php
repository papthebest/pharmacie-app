<?php
require __DIR__ . '/app/bootstrap.php';

$page = preg_replace('/[^a-z_]/', '', $_GET['p'] ?? 'dashboard') ?: 'dashboard';

$public = ['login'];
if (!user() && !in_array($page, $public, true)) {
    if ($page === 'api') json_out(['error' => 'Session expirée, reconnectez-vous.'], 401);
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
