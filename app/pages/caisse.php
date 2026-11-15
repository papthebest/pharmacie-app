<?php
require_right('caisse');
$me = user();
$session = open_session_for((int)$me['id']);
$act = post('action');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($act === 'open' && !$session) {
        q('INSERT INTO cash_sessions (pharmacy_id, user_id, fond_initial) VALUES (?, ?, ?)', [ph(), $me['id'], max(0, intv(post('fond_initial')))]);
        flash('Caisse ouverte.');
        redirect('caisse');
    }
    if (!$session) redirect('caisse');

    if ($act === 'pay') {
        $sid = (int)post('sale_id');
        $mode = post('mode');
        if (!isset(MODES[$mode])) { flash('Choisissez un mode de paiement.', 'err'); redirect('caisse', ['id' => $sid]); }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $sale = one("SELECT * FROM sales WHERE id = ? AND pharmacy_id = ? FOR UPDATE", [$sid, ph()]);
            if (!$sale || $sale['statut'] !== 'en_attente') throw new DomainException('Ce bon n\'est plus en attente.');
            $recu = $mode === 'especes' ? intv(post('montant_recu')) : (int)$sale['part_patient'];
            if ($recu < (int)$sale['part_patient']) throw new DomainException('Montant reçu insuffisant.');
            foreach (all('SELECT l.*, p.nom FROM sale_lines l JOIN products p ON p.id = l.product_id WHERE l.sale_id = ?', [$sid]) as $line) {
                $need = (int)$line['quantite'];
                $lots = all('SELECT id, quantite FROM lots WHERE pharmacy_id = ? AND product_id = ? AND quantite > 0 AND date_peremption >= CURDATE()
                             ORDER BY date_peremption, id FOR UPDATE', [ph(), $line['product_id']]);
                foreach ($lots as $lot) {
                    if ($need <= 0) break;
                    $take = min($need, (int)$lot['quantite']);
                    q('UPDATE lots SET quantite = quantite - ? WHERE id = ?', [$take, $lot['id']]);
                    q('INSERT INTO sale_line_lots (sale_line_id, lot_id, quantite) VALUES (?,?,?)', [$line['id'], $lot['id'], $take]);
                    q("INSERT INTO stock_movements (pharmacy_id, product_id, lot_id, type, quantite, reference, user_id) VALUES (?,?,?, 'vente', ?, ?, ?)",
                        [ph(), $line['product_id'], $lot['id'], -$take, 'Bon ' . $sale['numero'], $me['id']]);
                    $need -= $take;
                }
                if ($need > 0) throw new DomainException("Stock insuffisant pour « {$line['nom']} » (il manque {$need}). Le bon ne peut pas être encaissé.");
            }
            q("UPDATE sales SET statut = 'payee', caissier_id = ?, cash_session_id = ?, mode_paiement = ?, montant_recu = ?, monnaie = ?, paid_at = NOW() WHERE id = ?",
                [$me['id'], $session['id'], $mode, $recu, $recu - (int)$sale['part_patient'], $sid]);
            $col = ['especes' => 'total_especes', 'mobile' => 'total_mobile', 'carte' => 'total_carte'][$mode];
            q("UPDATE cash_sessions SET {$col} = {$col} + ?, total_assurance = total_assurance + ? WHERE id = ?",
                [$sale['part_patient'], $sale['part_assurance'], $session['id']]);
            $pdo->commit();
        } catch (DomainException $ex) {
            $pdo->rollBack();
            flash($ex->getMessage(), 'err');
            redirect('caisse', ['id' => $sid]);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        flash("Bon {$sale['numero']} encaissé." . ($mode === 'especes' && $recu > $sale['part_patient'] ? ' Monnaie à rendre : ' . money($recu - $sale['part_patient']) . '.' : ''));
        redirect('caisse', ['paid' => $sid]);
    }

    if ($act === 'cancel') {
        $sid = (int)post('sale_id');
        $motif = post('motif');
        if ($motif === '') { flash('Indiquez le motif d\'annulation.', 'err'); redirect('caisse', ['id' => $sid]); }
        $n = q("UPDATE sales SET statut = 'annulee', cancelled_at = NOW(), cancelled_by = ?, cancel_motif = ? WHERE id = ? AND pharmacy_id = ? AND statut = 'en_attente'",
            [$me['id'], $motif, $sid, ph()])->rowCount();
        flash($n ? 'Bon annulé.' : 'Ce bon n\'est plus en attente.', $n ? 'ok' : 'err');
        redirect('caisse');
    }

    if ($act === 'close') {
        $compte = intv(post('montant_compte'));
        $attendu = (int)$session['fond_initial'] + (int)$session['total_especes'];
        q('UPDATE cash_sessions SET closed_at = NOW(), montant_compte = ?, ecart = ?, note = ? WHERE id = ?',
            [$compte, $compte - $attendu, post('note') ?: null, $session['id']]);
        flash('Caisse clôturée. Écart : ' . money($compte - $attendu) . '.', $compte === $attendu ? 'ok' : 'err');
        redirect('ventes', ['session' => $session['id']]);
    }
}

