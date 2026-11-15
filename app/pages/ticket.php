<?php
if (!can('comptoir') && !can('caisse') && !can('ventes')) require_right('ventes');
$type = get('type', 'ticket');
$ph = ['nom' => setting('pharmacie_nom', 'Pharmacie'), 'adr' => setting('pharmacie_adresse'), 'tel' => setting('pharmacie_tel')];

if ($type === 'session') {
    require_right('ventes');
    $s = one('SELECT c.*, u.nom FROM cash_sessions c JOIN users u ON u.id = c.user_id WHERE c.id = ?', [(int)get('id')]);
    if (!$s) exit('Session introuvable');
    $nb = (int)val("SELECT COUNT(*) FROM sales WHERE cash_session_id = ?", [$s['id']]);
} else {
    $s = one('SELECT s.*, v.nom AS vendeur, c.nom AS caissier, i.nom AS assurance FROM sales s JOIN users v ON v.id = s.vendeur_id
              LEFT JOIN users c ON c.id = s.caissier_id LEFT JOIN insurers i ON i.id = s.insurer_id WHERE s.id = ?', [(int)get('id')]);
    if (!$s) exit('Bon introuvable');
    $lines = all('SELECT l.*, p.nom, p.dosage FROM sale_lines l JOIN products p ON p.id = l.product_id WHERE l.sale_id = ?', [$s['id']]);
}
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><title>Ticket</title>
<style>
  @page { size: 80mm auto; margin: 3mm; }
  body { font: 12px/1.35 Arial, sans-serif; width: 74mm; margin: 0 auto; color: #000; }
  h1 { font-size: 15px; margin: 0; text-align: center; } .c { text-align: center; } .r { text-align: right; }
  hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; } table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 1px 0; } .big { font-size: 16px; font-weight: bold; } .num { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
  .noprint { margin: 10px 0; text-align: center; } @media print { .noprint { display: none; } }
</style></head>
<body onload="setTimeout(() => window.print(), 300)">
<div class="noprint"><button onclick="window.print()">Imprimer</button> <button onclick="window.close()">Fermer</button></div>
<h1><?= e($ph['nom']) ?></h1>
<div class="c"><?= e($ph['adr']) ?><?= $ph['tel'] ? '<br>Tél. ' . e($ph['tel']) : '' ?></div>
<hr>
<?php if ($type === 'session'): ?>
  <div class="c big">RAPPORT DE CAISSE</div>
  <div>Caissier : <?= e($s['nom']) ?><br>Ouverture : <?= dfr($s['opened_at'], true) ?><br>Clôture : <?= dfr($s['closed_at'], true) ?></div>
  <hr>
  <table>
    <tr><td>Ventes encaissées</td><td class="r"><?= $nb ?></td></tr>
    <tr><td>Fond initial</td><td class="r"><?= money($s['fond_initial']) ?></td></tr>
    <tr><td>Espèces</td><td class="r"><?= money($s['total_especes']) ?></td></tr>
    <tr><td>Mobile money</td><td class="r"><?= money($s['total_mobile']) ?></td></tr>
    <tr><td>Carte</td><td class="r"><?= money($s['total_carte']) ?></td></tr>
    <tr><td>Part assurances</td><td class="r"><?= money($s['total_assurance']) ?></td></tr>
    <tr><td>Espèces attendues</td><td class="r"><?= money((int)$s['fond_initial'] + (int)$s['total_especes']) ?></td></tr>
    <tr><td>Espèces comptées</td><td class="r"><?= $s['montant_compte'] === null ? '—' : money($s['montant_compte']) ?></td></tr>
    <tr class="big"><td>Écart</td><td class="r"><?= $s['ecart'] === null ? '—' : money($s['ecart']) ?></td></tr>
  </table>
  <hr><div>Signature caissier :<br><br>Signature gérant :</div>
<?php else: ?>
  <?php if ($type === 'bon'): ?>
    <div class="c">BON DE VENTE — à présenter à la caisse</div>
    <div class="c num"><?= e($s['numero']) ?></div>
  <?php else: ?>
    <div class="c big">TICKET DE CAISSE</div>
    <div class="c">N° <?= e($s['numero']) ?></div>
  <?php endif; ?>
  <div><?= dfr($s['paid_at'] ?? $s['created_at'], true) ?> · Vendeur : <?= e($s['vendeur']) ?><?= !empty($s['caissier']) ? '<br>Caissier : ' . e($s['caissier']) : '' ?></div>
  <?php if ($s['client_nom'] || $s['patient_nom']): ?><div>Client : <?= e($s['client_nom'] ?: $s['patient_nom']) ?></div><?php endif; ?>
  <hr>
  <table>
    <?php foreach ($lines as $l): ?>
      <tr><td colspan="2"><?= e($l['nom']) ?> <?= e($l['dosage']) ?></td></tr>
      <tr><td>&nbsp; <?= (int)$l['quantite'] ?> × <?= money($l['prix_unit']) ?></td><td class="r"><?= money($l['total']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <table>
    <tr><td>Total</td><td class="r"><?= money($s['total']) ?></td></tr>
    <?php if ($s['part_assurance']): ?>
      <tr><td><?= e($s['assurance']) ?> (<?= (int)$s['taux_assurance'] ?> %)<br>N° assuré <?= e($s['num_assure']) ?></td><td class="r">- <?= money($s['part_assurance']) ?></td></tr>
    <?php endif; ?>
    <tr class="big"><td><?= $type === 'bon' ? 'À PAYER' : 'PAYÉ' ?></td><td class="r"><?= money($s['part_patient']) ?></td></tr>
    <?php if ($type !== 'bon' && $s['mode_paiement']): ?>
      <tr><td><?= e(MODES[$s['mode_paiement']]) ?> reçu</td><td class="r"><?= money($s['montant_recu']) ?></td></tr>
      <?php if ((int)$s['monnaie'] > 0): ?><tr><td>Monnaie rendue</td><td class="r"><?= money($s['monnaie']) ?></td></tr><?php endif; ?>
    <?php endif; ?>
  </table>
  <?php if ($s['statut'] === 'annulee'): ?><hr><div class="c big">*** BON ANNULÉ ***</div><?php endif; ?>
  <hr>
  <div class="c"><?= e(setting('ticket_pied')) ?></div>
<?php endif; ?>
</body></html>
