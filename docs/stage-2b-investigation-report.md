# Stage 2B — Legacy Ownership Investigation

## 1. Scope

Investigasi ini memeriksa source code, SQL schema/migration/seed, dan snapshot read-only database `komnas_ham` untuk mencari bukti ownership, asal proposal bernomor `4`, judul `0`, identitas reviewer, serta status dan histori.

Database yang diperiksa adalah MySQL 8.0.30. Tidak ada migrasi data yang dijalankan. Tidak ada helper investigasi yang dibuat karena query `SHOW`/`SELECT` dan inspeksi source yang diperlukan dapat dilakukan langsung.

**Batas bukti:** snapshot database menggambarkan isi database pada saat pemeriksaan, bukan log provenance yang membuktikan siapa yang membuat setiap row atau script mana yang pernah dijalankan.

## 2. Read-Only Safety Verification

Database dibaca dengan `SHOW TABLES`, `SHOW CREATE TABLE`, query `information_schema`, serta `SELECT` di transaksi read-only. Tabel dan row counts dibaca; tidak ada operasi tulis ke database.

Snapshot database:

- Engine/version: MySQL 8.0.30.
- Semua tabel yang terdaftar menggunakan InnoDB.
- Tabel database: `proposal_documents`, `proposal_regulation_types`, `proposal_status_histories`, `proposals`, `regulation_types`, `reviews`, `roles`, `tb_biro`, `tb_rancangan`, `tb_reviu`, `tb_staf`, `tb_tim`, `units`, `users`.
- Tabel pemicu/prosedur/view: tidak ditemukan pada query metadata read-only.
- Tabel target proposal, pivot, review, status history, dan dokumen masing-masing berisi 0 row pada snapshot.

## 3. Current Legacy Data Snapshot

### `tb_rancangan` — semua kolom aktual

| ID | Nomor | Judul | idBiro / nama biro | idTim / nama tim | idStaf / nama staf | Jenis | Dasar/alasan | Keterangan | Status | submitted_at | created_at / updated_at |
|---:|---|---|---|---|---|---|---|---|---|---|---|
| 1 | USL-2026-001 | Rancangan Pedoman Pengelolaan Usulan | 1 / Sekretariat | 1 / Tim Perumus | 1 / Maya | Peraturan Pemerintah | Mendorong pengelolaan usulan yang lebih tertib dan terdokumentasi. | Draft awal untuk kebutuhan pengelolaan internal. | Draft | NULL | 2026-10-03 23:05:57 / 2026-10-03 23:05:57 |
| 2 | USL-2026-002 | Rancangan Tata Cara Review Dokumen | 2 / Biro Hukum | 2 / Tim Evaluasi | 2 / Andi | Peraturan Presiden | Memastikan proses review berjalan konsisten. | Usulan menunggu review Biro Hukum. | Menunggu Review | NULL | 2026-10-03 23:05:57 / 2026-10-03 23:05:57 |
| 3 | USL-2026-003 | Rancangan Standarisasi Arsip Usulan | 3 / Pusat Data dan Informasi | 3 / Tim Dokumentasi | 3 / Rina | Peraturan Daerah | Mengurangi risiko kehilangan dokumentasi. | Usulan sedang dalam proses review lanjutan. | Sedang Direview | NULL | 2026-10-03 23:05:57 / 2026-10-03 23:05:57 |
| 4 | 4 | 0 | 1 / Sekretariat | 2 / Tim Evaluasi | 1 / Maya | Peraturan Pemerintah | dari kejadian kemaren makanya saya usul | string kosong | Draft | 2026-10-03 23:22:18 | 2026-10-03 23:22:18 / 2026-10-03 23:22:18 |
| 5 | 4 | makan bergizi gratis | 3 / Pusat Data dan Informasi | 2 / Tim Evaluasi | 2 / Andi | Peraturan Pemerintah | ya seperti itu | string kosong | Menunggu Review | 2026-10-04 08:39:06 | 2026-10-04 08:39:06 / 2026-10-04 08:39:06 |

