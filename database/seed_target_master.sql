USE komnas_ham;

-- Canonical role master data only. This does not change users or legacy data.
INSERT INTO roles (name, description)
VALUES
    ('admin', 'Administrator sistem'),
    ('pengusul', 'Pengusul rancangan peraturan'),
    ('biro_hukum', 'Reviewer Biro Hukum')
ON DUPLICATE KEY UPDATE name = VALUES(name);
