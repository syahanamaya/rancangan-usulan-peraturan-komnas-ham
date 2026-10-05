CREATE DATABASE IF NOT EXISTS komnas_ham
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE komnas_ham;

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

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NULL,
    unit_id INT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NOT NULL,
    position VARCHAR(255) NULL,
    role ENUM('admin', 'pengusul', 'biro_hukum', 'user') NOT NULL DEFAULT 'pengusul',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_biro (
    idBiro INT AUTO_INCREMENT PRIMARY KEY,
    nmaBiro VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_tim (
    idTim INT AUTO_INCREMENT PRIMARY KEY,
    nmaTim VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_staf (
    idStaf INT AUTO_INCREMENT PRIMARY KEY,
    nmaStaf VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_rancangan (
    idRanc INT AUTO_INCREMENT PRIMARY KEY,
    proposal_number VARCHAR(80) NULL,
    jdlRanc VARCHAR(255) NOT NULL,
    idBiro INT NULL,
    idTim INT NULL,
    idStaf INT NULL,
    jnsPeraturan VARCHAR(255) NOT NULL,
    dsrAlsn TEXT NOT NULL,
    ket TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Draft',
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rancangan_biro FOREIGN KEY (idBiro) REFERENCES tb_biro(idBiro),
    CONSTRAINT fk_rancangan_tim FOREIGN KEY (idTim) REFERENCES tb_tim(idTim),
    CONSTRAINT fk_rancangan_staf FOREIGN KEY (idStaf) REFERENCES tb_staf(idStaf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tb_reviu (
    idReviu INT AUTO_INCREMENT PRIMARY KEY,
    idRanc INT NULL,
    catatan TEXT NOT NULL,
    reviewer_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reviu_rancangan FOREIGN KEY (idRanc) REFERENCES tb_rancangan(idRanc) ON DELETE CASCADE,
    CONSTRAINT fk_reviu_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL
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

INSERT INTO roles (name, description) VALUES
    ('admin', 'Administrator sistem'),
    ('pengusul', 'Pengusul rancangan peraturan'),
    ('biro_hukum', 'Reviewer untuk usulan rancangan peraturan')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO units (name, code, description) VALUES
    ('Sekretariat', 'SEK', 'Sekretariat internal'),
    ('Biro Hukum', 'BH', 'Unit pemeriksa usulan'),
    ('Pusat Data dan Informasi', 'PUSDA', 'Unit pengelola data dan informasi')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO regulation_types (name, description) VALUES
    ('Peraturan Pemerintah', 'Jenis peraturan tingkat pemerintah'),
    ('Peraturan Presiden', 'Peraturan presiden'),
    ('Peraturan Daerah', 'Peraturan daerah')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (name, username, password, role, role_id, unit_id, position, is_active)
VALUES
    ('Admin Sistem', 'admin', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'admin', 1, 1, 'Administrator', 1),
    ('Pengusul Utama', 'pengusul', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'pengusul', 2, 1, 'Staf', 1),
    ('Reviewer Hukum', 'biro_hukum', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'biro_hukum', 3, 2, 'Analis Hukum', 1)
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO tb_biro (nmaBiro) VALUES
    ('Sekretariat'),
    ('Biro Hukum'),
    ('Pusat Data dan Informasi')
ON DUPLICATE KEY UPDATE nmaBiro = VALUES(nmaBiro);

INSERT INTO tb_tim (nmaTim) VALUES
    ('Tim Perumus'),
    ('Tim Evaluasi'),
    ('Tim Dokumentasi')
ON DUPLICATE KEY UPDATE nmaTim = VALUES(nmaTim);

INSERT INTO tb_staf (nmaStaf) VALUES
    ('Maya'),
    ('Andi'),
    ('Rina')
ON DUPLICATE KEY UPDATE nmaStaf = VALUES(nmaStaf);

INSERT INTO tb_rancangan (proposal_number, jdlRanc, idBiro, idTim, idStaf, jnsPeraturan, dsrAlsn, ket, status)
VALUES
    ('USL-2026-001', 'Rancangan Pedoman Pengelolaan Usulan', 1, 1, 1, 'Peraturan Pemerintah', 'Mendorong pengelolaan usulan yang lebih tertib dan terdokumentasi.', 'Draft awal untuk kebutuhan pengelolaan internal.', 'Draft'),
    ('USL-2026-002', 'Rancangan Tata Cara Review Dokumen', 2, 2, 2, 'Peraturan Presiden', 'Memastikan proses review berjalan konsisten.', 'Usulan menunggu review Biro Hukum.', 'Menunggu Review'),
    ('USL-2026-003', 'Rancangan Standarisasi Arsip Usulan', 3, 3, 3, 'Peraturan Daerah', 'Mengurangi risiko kehilangan dokumentasi.', 'Usulan sedang dalam proses review lanjutan.', 'Sedang Direview')
ON DUPLICATE KEY UPDATE jdlRanc = VALUES(jdlRanc);

INSERT INTO tb_reviu (idRanc, catatan, reviewer_id) VALUES
    (2, 'Perlu ditambahkan aspek riwayat review.', 3),
    (3, 'Dokumen pendukung perlu dilengkapi.', 3)
ON DUPLICATE KEY UPDATE catatan = VALUES(catatan);