Tabel memiliki primary key `idRanc`; field organisasi `idBiro`, `idTim`, dan `idStaf` nullable dengan FK ke masing-masing tabel master. Tidak ada kolom username, account ID, creator ID, atau proposer ID.

### Tabel organisasi dan akun

| Tabel | Schema/data penting |
|---|---|
| `tb_staf` | `idStaf` PK, `nmaStaf`; 3 row: Maya, Andi, Rina. Tidak ada kolom user/account, username, password, role, atau unit. |
| `tb_tim` | `idTim` PK, `nmaTim`; 3 row: Tim Perumus, Tim Evaluasi, Tim Dokumentasi. Tidak ada FK ke user/staf. |
| `tb_biro` | `idBiro` PK, `nmaBiro`; 3 row: Sekretariat, Biro Hukum, Pusat Data dan Informasi. Tidak ada FK ke user/staf. |
| `users` | 3 akun aktif: ID 1 `admin` / role canonical `admin` / unit Sekretariat; ID 2 `pengusul` / role `pengusul` / unit Sekretariat; ID 3 `biro_hukum` / role `biro_hukum` / unit Biro Hukum. Hash password tidak dibaca atau dicantumkan. |

### `tb_reviu` — seluruh kolom dan row

| Review ID | Proposal ID | Catatan | reviewer_id | Username/role reviewer | created_at |
|---:|---:|---|---:|---|---|
| 1 | 2 | Perlu ditambahkan aspek riwayat review. | 3 | biro_hukum / biro_hukum | 2026-10-03 23:05:57 |
| 2 | 3 | Dokumen pendukung perlu dilengkapi. | 3 | biro_hukum / biro_hukum | 2026-10-03 23:05:57 |

Schema aktual mempunyai FK `tb_reviu.idRanc → tb_rancangan.idRanc` dan `tb_reviu.reviewer_id → users.id`; keduanya nullable. Tidak ada kolom keputusan terstruktur atau putaran review.

### Database target

| Tabel | Jumlah row |
|---|---:|
| roles | 3 |
| users | 3 |
| units | 3 |
| regulation_types | 3 |
| proposals | 0 |
| proposal_regulation_types | 0 |
| reviews | 0 |
| proposal_status_histories | 0 |
| proposal_documents | 0 |

Role/unit/jenis yang ada bersesuaian dengan row aktual yang disebut di atas. Tidak ada proposal target atau history target untuk membantu membuktikan owner legacy.

## 4. Legacy Schema Relationship Map

```text
tb_rancangan.idRanc (PK)
  ├── idBiro → tb_biro.idBiro (FK)
  ├── idTim  → tb_tim.idTim (FK)
  └── idStaf → tb_staf.idStaf (FK)

tb_reviu.idReviu (PK)
  ├── idRanc     → tb_rancangan.idRanc (FK, nullable)
  └── reviewer_id → users.id (FK, nullable)

users.id (PK)
  ├── role_id → roles.id
  └── unit_id → units.id
```

- `tb_rancangan` memuat status saat ini serta `submitted_at`, `created_at`, `updated_at`; tidak ada actor/creator atau event history.
- `tb_staf`, `tb_tim`, dan `tb_biro` hanya menyimpan ID dan nama.
- Tidak ada jalur FK dari `tb_staf`, `tb_tim`, `tb_biro`, atau `tb_rancangan` menuju akun `users`.
- `tb_reviu.reviewer_id` adalah satu-satunya relasi legacy proposal yang langsung menunjuk ke akun dan itu adalah identitas **reviewer**, bukan owner.
- Tidak ada tabel legacy file/dokumen di antara tabel yang ditemukan.

## 5. Proposal Creation Flow Investigation

### Fakta source code saat ini

