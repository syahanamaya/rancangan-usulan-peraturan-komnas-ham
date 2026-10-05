# Stage 1 — Target Database

## 1. Database sebelum perubahan

Preflight dilakukan dengan query metadata dan pembacaan data saja sebelum migration baru dijalankan.

- Engine: MySQL
- Versi server: 8.0.30
- Database: `komnas_ham`
- Tabel memakai InnoDB.

Tabel legacy yang ditemukan:

| Tabel | Baris sebelum migration |
|---|---:|
| `tb_biro` | 3 |
| `tb_tim` | 3 |
| `tb_staf` | 3 |
| `tb_rancangan` | 4 |
| `tb_reviu` | 2 |

Semua tabel target sudah ada sebelum tahap ini. Tabel master berisi 3 role, 3 user, 3 unit, dan 3 jenis peraturan. Setiap user memiliki `role_id`, role lama cocok dengan role master untuk ketiga akun, dan tidak ditemukan role/unit code kosong atau duplikat.

Kelompok tabel workflow target belum memiliki data proposal, dokumen, review, atau history:

| Tabel | Baris sebelum migration |
|---|---:|
| `proposals` | 0 |
| `proposal_documents` | 0 |
| `reviews` | 0 |
| `proposal_status_histories` | 0 |

Konflik schema yang ditemukan sebelum migration:

- `users.role_id` nullable dan masih ada `users.role` enum. Semua `role_id` saat ini sudah terisi dan cocok dengan role master.
- `units.code` nullable dan belum unique; data saat ini tidak memiliki code kosong atau duplikat.
- `regulation_types.name` belum unique; data saat ini tidak memiliki nama duplikat.
- `proposals.proposer_id` nullable dan FK sebelumnya memakai `ON DELETE SET NULL`.
- `proposal_documents` menggunakan nama kolom lama (`file_name`, `file_path`, `file_type`) dan belum memiliki `document_key` maupun `checksum`.
- `reviews` belum memiliki `review_round`; `reviewer_id` nullable.
- `proposal_status_histories.changed_by` nullable.
- Beberapa FK target menggunakan `SET NULL` atau `CASCADE`, yang dapat melepaskan atau menghapus data historis.
- Form `frontend/src/App.jsx` memang memungkinkan memilih beberapa jenis peraturan. Karena itu tabel pivot dibuat setelah kebutuhan multi-jenis dikonfirmasi.

**Migration risk sebelum preflight: HIGH** karena tabel target sudah ada dengan bentuk berbeda dan data legacy tidak memiliki relasi owner akun yang dapat dibuktikan. Migration baru memeriksa prasyarat dan berhenti bila data target yang ada tidak aman untuk dikencangkan. Pada preflight aktual, tabel workflow target kosong dan data role master terpetakan sehingga penguatan schema dapat dilakukan tanpa memindahkan proposal legacy.

## 2. Database target

| Tabel | Fungsi |
|---|---|
| `roles` | Master role canonical: `admin`, `pengusul`, `biro_hukum`. |
| `users` | Akun. `role_id` menjadi sumber role canonical; kolom `role` yang sudah ada tetap dipertahankan sebagai legacy. |
| `units` | Master unit organisasi dengan `code` unik. Data yang sudah ada dipertahankan. |
| `regulation_types` | Master jenis peraturan dengan nama unik. Data yang sudah ada dipertahankan. |
| `proposals` | Data proposal; setiap proposal wajib memiliki `proposer_id` dan nomor unik. |
| `proposal_documents` | Metadata dokumen dan versi dokumen per proposal. File belum diunggah oleh tahap ini. |
| `reviews` | Tindakan review substantif; setiap review memiliki ID sendiri dan nomor putaran. |
| `proposal_status_histories` | Catatan aktor, status lama/baru, waktu, serta notes untuk setiap transisi mendatang. |
| `proposal_regulation_types` | Relasi banyak-ke-banyak proposal dan jenis peraturan, sesuai pilihan jamak pada form saat ini. |

