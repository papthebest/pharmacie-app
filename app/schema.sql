-- PharmaFlow — schéma MySQL / MariaDB (utf8mb4, InnoDB)
-- SaaS multi-pharmacies : chaque table métier porte un pharmacy_id.
SET NAMES utf8mb4;

-- Pharmacies clientes (une ligne par pharmacie abonnée)
CREATE TABLE IF NOT EXISTS pharmacies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,       -- code saisi à la connexion
  nom VARCHAR(160) NOT NULL,
  adresse VARCHAR(255) NULL,
  telephone VARCHAR(40) NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,    -- 0 = suspendue
  abonnement_fin DATE NULL,               -- NULL = sans échéance
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Super-administrateurs de la plateforme (hors pharmacies)
CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(120) NOT NULL,
  login VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  pharmacy_id INT NOT NULL,
  k VARCHAR(64) NOT NULL,
  v TEXT,
  PRIMARY KEY (pharmacy_id, k),
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  nom VARCHAR(120) NOT NULL,
  login VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('gerant','pharmacien','vendeur','caissier') NOT NULL,
  peut_encaisser TINYINT(1) NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  UNIQUE KEY uq_login (pharmacy_id, login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  nom VARCHAR(160) NOT NULL,
  dci VARCHAR(160) NULL,
  dosage VARCHAR(60) NULL,
  forme VARCHAR(60) NULL,
  laboratoire VARCHAR(120) NULL,
  code_barre VARCHAR(64) NULL,
  prix_achat INT NOT NULL DEFAULT 0,
  prix_vente INT NOT NULL DEFAULT 0,
  seuil_alerte INT NOT NULL DEFAULT 10,
  ordonnance TINYINT(1) NOT NULL DEFAULT 0,
  stupefiant TINYINT(1) NOT NULL DEFAULT 0,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  UNIQUE KEY uq_code_barre (pharmacy_id, code_barre),
  INDEX idx_nom (pharmacy_id, nom), INDEX idx_dci (pharmacy_id, dci)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  nom VARCHAR(160) NOT NULL,
  telephone VARCHAR(40) NULL,
  adresse VARCHAR(255) NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS insurers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  nom VARCHAR(160) NOT NULL,
  taux INT NOT NULL DEFAULT 80,          -- % pris en charge
  telephone VARCHAR(40) NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  supplier_id INT NULL,
  reference VARCHAR(80) NULL,
  date_reception DATE NOT NULL,
  total INT NOT NULL DEFAULT 0,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  product_id INT NOT NULL,
  numero_lot VARCHAR(60) NOT NULL,
  date_peremption DATE NOT NULL,
  quantite INT NOT NULL DEFAULT 0,
  quantite_initiale INT NOT NULL DEFAULT 0,
  prix_achat INT NOT NULL DEFAULT 0,
  reception_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (reception_id) REFERENCES receptions(id),
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  INDEX idx_fefo (product_id, date_peremption),
  INDEX idx_ph_per (pharmacy_id, date_peremption)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  user_id INT NOT NULL,
  fond_initial INT NOT NULL DEFAULT 0,
  opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  total_especes INT NOT NULL DEFAULT 0,
  total_mobile INT NOT NULL DEFAULT 0,
  total_carte INT NOT NULL DEFAULT 0,
  total_assurance INT NOT NULL DEFAULT 0,
  montant_compte INT NULL,
  ecart INT NULL,
  note VARCHAR(255) NULL,
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  numero VARCHAR(20) NOT NULL,
  statut ENUM('en_attente','payee','annulee') NOT NULL DEFAULT 'en_attente',
  vendeur_id INT NOT NULL,
  caissier_id INT NULL,
  cash_session_id INT NULL,
  client_nom VARCHAR(160) NULL,
  insurer_id INT NULL,
  taux_assurance INT NOT NULL DEFAULT 0,
  num_assure VARCHAR(80) NULL,
  total INT NOT NULL DEFAULT 0,
  part_assurance INT NOT NULL DEFAULT 0,
  part_patient INT NOT NULL DEFAULT 0,
  mode_paiement ENUM('especes','mobile','carte') NULL,
  montant_recu INT NULL,
  monnaie INT NULL,
  ordonnance_num VARCHAR(80) NULL,
  ordonnance_date DATE NULL,
  medecin VARCHAR(160) NULL,
  patient_nom VARCHAR(160) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancelled_by INT NULL,
  cancel_motif VARCHAR(255) NULL,
  FOREIGN KEY (vendeur_id) REFERENCES users(id),
  FOREIGN KEY (caissier_id) REFERENCES users(id),
  FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
  FOREIGN KEY (insurer_id) REFERENCES insurers(id),
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  UNIQUE KEY uq_numero (pharmacy_id, numero),
  INDEX idx_statut (pharmacy_id, statut, created_at),
  INDEX idx_paid (pharmacy_id, paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NOT NULL,
  quantite INT NOT NULL,
  prix_unit INT NOT NULL,
  total INT NOT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_line_lots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_line_id INT NOT NULL,
  lot_id INT NOT NULL,
  quantite INT NOT NULL,
  FOREIGN KEY (sale_line_id) REFERENCES sale_lines(id) ON DELETE CASCADE,
  FOREIGN KEY (lot_id) REFERENCES lots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id INT NOT NULL,
  product_id INT NOT NULL,
  lot_id INT NULL,
  type ENUM('entree','vente','ajustement','peremption') NOT NULL,
  quantite INT NOT NULL,                  -- positif = entrée, négatif = sortie
  reference VARCHAR(120) NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (lot_id) REFERENCES lots(id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id),
  INDEX idx_prod (product_id, created_at),
  INDEX idx_ph (pharmacy_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