- API aktif yang membuat proposal adalah POST `api/regulations.php`.
- API hanya menerima create/manage dari role yang dinormalisasi sebagai `pengusul` atau `admin`.
- API memvalidasi `title`, `bureauId`, `teamId`, `staffId`, jenis peraturan, alasan, dan deskripsi. Nomor proposal diterima dari payload; bila kosong API membangkitkan fallback berdasarkan tahun dan hari dalam tahun.
- Statement insert hanya menulis nomor, judul, biro, tim, staf, jenis, alasan, deskripsi, status, dan waktu submit. Session username/user ID **tidak ditulis** ke row.
- Field `staffId` datang dari request browser dan disimpan sebagai `idStaf`. Tidak ada hubungan `tb_staf` ke `users`.
- Form React menyediakan pilihan biro, tim, dan staf; form tidak memiliki hidden owner ID. Nomor proposal di form pembuatan baru default-nya kosong, tetapi input nomor tetap dapat dikirim.
- Legacy `insert.php`, `login.php`, `login_process.php`, `user_dashboard.php`, `user2_dashboard.php`, dan `admin_dashboard.php` yang saat ini ada hanya redirect/menonaktifkan endpoint lama; file-file itu tidak menunjukkan flow historis lama.
- Tidak ditemukan source lain yang melakukan insert ke `tb_rancangan` selain API aktif dan SQL seed/schema. Log request atau audit log pembuatan record tidak ditemukan.

### Jawaban

1. **Siapa yang boleh membuat proposal melalui API aktif?** Pengusul atau Admin.
2. **Apakah akun yang login disimpan pada `tb_rancangan`?** Tidak.
3. **Kolom owner/account apa yang dipakai?** Tidak ada.
4. **Apakah `idStaf` deterministik menunjuk akun?** Tidak; FK hanya menunjuk `tb_staf.idStaf`.
5. **Apakah `idTim` atau `idBiro` menunjuk owner?** Tidak; keduanya hanya relasi ke direktori organisasi.
6. **Apakah form mengirim ID owner tersembunyi?** Tidak; body memuat field organisasi dan proposal, tanpa user/account identifier.
7. **Apakah session menyimpan identitas akun?** Ya, API session menyimpan `username` dan `role`, tetapi tidak menyimpan/menghubungkannya ke proposal row.
8. **Adakah relasi lain dari proposal ke akun?** Tidak ditemukan.

**Kesimpulan:** tidak ada hubungan deterministik antara akun pembuat dan record `tb_rancangan`. `OWNERSHIP CANNOT BE PROVEN`.

## 6. Authentication & Session Investigation

`api/login.php` mencari `users` berdasarkan username, lalu menaruh `username`, `role`, dan CSRF token di session. `api/_bootstrap.php` membaca `username`/`role` untuk akses role. ID akun tidak disimpan di session object yang dipakai API proposal; reviewer ID di `api/notes.php` di-resolve terpisah lewat username.

Rantai yang ada:

```text
users.username + role
        ↓
PHP session username + role
        ↓
request proposal inserts idStaf/idTim/idBiro dari payload
        ↓
tb_rancangan (tanpa account ID/username/creator)
```

Karena rantai tersebut tidak menghubungkan session dengan proposal row, login identity tidak membuktikan ownership proposal. Kesamaan nama seperti staf “Maya” dengan data demo lain tidak cukup untuk menghubungkan account tertentu.

## 7. Ownership Investigation

Keputusan menggunakan rekomendasi terbatas: `MIGRATE`, `MANUAL_REVIEW`, atau `KEEP_LEGACY`.

### Proposal ID 1

- **FACT:** nomor `USL-2026-001`; judul, staf Maya, unit Sekretariat, tim Perumus, status Draft. Row ini cocok dengan isi seed `database/schema.sql`.
- **FACT:** tidak memiliki owner/account field; `idStaf=1` hanya menunjuk row staf bernama Maya.
- **UNKNOWN:** apakah live row dibuat dengan menjalankan seed atau sumber lain; tidak ada provenance log.
- **Confidence ownership:** LOW/unknown.
- **Recommendation:** `KEEP_LEGACY`.

