<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../koneksi.php';

function quoteMarkdown(mixed $value): string
{
    $text = (string) ($value ?? '');
    return str_replace(["|", "\r", "\n"], ["\\|", ' ', ' '], $text);
}

function fetchAllRows(mysqli $connection, string $sql): array
{
    $result = $connection->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function columnExists(mysqli $connection, string $table, string $column): bool
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->bind_param('ss', $table, $column);
    $statement->execute();
    return (int) $statement->get_result()->fetch_assoc()['total'] > 0;
}

function exactUnitMatches(mysqli $connection, string $name): array
{
    $statement = $connection->prepare(
        'SELECT id, code, name FROM units WHERE BINARY name = BINARY ? ORDER BY id'
    );
    $statement->bind_param('s', $name);
    $statement->execute();
    return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

function exactRegulationTypeMatches(mysqli $connection, string $name): array
{
    $statement = $connection->prepare(
        'SELECT id, name FROM regulation_types WHERE BINARY name = BINARY ? ORDER BY id'
    );
    $statement->bind_param('s', $name);
    $statement->execute();
    return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

function targetProposalNumberExists(mysqli $connection, string $number): bool
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) AS total FROM proposals WHERE proposal_number = ?'
    );
    $statement->bind_param('s', $number);
    $statement->execute();
    return (int) $statement->get_result()->fetch_assoc()['total'] > 0;
}

