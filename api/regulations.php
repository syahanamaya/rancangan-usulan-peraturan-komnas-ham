<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    $user = requireUser();
    ensureAccountActive($user, $koneksi);
    $role = $user['role'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($role === 'pengusul' && $user['proposerId'] === null) {
            jsonResponse(['items' => []]);
        }
        if ($role === 'reviewer' && $user['reviewerId'] === null) {
            jsonResponse(['items' => []]);
        }
        if (!in_array($role, ['admin', 'pengusul', 'reviewer'], true)) {
            jsonResponse(['error' => 'Anda tidak memiliki akses untuk melihat usulan.'], 403);
        }

        $where = '';
        if ($role === 'pengusul') {
            $where = 'WHERE p.proposer_id = ' . (int) $user['proposerId'];
        } elseif ($role === 'reviewer') {
            $where = "WHERE p.status <> 'Draft'";
        }
        $result = $koneksi->query(
            'SELECT p.id, p.proposal_number AS proposalNumber, p.title,
                    p.unit_id AS unitId, un.name AS unitName,
                    p.proposer_id AS proposerId, pr.nama AS proposerName,
                    pr.jabatan AS proposerPosition,
                    COALESCE(GROUP_CONCAT(DISTINCT rt.name ORDER BY rt.name SEPARATOR ", "), single_type.name) AS types,
                    p.background AS reason, p.legal_basis AS legalBasis, p.objective,
                    p.description, p.status, p.submitted_at AS submittedAt,
                    p.created_at AS createdAt, p.updated_at AS updatedAt
             FROM proposals p
             LEFT JOIN units un ON un.id = p.unit_id
             JOIN pengusul pr ON pr.id = p.proposer_id
             LEFT JOIN proposal_regulation_types prt ON prt.proposal_id = p.id
             LEFT JOIN regulation_types rt ON rt.id = prt.regulation_type_id
             LEFT JOIN regulation_types single_type ON single_type.id = p.regulation_type_id
             ' . $where . '
             GROUP BY p.id
             ORDER BY p.updated_at DESC, p.id DESC'
        );
        $items = [];
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['unitId'] = $row['unitId'] === null ? null : (int) $row['unitId'];
            $row['proposerId'] = (int) $row['proposerId'];
            $row['notes'] = [];
            $row['statusHistory'] = [];
            $row['documents'] = [];
            $ids[] = $row['id'];
            $items[$row['id']] = $row;
        }
        if ($ids !== []) {
            $notes = $koneksi->query(
                'SELECT rv.proposal_id AS proposalId, rv.review_round AS reviewRound,
                        rv.reviewer_id AS reviewerId, rv.decision, rv.notes AS text, rv.created_at AS date,
                        reviewer.nama AS reviewerName
                 FROM reviews rv
                 JOIN reviewers reviewer ON reviewer.id = rv.reviewer_id
                 WHERE rv.proposal_id IN (' . implode(',', array_map('intval', $ids)) . ')
                 ORDER BY rv.review_round, rv.created_at'
            );
            while ($note = $notes->fetch_assoc()) {
                $proposalId = (int) $note['proposalId'];
                $items[$proposalId]['notes'][] = [
                    'text' => (string) ($note['text'] ?? ''),
                    'date' => (string) $note['date'],
                    'decision' => (string) $note['decision'],
                    'reviewRound' => (int) $note['reviewRound'],
                    'reviewerId' => (int) $note['reviewerId'],
                    'reviewerName' => (string) $note['reviewerName'],
                ];
            }
            $histories = $koneksi->query(
                'SELECT h.proposal_id AS proposalId, h.old_status AS oldStatus,
                        h.new_status AS newStatus, h.notes, h.created_at AS date,
                        u.name AS actorName
                 FROM proposal_status_histories h
                 LEFT JOIN users u ON u.id = h.changed_by
                 WHERE h.proposal_id IN (' . implode(',', array_map('intval', $ids)) . ')
                 ORDER BY h.created_at, h.id'
            );
            while ($history = $histories->fetch_assoc()) {
                $proposalId = (int) $history['proposalId'];
                $items[$proposalId]['statusHistory'][] = [
                    'oldStatus' => $history['oldStatus'],
                    'newStatus' => (string) $history['newStatus'],
                    'notes' => (string) ($history['notes'] ?? ''),
                    'date' => (string) $history['date'],
                    'actorName' => (string) ($history['actorName'] ?? 'Pengguna'),
                ];
            }
            $documents = $koneksi->query(
                'SELECT proposal_id AS proposalId, id, original_filename AS name, mime_type AS mimeType,
                        file_size AS fileSize, created_at AS createdAt
                 FROM proposal_documents
                 WHERE proposal_id IN (' . implode(',', array_map('intval', $ids)) . ')
                 ORDER BY created_at, id'
            );
            while ($document = $documents->fetch_assoc()) {
                $proposalId = (int) $document['proposalId'];
                $items[$proposalId]['documents'][] = [
                    'id' => (int) $document['id'],
                    'name' => (string) $document['name'],
                    'mimeType' => (string) ($document['mimeType'] ?? ''),
                    'fileSize' => (int) ($document['fileSize'] ?? 0),
                    'createdAt' => (string) $document['createdAt'],
                ];
            }
        }
        jsonResponse(['items' => array_values($items)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    $input = requestData();
    $action = $input['action'] ?? '';
    if (!in_array($action, ['create', 'update', 'delete'], true)) {
        jsonResponse(['error' => 'Tindakan usulan tidak valid.'], 422);
    }
    $user = requireRole('pengusul');
    if ($user['proposerId'] === null) {
        jsonResponse(['error' => 'Akun ini belum ditautkan ke profil Pengusul. Hubungi Admin.'], 403);
    }
    $proposerId = (int) $user['proposerId'];

    if ($action === 'delete') {
        $id = positiveId($input['id'] ?? null);
        $documentKeys = [];
        $koneksi->begin_transaction();
        try {
            $statement = $koneksi->prepare('SELECT status FROM proposals WHERE id = ? AND proposer_id = ? FOR UPDATE');
            $statement->bind_param('ii', $id, $proposerId);
            $statement->execute();
            $proposal = $statement->get_result()->fetch_assoc();
            if (!$proposal) {
                $koneksi->rollback();
                jsonResponse(['error' => 'Usulan tidak ditemukan.'], 404);
            }
            if ($proposal['status'] !== 'Draft') {
                $koneksi->rollback();
                jsonResponse(['error' => 'Hanya usulan Draft yang dapat dihapus.'], 409);
            }
            $documents = $koneksi->prepare('SELECT storage_path FROM proposal_documents WHERE proposal_id = ?');
            $documents->bind_param('i', $id);
            $documents->execute();
            $documentResult = $documents->get_result();
            while ($document = $documentResult->fetch_assoc()) {
                $documentKeys[] = (string) $document['storage_path'];
            }
            $deleteDocuments = $koneksi->prepare('DELETE FROM proposal_documents WHERE proposal_id = ?');
            $deleteDocuments->bind_param('i', $id);
            $deleteDocuments->execute();
            $delete = $koneksi->prepare('DELETE FROM proposals WHERE id = ? AND proposer_id = ?');
            $delete->bind_param('ii', $id, $proposerId);
            $delete->execute();
            $koneksi->commit();
        } catch (Throwable $exception) {
            $koneksi->rollback();
            throw $exception;
        }
        $cleanupFailed = false;
        foreach ($documentKeys as $storageKey) {
            $path = proposalDocumentPath($storageKey);
            if ($path !== null && is_file($path) && !unlink($path)) {
                $cleanupFailed = true;
                error_log('Unable to remove deleted proposal document: ' . $path);
            }
        }
        jsonResponse([
            'ok' => true,
            'warning' => $cleanupFailed
                ? 'Usulan terhapus, tetapi sebagian file dokumen belum dapat dibersihkan. Hubungi Admin.'
                : null,
        ]);
    }

    $workflowAction = $input['workflowAction'] ?? 'save';
    if (!is_string($workflowAction) || !in_array($workflowAction, ['save', 'submit'], true)) {
        jsonResponse(['error' => 'Tindakan workflow tidak valid.'], 422);
    }
    $title = requiredText($input['title'] ?? null, 'Judul', 255);
    $background = requiredText($input['reason'] ?? null, 'Alasan pengusulan', 65535);
    $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
    $legalBasis = is_string($input['legalBasis'] ?? null) ? trim($input['legalBasis']) : '';
    $objective = is_string($input['objective'] ?? null) ? trim($input['objective']) : '';
    if (mb_strlen($description) > 65535 || mb_strlen($legalBasis) > 65535 || mb_strlen($objective) > 65535) {
        jsonResponse(['error' => 'Keterangan melebihi batas kolom database.'], 422);
    }

    $types = $input['types'] ?? [];
    if (!is_array($types) || $types === [] || count($types) > 10) {
        jsonResponse(['error' => 'Pilih minimal satu jenis peraturan.'], 422);
    }
    $validTypes = [];
    $typeResult = $koneksi->query('SELECT id, name FROM regulation_types WHERE is_active = 1');
    while ($type = $typeResult->fetch_assoc()) {
        $validTypes[(string) $type['name']] = (int) $type['id'];
    }
    $typeIds = [];
    foreach (array_unique($types) as $type) {
        if (!is_string($type) || !isset($validTypes[$type])) {
            jsonResponse(['error' => 'Jenis peraturan tidak valid.'], 422);
        }
        $typeIds[] = $validTypes[$type];
    }

    $unitId = optionalPositiveId($input['unitId'] ?? $user['unitId'], 'Unit');
    if ($unitId !== null) {
        $unitCheck = $koneksi->prepare('SELECT id FROM units WHERE id = ? AND is_active = 1');
        $unitCheck->bind_param('i', $unitId);
        $unitCheck->execute();
        if (!$unitCheck->get_result()->fetch_assoc()) {
            jsonResponse(['error' => 'Unit yang dipilih tidak ditemukan atau tidak aktif.'], 422);
        }
    }
    $id = $action === 'update' ? positiveId($input['id'] ?? null) : null;
    $proposalNumber = is_string($input['proposalNumber'] ?? null) ? trim($input['proposalNumber']) : '';
    if ($proposalNumber === '') {
        $proposalNumber = 'USL-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
    if (mb_strlen($proposalNumber) > 80) {
        jsonResponse(['error' => 'Nomor usulan maksimal 80 karakter.'], 422);
    }
    if ($id === null) {
        $numberCheck = $koneksi->prepare('SELECT id FROM proposals WHERE proposal_number = ? LIMIT 1');
        $numberCheck->bind_param('s', $proposalNumber);
    } else {
        $numberCheck = $koneksi->prepare('SELECT id FROM proposals WHERE proposal_number = ? AND id <> ? LIMIT 1');
        $numberCheck->bind_param('si', $proposalNumber, $id);
    }
    $numberCheck->execute();
    if ($numberCheck->get_result()->fetch_assoc()) {
        jsonResponse(['error' => 'Nomor usulan sudah digunakan.'], 409);
    }
    $koneksi->begin_transaction();
    try {
        if ($action === 'create') {
            $status = $workflowAction === 'submit' ? 'Menunggu Review' : 'Draft';
            $submittedAt = $workflowAction === 'submit';
            $statement = $koneksi->prepare(
                'INSERT INTO proposals (proposal_number, title, unit_id, proposer_id, background, legal_basis,
                 objective, description, status, submitted_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($submittedAt ? 'NOW()' : 'NULL') . ')'
            );
            $statement->bind_param(
                'ssiisssss',
                $proposalNumber,
                $title,
                $unitId,
                $proposerId,
                $background,
                $legalBasis,
                $objective,
                $description,
                $status
            );
            $statement->execute();
            $id = (int) $koneksi->insert_id;
            $history = $koneksi->prepare(
                'INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes)
                 VALUES (?, ?, NULL, ?, ?)'
            );
            $historyNote = $submittedAt ? 'Usulan dibuat dan diajukan.' : 'Usulan disimpan sebagai draft.';
            $history->bind_param('iiss', $id, $user['id'], $status, $historyNote);
            $history->execute();
        } else {
            $statement = $koneksi->prepare(
                'SELECT status FROM proposals WHERE id = ? AND proposer_id = ? FOR UPDATE'
            );
            $statement->bind_param('ii', $id, $proposerId);
            $statement->execute();
            $existing = $statement->get_result()->fetch_assoc();
            if (!$existing) {
                $koneksi->rollback();
                jsonResponse(['error' => 'Usulan tidak ditemukan.'], 404);
            }
            $currentStatus = (string) $existing['status'];
            if (!in_array($currentStatus, ['Draft', 'Perlu Revisi'], true)) {
                $koneksi->rollback();
                jsonResponse(['error' => 'Usulan hanya dapat diubah saat Draft atau Perlu Revisi.'], 409);
            }
            $newStatus = $currentStatus;
            if ($workflowAction === 'submit') {
                $newStatus = $currentStatus === 'Draft' ? 'Menunggu Review' : 'Diajukan Kembali';
            }
            $statement = $koneksi->prepare(
                'UPDATE proposals SET proposal_number = ?, title = ?, unit_id = ?, background = ?,
                 legal_basis = ?, objective = ?, description = ?, status = ?,
                 submitted_at = ' . ($workflowAction === 'submit' ? 'NOW()' : 'submitted_at') . '
                 WHERE id = ? AND proposer_id = ?'
            );
            $statement->bind_param(
                'ssisssssii',
                $proposalNumber,
                $title,
                $unitId,
                $background,
                $legalBasis,
                $objective,
                $description,
                $newStatus,
                $id,
                $proposerId
            );
            $statement->execute();
            if ($newStatus !== $currentStatus) {
                $history = $koneksi->prepare(
                    'INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $historyNote = $newStatus === 'Diajukan Kembali' ? 'Revisi diajukan kembali.' : 'Usulan diajukan.';
                $history->bind_param('iisss', $id, $user['id'], $currentStatus, $newStatus, $historyNote);
                $history->execute();
            }
            $deleteTypes = $koneksi->prepare('DELETE FROM proposal_regulation_types WHERE proposal_id = ?');
            $deleteTypes->bind_param('i', $id);
            $deleteTypes->execute();
        }

        $insertType = $koneksi->prepare(
            'INSERT INTO proposal_regulation_types (proposal_id, regulation_type_id) VALUES (?, ?)'
        );
        foreach ($typeIds as $typeId) {
            $insertType->bind_param('ii', $id, $typeId);
            $insertType->execute();
        }
        $koneksi->commit();
    } catch (Throwable $exception) {
        $koneksi->rollback();
        throw $exception;
    }
    $savedProposal = $koneksi->prepare(
        'SELECT proposal_number AS proposalNumber, status FROM proposals WHERE id = ? LIMIT 1'
    );
    $savedProposal->bind_param('i', $id);
    $savedProposal->execute();
    $savedDetails = $savedProposal->get_result()->fetch_assoc();
    jsonResponse([
        'ok' => true,
        'id' => $id,
        'proposalNumber' => (string) $savedDetails['proposalNumber'],
        'status' => (string) $savedDetails['status'],
    ], $action === 'create' ? 201 : 200);
});
