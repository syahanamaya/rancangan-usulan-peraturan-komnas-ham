# Tahap 2 — Dry-Run Mapping Data Legacy

**Mode:** read-only dry run

**Database:** `komnas_ham` — MySQL 8.0.30

**Helper:** [migration_target_data_dry_run.php](../migration_target_data_dry_run.php)

**Commit:** tidak dijalankan karena prasyarat ownership dan identifier belum terpenuhi.

Dry run dijalankan terhadap consistent read-only snapshot. Tidak ada data target atau legacy yang ditulis.

## Preflight dan mapping master

### Users dan roles

Tiga akun sudah ada di tabel `users` yang sama dengan model target. Semuanya memiliki `role_id` dan canonical role yang cocok dengan nilai kolom legacy `role`; tidak ada akun baru yang perlu dibuat dan password tidak disentuh.

| User ID | Username | Role legacy | Role canonical | Mapping |
|---:|---|---|---|---|
| 1 | admin | admin | admin | Gunakan row existing |
| 2 | pengusul | pengusul | pengusul | Gunakan row existing |
| 3 | biro_hukum | biro_hukum | biro_hukum | Gunakan row existing |

Hasil: 3 existing/reused, 0 user baru, 0 role mismatch.

### Units

Ketiga unit legacy cocok dengan tepat satu unit target berdasarkan kecocokan nama exact. Tidak ada unit yang disisipkan atau diubah.

| Legacy ID | Nama | Target ID/kode | Evidence |
|---:|---|---|---|
| 1 | Sekretariat | 1 / SEK | Nama exact |
| 2 | Biro Hukum | 2 / BH | Nama exact |
| 3 | Pusat Data dan Informasi | 3 / PUSDA | Nama exact |

Hasil: 3 dari 3 unit terpetakan; 5 referensi proposal memiliki unit exact match.

### Jenis peraturan

Semua nilai legacy cocok exact dengan satu jenis target. Karena form aplikasi mendukung beberapa jenis, relasi target nantinya melalui `proposal_regulation_types`. Tidak ada row pivot yang dibuat pada dry run.

| Jenis legacy | Jumlah proposal | Target |
|---|---:|---|
| Peraturan Daerah | 1 | ID 3, exact |
| Peraturan Pemerintah | 3 | ID 1, exact |
| Peraturan Presiden | 1 | ID 2, exact |

Hasil: 3 dari 3 nilai jenis terpetakan; 5 referensi proposal memiliki jenis exact match.

## Proposal legacy

**Ditemukan 5 proposal, bukan 4 seperti laporan Tahap 1 sebelumnya.** Dua row baru (ID 4 dan 5) sudah ada pada database saat dry run. Semuanya tidak memiliki owner yang dapat diverifikasi: `tb_rancangan` tidak memiliki kolom user/owner/username, dan `tb_staf` tidak memiliki FK atau identifier akun. Nama staf bukan bukti relasi akun.

| Legacy ID | Nomor | Judul | Staf tercatat (bukan bukti owner) | Status legacy | Target status jika dipetakan | Keputusan |
|---:|---|---|---|---|---|---|
| 1 | USL-2026-001 | Rancangan Pedoman Pengelolaan Usulan | Maya | Draft | Draft | `SKIP_OWNER_UNKNOWN` |
| 2 | USL-2026-002 | Rancangan Tata Cara Review Dokumen | Andi | Menunggu Review | Menunggu Review | `SKIP_OWNER_UNKNOWN` |
| 3 | USL-2026-003 | Rancangan Standarisasi Arsip Usulan | Rina | Sedang Direview | Sedang Direview | `SKIP_OWNER_UNKNOWN` |
| 4 | 4 | 0 | Maya | Draft | Draft | `SKIP_OWNER_UNKNOWN`; nomor duplikat; status/waktu tidak konsisten; judul perlu pemeriksaan |
| 5 | 4 | makan bergizi gratis | Andi | Menunggu Review | Menunggu Review | `SKIP_OWNER_UNKNOWN`; nomor duplikat |

Kelima status saat ini memiliki pasangan status target exact. Itu hanya memetakan current state; tidak membuktikan lifecycle sebelumnya atau siapa yang melakukan perubahan.

Nomor `4` dipakai oleh dua row legacy (ID 4 dan 5). Tidak ada nomor yang diubah, tidak ada row yang dipilih sebagai row canonical, dan tidak ada proposal yang masuk ke target.

