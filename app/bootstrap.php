<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP', __DIR__);
date_default_timezone_set('Africa/Niamey');

if (!is_file(ROOT . '/config.php') || !is_file(ROOT . '/install.lock')) {
    header('Location: install.php');
    exit;
}
$CONFIG = require ROOT . '/config.php';

ini_set('display_errors', !empty($CONFIG['debug']) ? '1' : '0');
error_reporting(E_ALL);

// Erreur imprévue : message lisible pour l'utilisateur, détail dans le journal du serveur.
set_exception_handler(function (Throwable $ex) use ($CONFIG): void {
    error_log('PharmaFlow: ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) http_response_code(500);
    echo '<div class="flash err" style="margin:16px">Une erreur est survenue. Rechargez la page ; si le problème persiste, contactez PharmaFlow.'
       . (!empty($CONFIG['debug']) ? '<br><small>' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . '</small>' : '') . '</div>';
});

session_name('pharmaflow');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

/* ---------- Base de données ---------- */
function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $CONFIG['db_host'], (int)($CONFIG['db_port'] ?? 3306), $CONFIG['db_name']);
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+01:00'");
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}
function one(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r === false ? null : $r;
}
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $v = q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }

function setting(string $k, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k, v FROM settings WHERE pharmacy_id = ?', [ph()]) as $r) $cache[$r['k']] = (string)$r['v'];
    }
    return $cache[$k] ?? $default;
}

