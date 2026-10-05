<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    $user = requireRole('admin');
    ensureAccountActive($user, $koneksi);

    $type = $_GET['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['pengusul', 'reviewers'], true)) {
        jsonResponse(['error' => 'Jenis akun tidak valid.'], 422);
    }
    $isProposer = $type === 'pengusul';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($isProposer) {
            $result = $koneksi->query(
                'SELECT p.id, p.nama AS name, p.unit_id AS unitId, un.name AS unitName,
                        p.jabatan AS position, p.email, p.no_hp AS phone, p.status,
                        u.username, u.is_active AS accountActive
                 FROM pengusul p
                 JOIN units un ON un.id = p.unit_id
                 LEFT JOIN users u ON u.pengusul_id = p.id
                 ORDER BY p.nama'
            );
        } else {
            $result = $koneksi->query(
                'SELECT rv.id, rv.nama AS name, rv.biro_hukum_id AS bureauId,
                        bh.nama_biro AS bureauName, rv.jabatan AS position, rv.email,
                        rv.no_hp AS phone, rv.status, u.username, u.is_active AS accountActive
                 FROM reviewers rv
                 JOIN biro_hukum bh ON bh.id = rv.biro_hukum_id
                 LEFT JOIN users u ON u.reviewer_id = rv.id
                 ORDER BY rv.nama'
            );
        }
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            if (isset($row['unitId'])) {
                $row['unitId'] = (int) $row['unitId'];
            }
            if (isset($row['bureauId'])) {
                $row['bureauId'] = (int) $row['bureauId'];
            }
            $row['accountActive'] = $row['accountActive'] === null ? null : (bool) $row['accountActive'];
            $items[] = $row;
        }
        jsonResponse(['items' => $items]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    $input = requestData();
    $action = $input['action'] ?? '';
    if ($action === 'set_status') {
        $profileId = positiveId($input['profileId'] ?? null);
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['aktif', 'nonaktif'], true)) {
            jsonResponse(['error' => 'Status profil tidak valid.'], 422);
        }
        $table = $isProposer ? 'pengusul' : 'reviewers';
        $profileColumn = $isProposer ? 'pengusul_id' : 'reviewer_id';
        $active = $status === 'aktif' ? 1 : 0;
        $koneksi->begin_transaction();
        try {
            $profileUpdate = $koneksi->prepare('UPDATE ' . $table . ' SET status = ? WHERE id = ?');
            $profileUpdate->bind_param('si', $status, $profileId);
            $profileUpdate->execute();
            if ($profileUpdate->affected_rows === 0) {
                $exists = $koneksi->prepare('SELECT id FROM ' . $table . ' WHERE id = ?');
                $exists->bind_param('i', $profileId);
                $exists->execute();
                if (!$exists->get_result()->fetch_assoc()) {
                    $koneksi->rollback();
                    jsonResponse(['error' => 'Profil tidak ditemukan.'], 404);
                }
            }
            $accountUpdate = $koneksi->prepare('UPDATE users SET is_active = ? WHERE ' . $profileColumn . ' = ?');
            $accountUpdate->bind_param('ii', $active, $profileId);
            $accountUpdate->execute();
            $koneksi->commit();
        } catch (Throwable $exception) {
            $koneksi->rollback();
            throw $exception;
        }
        jsonResponse(['ok' => true]);
    }

    if ($action !== 'create') {
        jsonResponse(['error' => 'Tindakan akun tidak valid.'], 422);
    }

    $profile = is_array($input['profile'] ?? null) ? $input['profile'] : [];
    $account = is_array($input['account'] ?? null) ? $input['account'] : [];
    $name = requiredText($profile['name'] ?? null, 'Nama lengkap', 255);
    $position = is_string($profile['position'] ?? null) ? trim($profile['position']) : '';
    $emailInput = is_string($profile['email'] ?? null) ? trim($profile['email']) : '';
    $phone = is_string($profile['phone'] ?? null) ? trim($profile['phone']) : '';
    if (mb_strlen($position) > 255 || mb_strlen($emailInput) > 255 || mb_strlen($phone) > 40
        || ($emailInput !== '' && filter_var($emailInput, FILTER_VALIDATE_EMAIL) === false)) {
        jsonResponse(['error' => 'Jabatan, email, atau nomor HP tidak valid.'], 422);
    }
    $email = $emailInput !== '' ? $emailInput : null;
    $username = requiredText($account['username'] ?? null, 'Username', 100);
    $password = $account['password'] ?? null;
    if (!is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 4096) {
        jsonResponse(['error' => 'Password wajib berisi minimal 12 karakter.'], 422);
    }
    $status = $profile['status'] ?? 'aktif';
    if (!is_string($status) || !in_array($status, ['aktif', 'nonaktif'], true)) {
        jsonResponse(['error' => 'Status profil tidak valid.'], 422);
    }
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $isActive = $status === 'aktif' ? 1 : 0;

    if ($isProposer) {
        $unitId = positiveId($profile['unitId'] ?? null);
        $unitCheck = $koneksi->prepare('SELECT id FROM units WHERE id = ? AND is_active = 1');
        $unitCheck->bind_param('i', $unitId);
        $unitCheck->execute();
        if (!$unitCheck->get_result()->fetch_assoc()) {
            jsonResponse(['error' => 'Unit yang dipilih tidak ditemukan atau tidak aktif.'], 422);
        }
        $roleName = 'pengusul';
    } else {
        $bureauId = positiveId($profile['bureauId'] ?? null);
        $bureauCheck = $koneksi->prepare("SELECT id FROM biro_hukum WHERE id = ? AND status = 'aktif'");
        $bureauCheck->bind_param('i', $bureauId);
        $bureauCheck->execute();
        if (!$bureauCheck->get_result()->fetch_assoc()) {
            jsonResponse(['error' => 'Biro Hukum yang dipilih tidak ditemukan atau tidak aktif.'], 422);
        }
        $roleName = 'reviewer';
        $unitId = null;
    }

    $roleQuery = $koneksi->prepare('SELECT id FROM roles WHERE name = ?');
    $roleQuery->bind_param('s', $roleName);
    $roleQuery->execute();
    $roleRecord = $roleQuery->get_result()->fetch_assoc();
    if (!$roleRecord) {
        jsonResponse(['error' => 'Role akun belum tersedia pada master role.'], 500);
    }
    $roleId = (int) $roleRecord['id'];

    $koneksi->begin_transaction();
    try {
        if ($isProposer) {
            $insertProfile = $koneksi->prepare(
                'INSERT INTO pengusul (nama, unit_id, jabatan, email, no_hp, status) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertProfile->bind_param('sissss', $name, $unitId, $position, $email, $phone, $status);
        } else {
            $insertProfile = $koneksi->prepare(
                'INSERT INTO reviewers (nama, biro_hukum_id, jabatan, email, no_hp, status) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insertProfile->bind_param('sissss', $name, $bureauId, $position, $email, $phone, $status);
        }
        $insertProfile->execute();
        $profileId = (int) $koneksi->insert_id;
        $proposerId = $isProposer ? $profileId : null;
        $reviewerId = $isProposer ? null : $profileId;
        $insertAccount = $koneksi->prepare(
            'INSERT INTO users (role_id, unit_id, name, username, email, password, position, role, is_active, pengusul_id, reviewer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertAccount->bind_param(
            'iissssssiii',
            $roleId,
            $unitId,
            $name,
            $username,
            $email,
            $hashedPassword,
            $position,
            $roleName,
            $isActive,
            $proposerId,
            $reviewerId
        );
        $insertAccount->execute();
        $koneksi->commit();
    } catch (Throwable $exception) {
        $koneksi->rollback();
        throw $exception;
    }

    jsonResponse(['ok' => true, 'profileId' => $profileId], 201);
});
