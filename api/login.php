<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    $input = requestData();
    $username = requiredText($input['username'] ?? null, 'Username', 100);
    $password = $input['password'] ?? null;
    if (!is_string($password) || $password === '' || mb_strlen($password) > 4096) {
        jsonResponse(['error' => 'Password wajib diisi dan tidak boleh melebihi 4096 karakter.'], 422);
    }

    $statement = $koneksi->prepare(
        'SELECT u.id, u.username, u.password, u.role, u.name, u.position, u.unit_id, u.pengusul_id, u.reviewer_id, u.is_active,
                p.nama AS proposer_name, p.jabatan AS proposer_position, p.unit_id AS proposer_unit_id,
                pu.name AS proposer_unit_name, p.status AS proposer_status,
                rv.nama AS reviewer_name, rv.jabatan AS reviewer_position, rv.status AS reviewer_status,
                bh.nama_biro AS reviewer_bureau_name
         FROM users u
         LEFT JOIN pengusul p ON p.id = u.pengusul_id
         LEFT JOIN units pu ON pu.id = p.unit_id
         LEFT JOIN reviewers rv ON rv.id = u.reviewer_id
         LEFT JOIN biro_hukum bh ON bh.id = rv.biro_hukum_id
         WHERE u.username = ? LIMIT 1'
    );
    $statement->bind_param('s', $username);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();

    $storedPassword = is_array($user) ? (string) $user['password'] : '';
    $passwordMatches = $storedPassword !== '' && password_verify($password, $storedPassword);
    $rehashPassword = $passwordMatches && password_needs_rehash($storedPassword, PASSWORD_DEFAULT);

    if (!$user || !$passwordMatches || !(int) $user['is_active']
        || !in_array($user['role'], ['admin', 'pengusul', 'reviewer', 'biro_hukum', 'user'], true)) {
        jsonResponse(['error' => 'Username atau password salah.'], 401);
    }

    $role = match ($user['role']) {
        'user' => 'pengusul',
        'biro_hukum' => 'reviewer',
        default => (string) $user['role'],
    };
    $profileId = $role === 'pengusul' ? $user['pengusul_id'] : ($role === 'reviewer' ? $user['reviewer_id'] : null);
    if ($role === 'pengusul' && $user['pengusul_id'] !== null && $user['proposer_status'] !== 'aktif') {
        jsonResponse(['error' => 'Profil Pengusul tidak aktif. Hubungi Admin.'], 403);
    }
    if ($role === 'reviewer' && $user['reviewer_id'] !== null && $user['reviewer_status'] !== 'aktif') {
        jsonResponse(['error' => 'Profil Reviewer tidak aktif. Hubungi Admin.'], 403);
    }

    if ($rehashPassword) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $update = $koneksi->prepare('UPDATE users SET password = ? WHERE username = ? AND password = ?');
        $update->bind_param('sss', $newHash, $user['username'], $storedPassword);
        $update->execute();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = $role;
    $_SESSION['name'] = (string) ($role === 'pengusul'
        ? ($user['proposer_name'] ?: $user['name'])
        : ($role === 'reviewer' ? ($user['reviewer_name'] ?: $user['name']) : $user['name']));
    $_SESSION['proposer_id'] = $role === 'pengusul' && $profileId !== null ? (int) $profileId : null;
    $_SESSION['reviewer_id'] = $role === 'reviewer' && $profileId !== null ? (int) $profileId : null;
    $_SESSION['unit_id'] = $role === 'pengusul'
        ? ($user['proposer_unit_id'] === null ? null : (int) $user['proposer_unit_id'])
        : ($user['unit_id'] === null ? null : (int) $user['unit_id']);
    $_SESSION['unit_name'] = $role === 'pengusul' ? $user['proposer_unit_name'] : $user['reviewer_bureau_name'];
    $_SESSION['position'] = $role === 'pengusul'
        ? (string) ($user['proposer_position'] ?? '')
        : ($role === 'reviewer' ? (string) ($user['reviewer_position'] ?? '') : (string) ($user['position'] ?? ''));
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    jsonResponse([
        'user' => [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'name' => $_SESSION['name'],
            'proposerId' => $_SESSION['proposer_id'],
            'reviewerId' => $_SESSION['reviewer_id'],
            'unitId' => $_SESSION['unit_id'],
            'unitName' => $_SESSION['unit_name'],
            'position' => $_SESSION['position'],
        ],
        'csrfToken' => $_SESSION['csrf_token'],
    ]);
});