/* ---------- Helpers ---------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((int)$n, 0, ',', ' ') . ' F'; }
function dfr(?string $d, bool $time = false): string
{
    if (!$d) return '—';
    $t = strtotime($d);
    return $time ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}
function url(string $page, array $params = []): string
{
    // La connexion de l'administration a une adresse propre : /admin
    if ($page === 'admin_login' && !$params) return 'admin';
    // La connexion des pharmacies se fait à la racine du site.
    if (($page === 'login' || $page === 'dashboard') && !$params) return './';
    return 'index.php?' . http_build_query(['p' => $page] + $params);
}
function redirect(string $page, array $params = []): never
{
    header('Location: ' . url($page, $params));
    exit;
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function post(string $k, $default = '') { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $default; }
function get(string $k, $default = '') { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $default; }
function intv($v): int { return (int)preg_replace('/[^\d-]/', '', (string)$v); }
function status_badge(string $s): string
{
    return match ($s) {
        'en_attente' => '<span class="badge warn">En attente</span>',
        'payee' => '<span class="badge ok">Payé</span>',
        'annulee' => '<span class="badge err">Annulé</span>',
        default => e($s),
    };
}
const MODES = ['especes' => 'Espèces', 'mobile' => 'Mobile money', 'carte' => 'Carte'];
function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Requête invalide (jeton de sécurité expiré). Rechargez la page.');
    }
}

/* ---------- Authentification & rôles ---------- */
// Hash factice : garde un temps de réponse identique quand le compte n'existe pas.
const DUMMY_HASH = '$2y$10$zciRkaoNLHCUQfhFc/1Kbu7joWdHy4njGwL6EMamB47AegCy/frle';
/** Code pharmacie normalisé : majuscules, lettres et chiffres uniquement. */
function norm_code(string $c): string
{
    return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($c)), 0, 20);
}
const ROLES = [
    'gerant' => 'Gérant',
    'pharmacien' => 'Pharmacien',
    'vendeur' => 'Vendeur',
    'caissier' => 'Caissier',
];
// Droits par module
const RIGHTS = [
    'dashboard'   => ['gerant', 'pharmacien', 'vendeur', 'caissier'],
    'comptoir'    => ['gerant', 'pharmacien', 'vendeur'],
    'caisse'      => ['gerant', 'caissier'],
    'produits'    => ['gerant', 'pharmacien'],
    'stock'       => ['gerant', 'pharmacien'],
    'receptions'  => ['gerant', 'pharmacien'],
    'ventes'      => ['gerant', 'pharmacien', 'caissier'],
    'rapports'    => ['gerant'],
    'stupefiants' => ['gerant', 'pharmacien'],
    'parametres'  => ['gerant'],
    'utilisateurs'=> ['gerant'],
    'stupefiant_vente' => ['gerant', 'pharmacien'],
];
function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = one('SELECT * FROM users WHERE id = ? AND actif = 1', [$_SESSION['uid']]);
        }
    }
    return $u;
}
/** Identifiant de la pharmacie de l'utilisateur connecté (0 si personne n'est connecté). */
function ph(): int
{
    $u = user();
    return $u ? (int)$u['pharmacy_id'] : 0;
}
/** Pharmacie de l'utilisateur connecté. */
function pharmacy(): ?array
{
    static $p = false;
    if ($p === false) $p = ph() ? one('SELECT * FROM pharmacies WHERE id = ?', [ph()]) : null;
    return $p;
}
/** Motif de blocage d'une pharmacie (suspendue ou abonnement expiré), ou null si elle peut travailler. */
function pharmacy_block(?array $p): ?string
{
    if (!$p) return 'Pharmacie introuvable.';
    if ((int)$p['actif'] !== 1) return 'Le compte de cette pharmacie est suspendu. Contactez PharmaFlow.';
    if ($p['abonnement_fin'] !== null && $p['abonnement_fin'] < date('Y-m-d')) {
        return 'L\'abonnement de cette pharmacie a expiré le ' . dfr($p['abonnement_fin']) . '. Contactez PharmaFlow pour le renouveler.';
    }
    return null;
}
/** Jours restants avant la fin de l'abonnement (null = sans échéance). */
function abonnement_jours(?array $p): ?int
{
    if (!$p || $p['abonnement_fin'] === null) return null;
    return (int)floor((strtotime($p['abonnement_fin']) - strtotime(date('Y-m-d'))) / 86400);
}
/** Super-administrateur de la plateforme connecté, ou null. */
function admin(): ?array
{
    static $a = false;
    if ($a === false) $a = !empty($_SESSION['aid']) ? one('SELECT * FROM admins WHERE id = ?', [$_SESSION['aid']]) : null;
    return $a;
}
function can(string $right): bool
{
    $u = user();
    if (!$u) return false;
    if ($right === 'caisse' && (int)$u['peut_encaisser'] === 1) return true;
    return in_array($u['role'], RIGHTS[$right] ?? [], true);
}
function require_right(string $right): void
{
    if (!can($right)) {
        http_response_code(403);
        page_header('Accès refusé');
        echo '<div class="card"><h2>Accès refusé</h2><p>Votre rôle ne permet pas d\'ouvrir cette page.</p>'
           . '<p><a class="btn" href="' . url('dashboard') . '">Retour à l\'accueil</a></p></div>';
        page_footer();
        exit;
    }
}

function open_session_for(int $uid): ?array
{
    return one('SELECT * FROM cash_sessions WHERE pharmacy_id = ? AND user_id = ? AND closed_at IS NULL ORDER BY id DESC LIMIT 1', [ph(), $uid]);
}

/* ---------- Stock ---------- */
/** Stock vendable (lots non périmés) par produit */
function stock_dispo(int $pid): int
{
    return (int)val('SELECT COALESCE(SUM(quantite),0) FROM lots WHERE pharmacy_id = ? AND product_id = ? AND quantite > 0 AND date_peremption >= CURDATE()', [ph(), $pid]);
}
/** Quantités déjà réservées par les bons en attente */
function stock_reserve(int $pid, int $excludeSale = 0): int
{
    return (int)val("SELECT COALESCE(SUM(l.quantite),0) FROM sale_lines l JOIN sales s ON s.id = l.sale_id
                     WHERE s.pharmacy_id = ? AND s.statut = 'en_attente' AND l.product_id = ? AND s.id <> ?", [ph(), $pid, $excludeSale]);
}

require APP . '/layout.php';
