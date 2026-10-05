<?php
require __DIR__ . '/_bootstrap.php';

apiRun(static function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Metode tidak diizinkan.'], 405);
    }
    requireCsrf();
    require __DIR__ . '/../koneksi.php';
    $user = requireRole('reviewer');
    ensureAccountActive($user, $koneksi);
    if ($user['reviewerId'] === null) {
        jsonResponse(['error' => 'Akun ini belum ditautkan ke profil Reviewer. Hubungi Admin.'], 403);
    }
    $input = requestData();
    $proposalId = positiveId($input['proposalId'] ?? null);
    $action = $input['action'] ?? null;
    $transitions = [
        'start_review' => ['from' => ['Menunggu Review', 'Diajukan Kembali'], 'to' => 'Sedang Direview'],
        'request_revision' => ['from' => ['Sedang Direview'], 'to' => 'Perlu Revisi'],
        'approve' => ['from' => ['Sedang Direview'], 'to' => 'Disetujui'],
        'reject' => ['from' => ['Sedang Direview'], 'to' => 'Ditolak'],
    ];
    if (!is_string($action) || !isset($transitions[$action])) {
        jsonResponse(['error' => 'Tindakan review tidak valid.'], 422);
    }
    $note = $action === 'start_review'
        ? 'Review dimulai.'
        : requiredText($input['note'] ?? null, 'Catatan review', 10000);
    $reviewerId = (int) $user['reviewerId'];

    $koneksi->begin_transaction();
    try {
        $proposalQuery = $koneksi->prepare('SELECT status FROM proposals WHERE id = ? FOR UPDATE');
        $proposalQuery->bind_param('i', $proposalId);
        $proposalQuery->execute();
        $proposal = $proposalQuery->get_result()->fetch_assoc();
        if (!$proposal) {
            $koneksi->rollback();
            jsonResponse(['error' => 'Usulan tidak ditemukan.'], 404);
        }
        $currentStatus = (string) $proposal['status'];
        if (!in_array($currentStatus, $transitions[$action]['from'], true)) {
            $koneksi->rollback();
            jsonResponse(['error' => 'Status usulan tidak sesuai untuk tindakan ini. Muat ulang daftar usulan.'], 409);
        }

        if ($action === 'start_review') {
            $roundQuery = $koneksi->prepare('SELECT COALESCE(MAX(review_round), 0) + 1 AS next_round FROM reviews WHERE proposal_id = ?');
            $roundQuery->bind_param('i', $proposalId);
            $roundQuery->execute();
            $round = (int) $roundQuery->get_result()->fetch_assoc()['next_round'];
            $decision = 'Sedang Direview';
            $insertReview = $koneksi->prepare(
                'INSERT INTO reviews (proposal_id, reviewer_id, review_round, notes, decision)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insertReview->bind_param('iiiss', $proposalId, $reviewerId, $round, $note, $decision);
            $insertReview->execute();
        } else {
            $reviewQuery = $koneksi->prepare(
                "SELECT id FROM reviews
                 WHERE proposal_id = ? AND reviewer_id = ? AND decision = 'Sedang Direview'
                 ORDER BY review_round DESC LIMIT 1 FOR UPDATE"
            );
            $reviewQuery->bind_param('ii', $proposalId, $reviewerId);
            $reviewQuery->execute();
            $review = $reviewQuery->get_result()->fetch_assoc();
            if (!$review) {
                $koneksi->rollback();
                jsonResponse(['error' => 'Review aktif tidak ditemukan untuk akun Anda. Reviewer yang memulai review harus menyelesaikannya.'], 409);
            }
            $decision = $transitions[$action]['to'];
            $updateReview = $koneksi->prepare(
                'UPDATE reviews SET decision = ?, notes = ? WHERE id = ? AND reviewer_id = ?'
            );
            $updateReview->bind_param('ssii', $decision, $note, $review['id'], $reviewerId);
            $updateReview->execute();
        }

        $newStatus = $transitions[$action]['to'];
        $updateProposal = $koneksi->prepare('UPDATE proposals SET status = ? WHERE id = ?');
        $updateProposal->bind_param('si', $newStatus, $proposalId);
        $updateProposal->execute();

        $history = $koneksi->prepare(
            'INSERT INTO proposal_status_histories (proposal_id, changed_by, old_status, new_status, notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $history->bind_param('iisss', $proposalId, $user['id'], $currentStatus, $newStatus, $note);
        $history->execute();
        $koneksi->commit();
    } catch (Throwable $exception) {
        $koneksi->rollback();
        throw $exception;
    }

    jsonResponse(['ok' => true, 'status' => $newStatus]);
});