### Proposal ID 2

- **FACT:** nomor `USL-2026-002`; staf Andi, unit Biro Hukum, tim Evaluasi, status Menunggu Review. Row cocok dengan seed `database/schema.sql`.
- **FACT:** review ID 1 menunjuk proposal ini; reviewer user ID 3 adalah Biro Hukum. Reviewer tidak membuktikan owner.
- **UNKNOWN:** owner akun.
- **Confidence ownership:** LOW/unknown.
- **Recommendation:** `KEEP_LEGACY`.

### Proposal ID 3

- **FACT:** nomor `USL-2026-003`; staf Rina, unit Pusat Data dan Informasi, tim Dokumentasi, status Sedang Direview. Row cocok dengan seed `database/schema.sql`.
- **FACT:** review ID 2 menunjuk proposal ini; reviewer user ID 3 adalah Biro Hukum. Reviewer tidak membuktikan owner.
- **UNKNOWN:** owner akun.
- **Confidence ownership:** LOW/unknown.
- **Recommendation:** `KEEP_LEGACY`.

### Proposal ID 4

- **FACT:** nomor `4`; judul literal `0`; staf Maya, unit Sekretariat, tim Evaluasi; status Draft; `submitted_at` tidak NULL. Nilai ini tidak ada dalam seed `database/schema.sql`.
- **FACT:** schema menerima string judul dan nomor; API saat ini menerima nomor dari request. Form tidak menetapkan judul `0` sebagai default.
- **UNKNOWN:** apakah nilai tersebut input pengguna, test/manual SQL, atau hasil proses lain; tidak ditemukan request/audit log yang membuktikan asal.
- **FACT:** kombinasi Draft dengan `submitted_at` tidak sesuai alur create/update aktif yang terlihat: create Draft mengisi submitted time NULL; submit mengubah status ke Menunggu Review atau Diajukan Kembali.
- **Confidence ownership:** LOW/unknown.
- **Recommendation:** `KEEP_LEGACY`.
- **Additional decision:** nomor, judul, dan kombinasi status/waktu memerlukan `MANUAL_REVIEW`; tidak ada koreksi otomatis.

### Proposal ID 5

- **FACT:** nomor `4`; judul `makan bergizi gratis`; staf Andi, unit Pusat Data dan Informasi, tim Evaluasi; status Menunggu Review; `submitted_at = created_at = updated_at = 2026-10-04 08:39:06`. Row ini tidak ada dalam seed `database/schema.sql`.
- **FACT:** status dan waktu konsisten dengan kemungkinan jalur API submit; form/API mengizinkan nomor dikirim pengguna.
- **INFERENCE:** mungkin dibuat melalui submit API, tetapi waktu dan bentuk data saja tidak membuktikan jalur pembuatannya.
- **UNKNOWN:** owner akun dan asal pasti row.
- **Confidence ownership:** LOW/unknown.
- **Recommendation:** `KEEP_LEGACY`.
- **Additional decision:** nomor `4` konflik dengan ID 4; perlu `MANUAL_REVIEW`, tidak ada pemilihan record otomatis.

## 8. Duplicate Proposal Number Investigation

| Record | Evidence | Recommendation |
|---|---|---|
| A — ID 4 | Nomor `4`; judul `0`; Draft tetapi `submitted_at` terisi; bukan row pada seed schema; owner tidak diketahui. | `KEEP_LEGACY`; `MANUAL_REVIEW` untuk provenance, title, status/time, dan nomor. |
| B — ID 5 | Nomor `4`; judul `makan bergizi gratis`; Menunggu Review; timestamp sama pada created/submitted/updated; bukan row pada seed schema; owner tidak diketahui. | `KEEP_LEGACY`; `MANUAL_REVIEW` untuk provenance dan nomor. |

