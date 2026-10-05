USE komnas_ham;

-- Target-workflow profile migration. Legacy tables (tb_*) are not modified.
-- Run only after verifying proposals and reviews are empty; their ownership
-- foreign keys are deliberately changing from users to profile records.
CREATE TABLE biro_hukum (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_biro VARCHAR(255) NOT NULL,
    kode VARCHAR(40) NOT NULL UNIQUE,
    status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pengusul (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(255) NOT NULL,
    unit_id INT NOT NULL,
    jabatan VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    no_hp VARCHAR(40) NULL,
    status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pengusul_email (email),
    KEY idx_pengusul_unit (unit_id),
    CONSTRAINT fk_pengusul_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reviewers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(255) NOT NULL,
    biro_hukum_id INT NOT NULL,
    jabatan VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    no_hp VARCHAR(40) NULL,
    status ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reviewers_email (email),
    KEY idx_reviewers_biro_hukum (biro_hukum_id),
    CONSTRAINT fk_reviewers_biro_hukum FOREIGN KEY (biro_hukum_id) REFERENCES biro_hukum(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (name, description)
VALUES ('reviewer', 'Reviewer Biro Hukum')
ON DUPLICATE KEY UPDATE description = VALUES(description);

ALTER TABLE users
    MODIFY role ENUM('admin', 'pengusul', 'reviewer', 'biro_hukum', 'user') NOT NULL DEFAULT 'pengusul',
    ADD COLUMN pengusul_id INT NULL AFTER unit_id,
    ADD COLUMN reviewer_id INT NULL AFTER pengusul_id,
    ADD UNIQUE KEY uq_users_pengusul (pengusul_id),
    ADD UNIQUE KEY uq_users_reviewer (reviewer_id),
    ADD CONSTRAINT fk_users_pengusul FOREIGN KEY (pengusul_id) REFERENCES pengusul(id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_users_reviewer FOREIGN KEY (reviewer_id) REFERENCES reviewers(id) ON DELETE RESTRICT;

ALTER TABLE proposals
    DROP FOREIGN KEY fk_proposals_proposer,
    MODIFY proposer_id INT NOT NULL,
    ADD CONSTRAINT fk_proposals_pengusul FOREIGN KEY (proposer_id) REFERENCES pengusul(id) ON DELETE RESTRICT;

ALTER TABLE reviews
    DROP FOREIGN KEY fk_reviews_reviewer,
    MODIFY reviewer_id INT NOT NULL,
    ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD CONSTRAINT fk_reviews_reviewer_profile FOREIGN KEY (reviewer_id) REFERENCES reviewers(id) ON DELETE RESTRICT;
