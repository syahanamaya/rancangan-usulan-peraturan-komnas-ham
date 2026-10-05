<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    $user = requireUser();
    ensureAccountActive($user, $koneksi);

    $input = requestData();
    $currentPassword = $input['currentPassword'] ?? null;
    $newPassword = $input['newPassword'] ?? null;
    $confirmPassword = $input['confirmPassword'] ?? null;
    if (!is_string($currentPassword) || $currentPassword === ''
        || !is_string($newPassword) || $newPassword === ''
        || !is_string($confirmPassword) || $confirmPassword === '') {
        jsonResponse(['error' => 'Semua field password wajib diisi.'], 422);
    }
    if (mb_strlen($currentPassword) > 4096 || mb_strlen($newPassword) > 4096
        || mb_strlen($confirmPassword) > 4096) {
        jsonResponse(['error' => 'Password tidak boleh melebihi 4096 karakter.'], 422);
    }
    if (mb_strlen($newPassword) < 12) {
        jsonResponse(['error' => 'Password baru harus berisi minimal 12 karakter.'], 422);
    }
    if (!hash_equals($newPassword, $confirmPassword)) {
        jsonResponse(['error' => 'Konfirmasi password baru tidak sama.'], 422);
    }
    if (hash_equals($currentPassword, $newPassword)) {
        jsonResponse(['error' => 'Password baru harus berbeda dari password saat ini.'], 422);
    }

    $select = $koneksi->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $select->bind_param('i', $user['id']);
    $select->execute();
    $account = $select->get_result()->fetch_assoc();
    if (!$account) {
        jsonResponse(['error' => 'Akun tidak ditemukan. Silakan masuk kembali.'], 404);
    }
    $currentHash = (string) $account['password'];
    if (!password_verify($currentPassword, $currentHash)) {
        jsonResponse(['error' => 'Password saat ini salah.'], 422);
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($newHash)) {
        throw new RuntimeException('Unable to hash new account password.');
    }
    $update = $koneksi->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ?');
    $update->bind_param('sis', $newHash, $user['id'], $currentHash);
    $update->execute();
    if ($update->affected_rows !== 1) {
        jsonResponse(['error' => 'Password akun berubah bersamaan. Muat ulang sesi lalu coba kembali.'], 409);
    }

    session_regenerate_id(true);
    jsonResponse(['ok' => true, 'message' => 'Password berhasil diubah.']);
});