- **Dapat dipecahkan otomatis?** Tidak.
- **Evidence:** tidak ada unique constraint pada legacy `proposal_number`; tidak ada request log atau creator ID; file seed schema tidak memuat nomor `4`; dua row memiliki isi dan status yang berbeda.
- **Human decision:** apakah keduanya record yang dimaksud, bagaimana nomor diselesaikan untuk penggunaan target, dan apakah masing-masing masih perlu dipertahankan sebagai proposal. Jangan menghapus atau menomori ulang secara otomatis.

## 9. Title "0" Investigation

- `jdlRanc` adalah `VARCHAR(255)`, sehingga string `0` tersimpan sebagai teks.
- `database/schema.sql` tidak memiliki row seed dengan judul `0`.
- Form frontend mengirim isi input judul apa adanya dan tidak mengisi `0` sebagai default.
- API menggunakan required-text check: string kosong ditolak; literal `"0"` bukan string kosong sehingga lolos validasi.
- Tidak ditemukan source code yang mengubah judul kosong menjadi `0`.

**Kesimpulan:** kode aktif memungkinkan literal `"0"` menjadi input judul, tetapi bukti yang tersedia tidak menunjukkan apakah Proposal 4 dibuat lewat UI/API, SQL manual, atau jalur lain. Klasifikasi: **UNKNOWN — jangan menyebutnya pasti placeholder, corruption, atau data uji.**

## 10. Review Investigation

| Review ID | Proposal ID | Reviewer | Reviewer proven? | Parent proven? | Decision proven? | Can migrate? | Reason |
|---:|---:|---|---|---|---|---|---|
| 1 | 2 | User ID 3 `biro_hukum`, role canonical Biro Hukum | Yes — HIGH (FK `reviewer_id → users.id`) | No — owner proposal tidak terbukti | No | No | Parent tidak dapat dimigrasikan; tidak ada `decision` atau `review_round`. Catatan tidak dapat diperlakukan sebagai keputusan. |
| 2 | 3 | User ID 3 `biro_hukum`, role canonical Biro Hukum | Yes — HIGH (FK `reviewer_id → users.id`) | No — owner proposal tidak terbukti | No | No | Parent tidak dapat dimigrasikan; tidak ada `decision` atau `review_round`. Catatan tidak dapat diperlakukan sebagai keputusan. |

Isi catatan merupakan fakta, bukan decision:

- Review 1: “Perlu ditambahkan aspek riwayat review.”
- Review 2: “Dokumen pendukung perlu dilengkapi.”

Kedua catatan tersebut juga cocok persis dengan contoh review pada seed `database/schema.sql`. Ini menunjukkan nilai tersebut disediakan sebagai data seed; tanpa log deployment tidak membuktikan provenance row database saat ini.

## 11. Status Investigation

Status yang benar-benar ada saat ini:

| Legacy status | Jumlah | Arti yang dibuktikan source saat ini | Current state/history | Target candidate | History dapat direkonstruksi? | Rekomendasi |
|---|---:|---|---|---|---|---|
| Draft | 2 | Create/save draft; proposal dapat diedit bila Draft | Status kini saja; satu row memiliki submitted_at anomali | Draft | Tidak | Current value dapat dipertahankan sebagai kandidat; dua row tetap `KEEP_LEGACY` karena ownership belum terbukti. |
| Menunggu Review | 2 | Antrean review; Biro Hukum dapat memulai review | Status kini saja; satu row memiliki submitted_at NULL | Menunggu Review | Tidak | Current value dapat dipertahankan sebagai kandidat; row tetap `KEEP_LEGACY`. |
| Sedang Direview | 1 | Review sedang berlangsung; API mengizinkan minta revisi/setuju/tolak | Status kini saja | Sedang Direview | Tidak | Current value dapat dipertahankan sebagai kandidat; row tetap `KEEP_LEGACY`. |

