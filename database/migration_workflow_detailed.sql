
USE komnas_ham;

-- ===============================================================
-- Migration detail: legacy system -> workflow proposal management
-- Deskripsi:
-- 1. Menjaga tabel lama tetap aman
-- 2. Menambah struktur baru untuk role, unit, proposal, review, document
-- 3. Mengisi data awal untuk demo
-- 4. Memindahkan data lama ke model baru tanpa menghapus sumber lama
-- ===============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------
-- A. TABEL BARU UNTUK ROLE DAN MASTER DATA
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    code VARCHAR(80) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS regulation_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- B. TABEL BARU UNTUK PROPOSAL, REVIEW, DAN DOKUMEN
-- ---------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_number VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    unit_id INT NULL,
    proposer_id INT NULL,
    regulation_type_id INT NULL,
    background TEXT NOT NULL,
    legal_basis TEXT NULL,
    objective TEXT NULL,
    description TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Draft',
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_proposals_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL,
    CONSTRAINT fk_proposals_proposer FOREIGN KEY (proposer_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_proposals_regulation_type FOREIGN KEY (regulation_type_id) REFERENCES regulation_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    uploaded_by INT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(120) NULL,
    file_size INT NULL,
    version VARCHAR(30) NOT NULL DEFAULT '1',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_documents_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    CONSTRAINT fk_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    reviewer_id INT NULL,
    decision VARCHAR(40) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviews_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_status_histories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    changed_by INT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_history_proposal FOREIGN KEY (proposal_id) REFERENCES proposals(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------
-- C. TAMBAH KOLOM BARU KE USERS
-- Catatan:
-- 1. Jika database lama sudah punya kolom ini, statement ini tidak akan error karena IF NOT EXISTS
-- 2. Untuk database MySQL < 8.0, gunakan langkah manual jika IF NOT EXISTS tidak didukung.
-- ---------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role_id INT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS unit_id INT NULL AFTER role_id,
    ADD COLUMN IF NOT EXISTS name VARCHAR(255) NOT NULL DEFAULT '' AFTER username,
    ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS position VARCHAR(255) NULL AFTER email,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE tb_reviu
    ADD COLUMN IF NOT EXISTS reviewer_id INT NULL;

-- ---------------------------------------------------------------
-- D. SEED ROLE BARU
-- ---------------------------------------------------------------
INSERT INTO roles (name, description)
VALUES
    ('admin', 'Administrator sistem'),
    ('pengusul', 'Pengusul rancangan peraturan'),
    ('biro_hukum', 'Reviewer untuk usulan rancangan peraturan')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------
-- E. SEED UNIT BARU BERDASARKAN DATA tb_biro
-- ---------------------------------------------------------------
INSERT INTO units (name, code, description, is_active)
SELECT
    b.nmaBiro,
    LOWER(REPLACE(REPLACE(b.nmaBiro, ' ', '_'), '/', '_')),
    'Migrated from tb_biro',
    1
FROM tb_biro b
LEFT JOIN units u ON u.name = b.nmaBiro
WHERE u.id IS NULL
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------
-- F. SEED JENIS PERATURAN
-- ---------------------------------------------------------------
INSERT INTO regulation_types (name, description, is_active)
VALUES
    ('Peraturan Pemerintah', 'Jenis peraturan tingkat pemerintah', 1),
    ('Peraturan Presiden', 'Peraturan presiden', 1),
    ('Peraturan Daerah', 'Peraturan daerah', 1),
    ('Keputusan', 'Keputusan internal', 1),
    ('Pedoman', 'Pedoman pelaksanaan', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------
-- G. MAPPING ROLE LAMA KE ROLE BARU
-- ---------------------------------------------------------------
UPDATE users u
SET u.role_id = (
    SELECT r.id FROM roles r WHERE r.name = 'admin'
)
WHERE u.role = 'admin';

UPDATE users u
SET u.role_id = (
    SELECT r.id FROM roles r WHERE r.name = 'pengusul'
)
WHERE u.role IN ('user', 'pengusul');

UPDATE users u
SET u.role_id = (
    SELECT r.id FROM roles r WHERE r.name = 'biro_hukum'
)
WHERE u.role = 'biro_hukum';

UPDATE users u
SET u.name = COALESCE(NULLIF(TRIM(u.name), ''), u.username),
    u.is_active = 1
WHERE u.name IS NULL OR TRIM(u.name) = '';

-- ---------------------------------------------------------------
-- H. MENGISI UNIT_ID PADA USERS (jika data unit tersedia)
-- ---------------------------------------------------------------
UPDATE users u
SET u.unit_id = (
    SELECT unit.id
    FROM units unit
    WHERE unit.name = 'Sekretariat'
    LIMIT 1
)
WHERE u.role = 'admin' AND u.unit_id IS NULL;

UPDATE users u
SET u.unit_id = (
    SELECT unit.id
    FROM units unit
    WHERE unit.name = 'Biro Hukum'
    LIMIT 1
)
WHERE u.role = 'biro_hukum' AND u.unit_id IS NULL;

UPDATE users u
SET u.unit_id = (
    SELECT unit.id
    FROM units unit
    WHERE unit.name = 'Pusat Data dan Informasi'
    LIMIT 1
)
WHERE u.role IN ('user', 'pengusul') AND u.unit_id IS NULL;

-- ---------------------------------------------------------------
-- I. MEMBUAT DATA DEMO AKUN SEBELUM MENGIMPORT PROPOSAL
-- Jika akun demo belum ada, insert dengan password hash aman.
-- ---------------------------------------------------------------
INSERT INTO users (name, username, password, role, role_id, unit_id, position, is_active)
VALUES
    ('Administrator Sistem', 'admin', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'admin', (SELECT id FROM roles WHERE name = 'admin'), (SELECT id FROM units WHERE name = 'Sekretariat'), 'Administrator', 1),
    ('Maya Puspita', 'pengusul', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'pengusul', (SELECT id FROM roles WHERE name = 'pengusul'), (SELECT id FROM units WHERE name = 'Pusat Data dan Informasi'), 'Staf Peraturan', 1),
    ('Rizky Hidayat', 'biro_hukum', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'biro_hukum', (SELECT id FROM roles WHERE name = 'biro_hukum'), (SELECT id FROM units WHERE name = 'Biro Hukum'), 'Analis Hukum', 1)
ON DUPLICATE KEY UPDATE username = VALUES(username);

-- ---------------------------------------------------------------
-- J. MIGRASI DATA LEGACY tb_rancangan KE proposals
-- ---------------------------------------------------------------
INSERT INTO proposals (
    proposal_number,
    title,
    unit_id,
    proposer_id,
    regulation_type_id,
    background,
    legal_basis,
    objective,
    description,
    status,
    submitted_at,
    created_at,
    updated_at
)
SELECT
    CONCAT('USL-', YEAR(CURRENT_DATE), '-', LPAD(r.idRanc, 3, '0')) AS proposal_number,
    r.jdlRanc AS title,
    (
        SELECT u.id
        FROM units u
        WHERE u.name = (
            SELECT b.nmaBiro
            FROM tb_biro b
            WHERE b.idBiro = r.idBiro
            LIMIT 1
        )
        LIMIT 1
    ) AS unit_id,
    (
        SELECT u.id
        FROM users u
        WHERE u.role_id = (SELECT id FROM roles WHERE name = 'pengusul')
        ORDER BY u.id
        LIMIT 1
    ) AS proposer_id,
    (
        SELECT rt.id
        FROM regulation_types rt
        WHERE rt.name = CASE
            WHEN LOWER(TRIM(r.jnsPeraturan)) LIKE '%peraturan pemerintah%' THEN 'Peraturan Pemerintah'
            WHEN LOWER(TRIM(r.jnsPeraturan)) LIKE '%peraturan presiden%' THEN 'Peraturan Presiden'
            WHEN LOWER(TRIM(r.jnsPeraturan)) LIKE '%peraturan daerah%' THEN 'Peraturan Daerah'
            ELSE 'Pedoman'
        END
        LIMIT 1
    ) AS regulation_type_id,
    COALESCE(r.dsrAlsn, '') AS background,
    NULL AS legal_basis,
    NULL AS objective,
    COALESCE(r.ket, '') AS description,
    'Draft' AS status,
    NULL AS submitted_at,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM tb_rancangan r
LEFT JOIN proposals p
    ON p.proposal_number = CONCAT('USL-', YEAR(CURRENT_DATE), '-', LPAD(r.idRanc, 3, '0'))
WHERE p.id IS NULL;

-- ---------------------------------------------------------------
-- K. MIGRASI REVIEW LAMA KE TABEL reviews
-- ---------------------------------------------------------------
INSERT INTO reviews (proposal_id, reviewer_id, decision, notes, created_at)
SELECT
    p.id,
    (
        SELECT u.id
        FROM users u
        WHERE u.role_id = (SELECT id FROM roles WHERE name = 'biro_hukum')
        ORDER BY u.id
        LIMIT 1
    ) AS reviewer_id,
    'Perlu Revisi' AS decision,
    rev.catatan,
    CURRENT_TIMESTAMP
FROM tb_reviu rev
JOIN proposals p
    ON p.proposal_number = CONCAT('USL-', YEAR(CURRENT_DATE), '-', LPAD(rev.idRanc, 3, '0'))
LEFT JOIN reviews rv ON rv.proposal_id = p.id
WHERE rv.id IS NULL;

-- ---------------------------------------------------------------
-- L. BUAT HISTORY STATUS AWAL UNTUK EVERY PROPOSAL
-- ---------------------------------------------------------------
INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes, created_at)
SELECT
    p.id,
    (
        SELECT u.id
        FROM users u
        WHERE u.role_id = (SELECT id FROM roles WHERE name = 'pengusul')
        ORDER BY u.id
        LIMIT 1
    ) AS changed_by,
    NULL AS old_status,
    'Draft' AS new_status,
    'Migrasi data dari sistem lama' AS notes,
    CURRENT_TIMESTAMP
FROM proposals p
LEFT JOIN proposal_status_histories sh ON sh.proposal_id = p.id
WHERE sh.id IS NULL;

-- ---------------------------------------------------------------
-- M. VERIFIKASI AWAL
-- ---------------------------------------------------------------
SELECT 'roles' AS table_name, COUNT(*) AS total_rows FROM roles
UNION ALL
SELECT 'units', COUNT(*) FROM units
UNION ALL
SELECT 'regulation_types', COUNT(*) FROM regulation_types
UNION ALL
SELECT 'users', COUNT(*) FROM users
UNION ALL
SELECT 'proposals', COUNT(*) FROM proposals;

SET FOREIGN_KEY_CHECKS = 1;

-- ===============================================================
-- Catatan penting:
-- - Jangan langsung drop tabel lama sebelum yakin semua data sudah masuk
-- - Jika database sudah punya constraint dengan nama yang sama,
--   hapus constraint manual dulu atau jalankan di DB staging terlebih dahulu
-- - Script ini dibuat agar lebih aman untuk migrasi database yang telah
--   berjalan dan masih mengandung data lama.
-- ===============================================================
