<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (!$id) {
    http_response_code(404);
    exit;
}

try {
    $statement = db()->prepare(
        'SELECT mime_type, file_size, image_data, created_at
         FROM uploaded_media
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $media = $statement->fetch();
} catch (PDOException) {
    $media = false;
}

if (!$media || !in_array($media['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

$etag = '"media-' . (int) $id . '-' . (int) $media['file_size'] . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . (int) $media['file_size']);
header('Content-Disposition: inline');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
$imageData = $media['image_data'];
if (is_resource($imageData)) {
    fpassthru($imageData);
} else {
    echo $imageData;
}
