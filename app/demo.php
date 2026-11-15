<?php
/** Données de démonstration pour une pharmacie : 1 fournisseur, 2 assurances, 8 médicaments avec 2 lots chacun. */
function demo_seed(int $ph, int $userId): void
{
    q("INSERT INTO suppliers (pharmacy_id, nom, telephone) VALUES (?, 'Grossiste démo', '')", [$ph]);
    $sup = (int)db()->lastInsertId();
    q("INSERT INTO insurers (pharmacy_id, nom, taux) VALUES (?, 'Assurance démo 80 %', 80), (?, 'Mutuelle démo 50 %', 50)", [$ph, $ph]);
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
    q("INSERT INTO receptions (pharmacy_id, supplier_id, reference, date_reception, user_id) VALUES (?, ?, 'DEMO-001', CURDATE(), ?)", [$ph, $sup, $userId]);
    $rec = (int)db()->lastInsertId();
    $total = 0;
    foreach ($demo as $i => $d) {
        q('INSERT INTO products (pharmacy_id, nom, dci, dosage, forme, prix_achat, prix_vente, ordonnance, stupefiant, seuil_alerte) VALUES (?,?,?,?,?,?,?,?,?,10)', [$ph, ...$d]);
        $pid = (int)db()->lastInsertId();
        foreach ([[40, '+2 months'], [60, '+14 months']] as $j => [$qty, $per]) {
            q('INSERT INTO lots (pharmacy_id, product_id, numero_lot, date_peremption, quantite, quantite_initiale, prix_achat, reception_id) VALUES (?,?,?,?,?,?,?,?)',
                [$ph, $pid, sprintf('L%02d%s', $i + 1, $j ? 'B' : 'A'), date('Y-m-d', strtotime($per)), $qty, $qty, $d[4], $rec]);
            q("INSERT INTO stock_movements (pharmacy_id, product_id, lot_id, type, quantite, reference, user_id) VALUES (?,?,?, 'entree', ?, 'Réception DEMO-001', ?)",
                [$ph, $pid, (int)db()->lastInsertId(), $qty, $userId]);
            $total += $qty * $d[4];
        }
    }
    q('UPDATE receptions SET total = ? WHERE id = ?', [$total, $rec]);
}
