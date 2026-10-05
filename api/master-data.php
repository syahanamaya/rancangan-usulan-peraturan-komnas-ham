<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    $user = requireRole('admin');
    ensureAccountActive($user, $koneksi);
    $type = $_GET['type'] ?? null;
    if (!is_string($type) || !in_array($type, ['biro-hukum', 'jenis-peraturan'], true)) {
        jsonResponse(['error' => 'Jenis master data tidak valid.'], 422);
    }
    $isBureau = $type === 'biro-hukum';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($isBureau) {
            $result = $koneksi->query('SELECT id, nama_biro AS name, kode AS code, status FROM biro_hukum ORDER BY nama_biro');
        } else {
            $result = $koneksi->query(
                "SELECT id, name, NULL AS code, IF(is_active = 1, 'aktif', 'nonaktif') AS status
                 FROM regulation_types ORDER BY name"
            );
        }
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $items[] = $row;
        }
        jsonResponse(['items' => $items]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    $input = requestData();
    $action = $input['action'] ?? null;
    if ($action === 'set_status') {
        $id = positiveId($input['id'] ?? null);
        $status = $input['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['aktif', 'nonaktif'], true)) {
            jsonResponse(['error' => 'Status tidak valid.'], 422);
        }
        if ($isBureau) {
            $statement = $koneksi->prepare('UPDATE biro_hukum SET status = ? WHERE id = ?');
            $statement->bind_param('si', $status, $id);
        } else {
            $isActive = $status === 'aktif' ? 1 : 0;
            $statement = $koneksi->prepare('UPDATE regulation_types SET is_active = ? WHERE id = ?');
            $statement->bind_param('ii', $isActive, $id);
        }
        $statement->execute();
        if ($statement->affected_rows === 0) {
            $table = $isBureau ? 'biro_hukum' : 'regulation_types';
            $exists = $koneksi->prepare('SELECT id FROM ' . $table . ' WHERE id = ?');
            $exists->bind_param('i', $id);
            $exists->execute();
            if (!$exists->get_result()->fetch_assoc()) {
                jsonResponse(['error' => 'Data master tidak ditemukan.'], 404);
            }
        }
        jsonResponse(['ok' => true]);
    }
    if ($action !== 'create') {
        jsonResponse(['error' => 'Tindakan master data tidak valid.'], 422);
    }

    $name = requiredText($input['name'] ?? null, 'Nama', 255);
    if ($isBureau) {
        $code = requiredText($input['code'] ?? null, 'Kode', 40);
        $duplicate = $koneksi->prepare('SELECT id FROM biro_hukum WHERE nama_biro = ? OR kode = ? LIMIT 1');
        $duplicate->bind_param('ss', $name, $code);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            jsonResponse(['error' => 'Nama atau kode Biro Hukum sudah digunakan.'], 409);
        }
        $statement = $koneksi->prepare('INSERT INTO biro_hukum (nama_biro, kode) VALUES (?, ?)');
        $statement->bind_param('ss', $name, $code);
    } else {
        $duplicate = $koneksi->prepare('SELECT id FROM regulation_types WHERE name = ? LIMIT 1');
        $duplicate->bind_param('s', $name);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            jsonResponse(['error' => 'Jenis peraturan sudah terdaftar.'], 409);
        }
        $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
        $statement = $koneksi->prepare('INSERT INTO regulation_types (name, description, is_active) VALUES (?, ?, 1)');
        $statement->bind_param('ss', $name, $description);
    }
    $statement->execute();
    jsonResponse(['ok' => true, 'id' => (int) $koneksi->insert_id], 201);
});
