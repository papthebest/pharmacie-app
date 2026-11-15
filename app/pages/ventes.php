<?php
require_right('ventes');
$me = user();
$isCaissier = $me['role'] === 'caissier';

page_header('Ventes', 'ventes');

if (get('session')):
    $s = one('SELECT c.*, u.nom FROM cash_sessions c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [(int)get('session')]);
    if (!$s || ($isCaissier && (int)$s['user_id'] !== (int)$me['id'])) { echo '<div class="card">Session introuvable.</div>'; page_footer(); return; } ?>
  <div class="card">
    <div class="row between"><h2>Caisse de <?= e($s['nom']) ?> — <?= dfr($s['opened_at'], true) ?></h2>
      <a class="btn" target="_blank" href="<?= url('ticket', ['type' => 'session', 'id' => $s['id']]) ?>">Imprimer le rapport</a></div>
    <div class="kpis">
      <div class="kpi"><small>Espèces</small><strong><?= money($s['total_especes']) ?></strong></div>
      <div class="kpi"><small>Mobile money</small><strong><?= money($s['total_mobile']) ?></strong></div>
      <div class="kpi"><small>Carte</small><strong><?= money($s['total_carte']) ?></strong></div>
      <div class="kpi"><small>Assurances</small><strong><?= money($s['total_assurance']) ?></strong></div>
      <div class="kpi <?= (int)$s['ecart'] === 0 ? '' : 'alert' ?>"><small>Écart de caisse</small><strong><?= $s['ecart'] === null ? 'ouverte' : money($s['ecart']) ?></strong></div>
    </div>
  </div>
<?php endif;

$du = get('du', date('Y-m-d'));
$au = get('au', date('Y-m-d'));
$statut = get('statut');
$num = get('num');
$params = [$du, $au];
$w = 'DATE(s.created_at) BETWEEN ? AND ?';
if ($statut !== '') { $w .= ' AND s.statut = ?'; $params[] = $statut; }
if ($num !== '') { $w .= ' AND s.numero LIKE ?'; $params[] = '%' . $num . '%'; }
if (get('session')) { $w = 's.cash_session_id = ?'; $params = [(int)get('session')]; }
$rows = all("SELECT s.*, v.nom AS vendeur, c.nom AS caissier, i.nom AS assurance FROM sales s
             JOIN users v ON v.id = s.vendeur_id LEFT JOIN users c ON c.id = s.caissier_id LEFT JOIN insurers i ON i.id = s.insurer_id
             WHERE $w ORDER BY s.id DESC LIMIT 500", $params);
$sum = array_sum(array_map(fn($r) => $r['statut'] === 'payee' ? (int)$r['total'] : 0, $rows));
?>
<div class="card">
  <form class="row wrap">
    <input type="hidden" name="p" value="ventes">
    <label>Du<input type="date" name="du" value="<?= e($du) ?>"></label>
    <label>Au<input type="date" name="au" value="<?= e($au) ?>"></label>
    <label>Statut<select name="statut"><option value="">Tous</option>
      <?php foreach (['payee' => 'Payé', 'en_attente' => 'En attente', 'annulee' => 'Annulé'] as $k => $l): ?><option value="<?= $k ?>" <?= $statut === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label>N° bon<input name="num" value="<?= e($num) ?>"></label>
    <button class="btn">Filtrer</button>
  </form>
  <p class="muted"><?= count($rows) ?> bon(s) · Chiffre d'affaires payé : <strong><?= money($sum) ?></strong></p>
  <table class="tbl">
    <thead><tr><th>N°</th><th>Date</th><th>Vendeur</th><th>Caissier</th><th>Client / patient</th><th class="r">Total</th><th class="r">Assurance</th><th>Mode</th><th>Statut</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><strong><?= e($r['numero']) ?></strong></td><td><?= dfr($r['created_at'], true) ?></td><td><?= e($r['vendeur']) ?></td><td><?= e($r['caissier'] ?? '—') ?></td>
        <td><?= e($r['client_nom'] ?: $r['patient_nom'] ?: '—') ?></td><td class="r"><?= money($r['total']) ?></td>
        <td class="r"><?= $r['part_assurance'] ? money($r['part_assurance']) . '<br><small class="muted">' . e($r['assurance']) . '</small>' : '—' ?></td>
        <td><?= $r['mode_paiement'] ? e(MODES[$r['mode_paiement']]) : '—' ?></td>
        <td><?= status_badge($r['statut']) ?><?= $r['cancel_motif'] ? '<br><small class="muted">' . e($r['cancel_motif']) . '</small>' : '' ?></td>
        <td><a target="_blank" href="<?= url('ticket', ['id' => $r['id']]) ?>">Ticket</a></td></tr>
    <?php endforeach; if (!$rows): ?><tr class="empty"><td colspan="10">Aucune vente sur cette période.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php
$sessions = all('SELECT c.*, u.nom FROM cash_sessions c JOIN users u ON u.id = c.user_id ' . ($isCaissier ? 'WHERE c.user_id = ' . (int)$me['id'] : '') . ' ORDER BY c.id DESC LIMIT 30');
?>
<div class="card">
  <h2>Sessions de caisse</h2>
  <table class="tbl"><thead><tr><th>Caissier</th><th>Ouverture</th><th>Clôture</th><th class="r">Espèces</th><th class="r">Mobile</th><th class="r">Assurances</th><th class="r">Écart</th><th></th></tr></thead><tbody>
  <?php foreach ($sessions as $s): ?>
    <tr><td><?= e($s['nom']) ?></td><td><?= dfr($s['opened_at'], true) ?></td><td><?= $s['closed_at'] ? dfr($s['closed_at'], true) : '<span class="badge ok">ouverte</span>' ?></td>
      <td class="r"><?= money($s['total_especes']) ?></td><td class="r"><?= money($s['total_mobile']) ?></td><td class="r"><?= money($s['total_assurance']) ?></td>
      <td class="r <?= (int)$s['ecart'] < 0 ? 'neg' : '' ?>"><?= $s['ecart'] === null ? '—' : money($s['ecart']) ?></td>
      <td><a href="<?= url('ventes', ['session' => $s['id']]) ?>">Détail</a></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php page_footer();
