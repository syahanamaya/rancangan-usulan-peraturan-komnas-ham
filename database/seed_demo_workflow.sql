USE komnas_ham;

-- ===============================================================
-- Demo data untuk workflow usulan rancangan peraturan
-- Jalankan setelah migration_legacy_workflow.sql atau schema baru
-- ===============================================================

INSERT INTO roles (name, description)
VALUES
    ('admin', 'Administrator sistem'),
    ('pengusul', 'Pengusul rancangan peraturan'),
    ('biro_hukum', 'Reviewer untuk usulan rancangan peraturan')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO units (name, code, description, is_active)
VALUES
    ('Sekretariat', 'SEK', 'Sekretariat internal', 1),
    ('Biro Hukum', 'BH', 'Unit review usulan', 1),
    ('Pusat Data dan Informasi', 'PUSDA', 'Unit data dan informasi', 1),
    ('Bidang Kebijakan', 'BK', 'Unit perumus kebijakan', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO regulation_types (name, description, is_active)
VALUES
    ('Peraturan Pemerintah', 'Jenis peraturan tingkat pemerintah', 1),
    ('Peraturan Presiden', 'Peraturan presiden', 1),
    ('Peraturan Daerah', 'Peraturan daerah', 1),
    ('Keputusan', 'Keputusan internal', 1),
    ('Pedoman', 'Pedoman pelaksanaan', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO users (name, username, password, role, role_id, unit_id, position, is_active)
VALUES
    ('Administrator Sistem', 'admin', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'admin', (SELECT id FROM roles WHERE name = 'admin'), (SELECT id FROM units WHERE code = 'SEK'), 'Administrator', 1),
    ('Maya Puspita', 'pengusul', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'pengusul', (SELECT id FROM roles WHERE name = 'pengusul'), (SELECT id FROM units WHERE code = 'PUSDA'), 'Staf Peraturan', 1),
    ('Rizky Hidayat', 'biro_hukum', '$2y$12$dTOVMBjujJLGh6B.BY6jVeL7iMx4s0GHZcFcAVxF3VV15g/tApesO', 'biro_hukum', (SELECT id FROM roles WHERE name = 'biro_hukum'), (SELECT id FROM units WHERE code = 'BH'), 'Analis Hukum', 1)
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO proposals (proposal_number, title, unit_id, proposer_id, regulation_type_id, background, legal_basis, objective, description, status, submitted_at)
VALUES
    ('USL-2026-001', 'Rancangan Pedoman Pengelolaan Usulan', (SELECT id FROM units WHERE code = 'PUSDA'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Pedoman'), 'Diperlukan sistem yang konsisten untuk memantau usulan rancangan peraturan dari tahap draft hingga keputusan akhir.', 'Peraturan internal organisasi', 'Meningkatkan kualitas dan kejelasan proses pengajuan serta review.', 'Draft awal untuk kebutuhan penyusunan usulan internal.', 'Draft', NULL),
    ('USL-2026-002', 'Rancangan Tata Cara Review Dokumen', (SELECT id FROM units WHERE code = 'BH'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Peraturan Presiden'), 'Usulan ini diperlukan agar proses review dokumen lebih terstandar dan terdokumentasi dengan baik.', 'Instruksi internal', 'Menyederhanakan alur review dokumen dan meningkatkan konsistensi keputusan.', 'Usulan sedang menunggu review awal dari Biro Hukum.', 'Menunggu Review', NOW()),
    ('USL-2026-003', 'Rancangan Standarisasi Arsip Usulan', (SELECT id FROM units WHERE code = 'SEK'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Peraturan Daerah'), 'Untuk menjaga arsip usulan tetap lengkap, aman, dan mudah ditelusuri sesuai kebutuhan organisasi.', 'Bukti kebutuhan operasional', 'Menyamakan alur dokumentasi usulan antar unit.', 'Usulan sedang dalam review substansi oleh Biro Hukum.', 'Sedang Direview', NOW()),
    ('USL-2026-004', 'Rancangan Perbaikan Prosedur Revisi Usulan', (SELECT id FROM units WHERE code = 'BK'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Keputusan'), 'Dibutuhkan prosedur revisi yang lebih jelas agar pengusul dapat memperbaiki usulan dengan cepat dan terdokumentasi.', 'Prosedur internal', 'Mempercepat penyerahan revisi dan menurunkan kemungkinan kesalahan.', 'Pengusul diminta melakukan revisi pada beberapa bagian penting.', 'Perlu Revisi', NOW()),
    ('USL-2026-005', 'Rancangan Penetapan Kriteria Usulan Prioritas', (SELECT id FROM units WHERE code = 'BK'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Peraturan Pemerintah'), 'Untuk memastikan usulan prioritas dapat diputuskan berdasarkan kebutuhan operasional dan urgensi.', 'Kebijakan organisasi', 'Menentukan prioritas usulan secara lebih objektif.', 'Usulan sudah diajukan kembali setelah revisi.', 'Diajukan Kembali', NOW()),
    ('USL-2026-006', 'Rancangan Panduan Pembuatan Dokumen Usulan', (SELECT id FROM units WHERE code = 'PUSDA'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Pedoman'), 'Panduan ini diperlukan untuk menyatukan format dan standar penulisan dokumen usulan antar unit.', 'Pedoman kerja organisasi', 'Menyelaraskan format dokumen pendukung usulan.', 'Usulan telah disetujui dan masuk tahap finalisasi.', 'Disetujui', NOW()),
    ('USL-2026-007', 'Rancangan Penolakan Usulan Duplikasi', (SELECT id FROM units WHERE code = 'SEK'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Keputusan'), 'Dibutuhkan mekanisme penolakan usulan yang sudah ada agar tidak terjadi duplikasi proposal.', 'Kebijakan tata kelola organisasi', 'Mengurangi duplikasi usulan dan memperjelas alur prioritas.', 'Usulan ditolak karena substansi sudah tertutup pada mekanisme lama.', 'Ditolak', NOW()),
    ('USL-2026-008', 'Rancangan Formulir Monitoring Status Usulan', (SELECT id FROM units WHERE code = 'PUSDA'), (SELECT id FROM users WHERE username = 'pengusul'), (SELECT id FROM regulation_types WHERE name = 'Pedoman'), 'Form monitoring status diperlukan untuk memudahkan pemantauan dan pelaporan progres usulan.', 'Kebutuhan operasional', 'Memudahkan pemantauan progress sekaligus dokumentasi interaksi.', 'Proses telah selesai dan masuk tahap penyimpanan final.', 'Selesai', NOW())
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes, created_at)
SELECT p.id, (SELECT id FROM users WHERE username = 'pengusul'), NULL, p.status, 'Demo seed data', NOW()
FROM proposals p
LEFT JOIN proposal_status_histories h ON h.proposal_id = p.id
WHERE h.id IS NULL;

INSERT INTO proposal_documents (proposal_id, uploaded_by, file_name, file_path, file_type, file_size, version, created_at)
VALUES
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-001'), (SELECT id FROM users WHERE username = 'pengusul'), 'draft-pedoman-pengelolaan-usulan-v1.docx', '/uploads/proposals/USL-2026-001/draft-v1.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 180000, '1', NOW()),
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-001'), (SELECT id FROM users WHERE username = 'pengusul'), 'draft-pedoman-pengelolaan-usulan-v2.docx', '/uploads/proposals/USL-2026-001/draft-v2.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 220000, '2', NOW()),
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-004'), (SELECT id FROM users WHERE username = 'pengusul'), 'prosedur-revisi-usulan-v1.pdf', '/uploads/proposals/USL-2026-004/revisi-v1.pdf', 'application/pdf', 250000, '1', NOW()),
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-006'), (SELECT id FROM users WHERE username = 'pengusul'), 'panduan-dokumen-usulan-final.docx', '/uploads/proposals/USL-2026-006/final.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 310000, '3', NOW())
ON DUPLICATE KEY UPDATE file_name = VALUES(file_name);

INSERT INTO reviews (proposal_id, reviewer_id, decision, notes, created_at)
VALUES
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-002'), (SELECT id FROM users WHERE username = 'biro_hukum'), 'Perlu Revisi', 'Tambahkan porsi riwayat review dan proses penjadwalan agar lebih kuat.', NOW()),
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-003'), (SELECT id FROM users WHERE username = 'biro_hukum'), 'Sedang Direview', 'Dokumen pendukung masih perlu dilengkapi pada bagian struktur arsip.', NOW()),
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-004'), (SELECT id FROM users WHERE username = 'biro_hukum'), 'Perlu Revisi', 'Perlu penjelasan lebih rinci tentang mekanisme revisi yang ditanggung oleh pengusul.', NOW()),
    ((SELECT id FROM proposals WHERE proposal_number = 'USL-2026-006'), (SELECT id FROM users WHERE username = 'biro_hukum'), 'Setujui', 'Dokumen sesuai dengan kebutuhan dan telah siap disahkan.', NOW())
ON DUPLICATE KEY UPDATE notes = VALUES(notes);
