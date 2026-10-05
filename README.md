# Ruang Regulasi Komnas HAM

Aplikasi ini menggunakan React untuk antarmuka, Vite untuk development/build, dan PHP + MySQL untuk API serta sesi login. Proyek tetap dapat dijalankan melalui Laragon/Apache.

## Status sistem yang diterapkan

Aplikasi kini dibuat sesuai workflow usulan rancangan peraturan dengan tiga role utama:

- Pengusul: membuat draft, mengajukan usulan, meninjau revisi, dan melihat riwayat status
- Biro Hukum: meninjau usulan, memberikan catatan, serta menyetujui atau menolak usulan
- Admin: memantau seluruh aktivitas sistem dan usulan di berbagai unit

Workflow status yang tersedia adalah:

- Draft
- Menunggu Review
- Sedang Direview
- Perlu Revisi
- Diajukan Kembali
- Disetujui
- Ditolak

Alur tindakan:

- Pengusul menyimpan formulir dengan **Simpan sebagai Draft** agar status tetap `Draft` dan belum masuk antrean Biro Hukum.
- Pengusul memilih **Ajukan Usulan** untuk mengubah status menjadi `Menunggu Review`.
- Reviewer memulai review, lalu dapat meminta revisi, menyetujui, atau menolak usulan. Catatan wajib untuk keputusan tersebut.
- Setelah diminta revisi, pengusul menyimpan perubahan tanpa mengirimkannya, atau memilih **Ajukan Kembali** setelah revisi siap.
- Setiap review menyimpan reviewer dan putaran yang melakukan tindakan. API memvalidasi transisi status dan mengambil identitas Pengusul/Reviewer dari sesi login.
- Pengusul dapat melampirkan maksimal lima file PDF/DOCX per pengiriman, masing-masing maksimal 10 MB. File disimpan di luar web root pada folder `rancangan-peraturan-storage/proposals` di bawah direktori Laragon; set `PROPOSAL_STORAGE_DIR` untuk menggunakan lokasi privat lain. Unduhan memeriksa kepemilikan usulan dan role pengguna.

## Database target

`database/migration_user_profiles.sql` menambahkan profil `pengusul`, `reviewers`, dan master `biro_hukum`, lalu menghubungkan akun dan workflow target ke profil tersebut. Ini adalah migrasi satu kali untuk tabel target. Sebelum menerapkannya pada salinan lain, pastikan tabel target `proposals` dan `reviews` kosong; migrasi tidak menebak atau memindahkan ownership dari tabel legacy.

**Jangan jalankan** `database/migration_legacy_workflow.sql` atau `database/migration_workflow_detailed.sql` untuk menyiapkan workflow baru. Tabel legacy `tb_*` tetap dipertahankan terpisah dan tidak dipakai oleh endpoint workflow target.

## Demo data

Setelah skema profil target diterapkan, jalankan `php database/seed_target_demo.php` sekali untuk membuat:

- Tiga akun Pengusul: `maya`, `andi`, dan `rina`.
- Tiga akun Reviewer: `sinta`, `budi`, dan `dimas`.
- Lima proposal demo dengan pemilik, jenis, status, catatan review, dan riwayat yang jelas.

Seeder hanya bekerja jika tabel target profil, proposal, review, dan histori masih kosong. Jika `DEMO_ACCOUNT_PASSWORD` tidak disetel, seeder membuat satu password acak untuk keenam akun dan menampilkannya satu kali. Akun lama (`admin`, `pengusul`, `biro_hukum`) tidak otomatis ditautkan ke profil; Admin dapat membuat akun profil baru melalui aplikasi. Login menggunakan `password_hash()` dan `password_verify()`.

## Menjalankan aplikasi

1. Jalankan Apache dan MySQL melalui Laragon. Untuk database target baru, terapkan schema target lalu `database/migration_user_profiles.sql`; untuk database lokal yang sudah diperbarui, jangan jalankan migrasi ulang.
2. Jalankan `php database/seed_target_demo.php` satu kali jika data demo belum tersedia.
3. Sesuaikan alamat server dan kredensial database di `koneksi.php` bila konfigurasi lokal berbeda.
4. Dari folder proyek, jalankan `npm install`, kemudian `npm run build`.
5. Buka folder proyek melalui Apache, misalnya `http://localhost/RancanganPeraturan/`.

Untuk pengembangan UI dengan hot reload, jalankan `npm run dev`. Jika alamat proyek Apache bukan `http://localhost/RancanganPeraturan`, set environment variable `PHP_BASE_URL` ke URL root proyek tersebut sebelum menjalankan Vite.

## Struktur

```text
api/                 Endpoint JSON dan validasi sesi/akses
database/            Schema dan migrasi target, seeder demo
frontend/src/        Aplikasi React, komunikasi API, dan stylesheet
frontend/dist/       Hasil build Vite (dibuat oleh npm run build)
koneksi.php          Koneksi MySQL
index.php            Shell aplikasi untuk Apache
```

## Menyiapkan akun login

Role workflow baru adalah `admin`, `pengusul`, dan `reviewer`. Role lama `biro_hukum` dan `user` tetap dikenali untuk kompatibilitas login, tetapi akun tersebut tanpa tautan profil tidak dapat membuat atau me-review proposal.

Admin membuat akun Pengusul dan Reviewer melalui halaman pengelolaan akun. Tidak ada registrasi publik.
Semua role yang dapat masuk dapat mengganti password sendiri dari menu **Profil**. Password saat ini diverifikasi di server, password baru minimal 12 karakter, dan perubahan dilindungi token CSRF sesi.
