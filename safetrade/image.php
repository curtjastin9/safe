<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';

$type = $_GET['type'] ?? '';
$id   = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit; }

$conn = db();
$legacyPath = '';

switch ($type) {
    case 'product':
        $stmt = $conn->prepare('SELECT image_data, mime_type, path FROM product_images WHERE id = ? LIMIT 1');
        break;
    case 'group':
        $stmt = $conn->prepare('SELECT photo_data AS image_data, photo_mime AS mime_type FROM `groups` WHERE id = ? LIMIT 1');
        break;
    case 'id_front':
        require_role('admin', 'manager');
        $stmt = $conn->prepare('SELECT front_data AS image_data, front_mime AS mime_type FROM government_ids WHERE id = ? LIMIT 1');
        break;
    case 'id_back':
        require_role('admin', 'manager');
        $stmt = $conn->prepare('SELECT back_data AS image_data, back_mime AS mime_type FROM government_ids WHERE id = ? LIMIT 1');
        break;
    case 'selfie':
        require_role('admin', 'manager');
        $stmt = $conn->prepare('SELECT selfie_data AS image_data, selfie_mime AS mime_type FROM face_verifications WHERE id = ? LIMIT 1');
        break;
    default:
        http_response_code(400); exit;
}

$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); exit; }

$data = $row['image_data'];
$mime = $row['mime_type'] ?: 'image/jpeg';

// Legacy rows (uploaded before BLOB storage) only have a file path.
if (($data === null || $data === '') && $type === 'product' && !empty($row['path'])) {
    $rel  = ltrim(str_replace('\\', '/', (string) $row['path']), '/');
    $file = realpath(BASE_PATH . '/' . $rel);
    $root = realpath(BASE_PATH);
    if ($file && $root && strpos($file, $root) === 0 && is_file($file)) {
        $data = file_get_contents($file);
        $info = @getimagesize($file);
        $mime = $info['mime'] ?? 'image/jpeg';
    }
}

// Still nothing: serve a neutral placeholder instead of a broken-image icon.
if ($data === null || $data === '') {
    if ($type === 'product' || $type === 'group') {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-store');
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#E4EFE7"/><g fill="none" stroke="#155943" stroke-width="8" stroke-linejoin="round" opacity=".55"><path d="M200 95l55 30v62l-55 30-55-30v-62z"/><path d="M145 125l55 30 55-30M200 155v62"/></g></svg>';
        exit;
    }
    http_response_code(404); exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($data));
header(in_array($type, ['product', 'group'], true)
    ? 'Cache-Control: public, max-age=604800, immutable'
    : 'Cache-Control: private, no-store');
echo $data;
