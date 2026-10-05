<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../koneksi.php';

$password = getenv('DEMO_ACCOUNT_PASSWORD');
$generatedPassword = false;
if (!is_string($password) || $password === '') {
    $password = bin2hex(random_bytes(12));
    $generatedPassword = true;
}
if (strlen($password) < 12) {
    fwrite(STDERR, "DEMO_ACCOUNT_PASSWORD must contain at least 12 characters.\n");
    exit(2);
}

foreach (['pengusul', 'reviewers', 'proposals', 'reviews', 'proposal_status_histories'] as $table) {
    $count = $koneksi->query('SELECT COUNT(*) AS total FROM `' . $table . '`')->fetch_assoc();
    if ((int) $count['total'] !== 0) {
        fwrite(STDERR, "Demo seed stopped: $table is not empty; no demo rows were added.\n");
        exit(2);
    }
}

$proposalTypes = [];
$typeResult = $koneksi->query('SELECT id, name FROM regulation_types WHERE is_active = 1');
while ($type = $typeResult->fetch_assoc()) {
    $proposalTypes[(string) $type['name']] = (int) $type['id'];
}
foreach (['Peraturan Pemerintah', 'Peraturan Presiden', 'Peraturan Daerah'] as $typeName) {
    if (!isset($proposalTypes[$typeName])) {
        fwrite(STDERR, "Demo seed stopped: target regulation type '$typeName' is missing.\n");
        exit(2);
    }
}

$roleIds = [];
$roleResult = $koneksi->query("SELECT id, name FROM roles WHERE name IN ('pengusul', 'reviewer')");
while ($role = $roleResult->fetch_assoc()) {
    $roleIds[(string) $role['name']] = (int) $role['id'];
}
if (!isset($roleIds['pengusul'], $roleIds['reviewer'])) {
    fwrite(STDERR, "Demo seed stopped: target roles are incomplete.\n");
    exit(2);
}