**Rekonsiliasi proposal:** 5 legacy = 0 migrated + 5 retained/skipped + 0 unaccounted.

## Reviews

Kedua catatan review memiliki `reviewer_id = 3`; FK menunjuk ke user `biro_hukum` yang canonical role-nya juga `biro_hukum` (**confidence HIGH**). Namun, keduanya tidak dimigrasikan karena proposal induk belum dapat dimigrasikan. Tabel legacy tidak menyediakan `decision` atau `review_round`; catatan tidak ditafsirkan sebagai keputusan.

| Review legacy ID | Proposal legacy ID | Reviewer mapping | Round | Decision | Keputusan |
|---:|---:|---|---|---|---|
| 1 | 2 | User 3, `biro_hukum` (HIGH) | Tidak tersedia | Tidak tersedia | `SKIP_PROPOSAL_UNMAPPED` |
| 2 | 3 | User 3, `biro_hukum` (HIGH) | Tidak tersedia | Tidak tersedia | `SKIP_PROPOSAL_UNMAPPED` |

**Rekonsiliasi review:** 2 legacy = 0 migrated + 2 skipped + 0 unaccounted.

## Status history dan dokumen

- Status history yang dapat dimigrasikan: **0**. Sumber hanya menyimpan status saat ini; actor dan transisi sebelumnya tidak tersedia. Tidak ada history sintetis yang dibuat.
- Dokumen legacy yang terdeteksi: **0**. Tidak ada file atau storage path yang dibuat.

## Ringkasan dry run

| Data | Source total | Dapat dipetakan | Migrated | Skipped/retained |
|---|---:|---:|---:|---:|
| Users | 3 existing | 3 reuse existing | 0 row baru | 0 |
| Units (`tb_biro`) | 3 | 3 exact match | 0 row baru | 0 |
| Regulation types | 3 nilai distinct | 3 exact match | 0 row baru | 0 |
| Proposals (`tb_rancangan`) | 5 | 0 eligible | 0 | 5 owner unknown |
| Reviews (`tb_reviu`) | 2 | Reviewer 2; parent 0 | 0 | 2 |
| Status histories | 5 current-state rows | 0 historical transitions | 0 | 5 tidak terbukti |
| Documents | 0 source rows | 0 | 0 | 0 |

Confidence mapping:

- **HIGH:** role/user rows existing; unit exact matches; regulation type exact matches; dua FK reviewer.
- **MEDIUM:** tidak ada record yang di-commit.
- **LOW/unknown:** ownership seluruh proposal. Tidak ada ownership ber-confidence rendah yang di-commit.

## Data integrity dan reconciliation

| Tabel | Row count selama dry run |
|---|---:|
| `tb_biro` | 3 |
| `tb_tim` | 3 |
| `tb_staf` | 3 |
| `tb_rancangan` | 5 |
| `tb_reviu` | 2 |

Seluruh tabel target—termasuk `proposals`, `proposal_regulation_types`, `reviews`, `proposal_status_histories`, dan `proposal_documents`—tetap kosong setelah dry run. Master target tetap berisi 3 role, 3 user, 3 unit, dan 3 jenis peraturan. Data legacy tidak dihapus atau diubah.

## Keputusan commit

**BLOCKED — commit tidak dijalankan.** Semua proposal tidak memiliki owner terverifikasi; dua proposal berbagi nomor `4`; review tidak mempunyai keputusan atau putaran terstruktur; history tidak dapat dibuktikan.

Untuk melanjutkan commit, diperlukan:

1. Mapping otoritatif proposal legacy ID ke user ID untuk setiap proposal—bukan tebakan dari nama staf atau urutan data.
2. Keputusan manual untuk dua proposal bernomor `4`, tanpa renumber otomatis.
3. Pemeriksaan nilai judul `0` serta ketidaksesuaian `Draft` dengan `submitted_at` pada proposal ID 4.
4. Keputusan apakah catatan review boleh dimigrasikan tanpa keputusan/putaran, atau tetap hanya di legacy.
5. Kebijakan apakah initial imported state akan dicatat sebagai import event; saat ini tidak dibuat history.

Sampai keputusan dan evidence tersebut tersedia, kelima proposal dan kedua review tetap dipertahankan di tabel legacy.
