<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    require __DIR__ . '/../koneksi.php';
    $user = requireUser();
    ensureAccountActive($user, $koneksi);

    if (isset($_GET['download'])) {
        $documentId = positiveId($_GET['download']);
        $statement = $koneksi->prepare(
            'SELECT d.original_filename, d.storage_path, d.mime_type,
                    p.proposer_id, p.status
             FROM proposal_documents d
             JOIN proposals p ON p.id = d.proposal_id
             WHERE d.id = ? LIMIT 1'
        );
        $statement->bind_param('i', $documentId);
        $statement->execute();
        $document = $statement->get_result()->fetch_assoc();
        if (!$document) {
            jsonResponse(['error' => 'Dokumen tidak ditemukan.'], 404);
        }
        $authorized = $user['role'] === 'admin'
            || ($user['role'] === 'pengusul'
                && $user['proposerId'] !== null
                && (int) $document['proposer_id'] === $user['proposerId'])
            || ($user['role'] === 'reviewer' && $document['status'] !== 'Draft');
        if (!$authorized) {
            jsonResponse(['error' => 'Anda tidak memiliki akses ke dokumen ini.'], 403);
        }
        $extension = strtolower(pathinfo((string) $document['original_filename'], PATHINFO_EXTENSION));
        $allowedMimeTypes = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        if (!isset($allowedMimeTypes[$extension]) || $document['mime_type'] !== $allowedMimeTypes[$extension]) {
            jsonResponse(['error' => 'Tipe dokumen tidak valid.'], 415);
        }
        $path = proposalDocumentPath((string) $document['storage_path']);
        if ($path === null) {
            jsonResponse(['error' => 'Lokasi dokumen tidak valid.'], 404);
        }
        if (!is_file($path) || !is_readable($path)) {
            jsonResponse(['error' => 'File dokumen tidak tersedia.'], 404);
        }
        $safeName = str_replace(["\r", "\n", "\0"], '', basename((string) $document['original_filename']));
        header('Content-Type: ' . $allowedMimeTypes[$extension]);
        header('Content-Length: ' . (string) filesize($path));
        header("Content-Disposition: attachment; filename=\"document.$extension\"; filename*=UTF-8''" . rawurlencode($safeName));
        readfile($path);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    $user = requireRole('pengusul');
    if ($user['proposerId'] === null) {
        jsonResponse(['error' => 'Akun ini belum ditautkan ke profil Pengusul. Hubungi Admin.'], 403);
    }
    $proposalId = positiveId($_POST['proposalId'] ?? null);
    $files = $_FILES['documents'] ?? null;
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        jsonResponse(['error' => 'Pilih minimal satu dokumen pendukung.'], 422);
    }
    $fileCount = count(array_filter($files['name'], static fn (string $name): bool => $name !== ''));
    if ($fileCount < 1 || $fileCount > 5) {
        jsonResponse(['error' => 'Unggah antara 1 sampai 5 dokumen dalam satu kali pengiriman.'], 422);
    }

    $validatedFiles = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($files['name'] as $index => $originalName) {
        if ($originalName === '') {
            continue;
        }
        $uploadError = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Unggah dokumen gagal (kode ' . $uploadError . ').'], 422);
        }
        $size = (int) ($files['size'][$index] ?? 0);
        $temporaryPath = (string) ($files['tmp_name'][$index] ?? '');
        if ($size < 1 || $size > 10 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
            jsonResponse(['error' => 'Ukuran dokumen harus lebih dari 0 dan maksimal 10 MB.'], 422);
        }
        $safeName = str_replace(["\r", "\n", "\0"], '', basename((string) $originalName));
        if ($safeName === '' || mb_strlen($safeName) > 255) {
            jsonResponse(['error' => 'Nama file dokumen tidak valid.'], 422);
        }
        $extension = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
        $mimeType = $finfo->file($temporaryPath);
        $expectedMime = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ][$extension] ?? null;
        $mimeMatches = $extension === 'docx'
            ? in_array($mimeType, [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ], true)
            : $mimeType === $expectedMime;
        if ($expectedMime === null || !$mimeMatches) {
            jsonResponse(['error' => 'Format yang didukung hanya PDF dan DOCX yang valid.'], 415);
        }
        if ($extension === 'docx') {
            $archive = new ZipArchive();
            if ($archive->open($temporaryPath) !== true
                || $archive->locateName('[Content_Types].xml') === false
                || $archive->locateName('word/document.xml') === false) {
                jsonResponse(['error' => 'File DOCX tidak memiliki struktur dokumen yang valid.'], 415);
            }
            $archive->close();
        }
        $validatedFiles[] = [
            'temporaryPath' => $temporaryPath,
            'name' => $safeName,
            'extension' => $extension,
            'mimeType' => $expectedMime,
            'size' => $size,
            'checksum' => hash_file('sha256', $temporaryPath),
        ];
    }

    $proposalCheck = $koneksi->prepare('SELECT status FROM proposals WHERE id = ? AND proposer_id = ?');
    $proposalCheck->bind_param('ii', $proposalId, $user['proposerId']);
    $proposalCheck->execute();
    $proposal = $proposalCheck->get_result()->fetch_assoc();
    if (!$proposal) {
        jsonResponse(['error' => 'Usulan tidak ditemukan atau bukan milik akun Anda.'], 404);
    }
    if (!in_array($proposal['status'], ['Draft', 'Perlu Revisi'], true)) {
        jsonResponse(['error' => 'Dokumen hanya dapat ditambahkan pada Draft atau usulan yang perlu revisi.'], 409);
    }

    $storageDirectory = proposalStorageDirectory();
    if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0700, true) && !is_dir($storageDirectory)) {
        jsonResponse(['error' => 'Penyimpanan dokumen tidak dapat disiapkan.'], 500);
    }
    $movedPaths = [];
    $koneksi->begin_transaction();
    try {
        $insert = $koneksi->prepare(
            'INSERT INTO proposal_documents
             (proposal_id, uploaded_by, document_key, original_filename, storage_path, mime_type, file_size, checksum, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $version = '1';
        foreach ($validatedFiles as $file) {
            $storageKey = bin2hex(random_bytes(16)) . '.' . $file['extension'];
            $destination = proposalDocumentPath($storageKey);
            if ($destination === null) {
                throw new RuntimeException('Kunci penyimpanan dokumen tidak valid.');
            }
            if (!move_uploaded_file($file['temporaryPath'], $destination)) {
                throw new RuntimeException('File dokumen tidak dapat disimpan.');
            }
            $movedPaths[] = $destination;
            $checksum = $file['checksum'];
            $insert->bind_param(
                'iissssiss',
                $proposalId,
                $user['id'],
                $storageKey,
                $file['name'],
                $storageKey,
                $file['mimeType'],
                $file['size'],
                $checksum,
                $version
            );
            $insert->execute();
        }
        $koneksi->commit();
    } catch (Throwable $exception) {
        $koneksi->rollback();
        foreach ($movedPaths as $path) {
            if (is_file($path) && !unlink($path)) {
                error_log('Unable to remove uncommitted proposal document: ' . $path);
            }
        }
        throw $exception;
    }
    jsonResponse(['ok' => true, 'count' => count($validatedFiles)], 201);
});