Tidak ditemukan row legacy saat ini dengan `Perlu Revisi`, `Diajukan Kembali`, `Disetujui`, atau `Ditolak`. Nilai status pada database tidak memiliki timestamp perubahan atau actor. `created_at`, `updated_at`, dan `submitted_at` bukan log transisi lengkap.

API saat ini mengubah status lewat endpoint review dan update proposal, tetapi tidak menulis `proposal_status_histories`. Tidak ada tabel histori legacy yang ditemukan. Karena itu current status tidak dapat dipakai untuk mengarang alur status sebelumnya.

## 12. Source Code Evidence

- [schema.sql](../database/schema.sql): struktur legacy, FK organisasi, dan seed tiga proposal serta dua catatan review. Seed tidak berisi account owner proposal.
- [regulations.php](../api/regulations.php): query daftar, hak create role Pengusul/Admin, pengambilan field organisasi dari payload, INSERT tanpa user ID, dan update status saat submit.
- [App.jsx](../frontend/src/App.jsx): form mengirim title, nomor, biro, tim, staf dan jenis; tidak mengirim owner ID. Field nomor proposal pada form create default kosong.
- [login.php](../api/login.php): login mencari akun berdasarkan username dan memasukkan username/role ke session, tidak menyimpan identitas itu pada proposal.
- [\_bootstrap.php](../api/_bootstrap.php): session authorization memeriksa username/role.
- [notes.php](../api/notes.php): reviewer diselesaikan dari username session; catatan direkam dan status diperbarui dalam transaksi, tanpa status-history insert.
- [insert.php](../insert.php): endpoint legacy yang ada saat ini mengembalikan 410 lalu redirect; tidak ada logic insert legacy di file ini.
- [login_process.php](../login_process.php), [login.php](../login.php), [admin_dashboard.php](../admin_dashboard.php), [user_dashboard.php](../user_dashboard.php), [user2_dashboard.php](../user2_dashboard.php): file root yang tersedia berisi redirect/session teardown, bukan implementasi login atau ownership lama.
- [migration_legacy_workflow.sql](../database/migration_legacy_workflow.sql) dan [migration_workflow_detailed.sql](../database/migration_workflow_detailed.sql): script lama memilih Pengusul pertama sebagai `proposer_id`, memilih reviewer Biro Hukum pertama, memaksa status menjadi Draft, dan membangun history Draft. Ini adalah asumsi script migrasi, bukan bukti historis; keduanya tidak dijalankan dalam investigasi ini.
- [seed_demo_workflow.sql](../database/seed_demo_workflow.sql): data demo target terpisah; tidak membuktikan ownership proposal legacy.
- [stage-1-database.md](stage-1-database.md): menyebut 4 proposal, tetapi snapshot terbaru menunjukkan 5.
- [stage-2-dry-run-report.md](../database/migration_logs/stage-2-dry-run-report.md): dry run terdahulu melaporkan 5 proposal; snapshot read-only kali ini mengonfirmasi jumlah tersebut.

## 13. Evidence Matrix

