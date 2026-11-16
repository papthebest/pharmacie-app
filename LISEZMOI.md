# PharmaFlow — gestion de pharmacie en SaaS (vente, caisse, stock)

Application web PHP 8 + MySQL multi-pharmacies, sans dépendance, prévue pour l'hébergement **Hostinger Premium / Business** (ou tout hébergement PHP + MySQL).

## Fonctionnement en SaaS

Une seule installation sert plusieurs pharmacies. Chaque pharmacie a ses propres utilisateurs, médicaments, stock, ventes et caisse ; les données d'une pharmacie ne sont jamais visibles par une autre.

- **Administration de la plateforme** (`/admin`) : le super-administrateur crée les pharmacies et leur gérant, suit les abonnements, suspend ou supprime un compte et réinitialise le mot de passe d'un gérant.
- **Abonnement** : chaque pharmacie a une date de fin d'abonnement. Après paiement, le super-administrateur la prolonge (+1, +3, +6 mois ou +1 an). Le lendemain de l'échéance, l'accès de la pharmacie est bloqué ; le gérant est prévenu 7 jours avant.
- **Connexion du personnel** (`index.php`) : code pharmacie + identifiant + mot de passe. Le même identifiant peut exister dans deux pharmacies.

## Fonctions

- **Comptoir** : le vendeur recherche ou scanne les médicaments, saisit l'ordonnance et l'assurance, et crée un **bon numéroté** (ex. `B261115-004`).
- **Caisse** : les bons en attente s'affichent en temps réel. Le caissier encaisse en espèces (avec calcul de la monnaie), par mobile money ou par carte, puis imprime le ticket 80 mm. Il gère aussi l'ouverture et la clôture de caisse, avec le calcul de l'écart.
- **Stock par lots** : chaque lot a un numéro et une date de péremption. Les ventes puisent d'abord dans les lots qui périment le plus tôt (FEFO). L'appli signale les stocks bas, les lots qui périment bientôt et les lots périmés à retirer, et permet les ajustements d'inventaire.
- **Ordonnances** : elles sont obligatoires pour les produits marqués « sur ordonnance ». Les stupéfiants ne peuvent être délivrés que par un pharmacien ou un gérant, et sont inscrits dans un **registre** imprimable.
- **Tiers payant** : pour chaque assurance (taux de prise en charge), l'appli calcule la part assurance et la part patient, et suit les créances par assurance.
- **Rôles** :
  - Gérant : tout ;
  - Pharmacien : comptoir, stupéfiants, stock et réceptions ;
  - Vendeur : comptoir ;
  - Caissier : caisse.
  
  L'option « Peut aussi encaisser » sert aux petites pharmacies où une seule personne tient le comptoir et la caisse.
- **Rapports** : chiffre d'affaires, marge estimée, encaissements par mode de paiement, résultats par caissier et par vendeur, top des médicaments, valeur du stock.

## Installation sur Hostinger

1. **hPanel › Bases de données › Gestion MySQL** : créez une base, un utilisateur et un mot de passe. Notez les trois.
2. **hPanel › Gestionnaire de fichiers** : ouvrez `public_html` (ou le dossier d'un sous-domaine, par exemple `pharma.mondomaine.com`), envoyez `pharmaflow.zip`, puis faites un clic droit › **Extraire**. Les fichiers `index.php`, `install.php`, `app/`… doivent se retrouver directement dans le dossier.
3. **hPanel › Avancé › Configuration PHP** : choisissez **PHP 8.1 ou plus récent**.
4. **SSL** : activez le certificat gratuit pour le domaine. L'application force le HTTPS.
5. Ouvrez `https://votre-domaine/install.php`. Le serveur MySQL est `localhost`. Saisissez la base, l'utilisateur et le mot de passe, puis le compte super-administrateur.
6. **Supprimez `install.php`** du serveur une fois l'installation terminée.
7. Ouvrez `https://votre-domaine/admin`, connectez-vous et créez la première pharmacie avec son gérant.
8. Le gérant se connecte avec le code pharmacie et suit cet ordre :
   1. Paramètres : assurances et fournisseurs ;
   2. Utilisateurs ;
   3. Médicaments ;
   4. Réceptions, pour créer les lots et donc le stock.

## Mise à jour du site

`config.php` et `install.lock` n'existent que sur le serveur. Un redéploiement complet de l'archive efface le contenu du dossier : il faut alors les renvoyer, sinon le site redirige vers l'installation.

## Matériel conseillé

- **Lecteur code-barres USB** : il fonctionne comme un clavier dans le champ de recherche du comptoir. Si le code scanné correspond exactement à un produit, celui-ci est ajouté directement au bon.
- **Imprimante ticket 80 mm** : impression depuis le navigateur. Dans les réglages d'impression, choisissez le format de papier 80 mm et des marges minimales.
- **Postes** : n'importe quel PC, tablette ou téléphone avec Chrome. Dans Chrome, le menu « Installer l'application » ajoute PharmaFlow sur le bureau comme une appli.

## Sauvegardes

Dans hPanel › Fichiers › Sauvegardes, téléchargez régulièrement la base de données. Vous pouvez aussi exporter la base avec phpMyAdmin.