$koneksi->begin_transaction();
try {
    $unitIds = [];
    foreach ([['Biro A', 'DEMO-BA'], ['Biro B', 'DEMO-BB']] as [$unitName, $unitCode]) {
        $unitQuery = $koneksi->prepare('SELECT id FROM units WHERE name = ? LIMIT 1');
        $unitQuery->bind_param('s', $unitName);
        $unitQuery->execute();
        $unit = $unitQuery->get_result()->fetch_assoc();
        if ($unit) {
            $unitIds[$unitName] = (int) $unit['id'];
            continue;
        }
        $insertUnit = $koneksi->prepare(
            'INSERT INTO units (name, code, description, is_active) VALUES (?, ?, ?, 1)'
        );
        $description = 'Unit contoh untuk akun demo portofolio.';
        $insertUnit->bind_param('sss', $unitName, $unitCode, $description);
        $insertUnit->execute();
        $unitIds[$unitName] = (int) $koneksi->insert_id;
    }

    $bureauName = 'Biro Hukum';
    $bureauCode = 'BH';
    $bureauQuery = $koneksi->prepare('SELECT id FROM biro_hukum WHERE kode = ? LIMIT 1');
    $bureauQuery->bind_param('s', $bureauCode);
    $bureauQuery->execute();
    $bureau = $bureauQuery->get_result()->fetch_assoc();
    if ($bureau) {
        $bureauId = (int) $bureau['id'];
    } else {
        $insertBureau = $koneksi->prepare('INSERT INTO biro_hukum (nama_biro, kode) VALUES (?, ?)');
        $insertBureau->bind_param('ss', $bureauName, $bureauCode);
        $insertBureau->execute();
        $bureauId = (int) $koneksi->insert_id;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $accountIds = [];
    $proposerIds = [];
    $proposers = [
        ['Maya', 'Biro A', 'Analis', 'maya.demo@example.test', 'maya'],
        ['Andi', 'Biro B', 'Staf', 'andi.demo@example.test', 'andi'],
        ['Rina', 'Biro A', 'Perancang', 'rina.demo@example.test', 'rina'],
    ];
    foreach ($proposers as [$name, $unitName, $position, $email, $username]) {
        $insertProfile = $koneksi->prepare(
            "INSERT INTO pengusul (nama, unit_id, jabatan, email, status) VALUES (?, ?, ?, ?, 'aktif')"
        );
        $unitId = $unitIds[$unitName];
        $insertProfile->bind_param('siss', $name, $unitId, $position, $email);
        $insertProfile->execute();
        $profileId = (int) $koneksi->insert_id;
        $proposerIds[$username] = $profileId;

        $roleId = $roleIds['pengusul'];
        $roleName = 'pengusul';
        $active = 1;
        $insertAccount = $koneksi->prepare(
            'INSERT INTO users (role_id, unit_id, pengusul_id, name, username, email, password, position, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertAccount->bind_param(
            'iiissssssi',
            $roleId,
            $unitId,
            $profileId,
            $name,
            $username,
            $email,
            $passwordHash,
            $position,
            $roleName,
            $active
        );
        $insertAccount->execute();
        $accountIds[$username] = (int) $koneksi->insert_id;
    }

    $reviewerIds = [];
    $reviewers = [
        ['Sinta', 'Perancang Peraturan', 'sinta.demo@example.test', 'sinta'],
        ['Budi', 'Analis Hukum', 'budi.demo@example.test', 'budi'],
        ['Dimas', 'Staf', 'dimas.demo@example.test', 'dimas'],
    ];
    foreach ($reviewers as [$name, $position, $email, $username]) {
        $insertProfile = $koneksi->prepare(
            "INSERT INTO reviewers (nama, biro_hukum_id, jabatan, email, status) VALUES (?, ?, ?, ?, 'aktif')"
        );
        $insertProfile->bind_param('siss', $name, $bureauId, $position, $email);
        $insertProfile->execute();
        $profileId = (int) $koneksi->insert_id;
        $reviewerIds[$username] = $profileId;

        $roleId = $roleIds['reviewer'];
        $roleName = 'reviewer';
        $active = 1;
        $insertAccount = $koneksi->prepare(
            'INSERT INTO users (role_id, name, username, email, password, position, role, is_active, reviewer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertAccount->bind_param(
            'issssssii',
            $roleId,
            $name,
            $username,
            $email,
            $passwordHash,
            $position,
            $roleName,
            $active,
            $profileId
        );
        $insertAccount->execute();
        $accountIds[$username] = (int) $koneksi->insert_id;
    }

    $proposals = [
        [
            'USL-2026-001', 'Rancangan Pedoman Pengelolaan Usulan', 'Maya',
            'Peraturan Pemerintah', 'Menyusun pedoman yang memastikan usulan tercatat dan ditangani secara konsisten.',
            'Pedoman pengelolaan usulan internal.', 'Disetujui', '2026-09-18 09:00:00',
            'Sinta', 'Dokumen telah lengkap dan dapat dilanjutkan.',
        ],
        [
            'USL-2026-002', 'Rancangan Tata Cara Review Dokumen', 'Maya',
            'Peraturan Presiden', 'Menetapkan tata cara pemeriksaan dokumen yang transparan dan terukur.',
            'Usulan menunggu pemeriksaan Biro Hukum.', 'Menunggu Review', '2026-09-24 10:00:00',
            null, null,
        ],
        [
            'USL-2026-003', 'Rancangan Standarisasi Arsip Usulan', 'Andi',
            'Peraturan Daerah', 'Meningkatkan keteraturan penyimpanan dokumen usulan.',
            'Usulan sedang ditinjau oleh reviewer.', 'Sedang Direview', '2026-09-27 11:00:00',
            'Budi', 'Review awal sedang berlangsung.',
        ],
        [
            'USL-2026-004', 'Rancangan Pedoman Dokumentasi Peraturan', 'Rina',
            'Peraturan Pemerintah', 'Menetapkan standar dokumentasi untuk setiap tahap penyusunan peraturan.',
            'Mohon lengkapi ruang lingkup dan dasar hukum usulan.', 'Perlu Revisi', '2026-09-29 13:00:00',
            'Dimas', 'Mohon lengkapi ruang lingkup dan dasar hukum usulan.',
        ],
        [
            'USL-2026-005', 'Rancangan Tata Kelola Pengajuan Peraturan', 'Maya',
            'Peraturan Presiden', 'Mengatur alur pengajuan rancangan peraturan di lingkungan unit kerja.',
            'Draft awal untuk dibahas bersama unit terkait.', 'Draft', null, null, null,
        ],
    ];

    foreach ($proposals as $index => [$number, $title, $owner, $typeName, $background, $description, $status, $submittedAt, $reviewerUsername, $reviewNote]) {
        $date = new DateTimeImmutable('2026-09-18 09:00:00');
        $date = $date->modify('+' . $index . ' days');
        $proposerUsername = strtolower($owner);
        $proposerId = $proposerIds[$proposerUsername];
        $accountId = $accountIds[$proposerUsername];
        $unitId = $unitIds[$owner === 'Andi' ? 'Biro B' : 'Biro A'];
        $typeId = $proposalTypes[$typeName];
        $legalBasis = 'Kajian dan ketentuan terkait penyusunan peraturan.';
        $objective = 'Mendukung tata kelola usulan yang efektif dan terdokumentasi.';
        $createdAt = $date->format('Y-m-d H:i:s');
        $insertProposal = $koneksi->prepare(
            'INSERT INTO proposals (proposal_number, title, unit_id, proposer_id, regulation_type_id,
             background, legal_basis, objective, description, status, submitted_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertProposal->bind_param(
            'ssiiisssssss',
            $number,
            $title,
            $unitId,
            $proposerId,
            $typeId,
            $background,
            $legalBasis,
            $objective,
            $description,
            $status,
            $submittedAt,
            $createdAt
        );
        $insertProposal->execute();
        $proposalId = (int) $koneksi->insert_id;

        $insertPivot = $koneksi->prepare(
            'INSERT INTO proposal_regulation_types (proposal_id, regulation_type_id) VALUES (?, ?)'
        );
        $insertPivot->bind_param('ii', $proposalId, $typeId);
        $insertPivot->execute();

        $insertHistory = $koneksi->prepare(
            'INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $previous = null;
        $events = [['Draft', 'Usulan disimpan sebagai draft.']];
        if ($status !== 'Draft') {
            $events[] = ['Menunggu Review', 'Usulan diajukan untuk ditinjau.'];
        }
        if (in_array($status, ['Sedang Direview', 'Perlu Revisi', 'Disetujui'], true)) {
            $events[] = ['Sedang Direview', 'Reviewer mulai melakukan pemeriksaan.'];
        }
        if (in_array($status, ['Perlu Revisi', 'Disetujui'], true)) {
            $events[] = [$status, $reviewNote];
        }
        foreach ($events as $eventIndex => [$eventStatus, $eventNote]) {
            $actorId = $accountId;
            if ($eventStatus === 'Sedang Direview' || in_array($eventStatus, ['Perlu Revisi', 'Disetujui'], true)) {
                if ($reviewerUsername !== null) {
                    $actorId = $accountIds[strtolower($reviewerUsername)];
                }
            }
            $eventDate = $date->modify('+' . ($eventIndex * 3) . ' hours')->format('Y-m-d H:i:s');
            $insertHistory->bind_param(
                'iissss',
                $proposalId,
                $actorId,
                $previous,
                $eventStatus,
                $eventNote,
                $eventDate
            );
            $insertHistory->execute();
            $previous = $eventStatus;
        }
        $proposalUpdatedAt = $date->modify('+' . ((count($events) - 1) * 3) . ' hours')->format('Y-m-d H:i:s');
        $updateProposalDate = $koneksi->prepare('UPDATE proposals SET updated_at = ? WHERE id = ?');
        $updateProposalDate->bind_param('si', $proposalUpdatedAt, $proposalId);
        $updateProposalDate->execute();

        if ($reviewerUsername !== null) {
            $reviewerId = $reviewerIds[strtolower($reviewerUsername)];
            $decision = $status === 'Sedang Direview' ? 'Sedang Direview' : $status;
            $round = 1;
            $reviewDate = $proposalUpdatedAt;
            $insertReview = $koneksi->prepare(
                'INSERT INTO reviews (proposal_id, reviewer_id, review_round, decision, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insertReview->bind_param(
                'iiissss',
                $proposalId,
                $reviewerId,
                $round,
                $decision,
                $reviewNote,
                $reviewDate,
                $reviewDate
            );
            $insertReview->execute();
        }
    }

    $koneksi->commit();
} catch (Throwable $exception) {
    $koneksi->rollback();
    fwrite(STDERR, "Demo seed failed; target inserts rolled back: " . $exception->getMessage() . "\n");
    exit(1);
}

echo "Demo target profiles, accounts, proposals, reviews, and status history created.\n";
if ($generatedPassword) {
    echo "One-time initial password for all six demo accounts: $password\n";
} else {
    echo "All six demo accounts use the DEMO_ACCOUNT_PASSWORD environment variable.\n";
}