| Legacy ID | Nomor | Judul | Candidate owner | Evidence | Source | Confidence ownership | Recommendation |
|---:|---|---|---|---|---|---|---|
| 1 | USL-2026-001 | Rancangan Pedoman Pengelolaan Usulan | None | `idStaf=1` hanya ke `tb_staf`; tidak ada user link. Nilai row cocok dengan seed schema, yang bukan bukti akun pemilik. | DB `tb_rancangan`; `schema.sql`; API/form | LOW/unknown | `KEEP_LEGACY` |
| 2 | USL-2026-002 | Rancangan Tata Cara Review Dokumen | None | `idStaf=2` hanya ke `tb_staf`; review parent relation dan reviewer FK tidak mengidentifikasi owner. Cocok dengan seed schema. | DB; `schema.sql`; `tb_reviu`; API | LOW/unknown | `KEEP_LEGACY` |
| 3 | USL-2026-003 | Rancangan Standarisasi Arsip Usulan | None | `idStaf=3` hanya ke `tb_staf`; reviewer FK tidak mengidentifikasi owner. Cocok dengan seed schema. | DB; `schema.sql`; `tb_reviu`; API | LOW/unknown | `KEEP_LEGACY` |
| 4 | 4 | 0 | None | Tidak ada owner field; bukan row seed schema; nomor duplikat; Draft dengan submitted time. | DB; `schema.sql`; `regulations.php`; `App.jsx` | LOW/unknown | `KEEP_LEGACY` |
| 5 | 4 | makan bergizi gratis | None | Tidak ada owner field; bukan row seed schema; nomor duplikat; timestamp selaras dengan kemungkinan submit tetapi tidak membuktikan actor. | DB; `regulations.php`; `App.jsx` | LOW/unknown | `KEEP_LEGACY` |

Semua kandidat ownership yang LOW dilarang dimigrasikan. Tidak ada proposal dengan ownership HIGH.

## 14. Review Mapping Matrix

| Review ID | Parent proposal | Reviewer | Reviewer proven? | Parent proven? | Decision/round proven? | Recommendation |
|---:|---:|---|---|---|---|---|
| 1 | 2 | User 3, `biro_hukum` | Yes — HIGH | No | No / No | `KEEP_LEGACY` |
| 2 | 3 | User 3, `biro_hukum` | Yes — HIGH | No | No / No | `KEEP_LEGACY` |

Reviewer valid tidak cukup untuk memigrasikan review bila parent proposal tidak dapat dimigrasikan. Catatan dan `created_at` dapat dipertahankan di legacy; target review round/decision tidak boleh ditebak.

## 15. Status Mapping Matrix

| Legacy status | Count | Target candidate | Meaning in active source | Historical transition evidence | Recommendation |
|---|---:|---|---|---|---|
| Draft | 2 | Draft | Draft yang dapat disimpan/edit | Tidak ada | `KEEP_LEGACY` row; jangan buat history |
| Menunggu Review | 2 | Menunggu Review | Menunggu tindakan Biro Hukum | Tidak ada; `submitted_at` salah satu row NULL | `KEEP_LEGACY` row; jangan buat history |
| Sedang Direview | 1 | Sedang Direview | Reviewer sedang memeriksa | Tidak ada | `KEEP_LEGACY` row; jangan buat history |

Status target pada tabel ini hanya kesamaan current-value yang eksplisit, bukan kesimpulan tentang riwayat atau transisi terdahulu.

## 16. Migration Recommendation

1. Pertahankan kelima proposal dan dua review pada tabel legacy sekarang.
2. Jangan menggunakan `idStaf`, nama staf, biro, tim, role, reviewer, atau urutan ID sebagai owner.
3. Jangan gunakan dua script migrasi legacy yang ada untuk memindahkan proposal: kedua script melakukan atribusi owner/reviewer dan status/history sintetis yang tidak dibuktikan sumber.
4. Jika kelak tersedia bukti otoritatif (mis. dokumen registrasi atau mapping pemilik yang disetujui), catat mapping legacy ID → target user ID beserta sumber dan confidence, kemudian review duplikasi nomor secara terpisah sebelum commit.
5. Review notes dapat tetap ditelusuri di legacy. Migrasi ke struktur review target menunggu keputusan manusia tentang pembuktian round dan decision; jangan menafsirkan isi catatan sebagai decision.

## 17. Human Decisions Required