`proposal_regulation_types` menggunakan primary key gabungan `(proposal_id, regulation_type_id)`, sehingga pasangan yang sama tidak dapat dimasukkan dua kali.

## 3. Relationship

- `roles` 1 → banyak `users`.
- `units` 1 → banyak `users` dan `proposals`.
- `users` 1 → banyak `proposals` melalui `proposer_id`.
- `regulation_types` banyak ↔ banyak `proposals` melalui `proposal_regulation_types`.
- `proposals` 1 → banyak `proposal_documents`.
- `proposals` 1 → banyak `reviews`.
- `proposals` 1 → banyak `proposal_status_histories`.
- `users` dapat menjadi uploader, reviewer, atau aktor history melalui foreign key masing-masing.

Semua FK pada tabel target memakai `ON DELETE RESTRICT ON UPDATE RESTRICT`. Data master yang sudah dipakai harus dinonaktifkan, bukan dihapus. Proposal beserta data historisnya juga tidak dapat dihapus secara tidak sengaja melalui cascade.

## 4. Constraints dan indexes

**Primary key**

- `roles`, `users`, `units`, `regulation_types`, `proposals`, `proposal_documents`, `reviews`, dan `proposal_status_histories`: kolom `id`.
- `proposal_regulation_types`: gabungan `proposal_id`, `regulation_type_id`.

**Foreign key**

- `users.role_id` → `roles.id` (NOT NULL).
- `users.unit_id` → `units.id` (nullable).
- `proposals.proposer_id` → `users.id` (NOT NULL).
- `proposals.unit_id` → `units.id` (nullable).
- `proposals.regulation_type_id` → `regulation_types.id` (nullable untuk kompatibilitas data target).
- `proposal_documents.proposal_id` → `proposals.id`.
- `proposal_documents.uploaded_by` → `users.id` (nullable).
- `reviews.proposal_id` → `proposals.id`.
- `reviews.reviewer_id` → `users.id` (NOT NULL).
- `proposal_status_histories.proposal_id` → `proposals.id`.
- `proposal_status_histories.changed_by` → `users.id` (NOT NULL).
- `proposal_regulation_types.proposal_id` → `proposals.id`.
- `proposal_regulation_types.regulation_type_id` → `regulation_types.id`.

**Unique**

- `roles.name`.
- `users.username`.
- `units.code`.
- `regulation_types.name`.
- `proposals.proposal_number`.
- `proposal_documents(proposal_id, document_key, version)`.
- `proposal_regulation_types(proposal_id, regulation_type_id)` melalui primary key gabungan.

**Index penting**

- FK/index lookup pada user role dan unit, proposal proposer/unit/jenis, dokumen proposal/uploader, review proposal/putaran dan reviewer, serta status history proposal/waktu dan aktor.
- `proposals(status, created_at)` untuk pencarian daftar kerja.
- `units.name` untuk pencarian master unit.

Status canonical dibatasi ke `Draft`, `Menunggu Review`, `Sedang Direview`, `Perlu Revisi`, `Diajukan Kembali`, `Disetujui`, dan `Ditolak`. `Selesai` tidak dimasukkan karena belum ada proses administratif sesudah persetujuan yang terverifikasi.

Versi dokumen tetap berupa `VARCHAR(30)` agar tidak membatasi format versi sebelum kebutuhan bisnis ditentukan. `document_key` mengelompokkan versi dari dokumen yang sama; checksum disediakan sebagai metadata opsional.

Constraint nomor proposal sudah disiapkan, tetapi generator nomor unik belum dibuat; generator menjadi tanggung jawab API pada tahap berikutnya.

## 5. Migration strategy

**Implemented**

- Menambahkan `database/migration_target_schema.sql`.
- Menambahkan `database/seed_target_master.sql`.
- Memperkuat `users.role_id`, `units.code`, `proposals.proposer_id`, `reviews.reviewer_id`, dan `proposal_status_histories.changed_by` menjadi wajib setelah memeriksa data.
- Menambahkan unique constraints/index, status check, `reviews.review_round`, metadata dokumen target, serta pivot multi-jenis.
- Mengubah aksi FK pada tabel target menjadi RESTRICT untuk mencegah hilangnya referensi historis.
- Menjalankan migration target pada `komnas_ham` dan menjalankannya kembali untuk memeriksa idempotensi.
- Menjalankan seed role yang aman dan tidak mengubah akun user.

