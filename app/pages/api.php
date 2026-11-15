<?php
$a = get('a');

if ($a === 'search') {
    if (!can('comptoir') && !can('receptions')) json_out(['error' => 'Accès refusé'], 403);
    $term = get('q');
    if ($term === '') json_out([]);
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
    $rows = all("SELECT id, nom, dci, dosage, forme, prix_vente, prix_achat, ordonnance, stupefiant, code_barre
                 FROM products WHERE pharmacy_id = ? AND actif = 1 AND (code_barre = ? OR nom LIKE ? OR dci LIKE ?)
                 ORDER BY (code_barre = ?) DESC, nom LIMIT 15", [ph(), $term, $like, $like, $term]);
    foreach ($rows as &$r) {
        $r['dispo'] = max(0, stock_dispo((int)$r['id']) - stock_reserve((int)$r['id']));
        foreach (['id', 'prix_vente', 'prix_achat', 'ordonnance', 'stupefiant'] as $k) $r[$k] = (int)$r[$k];
    }
    json_out($rows);
}

if ($a === 'pending') {
    if (!can('caisse')) json_out(['error' => 'Accès refusé'], 403);
    $rows = all("SELECT s.id, s.numero, s.client_nom, s.patient_nom, s.total, s.part_patient, s.part_assurance, s.created_at,
                        u.nom AS vendeur, i.nom AS assurance,
                        (SELECT COUNT(*) FROM sale_lines l WHERE l.sale_id = s.id) AS nb
                 FROM sales s JOIN users u ON u.id = s.vendeur_id LEFT JOIN insurers i ON i.id = s.insurer_id
                 WHERE s.pharmacy_id = ? AND s.statut = 'en_attente' ORDER BY s.created_at", [ph()]);
    json_out($rows);
}

json_out(['error' => 'Action inconnue'], 404);
