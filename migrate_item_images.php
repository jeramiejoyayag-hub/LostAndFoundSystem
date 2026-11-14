<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/item_storage.php';
if (!item_storage_uses_object_storage()) {
    fwrite(STDERR, "Configure S3_BUCKET before migrating existing photos.\n");
    exit(1);
}

$files = glob(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '*');
if ($files === false) {
    fwrite(STDERR, "Could not read the uploads directory.\n");
    exit(1);
}

$failed = 0;
$uploaded = 0;
foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }

    $name = basename($file);
    $content_type = mime_content_type($file) ?: 'application/octet-stream';
    if (item_storage_store_file($file, $name, $content_type)) {
        $uploaded++;
        echo "Uploaded: $name" . PHP_EOL;
    } else {
        $failed++;
        fwrite(STDERR, "Failed: $name" . PHP_EOL);
    }
}

echo "Migration complete: $uploaded uploaded, $failed failed." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
