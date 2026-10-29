<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * Face recognition helpers for SafeTrade's face-login / face-verified
 * registration feature.
 *
 * Embeddings come from face-api.js running entirely in the browser
 * (TensorFlow.js + pre-trained TinyFaceDetector / FaceLandmark68Net /
 * FaceRecognitionNet — see assets/js/face-auth.js). Nothing is trained
 * here; this file only stores and COMPARES the 128-float descriptor
 * arrays those models produce, and the match/duplicate DECISION is
 * always made here, server-side — never trusted from the browser.
 *
 * Storage: one row per user in the existing `face_verifications` table
 * (see sql/migration_add_face_verification.sql), embedding stored as a
 * JSON array. No selfie images are stored.
 * ---------------------------------------------------------------------
 */

const FACE_EMBEDDING_LENGTH = 128;

// face-api.js's own guidance for FaceRecognitionNet is ~0.6 Euclidean
// distance as a "same person" cutoff. We use a stricter value for both
// login and duplicate-detection to reduce false accepts — this is a
// BASIC/MEDIUM-strength check, not government-grade KYC, so we'd
// rather occasionally ask a genuine user to fall back to email+password
// than let two different faces match.
const FACE_MATCH_THRESHOLD = 0.50;

function face_embedding_is_valid(mixed $embedding): bool
{
    if (!is_array($embedding) || count($embedding) !== FACE_EMBEDDING_LENGTH) {
        return false;
    }
    foreach ($embedding as $v) {
        if (!is_int($v) && !is_float($v)) {
            return false;
        }
    }
    return true;
}

/** Euclidean distance between two same-length embeddings — lower = more similar. */
function face_euclidean_distance(array $a, array $b): float
{
    $sum = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $d = (float) $a[$i] - (float) $b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

/** Fetch the stored embedding for one user, or null if they never registered a face. */
function get_face_embedding(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT embedding FROM face_verifications WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !$row['embedding']) {
        return null;
    }
    $decoded = json_decode($row['embedding'], true);
    return face_embedding_is_valid($decoded) ? $decoded : null;
}

/** Insert or replace the given user's stored face embedding (one per account). */
function save_face_embedding(mysqli $conn, int $userId, array $embedding): void
{
    $json = json_encode(array_map('floatval', $embedding));
    $stmt = $conn->prepare(
        'INSERT INTO face_verifications (user_id, embedding, created_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), created_at = NOW()'
    );
    $stmt->bind_param('is', $userId, $json);
    $stmt->execute();
    $stmt->close();
}

/**
 * Scans every stored embedding and returns the closest match under the
 * given distance threshold, or null if nobody is close enough. Used
 * both for "log me in by face" (no email typed) and "is this face
 * already registered under another account" (signup duplicate check).
 *
 * @return array{user_id:int, distance:float}|null
 */
function find_closest_face_match(mysqli $conn, array $embedding, float $threshold = FACE_MATCH_THRESHOLD, ?int $excludeUserId = null): ?array
{
    $sql = 'SELECT user_id, embedding FROM face_verifications WHERE embedding IS NOT NULL';
    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> ' . (int) $excludeUserId;
    }
    $res = $conn->query($sql);

    $best = null;
    while ($row = $res->fetch_assoc()) {
        $stored = json_decode($row['embedding'], true);
        if (!face_embedding_is_valid($stored)) {
            continue;
        }
        $dist = face_euclidean_distance($embedding, $stored);
        if ($dist <= $threshold && ($best === null || $dist < $best['distance'])) {
            $best = ['user_id' => (int) $row['user_id'], 'distance' => $dist];
        }
    }
    return $best;
}
