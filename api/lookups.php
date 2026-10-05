<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    require __DIR__ . '/../koneksi.php';
    $user = requireUser();
    ensureAccountActive($user, $koneksi);

    $units = [];
    $result = $koneksi->query('SELECT id, name FROM units WHERE is_active = 1 ORDER BY name');
    while ($row = $result->fetch_assoc()) {
        $units[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    $regulationTypes = [];
    $result = $koneksi->query('SELECT id, name FROM regulation_types WHERE is_active = 1 ORDER BY name');
    while ($row = $result->fetch_assoc()) {
        $regulationTypes[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    $bureaus = [];
    $result = $koneksi->query("SELECT id, nama_biro AS name FROM biro_hukum WHERE status = 'aktif' ORDER BY nama_biro");
    while ($row = $result->fetch_assoc()) {
        $bureaus[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    jsonResponse([
        'units' => $units,
        'regulationTypes' => $regulationTypes,
        'biroHukum' => $bureaus,
        'profile' => [
            'name' => $user['name'],
            'role' => $user['role'],
            'unitId' => $user['unitId'],
            'unitName' => $user['unitName'],
            'position' => $user['position'],
        ],
    ]);
});
