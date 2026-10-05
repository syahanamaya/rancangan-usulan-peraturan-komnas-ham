USE komnas_ham;

-- Additive target-schema migration. Legacy tables are intentionally untouched.
-- The migration stops before tightening constraints if existing target data
-- cannot satisfy the target model without inventing ownership or history.

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_units_code (code),
    KEY idx_units_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS regulation_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_regulation_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    unit_id INT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    username VARCHAR(100) NOT NULL,
    email VARCHAR(255) NULL,
    password VARCHAR(255) NOT NULL,
    position VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role_id (role_id),
    KEY idx_users_unit_id (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_number VARCHAR(80) NOT NULL,
    proposer_id INT NOT NULL,
    unit_id INT NULL,
    regulation_type_id INT NULL,
    title VARCHAR(255) NOT NULL,
    background TEXT NOT NULL,
    legal_basis TEXT NULL,
    objective TEXT NULL,
    description TEXT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Draft',
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proposals_proposal_number (proposal_number),
    KEY idx_proposals_proposer_id (proposer_id),
    KEY idx_proposals_unit_id (unit_id),
    KEY idx_proposals_regulation_type_id (regulation_type_id),
    KEY idx_proposals_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    uploaded_by INT NULL,
    document_key VARCHAR(100) NOT NULL,
    version VARCHAR(30) NOT NULL DEFAULT '1',
    original_filename VARCHAR(255) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT NULL,
    checksum CHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_documents_proposal_key_version (proposal_id, document_key, version),
    KEY idx_documents_proposal_id (proposal_id),
    KEY idx_documents_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    review_round INT UNSIGNED NOT NULL,
    decision VARCHAR(40) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reviews_proposal_round (proposal_id, review_round),
    KEY idx_reviews_reviewer_id (reviewer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_status_histories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proposal_id INT NOT NULL,
    changed_by INT NOT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_status_history_proposal_created (proposal_id, created_at),
    KEY idx_status_history_changed_by (changed_by),
    KEY idx_status_history_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proposal_regulation_types (
    proposal_id INT NOT NULL,
    regulation_type_id INT NOT NULL,
    PRIMARY KEY (proposal_id, regulation_type_id),
    KEY idx_proposal_regulation_types_type (regulation_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

DROP PROCEDURE IF EXISTS _target_schema_assert_empty_or_mapped$$
CREATE PROCEDURE _target_schema_assert_empty_or_mapped()
BEGIN
    DECLARE row_count BIGINT DEFAULT 0;

    SELECT COUNT(*) INTO row_count FROM users WHERE role_id IS NULL;
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: users.role_id contains NULL values; map roles before retrying.';
    END IF;

    SELECT COUNT(*) INTO row_count FROM units WHERE code IS NULL OR TRIM(code) = '';
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: units.code contains empty values; resolve them before retrying.';
    END IF;

    SELECT COUNT(*) INTO row_count
    FROM (
        SELECT code
        FROM units
        GROUP BY code
        HAVING COUNT(*) > 1
    ) AS duplicate_unit_codes;
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: duplicate units.code values must be resolved first.';
    END IF;

    SELECT COUNT(*) INTO row_count
    FROM (
        SELECT name
        FROM regulation_types
        GROUP BY name
        HAVING COUNT(*) > 1
    ) AS duplicate_regulation_type_names;
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: duplicate regulation_types.name values must be resolved first.';
    END IF;

    SELECT COUNT(*) INTO row_count FROM proposals WHERE proposer_id IS NULL;
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: proposals without a verified owner cannot be migrated automatically.';
    END IF;

    SELECT COUNT(*) INTO row_count
    FROM proposals
    WHERE status NOT IN (
        'Draft',
        'Menunggu Review',
        'Sedang Direview',
        'Perlu Revisi',
        'Diajukan Kembali',
        'Disetujui',
        'Ditolak'
    );
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: proposal statuses outside the approved target set need an explicit mapping.';
    END IF;

    SELECT COUNT(*) INTO row_count FROM reviews WHERE reviewer_id IS NULL;
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: reviews without a verified reviewer need manual resolution.';
    END IF;

    SELECT COUNT(*) INTO row_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'reviews'
      AND COLUMN_NAME = 'review_round';
    IF row_count = 0 THEN
        SELECT COUNT(*) INTO row_count FROM reviews;
        IF row_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Target migration stopped: existing reviews have no review_round; do not guess historical rounds.';
        END IF;
    END IF;

    SELECT COUNT(*) INTO row_count FROM proposal_status_histories WHERE changed_by IS NULL;
    IF row_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Target migration stopped: existing status history has no verified actor.';
    END IF;

    SELECT COUNT(*) INTO row_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'document_key';
    IF row_count = 0 THEN
        SELECT COUNT(*) INTO row_count FROM proposal_documents;
        IF row_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Target migration stopped: existing documents need an explicit document_key mapping.';
        END IF;
    END IF;

    SELECT COUNT(*) INTO row_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'original_filename';
    IF row_count = 0 THEN
        SELECT COUNT(*) INTO row_count FROM proposal_documents;
        IF row_count > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Target migration stopped: existing document metadata must be reviewed before column conversion.';
        END IF;
    END IF;
END$$

DROP PROCEDURE IF EXISTS _target_schema_ensure_fk$$
CREATE PROCEDURE _target_schema_ensure_fk(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_constraint VARCHAR(64),
    IN p_ref_table VARCHAR(64),
    IN p_ref_column VARCHAR(64)
)
BEGIN
    DECLARE existing_constraint VARCHAR(64) DEFAULT NULL;
    DECLARE existing_ref_table VARCHAR(64) DEFAULT NULL;
    DECLARE existing_ref_column VARCHAR(64) DEFAULT NULL;
    DECLARE existing_delete_rule VARCHAR(20) DEFAULT NULL;
    DECLARE existing_update_rule VARCHAR(20) DEFAULT NULL;

    SET existing_constraint = (
        SELECT k.CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE k
        WHERE k.CONSTRAINT_SCHEMA = DATABASE()
          AND k.TABLE_NAME = p_table
          AND k.COLUMN_NAME = p_column
          AND k.REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1
    );
    SET existing_ref_table = (
        SELECT k.REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE k
        WHERE k.CONSTRAINT_SCHEMA = DATABASE()
          AND k.TABLE_NAME = p_table
          AND k.COLUMN_NAME = p_column
          AND k.REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1
    );
    SET existing_ref_column = (
        SELECT k.REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE k
        WHERE k.CONSTRAINT_SCHEMA = DATABASE()
          AND k.TABLE_NAME = p_table
          AND k.COLUMN_NAME = p_column
          AND k.REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1
    );
    SET existing_delete_rule = (
        SELECT r.DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS r
        WHERE r.CONSTRAINT_SCHEMA = DATABASE()
          AND r.TABLE_NAME = p_table
          AND r.CONSTRAINT_NAME = existing_constraint
        LIMIT 1
    );
    SET existing_update_rule = (
        SELECT r.UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS r
        WHERE r.CONSTRAINT_SCHEMA = DATABASE()
          AND r.TABLE_NAME = p_table
          AND r.CONSTRAINT_NAME = existing_constraint
        LIMIT 1
    );

    IF existing_constraint IS NOT NULL THEN
        IF existing_constraint = p_constraint
           AND existing_ref_table = p_ref_table
           AND existing_ref_column = p_ref_column
           AND existing_delete_rule = 'RESTRICT'
           AND existing_update_rule = 'RESTRICT' THEN
            SET existing_constraint = NULL;
        ELSE
            SET @target_ddl = CONCAT(
                'ALTER TABLE `', REPLACE(p_table, '`', '``'),
                '` DROP FOREIGN KEY `', REPLACE(existing_constraint, '`', '``'), '`'
            );
            PREPARE target_stmt FROM @target_ddl;
            EXECUTE target_stmt;
            DEALLOCATE PREPARE target_stmt;
            SET existing_constraint = NULL;
        END IF;
    END IF;

    IF existing_constraint IS NULL THEN
        SELECT COUNT(*) INTO @target_fk_exists
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND CONSTRAINT_NAME = p_constraint;

        IF @target_fk_exists = 0 THEN
            SET @target_ddl = CONCAT(
                'ALTER TABLE `', REPLACE(p_table, '`', '``'),
                '` ADD CONSTRAINT `', REPLACE(p_constraint, '`', '``'),
                '` FOREIGN KEY (`', REPLACE(p_column, '`', '``'),
                '`) REFERENCES `', REPLACE(p_ref_table, '`', '``'),
                '` (`', REPLACE(p_ref_column, '`', '``'),
                '`) ON DELETE RESTRICT ON UPDATE RESTRICT'
            );
            PREPARE target_stmt FROM @target_ddl;
            EXECUTE target_stmt;
            DEALLOCATE PREPARE target_stmt;
        END IF;
    END IF;
END$$

DROP PROCEDURE IF EXISTS _target_schema_ensure_index$$
CREATE PROCEDURE _target_schema_ensure_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255),
    IN p_unique TINYINT
)
BEGIN
    DECLARE index_exists INT DEFAULT 0;
    DECLARE index_kind VARCHAR(16);

    SELECT COUNT(*) INTO index_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index;

    IF index_exists = 0 THEN
        SET index_kind = IF(p_unique = 1, 'UNIQUE ', '');
        SET @target_ddl = CONCAT(
            'ALTER TABLE `', REPLACE(p_table, '`', '``'),
            '` ADD ', index_kind, 'INDEX `', REPLACE(p_index, '`', '``'),
            '` (', p_columns, ')'
        );
        PREPARE target_stmt FROM @target_ddl;
        EXECUTE target_stmt;
        DEALLOCATE PREPARE target_stmt;
    END IF;
END$$

DROP PROCEDURE IF EXISTS _target_schema_upgrade$$
CREATE PROCEDURE _target_schema_upgrade()
BEGIN
    DECLARE column_exists INT DEFAULT 0;
    DECLARE constraint_exists INT DEFAULT 0;

    CALL _target_schema_assert_empty_or_mapped();

    CALL _target_schema_ensure_fk('users', 'role_id', 'fk_users_role', 'roles', 'id');
    CALL _target_schema_ensure_fk('users', 'unit_id', 'fk_users_unit', 'units', 'id');
    CALL _target_schema_ensure_fk('proposals', 'proposer_id', 'fk_proposals_proposer', 'users', 'id');
    CALL _target_schema_ensure_fk('proposals', 'unit_id', 'fk_proposals_unit', 'units', 'id');
    CALL _target_schema_ensure_fk('proposals', 'regulation_type_id', 'fk_proposals_regulation_type', 'regulation_types', 'id');
    CALL _target_schema_ensure_fk('proposal_documents', 'proposal_id', 'fk_documents_proposal', 'proposals', 'id');
    CALL _target_schema_ensure_fk('proposal_documents', 'uploaded_by', 'fk_documents_uploader', 'users', 'id');
    CALL _target_schema_ensure_fk('reviews', 'proposal_id', 'fk_reviews_proposal', 'proposals', 'id');
    CALL _target_schema_ensure_fk('reviews', 'reviewer_id', 'fk_reviews_reviewer', 'users', 'id');
    CALL _target_schema_ensure_fk('proposal_status_histories', 'proposal_id', 'fk_status_history_proposal', 'proposals', 'id');
    CALL _target_schema_ensure_fk('proposal_status_histories', 'changed_by', 'fk_status_history_user', 'users', 'id');
    CALL _target_schema_ensure_fk('proposal_regulation_types', 'proposal_id', 'fk_proposal_regulation_types_proposal', 'proposals', 'id');
    CALL _target_schema_ensure_fk('proposal_regulation_types', 'regulation_type_id', 'fk_proposal_regulation_types_type', 'regulation_types', 'id');

    ALTER TABLE users MODIFY role_id INT NOT NULL;
    ALTER TABLE units MODIFY code VARCHAR(80) NOT NULL;
    ALTER TABLE proposals MODIFY proposer_id INT NOT NULL;
    ALTER TABLE reviews MODIFY reviewer_id INT NOT NULL;
    ALTER TABLE proposal_status_histories MODIFY changed_by INT NOT NULL;

    SELECT COUNT(*) INTO column_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'original_filename';
    IF column_exists = 0 THEN
        ALTER TABLE proposal_documents
            CHANGE COLUMN file_name original_filename VARCHAR(255) NOT NULL;
    END IF;

    SELECT COUNT(*) INTO column_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'storage_path';
    IF column_exists = 0 THEN
        ALTER TABLE proposal_documents
            CHANGE COLUMN file_path storage_path VARCHAR(500) NOT NULL;
    END IF;

    SELECT COUNT(*) INTO column_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'mime_type';
    IF column_exists = 0 THEN
        ALTER TABLE proposal_documents
            CHANGE COLUMN file_type mime_type VARCHAR(120) NULL;
    END IF;

    SELECT COUNT(*) INTO column_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'document_key';
    IF column_exists = 0 THEN
        ALTER TABLE proposal_documents
            ADD COLUMN document_key VARCHAR(100) NOT NULL AFTER uploaded_by;
    END IF;

    SELECT COUNT(*) INTO column_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_documents'
      AND COLUMN_NAME = 'checksum';
    IF column_exists = 0 THEN
        ALTER TABLE proposal_documents
            ADD COLUMN checksum CHAR(64) NULL AFTER file_size;
    END IF;

    SELECT COUNT(*) INTO column_exists FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews'
      AND COLUMN_NAME = 'review_round';
    IF column_exists = 0 THEN
        ALTER TABLE reviews
            ADD COLUMN review_round INT UNSIGNED NOT NULL AFTER reviewer_id;
    END IF;

    CALL _target_schema_ensure_index('units', 'uq_units_code', '`code`', 1);
    CALL _target_schema_ensure_index('units', 'idx_units_name', '`name`', 0);
    CALL _target_schema_ensure_index('regulation_types', 'uq_regulation_types_name', '`name`', 1);
    CALL _target_schema_ensure_index('proposals', 'idx_proposals_status_created', '`status`, `created_at`', 0);
    CALL _target_schema_ensure_index('proposal_documents', 'uq_documents_proposal_key_version', '`proposal_id`, `document_key`, `version`', 1);
    CALL _target_schema_ensure_index('proposal_documents', 'idx_documents_uploaded_by', '`uploaded_by`', 0);
    CALL _target_schema_ensure_index('reviews', 'idx_reviews_proposal_round', '`proposal_id`, `review_round`', 0);
    CALL _target_schema_ensure_index('reviews', 'idx_reviews_reviewer_id', '`reviewer_id`', 0);
    CALL _target_schema_ensure_index('proposal_status_histories', 'idx_status_history_proposal_created', '`proposal_id`, `created_at`', 0);
    CALL _target_schema_ensure_index('proposal_status_histories', 'idx_status_history_changed_by', '`changed_by`', 0);
    CALL _target_schema_ensure_index('proposal_status_histories', 'idx_status_history_created_at', '`created_at`', 0);
    CALL _target_schema_ensure_index('proposal_regulation_types', 'idx_proposal_regulation_types_type', '`regulation_type_id`', 0);

    SELECT COUNT(*) INTO constraint_exists
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'proposals'
      AND CONSTRAINT_NAME = 'chk_proposals_status'
      AND CONSTRAINT_TYPE = 'CHECK';
    IF constraint_exists = 0 THEN
        ALTER TABLE proposals
            ADD CONSTRAINT chk_proposals_status
            CHECK (status IN (
                'Draft',
                'Menunggu Review',
                'Sedang Direview',
                'Perlu Revisi',
                'Diajukan Kembali',
                'Disetujui',
                'Ditolak'
            ));
    END IF;

END$$

DELIMITER ;

CALL _target_schema_upgrade();

DROP PROCEDURE IF EXISTS _target_schema_upgrade;
DROP PROCEDURE IF EXISTS _target_schema_ensure_index;
DROP PROCEDURE IF EXISTS _target_schema_ensure_fk;
DROP PROCEDURE IF EXISTS _target_schema_assert_empty_or_mapped;
