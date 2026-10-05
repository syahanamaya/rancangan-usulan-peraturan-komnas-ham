<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    if (empty($_SESSION['user_id'])) {
        unset(
            $_SESSION['user_id'],
            $_SESSION['username'],
            $_SESSION['role'],
            $_SESSION['name'],
            $_SESSION['proposer_id'],
            $_SESSION['reviewer_id'],
            $_SESSION['unit_id'],
            $_SESSION['unit_name'],
            $_SESSION['position']
        );
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        jsonResponse(['user' => null, 'csrfToken' => $_SESSION['csrf_token']]);
    }

    $accountId = (int) $_SESSION['user_id'];
    $statement = $koneksi->prepare(
        'SELECT u.is_active, u.role, u.pengusul_id, u.reviewer_id,
                p.status AS proposer_status, rv.status AS reviewer_status,
                bh.status AS bureau_status
         FROM users u
         LEFT JOIN pengusul p ON p.id = u.pengusul_id
         LEFT JOIN reviewers rv ON rv.id = u.reviewer_id
         LEFT JOIN biro_hukum bh ON bh.id = rv.biro_hukum_id
         WHERE u.id = ? LIMIT 1'
    );
    $statement->bind_param('i', $accountId);
    $statement->execute();
    $account = $statement->get_result()->fetch_assoc();
    $role = (string) ($_SESSION['role'] ?? '');
    $databaseRole = match ($account['role'] ?? null) {
        'user' => 'pengusul',
        'biro_hukum' => 'reviewer',
        default => $account['role'] ?? null,
    };
    $profileActive = $role === 'pengusul'
        ? (($account['pengusul_id'] ?? null) === null
            ? ($_SESSION['proposer_id'] ?? null) === null
            : (int) $account['pengusul_id'] === (int) ($_SESSION['proposer_id'] ?? 0) && $account['proposer_status'] === 'aktif')
        : ($role === 'reviewer'
            ? (($account['reviewer_id'] ?? null) === null
                ? ($_SESSION['reviewer_id'] ?? null) === null
                : (int) $account['reviewer_id'] === (int) ($_SESSION['reviewer_id'] ?? 0) && $account['reviewer_status'] === 'aktif' && $account['bureau_status'] === 'aktif')
            : true);

    if (!$account || !(int) $account['is_active'] || $databaseRole !== $role || !$profileActive) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        jsonResponse(['user' => null, 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    jsonResponse([
        'user' => [
            'id' => $accountId,
            'username' => (string) $_SESSION['username'],
            'role' => $role,
            'name' => (string) ($_SESSION['name'] ?? $_SESSION['username']),
            'proposerId' => $_SESSION['proposer_id'] ?? null,
            'reviewerId' => $_SESSION['reviewer_id'] ?? null,
            'unitId' => $_SESSION['unit_id'] ?? null,
            'unitName' => $_SESSION['unit_name'] ?? null,
            'position' => $_SESSION['position'] ?? null,
        ],
        'csrfToken' => $_SESSION['csrf_token'],
    ]);
});
