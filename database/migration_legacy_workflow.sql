USE komnas_ham;

-- ===============================================================
-- Migrasi database lama ke workflow usulan rancangan peraturan
-- Tujuan: menjaga data lama tetap ada, lalu menambahkan struktur baru
-- untuk role, unit, proposal, review, dan history status.
-- ===============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Buat tabel baru jika belum ada
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

-- 2. Tambahkan kolom baru untuk users jika belum ada
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

-- 3. Tambahkan foreign key untuk users.role_id dan users.unit_id bila belum ada
-- Catatan: MySQL akan gagal jika constraint sudah ada. Jalankan sekali saja di DB yang belum dimigrasi.
ALTER TABLE users
    ADD CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL;

ALTER TABLE users
    ADD CONSTRAINT fk_users_unit
        FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL;

-- 4. Seed role baru
INSERT INTO roles (name, description)
VALUES
    ('admin', 'Administrator sistem'),
    ('pengusul', 'Pengusul rancangan peraturan'),
    ('biro_hukum', 'Reviewer untuk usulan rancangan peraturan')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 5. Isi unit baru dari legacy `tb_biro`
INSERT INTO units (name, code, description)
SELECT
    b.nmaBiro,
    LOWER(REPLACE(REPLACE(b.nmaBiro, ' ', '_'), '/', '_')),
    'Migrated from tb_biro'
FROM tb_biro b
LEFT JOIN units u ON u.name = b.nmaBiro
WHERE u.id IS NULL
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 6. Isi jenis peraturan dasar
INSERT INTO regulation_types (name, description)
VALUES
    ('Peraturan Pemerintah', 'Jenis peraturan tingkat pemerintah'),
    ('Peraturan Presiden', 'Peraturan presiden'),
    ('Peraturan Daerah', 'Peraturan daerah'),
    ('Keputusan', 'Keputusan internal'),
    ('Pedoman', 'Pedoman pelaksanaan')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 7. Mapping role lama ke role baru
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

-- 8. Migrasi data legacy `tb_biro` ke unit_id di users (jika ada relasi manual)
-- Karena tabel users lama tidak punya relasi unit, nilai ini diisi null sampai admin menetapkan unit user.
UPDATE users SET unit_id = NULL WHERE unit_id IS NULL;

-- 9. Migrasi data legacy `tb_rancangan` ke `proposals`
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
    CASE
        WHEN r.idBiro IS NOT NULL THEN (SELECT u.id FROM units u WHERE u.name = (SELECT b.nmaBiro FROM tb_biro b WHERE b.idBiro = r.idBiro) LIMIT 1)
        ELSE NULL
    END AS unit_id,
    COALESCE((SELECT u.id FROM users u WHERE u.role_id = (SELECT r2.id FROM roles r2 WHERE r2.name = 'pengusul') ORDER BY u.id LIMIT 1), NULL) AS proposer_id,
    (
        SELECT rt.id
        FROM regulation_types rt
        WHERE rt.name = COALESCE(
            CASE
                WHEN LOWER(TRIM(r.jnsPeraturan)) LIKE '%peraturan pemerintah%' THEN 'Peraturan Pemerintah'
                WHEN LOWER(TRIM(r.jnsPeraturan)) LIKE '%peraturan presiden%' THEN 'Peraturan Presiden'
                WHEN LOWER(TRIM(r.jnsPeraturan)) LIKE '%peraturan daerah%' THEN 'Peraturan Daerah'
                ELSE 'Pedoman'
            END,
            'Pedoman'
        )
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
LEFT JOIN proposals p ON p.proposal_number = CONCAT('USL-', YEAR(CURRENT_DATE), '-', LPAD(r.idRanc, 3, '0'))
WHERE p.id IS NULL;

-- 10. Migrasi catatan review lama ke tabel `reviews`
INSERT INTO reviews (proposal_id, reviewer_id, decision, notes, created_at)
SELECT
    p.id,
    COALESCE((SELECT u.id FROM users u WHERE u.role_id = (SELECT r.id FROM roles r WHERE r.name = 'biro_hukum') ORDER BY u.id LIMIT 1), NULL),
    'Perlu Revisi',
    rev.catatan,
    CURRENT_TIMESTAMP
FROM tb_reviu rev
JOIN proposals p ON p.proposal_number = CONCAT('USL-', YEAR(CURRENT_DATE), '-', LPAD(rev.idRanc, 3, '0'))
LEFT JOIN reviews rv ON rv.proposal_id = p.id
WHERE rv.id IS NULL;

-- 11. Buat history status awal untuk proposal yang dipindahkan
INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes, created_at)
SELECT
    p.id,
    COALESCE((SELECT u.id FROM users u WHERE u.role_id = (SELECT r.id FROM roles r WHERE r.name = 'pengusul') ORDER BY u.id LIMIT 1), NULL),
    NULL,
    'Draft',
    'Migrasi data dari sistem lama',
    CURRENT_TIMESTAMP
FROM proposals p
LEFT JOIN proposal_status_histories h ON h.proposal_id = p.id
WHERE h.id IS NULL;

-- 12. Jika tabel lama tidak dipakai lagi, jangan dihapus secara otomatis.
-- Biarkan tetap untuk audit / backup sampai proses migrasi selesai.

SET FOREIGN_KEY_CHECKS = 1;

-- ===============================================================
-- Catatan penggunaan:
-- 1. Buat backup database terlebih dahulu.
-- 2. Jalankan file ini pada salinan database staging dulu.
-- 3. Setelah cek data masuk benar, baru jalankan di database produksi.
-- 4. Jika role user di aplikasi didasarkan pada `role` string, sesuaikan
--    dengan nilai baru `admin`, `pengusul`, `biro_hukum`.
-- ===============================================================