**Sengaja belum dimigrasikan**

- Tidak ada baris proposal, review, dokumen, atau status history legacy yang disalin.
- Tidak ada history sintetis yang dibuat.
- Tidak ada ownership/reviewer/tanggal historis yang ditebak.
- Unit dan jenis peraturan master yang sudah tersedia tidak ditambah atau diubah.

## 6. Legacy tables

Semua tabel legacy tetap ada dan tidak disentuh oleh migration baru. API aktif masih memakai tabel legacy; perubahan API merupakan tahap berikutnya. Jumlah baris akhir tetap sama dengan preflight: `tb_biro` 3, `tb_tim` 3, `tb_staf` 3, `tb_rancangan` 4, dan `tb_reviu` 2.

## 7. Data yang belum dapat dimigrasikan

**LEGACY PROPOSAL MIGRATION BLOCKERS**

- Proposal legacy tanpa owner akun yang dapat diverifikasi: **4**.
- Field yang tersedia mencakup `idBiro`, `idTim`, `idStaf`, judul, jenis peraturan berbentuk teks, alasan/keterangan, status saat ini, serta waktu submit.
- Tidak ada FK dari `tb_rancangan.idStaf` ke `users.id`, dan tidak ada relasi yang membuktikan bahwa staf pada rancangan adalah pemilik akun tertentu. `users.unit_id` juga tidak membuktikan kepemilikan proposal.
- `tb_reviu` menyimpan catatan dan reviewer reference, tetapi tidak menyimpan keputusan, putaran review, status sebelum/sesudah, atau audit history lengkap.
- Status yang terlihat sebelum migrasi: Draft, Menunggu Review, dan Sedang Direview. Status historis sebelumnya tidak dapat dibangun dari kolom status saat ini saja.
- Unit dan jenis peraturan pada proposal lama perlu mapping eksplisit; nilai jenis legacy dapat berupa teks dengan lebih dari satu pilihan.

Proposal tersebut tetap berada di tabel lama sampai aturan mapping/manual reconciliation disepakati. Tidak ada proposal yang diassign ke user tertentu.

## 8. Validation result

**PASS**

- Database terhubung secara read-only saat preflight: MySQL 8.0.30.
- Semua tabel target tersedia; seluruh tabel target menggunakan InnoDB.
- Primary key, FK, unique constraints, index target, dan check status terverifikasi melalui `information_schema`.
- `users.role_id`, `proposals.proposer_id`, `reviews.reviewer_id`, dan `proposal_status_histories.changed_by` tidak nullable.
- Foreign key target beraksi RESTRICT untuk DELETE dan UPDATE.
- Migration target berhasil dijalankan ulang.
- Test integritas transaksional: owner proposal tidak valid ditolak FK (1452); nomor proposal duplikat ditolak UNIQUE (1062); username duplikat ditolak UNIQUE (1062); review dengan proposal tidak ada ditolak FK (1452); history dengan aktor tidak ada ditolak FK (1452).
- Semua baris test di-rollback. Jumlah baris proposal, review, dan history tetap 0; tidak ada baris probe yang tersisa.
- Tabel dan baris legacy tidak berubah.

**WARNING**

- `users.role` masih ada sebagai kolom legacy karena API login aktif belum dialihkan ke `role_id`. Jangan hapus kolom itu pada tahap database ini.
- API aktif belum menggunakan tabel target; setelah tahap ini, fitur proposal baru tetap mengikuti jalur legacy sampai API dimigrasikan.
- Tes insert yang di-rollback dapat meninggalkan celah pada nilai `AUTO_INCREMENT`; tidak ada data uji yang tersimpan.

**FAIL**

- Tidak ada test constraint yang gagal.