1. Adakah daftar atau dokumen otoritatif yang menghubungkan masing-masing `idRanc` 1–5 ke `users.id`? Jika ada, apa sumber dan persetujuan mapping-nya?
2. Apakah row ID 1–3 adalah data seed/demo yang memang ingin dipertahankan sebagai data target, atau hanya sample untuk legacy? Kecocokan seed adalah fakta; status provenance live tidak tercatat.
3. Apakah row ID 4 (nomor `4`, judul `0`, Draft dengan submitted timestamp) merupakan entri yang dikehendaki, test, atau input valid? Bukti teknis yang ada tidak menentukan.
4. Apakah row ID 5 (nomor `4`, judul “makan bergizi gratis”) merupakan entri yang dikehendaki dan bagaimana konflik nomor harus ditangani tanpa renumber/hapus diam-diam?
5. Apakah catatan review legacy tetap legacy-only atau ada proses manusia yang menetapkan decision/round yang dapat dicatat sebagai mapping baru? Catatan asli sendiri tidak menyatakan field tersebut.
6. Apakah status saat ini cukup untuk membuat satu imported-state marker di masa depan, terpisah dari historical transition? Belum ada aturan tersebut; investigasi ini tidak membuat history.

## 18. Unresolved Questions

- Siapa pemilik akun setiap proposal, jika dapat dibuktikan dari sumber eksternal?
- Apa provenance row ID 4 dan 5, dan apakah kedua nomor `4` memang disengaja?
- Apakah title literal `0` nilai yang dimasukkan pengguna? Tidak ada event log untuk menjawabnya.
- Mengapa `submitted_at` row ID 4 terisi saat status Draft, sementara `submitted_at` row ID 2 kosong saat Menunggu Review?
- Apakah teks dua catatan review merupakan data seed atau review runtime? Nilainya cocok dengan seed, tetapi tidak ada execution log.
- Apakah catatan review memiliki decision atau round yang dapat dikonfirmasi oleh reviewer/pemilik bisnis?

## 19. Stage 2B Conclusion

```text
Proposal 1 → KEEP_LEGACY (owner tidak terbukti; row cocok dengan seed schema)
Proposal 2 → KEEP_LEGACY (owner tidak terbukti; row cocok dengan seed schema)
Proposal 3 → KEEP_LEGACY (owner tidak terbukti; row cocok dengan seed schema)
Proposal 4 → KEEP_LEGACY (owner tidak terbukti; duplicate nomor; provenance/title/status memerlukan manual review)
Proposal 5 → KEEP_LEGACY (owner tidak terbukti; duplicate nomor; provenance memerlukan manual review)
```

**Ownership cannot be proven untuk kelima proposal.** Tidak ada proposal yang direkomendasikan untuk migrasi saat ini. Dua reviewer dapat dipetakan ke akun Biro Hukum dengan confidence HIGH, tetapi review tidak dapat dilepaskan dari parent proposal dan struktur target meminta data decision/round yang tidak tersedia. Current status bisa dibaca; status history tidak dapat direkonstruksi.

Tidak ada keputusan bisnis baru yang dibuat mengenai validitas, pemilik, nomor, title, atau keputusan review. Tetap gunakan `KEEP_LEGACY`/`MANUAL_REVIEW` sampai evidence/keputusan otoritatif tersedia.

## 20. Safety Verification

- [x] PASS — No INSERT executed
- [x] PASS — No UPDATE executed
- [x] PASS — No DELETE executed
- [x] PASS — No ALTER executed
- [x] PASS — No DROP executed
- [x] PASS — No TRUNCATE executed
- [x] PASS — No migration executed
- [x] PASS — No seed executed
- [x] PASS — No legacy data modified
- [x] PASS — No target data modified
- [x] PASS — No API changed
- [x] PASS — No frontend changed
- [x] PASS — No auth changed
- [x] PASS — No ownership guessed
- [x] PASS — No review decision fabricated
- [x] PASS — No status history fabricated

Only this investigation report was created. The Stage 2B result is **COMPLETE / READ-ONLY**; migration commit remains blocked and was not attempted.
