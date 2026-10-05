<?php
declare(strict_types=1);

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function apiRun(callable $handler): void
{
    header('Content-Type: application/json; charset=utf-8');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();

    try {
        $handler();
    } catch (mysqli_sql_exception $exception) {
        error_log('Database request failed: ' . $exception->getMessage());
        jsonResponse(['error' => 'Terjadi kesalahan saat mengakses database.'], 500);
    } catch (Throwable $exception) {
        error_log('API request failed: ' . $exception->getMessage());
        jsonResponse(['error' => 'Terjadi kesalahan pada server.'], 500);
    }
}

function proposalStorageDirectory(): string
{
    $configured = getenv('PROPOSAL_STORAGE_DIR');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, DIRECTORY_SEPARATOR);
    }
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'rancangan-peraturan-storage'
        . DIRECTORY_SEPARATOR . 'proposals';
}

function proposalDocumentPath(string $storageKey): ?string
{
    if (!preg_match('/\A[a-f0-9]{32}\.(?:pdf|docx)\z/D', $storageKey)) {
        return null;
    }
    return proposalStorageDirectory() . DIRECTORY_SEPARATOR . $storageKey;
}

function requestData(): array
{
    $input = file_get_contents('php://input');
    $data = json_decode($input === false ? '' : $input, true);
    return is_array($data) ? $data : [];
}

function requireUser(): array
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['username']) || empty($_SESSION['role'])) {
        jsonResponse(['error' => 'Silakan masuk terlebih dahulu.'], 401);
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) $_SESSION['username'],
        'role' => (string) $_SESSION['role'],
        'name' => (string) ($_SESSION['name'] ?? $_SESSION['username']),
        'proposerId' => isset($_SESSION['proposer_id']) ? (int) $_SESSION['proposer_id'] : null,
        'reviewerId' => isset($_SESSION['reviewer_id']) ? (int) $_SESSION['reviewer_id'] : null,
        'unitId' => isset($_SESSION['unit_id']) ? (int) $_SESSION['unit_id'] : null,
        'unitName' => isset($_SESSION['unit_name']) ? (string) $_SESSION['unit_name'] : null,
        'position' => isset($_SESSION['position']) ? (string) $_SESSION['position'] : null,
    ];
}

function ensureAccountActive(array $user, mysqli $koneksi): void
{
    $statement = $koneksi->prepare(
        'SELECT u.is_active, u.role, u.pengusul_id, u.reviewer_id,
                p.status AS proposer_status,
                rv.status AS reviewer_status, bh.status AS bureau_status
         FROM users u
         LEFT JOIN pengusul p ON p.id = u.pengusul_id
         LEFT JOIN reviewers rv ON rv.id = u.reviewer_id
         LEFT JOIN biro_hukum bh ON bh.id = rv.biro_hukum_id
         WHERE u.id = ? LIMIT 1'
    );
    $statement->bind_param('i', $user['id']);
    $statement->execute();
    $account = $statement->get_result()->fetch_assoc();
    $role = match ($account['role'] ?? null) {
        'user' => 'pengusul',
        'biro_hukum' => 'reviewer',
        default => $account['role'] ?? null,
    };
    $active = $account && (int) $account['is_active'] === 1 && $role === $user['role'];
    if ($user['role'] === 'pengusul') {
        if ($user['proposerId'] === null) {
            $active = $active && $account['pengusul_id'] === null;
        } else {
            $active = $active
                && (int) $account['pengusul_id'] === $user['proposerId']
                && $account['proposer_status'] === 'aktif';
        }
    } elseif ($user['role'] === 'reviewer') {
        if ($user['reviewerId'] === null) {
            $active = $active && $account['reviewer_id'] === null;
        } else {
            $active = $active
                && (int) $account['reviewer_id'] === $user['reviewerId']
                && $account['reviewer_status'] === 'aktif'
                && $account['bureau_status'] === 'aktif';
        }
    }
    if (!$active) {
        jsonResponse(['error' => 'Akun atau profil tidak aktif. Silakan masuk kembali atau hubungi Admin.'], 401);
    }
}

function requireAnyRole(array $roles): array
{
    $user = requireUser();
    $normalized = match ($user['role']) {
        'user' => 'pengusul',
        'biro_hukum' => 'reviewer',
        default => $user['role'],
    };
    $allowed = array_map(static fn (string $role): string => match ($role) {
        'user' => 'pengusul',
        'biro_hukum' => 'reviewer',
        default => $role,
    }, $roles);

    if (!in_array($normalized, $allowed, true)) {
        jsonResponse(['error' => 'Anda tidak memiliki akses untuk tindakan ini.'], 403);
    }

    return $user;
}

function requireRole(string $role): array
{
    return requireAnyRole([$role]);
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(['error' => 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.'], 403);
    }
}

function positiveId(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        jsonResponse(['error' => 'ID data tidak valid.'], 422);
    }
    return $id;
}

function optionalPositiveId(mixed $value, string $field): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        jsonResponse(['error' => $field . ' yang dipilih tidak valid.'], 422);
    }
    return $id;
}

function requiredText(mixed $value, string $field, int $maxLength = 10000): string
{
    if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maxLength) {
        jsonResponse(['error' => $field . ' wajib diisi dan tidak boleh melebihi ' . $maxLength . ' karakter.'], 422);
    }
    return trim($value);
}