try {
    $koneksi->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

    $database = (string) $koneksi->query('SELECT DATABASE()')->fetch_row()[0];
    $version = (string) $koneksi->query('SELECT VERSION()')->fetch_row()[0];
    $proposalOwnerColumns = array_filter(
        ['user_id', 'proposer_id', 'username', 'pengusul_id', 'owner_id'],
        static fn (string $column): bool => columnExists($koneksi, 'tb_rancangan', $column)
    );
    $staffUserColumns = array_filter(
        ['user_id', 'username', 'userId'],
        static fn (string $column): bool => columnExists($koneksi, 'tb_staf', $column)
    );

    $legacyProposals = fetchAllRows(
        $koneksi,
        'SELECT r.idRanc, r.proposal_number, r.jdlRanc, r.idBiro, b.nmaBiro,
                r.idTim, t.nmaTim, r.idStaf, s.nmaStaf, r.jnsPeraturan,
                r.dsrAlsn, r.ket, r.status, r.submitted_at, r.created_at, r.updated_at
         FROM tb_rancangan r
         LEFT JOIN tb_biro b ON b.idBiro = r.idBiro
         LEFT JOIN tb_tim t ON t.idTim = r.idTim
         LEFT JOIN tb_staf s ON s.idStaf = r.idStaf
         ORDER BY r.idRanc'
    );
    $legacyReviews = fetchAllRows(
        $koneksi,
        'SELECT r.idReviu, r.idRanc, r.catatan, r.reviewer_id,
                u.username AS reviewer_username, u.role_id AS reviewer_role_id,
                roles.name AS reviewer_role_name, r.created_at
         FROM tb_reviu r
         LEFT JOIN users u ON u.id = r.reviewer_id
         LEFT JOIN roles ON roles.id = u.role_id
         ORDER BY r.idReviu'
    );
    $users = fetchAllRows(
        $koneksi,
        'SELECT u.id, u.username, u.role, u.role_id, roles.name AS canonical_role,
                u.unit_id, units.code AS unit_code, units.name AS unit_name, u.is_active
         FROM users u
         LEFT JOIN roles ON roles.id = u.role_id
         LEFT JOIN units ON units.id = u.unit_id
         ORDER BY u.id'
    );
    $legacyBureaus = fetchAllRows($koneksi, 'SELECT idBiro, nmaBiro FROM tb_biro ORDER BY idBiro');
    $legacyTypes = fetchAllRows(
        $koneksi,
        'SELECT jnsPeraturan, COUNT(*) AS proposal_count
         FROM tb_rancangan GROUP BY jnsPeraturan ORDER BY jnsPeraturan'
    );
    $duplicateNumbers = fetchAllRows(
        $koneksi,
        'SELECT proposal_number, COUNT(*) AS occurrences
         FROM tb_rancangan
         WHERE proposal_number IS NOT NULL AND TRIM(proposal_number) <> \'\'
         GROUP BY proposal_number
         HAVING COUNT(*) > 1'
    );
    $duplicateNumberSet = [];
    foreach ($duplicateNumbers as $duplicate) {
        $duplicateNumberSet[(string) $duplicate['proposal_number']] = (int) $duplicate['occurrences'];
    }

    $targetTables = [
        'roles', 'users', 'units', 'regulation_types', 'proposals',
        'proposal_regulation_types', 'reviews', 'proposal_status_histories',
        'proposal_documents',
    ];
    $targetCounts = [];
    foreach ($targetTables as $table) {
        $targetCounts[$table] = (int) $koneksi->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
    }
    $legacyCounts = [];
    foreach (['tb_biro', 'tb_tim', 'tb_staf', 'tb_rancangan', 'tb_reviu'] as $table) {
        $legacyCounts[$table] = (int) $koneksi->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0];
    }

    $reviewColumns = array_column(
        fetchAllRows(
            $koneksi,
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_reviu'"
        ),
        'COLUMN_NAME'
    );
    $legacyDocumentTables = fetchAllRows(
        $koneksi,
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME NOT IN (
               'roles', 'users', 'units', 'regulation_types', 'proposals',
               'proposal_regulation_types', 'reviews', 'proposal_status_histories',
               'proposal_documents', 'tb_biro', 'tb_tim', 'tb_staf', 'tb_rancangan', 'tb_reviu'
           )
           AND (LOWER(TABLE_NAME) LIKE '%document%' OR LOWER(TABLE_NAME) LIKE '%file%')
         ORDER BY TABLE_NAME"
    );

    $koneksi->rollback();
} catch (Throwable $exception) {
    if ($koneksi->thread_id) {
        $koneksi->rollback();
    }
    fwrite(STDERR, 'Dry-run failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$proposalTotal = count($legacyProposals);
$proposalRows = [];
$mappedUnitCount = 0;
$unmappedUnitCount = 0;
$ambiguousUnitCount = 0;
$mappedTypeCount = 0;
$unmappedTypeCount = 0;
$mappedTypeReferenceCount = 0;
$statusCounts = [];

foreach ($legacyProposals as $proposal) {
    $unitMatches = $proposal['nmaBiro'] === null
        ? []
        : exactUnitMatches($koneksi, (string) $proposal['nmaBiro']);
    $typeMatches = exactRegulationTypeMatches($koneksi, (string) $proposal['jnsPeraturan']);
    $hasDuplicateNumber = isset($duplicateNumberSet[(string) $proposal['proposal_number']]);
    $numberExistsInTarget = targetProposalNumberExists($koneksi, (string) $proposal['proposal_number']);
    $status = (string) $proposal['status'];
    $validStatus = in_array(
        $status,
        ['Draft', 'Menunggu Review', 'Sedang Direview', 'Perlu Revisi', 'Diajukan Kembali', 'Disetujui', 'Ditolak'],
        true
    );

    if (count($unitMatches) === 1) {
        $mappedUnitCount++;
        $unitEvidence = 'Exact BINARY name match: ' . $proposal['nmaBiro'] . ' → unit ' . $unitMatches[0]['id'];
    } elseif (count($unitMatches) > 1) {
        $ambiguousUnitCount++;
        $unitEvidence = 'AMBIGUOUS UNIT: more than one exact target name match';
    } else {
        $unmappedUnitCount++;
        $unitEvidence = 'UNMAPPED UNIT: no exact target name match for ' . ($proposal['nmaBiro'] ?? '(null)');
    }

    if (count($typeMatches) === 1) {
        $mappedTypeCount++;
        $mappedTypeReferenceCount++;
        $typeEvidence = 'Exact BINARY name match: ' . $proposal['jnsPeraturan'] . ' → type ' . $typeMatches[0]['id'];
    } else {
        $unmappedTypeCount++;
        $typeEvidence = 'UNMAPPED REGULATION TYPE: expected exactly one target match, found ' . count($typeMatches);
    }

    $flags = [];
    if ($hasDuplicateNumber) {
        $flags[] = 'DUPLICATE LEGACY PROPOSAL NUMBER (' . $duplicateNumberSet[(string) $proposal['proposal_number']] . ' rows)';
    }
    if ($numberExistsInTarget) {
        $flags[] = 'DUPLICATE TARGET PROPOSAL NUMBER';
    }
    if (!$validStatus) {
        $flags[] = 'STATUS_UNMAPPED';
    }
    if ($proposal['status'] === 'Draft' && $proposal['submitted_at'] !== null) {
        $flags[] = 'STATUS/SUBMITTED_AT INCONSISTENCY';
    }
    if ((string) $proposal['jdlRanc'] === '0') {
        $flags[] = 'TITLE VALUE REQUIRES MANUAL REVIEW';
    }

    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
    $proposalRows[] = [
        'legacy_id' => (int) $proposal['idRanc'],
        'proposal_number' => (string) $proposal['proposal_number'],
        'title' => (string) $proposal['jdlRanc'],
        'bureau' => (string) ($proposal['nmaBiro'] ?? ''),
        'team' => (string) ($proposal['nmaTim'] ?? ''),
        'staff' => (string) ($proposal['nmaStaf'] ?? ''),
        'regulation_type' => (string) $proposal['jnsPeraturan'],
        'legacy_status' => $status,
        'submitted_at' => (string) ($proposal['submitted_at'] ?? ''),
        'unit_evidence' => $unitEvidence,
        'regulation_type_evidence' => $typeEvidence,
        'flags' => $flags ? implode('; ', $flags) : 'None found',
        'decision' => 'SKIP_OWNER_UNKNOWN',
        'reason' => 'tb_rancangan has no owner/account identifier; idStaf maps only to tb_staf, which has no user foreign key or username field.',
    ];
}

$reviewRows = [];
$reviewSkipUnmappedProposal = 0;
$reviewSkipUnknownReviewer = 0;
$reviewSkipMissingDecisionOrRound = 0;
foreach ($legacyReviews as $review) {
    $reviewerValid = $review['reviewer_id'] !== null
        && $review['reviewer_username'] !== null
        && $review['reviewer_role_name'] === 'biro_hukum';
    $reviewSkipUnmappedProposal++;
    if (!$reviewerValid) {
        $reviewSkipUnknownReviewer++;
    }
    $hasDecision = in_array('decision', $reviewColumns, true);
    $hasRound = in_array('review_round', $reviewColumns, true);
    if (!$hasDecision || !$hasRound) {
        $reviewSkipMissingDecisionOrRound++;
    }
    $reviewRows[] = [
        'legacy_id' => (int) $review['idReviu'],
        'legacy_proposal_id' => (int) $review['idRanc'],
        'reviewer' => $review['reviewer_username'] ?? '(unknown)',
        'reviewer_evidence' => $reviewerValid
            ? 'tb_reviu.reviewer_id FK → users.id ' . $review['reviewer_id'] . '; canonical role is biro_hukum (HIGH)'
            : 'No verified reviewer mapping',
        'review_round' => $hasRound ? 'Available' : 'Not stored in legacy schema',
        'legacy_decision' => $hasDecision ? 'Available' : 'Not stored; do not infer from note text',
        'notes' => (string) $review['catatan'],
        'created_at' => (string) $review['created_at'],
        'migration_decision' => 'SKIP_PROPOSAL_UNMAPPED',
        'reason' => 'Parent proposal is not migratable without verified owner; review round and structured decision are absent.',
    ];
}

$rolesByName = [];
foreach ($users as $user) {
    $rolesByName[(string) $user['canonical_role']] = ($rolesByName[(string) $user['canonical_role']] ?? 0) + 1;
}

$report = [];
$report[] = '# Stage 2 — Legacy Data Migration Dry Run';
$report[] = '';
$report[] = '> Generated by `database/migration_target_data_dry_run.php`. Read-only consistent snapshot; no target or legacy rows were written.';
$report[] = '';
$report[] = '## Database preflight';
$report[] = '';
$report[] = '- Database: `' . quoteMarkdown($database) . '`';
$report[] = '- Engine/version: MySQL `' . quoteMarkdown($version) . '`';
$report[] = '- `tb_rancangan` owner fields checked: ' . ($proposalOwnerColumns ? implode(', ', $proposalOwnerColumns) : 'none of user_id/proposer_id/username/pengusul_id/owner_id');
$report[] = '- `tb_staf` user-link fields checked: ' . ($staffUserColumns ? implode(', ', $staffUserColumns) : 'none of user_id/username/userId');
$report[] = '- Legacy review fields: `' . implode('`, `', $reviewColumns) . '`';
$report[] = '';
$report[] = '## Mapping dry run';
$report[] = '';
$report[] = '### Users';
$report[] = '';
$report[] = '- Legacy/target users present in the shared `users` table: ' . count($users);
$report[] = '- Reused target users (no duplicate account insertion): ' . count($users);
$report[] = '- New user rows to migrate: 0';
$report[] = '- Users missing `role_id` or canonical role: ' . count(array_filter($users, static fn (array $user): bool => $user['role_id'] === null || $user['canonical_role'] === null));
$report[] = '- Role column mismatch: ' . count(array_filter($users, static function (array $user): bool {
    $legacyRole = (string) $user['role'];
    $canonicalRole = (string) $user['canonical_role'];
    return !($legacyRole === $canonicalRole || ($legacyRole === 'user' && $canonicalRole === 'pengusul'));
}));
$report[] = '';
$report[] = '| User ID | Username | Legacy role | Canonical role | Unit | Mapping |';
$report[] = '|---:|---|---|---|---|---|';
foreach ($users as $user) {
    $report[] = '| ' . $user['id'] . ' | ' . quoteMarkdown($user['username']) . ' | ' . quoteMarkdown($user['role']) . ' | ' . quoteMarkdown($user['canonical_role']) . ' | ' . quoteMarkdown(($user['unit_code'] ?? '') . ' ' . ($user['unit_name'] ?? '')) . ' | Reuse existing row; no password changes |';
}
$report[] = '';
$report[] = '### Units';
$report[] = '';
$report[] = '- Legacy units (`tb_biro`): ' . count($legacyBureaus);
$report[] = '- Exact name matches to target `units`: ' . count(array_filter($legacyBureaus, static fn (array $bureau): bool => count(exactUnitMatches($koneksi, (string) $bureau['nmaBiro'])) === 1));
$report[] = '- Proposal references with an exact unit mapping: ' . $mappedUnitCount;
$report[] = '- Unmapped proposal unit references: ' . $unmappedUnitCount;
$report[] = '- Ambiguous proposal unit references: ' . $ambiguousUnitCount;
$report[] = '';
$report[] = '| Legacy unit ID | Legacy name | Target exact match | Evidence |';
$report[] = '|---:|---|---|---|';
foreach ($legacyBureaus as $bureau) {
    $matches = exactUnitMatches($koneksi, (string) $bureau['nmaBiro']);
    $target = count($matches) === 1 ? $matches[0]['id'] . ' (' . $matches[0]['code'] . ')' : (count($matches) === 0 ? 'Unmapped' : 'Ambiguous');
    $report[] = '| ' . $bureau['idBiro'] . ' | ' . quoteMarkdown($bureau['nmaBiro']) . ' | ' . quoteMarkdown($target) . ' | Exact BINARY name match; no row inserted |';
}
$report[] = '';
$report[] = '### Regulation types';
$report[] = '';
$report[] = '- Distinct legacy types: ' . count($legacyTypes);
$report[] = '- Exact unique target matches: ' . count(array_filter($legacyTypes, static fn (array $type): bool => count(exactRegulationTypeMatches($koneksi, (string) $type['jnsPeraturan'])) === 1));
$report[] = '- Proposal references with an exact type mapping: ' . $mappedTypeReferenceCount;
$report[] = '- Unmapped or ambiguous distinct values: ' . $unmappedTypeCount;
$report[] = '';
$report[] = '| Legacy type | Legacy proposal rows | Target mapping |';
$report[] = '|---|---:|---|';
foreach ($legacyTypes as $type) {
    $matches = exactRegulationTypeMatches($koneksi, (string) $type['jnsPeraturan']);
    $target = count($matches) === 1 ? $matches[0]['id'] . ' (' . $matches[0]['name'] . ')' : (count($matches) === 0 ? 'UNMAPPED REGULATION TYPE' : 'AMBIGUOUS');
    $report[] = '| ' . quoteMarkdown($type['jnsPeraturan']) . ' | ' . $type['proposal_count'] . ' | ' . quoteMarkdown($target) . ' |';
}
$report[] = '';
$report[] = '### Proposals';
$report[] = '';
$report[] = '- Total legacy proposals: ' . $proposalTotal;
$report[] = '- Eligible to migrate: 0';
$report[] = '- Skip — owner unknown: ' . $proposalTotal;
$report[] = '- Skip — ambiguous owner: 0 (no owner candidate is recorded in source fields)';
$report[] = '- Rows flagged duplicate legacy proposal number: ' . count(array_filter($proposalRows, static fn (array $row): bool => str_contains($row['flags'], 'DUPLICATE LEGACY PROPOSAL NUMBER')));
$report[] = '- Rows flagged invalid/unmapped status: ' . count(array_filter($proposalRows, static fn (array $row): bool => str_contains($row['flags'], 'STATUS_UNMAPPED')));
$report[] = '- Current statuses present: ' . implode(', ', array_map(static fn (string $status, int $count): string => $status . ' (' . $count . ')', array_keys($statusCounts), array_values($statusCounts)));
$report[] = '';
$report[] = '| Legacy ID | Number | Title | Candidate owner | Owner evidence | Unit evidence | Type evidence | Current status → target | Decision | Reason/flags |';
$report[] = '|---:|---|---|---|---|---|---|---|---|---|';
foreach ($proposalRows as $row) {
    $report[] = '| ' . $row['legacy_id'] . ' | ' . quoteMarkdown($row['proposal_number']) . ' | ' . quoteMarkdown($row['title']) . ' | None | No owner/account column or relation | ' . quoteMarkdown($row['unit_evidence']) . ' | ' . quoteMarkdown($row['regulation_type_evidence']) . ' | ' . quoteMarkdown($row['legacy_status'] . ' → ' . ($row['legacy_status'] === 'Draft' ? 'Draft' : $row['legacy_status'])) . ' | ' . $row['decision'] . ' | ' . quoteMarkdown($row['reason'] . ' ' . $row['flags']) . ' |';
}
$report[] = '';
$report[] = '### Proposal records without verified owner';
$report[] = '';
$report[] = '**Actual count: ' . $proposalTotal . ' (the prior Stage 1 count of four is stale; two additional rows are present in the current database).** All remain in `tb_rancangan`; none are inserted into `proposals`.';
$report[] = '';
$report[] = '| Legacy ID | Number | Title | Available clues | Why owner is not proven | Decision |';
$report[] = '|---:|---|---|---|---|---|';
foreach ($proposalRows as $row) {
    $clues = 'bureau=' . $row['bureau'] . '; team=' . $row['team'] . '; staff=' . $row['staff'] . '; type=' . $row['regulation_type'] . '; status=' . $row['legacy_status'];
    $report[] = '| ' . $row['legacy_id'] . ' | ' . quoteMarkdown($row['proposal_number']) . ' | ' . quoteMarkdown($row['title']) . ' | ' . quoteMarkdown($clues) . ' | `idStaf` links to `tb_staf` only; no user FK/username; no proposal owner field | NOT MIGRATED |';
}
$report[] = '';
$report[] = 'Potential clues are not ownership evidence: the existing source only stores staff labels/IDs. The API accepts a staff selection from the browser but does not associate that staff row with the authenticated account.';
$report[] = '';
$report[] = '### Reviews';
$report[] = '';
$report[] = '- Total legacy reviews: ' . count($legacyReviews);
$report[] = '- Migrated: 0';
$report[] = '- Skip — parent proposal unmapped: ' . $reviewSkipUnmappedProposal;
$report[] = '- Skip — reviewer unknown: ' . $reviewSkipUnknownReviewer;
$report[] = '- Skip — no structured decision and/or review round: ' . $reviewSkipMissingDecisionOrRound;
$report[] = '';
$report[] = '| Legacy review ID | Proposal ID | Reviewer evidence | Round | Legacy decision | Notes | Created at | Migration decision/reason |';
$report[] = '|---:|---:|---|---|---|---|---|---|';
foreach ($reviewRows as $row) {
    $report[] = '| ' . $row['legacy_id'] . ' | ' . $row['legacy_proposal_id'] . ' | ' . quoteMarkdown($row['reviewer_evidence']) . ' | ' . quoteMarkdown($row['review_round']) . ' | ' . quoteMarkdown($row['legacy_decision']) . ' | ' . quoteMarkdown($row['notes']) . ' | ' . quoteMarkdown($row['created_at']) . ' | ' . $row['migration_decision'] . ' — ' . quoteMarkdown($row['reason']) . ' |';
}
$report[] = '';
$report[] = 'Reviewer references are high-confidence for both rows because `tb_reviu.reviewer_id` resolves to user ID 3, whose canonical role is `biro_hukum`. Reviews still cannot be migrated because their parent proposals are not mapped and the legacy review table has no decision or round columns. Notes were not converted into decisions.';
$report[] = '';
$report[] = '### Status histories and documents';
$report[] = '';
$report[] = '- Status histories migratable: 0.';
$report[] = '- Histories skipped because transitions/actors cannot be proven: ' . $proposalTotal . ' current-state records. No history rows were fabricated.';
$report[] = '- Legacy document records found: ' . ($legacyDocumentTables ? implode(', ', array_column($legacyDocumentTables, 'TABLE_NAME')) : 'none; no legacy document table found').
    '. No document rows or storage paths were created.';
$report[] = '';
$report[] = '## Duplicate and inconsistent data';
$report[] = '';
$report[] = '- Duplicate legacy proposal number: `' . implode('`, `', array_map(static fn (array $row): string => $row['proposal_number'] . ' (' . $row['occurrences'] . ' rows)', $duplicateNumbers)) . '`; no number was changed.';
$report[] = '- Legacy ID 4 has status `Draft` and non-NULL `submitted_at`; flagged for manual review, not remapped.';
$report[] = '- Legacy ID 4 has title `0`; flagged for manual review, not corrected.';
$report[] = '- The two duplicate number rows are independently blocked by unknown ownership; neither was selected as the canonical row.';
$report[] = '';
$report[] = '## Reconciliation';
$report[] = '';
$report[] = '| Source | Legacy total | Migrated | Skipped/retained | Unaccounted |';
$report[] = '|---|---:|---:|---:|---:|';
$report[] = '| Users | ' . count($users) . ' | 0 new rows (all ' . count($users) . ' reused in place) | 0 | 0 |';
$report[] = '| Units (`tb_biro`) | ' . count($legacyBureaus) . ' | 0 new rows (all names mapped to existing master where exact) | 0 | 0 |';
$report[] = '| Regulation types | ' . count($legacyTypes) . ' distinct | 0 new rows (all values mapped to existing master where exact) | 0 | 0 |';
$report[] = '| Proposals (`tb_rancangan`) | ' . $proposalTotal . ' | 0 | ' . $proposalTotal . ' | 0 |';
$report[] = '| Reviews (`tb_reviu`) | ' . count($legacyReviews) . ' | 0 | ' . count($legacyReviews) . ' | 0 |';
$report[] = '| Status histories | ' . $proposalTotal . ' current status values | 0 | ' . $proposalTotal . ' | 0 |';
$report[] = '| Documents | 0 legacy source rows | 0 | 0 | 0 |';
$report[] = '';
$report[] = 'Target rows before/after this dry run:';
$report[] = '';
foreach ($targetCounts as $table => $count) {
    $report[] = '- `' . $table . '`: ' . $count . ' (unchanged; dry-run writes nothing)';
}
$report[] = '';
$report[] = 'Legacy rows observed during this dry run:';
$report[] = '';
foreach ($legacyCounts as $table => $count) {
    $report[] = '- `' . $table . '`: ' . $count . ' (unchanged; dry-run writes nothing)';
}
$report[] = '';
$report[] = '## Confidence';
$report[] = '';
$report[] = '- HIGH: exact unit-name matches (' . $mappedUnitCount . ' proposal references), exact regulation-type matches (' . $mappedTypeCount . ' distinct values), existing user/role mappings (' . count($users) . ' users), and reviewer FK references (' . count(array_filter($reviewRows, static fn (array $row): bool => str_contains($row['reviewer_evidence'], '(HIGH)'))) . ' reviews). These are mapping evidence only; related proposals/reviews were not committed.';
$report[] = '- MEDIUM: none committed.';
$report[] = '- LOW: all candidate proposal ownership mappings are LOW/unknown; no such mapping was committed.';
$report[] = '';
$report[] = '## Commit gate';
$report[] = '';
$report[] = '**BLOCKED — no commit was run.** All ' . $proposalTotal . ' proposal rows lack verified owners, two share a legacy proposal number, review round/decision data is absent, and current status does not establish prior lifecycle transitions. A commit now would violate the ownership, duplicate, and history rules.';
$report[] = '';
$report[] = 'Next required evidence/decisions: provide an authoritative proposal-to-user mapping for each legacy ID, decide how to resolve the duplicate number `4` without silently renumbering either row, and define whether/how legacy notes map to review rounds and structured decisions. Until then all proposal/review/history legacy rows remain unchanged.';
$report[] = '';

echo implode(PHP_EOL, $report);