page_header('Caisse', 'caisse');

if (!$session): ?>
  <form method="post" class="card narrow">
    <?= csrf_field() ?><input type="hidden" name="action" value="open">
    <h2>Ouvrir la caisse</h2>
    <p class="muted">Comptez l'argent présent dans le tiroir avant de commencer.</p>
    <label>Fond de caisse initial (FCFA)<input name="fond_initial" inputmode="numeric" value="0" required></label>
    <button class="btn primary block">Ouvrir la caisse</button>
  </form>
<?php page_footer(); return; endif;

$sel = get('id') ? one("SELECT s.*, u.nom AS vendeur, i.nom AS assurance FROM sales s JOIN users u ON u.id = s.vendeur_id
                         LEFT JOIN insurers i ON i.id = s.insurer_id WHERE s.id = ? AND s.pharmacy_id = ?", [(int)get('id'), ph()]) : null;
$selLines = $sel ? all('SELECT l.*, p.nom, p.dosage, p.forme FROM sale_lines l JOIN products p ON p.id = l.product_id WHERE l.sale_id = ?', [$sel['id']]) : [];
$paid = get('paid') ? one('SELECT id, numero FROM sales WHERE id = ? AND pharmacy_id = ?', [(int)get('paid'), ph()]) : null;
$encaisse = (int)$session['total_especes'] + (int)$session['total_mobile'] + (int)$session['total_carte'];
$nbVentes = (int)val("SELECT COUNT(*) FROM sales WHERE cash_session_id = ?", [$session['id']]);
?>
<?php if ($paid): ?>
  <div class="card bonbig ok">
    <div><small>Encaissé</small><strong><?= e($paid['numero']) ?></strong></div>
    <a class="btn primary" target="_blank" id="autoprint" href="<?= url('ticket', ['id' => $paid['id']]) ?>">Imprimer le ticket</a>
  </div>
<?php endif; ?>

<div class="kpis">
  <div class="kpi"><small>Ouverte depuis</small><strong><?= date('H:i', strtotime($session['opened_at'])) ?></strong></div>
  <div class="kpi"><small>Ventes encaissées</small><strong><?= $nbVentes ?></strong></div>
  <div class="kpi"><small>Encaissé (patients)</small><strong><?= money($encaisse) ?></strong></div>
  <div class="kpi"><small>Espèces attendues en tiroir</small><strong><?= money((int)$session['fond_initial'] + (int)$session['total_especes']) ?></strong></div>
</div>

<div class="cols">
  <section class="card grow">
    <h2>Bons en attente <span class="live" title="Mise à jour automatique">●</span></h2>
    <input id="bon-filter" placeholder="Taper le numéro du bon (ex. 012)…" autocomplete="off">
    <div id="pending" class="pending" data-selected="<?= $sel ? (int)$sel['id'] : 0 ?>"><p class="muted">Chargement…</p></div>
  </section>

  <aside class="card side-panel">
    <?php if ($sel && $sel['statut'] === 'en_attente'): ?>
      <h2>Bon <?= e($sel['numero']) ?></h2>
      <p class="muted">Vendeur : <?= e($sel['vendeur']) ?> · <?= date('H:i', strtotime($sel['created_at'])) ?>
        <?= $sel['client_nom'] ? ' · Client : ' . e($sel['client_nom']) : '' ?></p>
      <table class="tbl compact">
        <?php foreach ($selLines as $l): ?>
          <tr><td><?= e($l['nom']) ?> <small class="muted"><?= e($l['dosage']) ?></small></td><td class="c">×<?= (int)$l['quantite'] ?></td><td class="r"><?= money($l['total']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <?php if ($sel['medecin']): ?><p class="muted">Ordonnance <?= e($sel['ordonnance_num'] ?: '') ?> du <?= dfr($sel['ordonnance_date']) ?> — Dr <?= e($sel['medecin']) ?> — Patient : <?= e($sel['patient_nom']) ?></p><?php endif; ?>
      <div class="totals">
        <div><span>Total</span><strong><?= money($sel['total']) ?></strong></div>
        <?php if ($sel['part_assurance']): ?><div><span><?= e($sel['assurance']) ?> (<?= (int)$sel['taux_assurance'] ?> %) · n° <?= e($sel['num_assure']) ?></span><strong><?= money($sel['part_assurance']) ?></strong></div><?php endif; ?>
        <div class="big"><span>À encaisser</span><strong id="due" data-due="<?= (int)$sel['part_patient'] ?>"><?= money($sel['part_patient']) ?></strong></div>
      </div>
      <form method="post" id="payform">
        <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="sale_id" value="<?= (int)$sel['id'] ?>">
        <div class="modes">
          <?php foreach (MODES as $k => $lbl): ?>
            <label class="mode"><input type="radio" name="mode" value="<?= $k ?>" <?= $k === 'especes' ? 'checked' : '' ?>><span><?= $lbl ?></span></label>
          <?php endforeach; ?>
        </div>
        <label id="recu-wrap">Montant reçu<input name="montant_recu" id="recu" inputmode="numeric" value="<?= (int)$sel['part_patient'] ?>"></label>
        <div class="change">Monnaie à rendre : <strong id="monnaie">0 F</strong></div>
        <button class="btn primary block big">Encaisser <?= money($sel['part_patient']) ?></button>
      </form>
      <details class="cancel">
        <summary>Annuler ce bon</summary>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="sale_id" value="<?= (int)$sel['id'] ?>">
          <label>Motif<input name="motif" required placeholder="Client parti, erreur de saisie…"></label>
          <button class="btn danger block">Confirmer l'annulation</button>
        </form>
      </details>
    <?php else: ?>
      <h2>Encaissement</h2>
      <p class="muted">Sélectionnez un bon dans la liste ou tapez son numéro.</p>
    <?php endif; ?>
  </aside>
</div>

<details class="card">
  <summary><strong>Clôturer la caisse</strong></summary>
  <form method="post" class="narrow">
    <?= csrf_field() ?><input type="hidden" name="action" value="close">
    <p>Fond initial <?= money($session['fond_initial']) ?> + espèces encaissées <?= money($session['total_especes']) ?> =
      <strong><?= money((int)$session['fond_initial'] + (int)$session['total_especes']) ?></strong> attendus dans le tiroir.
      Mobile money : <?= money($session['total_mobile']) ?> · Carte : <?= money($session['total_carte']) ?> · Assurances (à facturer) : <?= money($session['total_assurance']) ?>.</p>
    <label>Espèces comptées dans le tiroir (FCFA)<input name="montant_compte" inputmode="numeric" required></label>
    <label>Remarque<input name="note"></label>
    <button class="btn danger" onclick="return confirm('Clôturer la caisse ?')">Clôturer</button>
  </form>
</details>
<?php page_footer();
