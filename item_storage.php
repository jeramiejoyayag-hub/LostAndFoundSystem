<?php
$composer_autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composer_autoload)) {
    require_once $composer_autoload;
}

function item_storage_uses_object_storage() {
    $bucket = getenv('S3_BUCKET');
    return is_string($bucket) && trim($bucket) !== '';
}

function item_storage_s3_client() {
    static $client = null;
    if ($client !== null) {
        return $client;
    }
    if (!class_exists('\\Aws\\S3\\S3Client')) {
        throw new RuntimeException("The AWS SDK is not installed.");
    }

    $bucket = trim((string)getenv('S3_BUCKET'));
    $access_key = getenv('S3_ACCESS_KEY_ID');
    $secret_key = getenv('S3_SECRET_ACCESS_KEY');
    $region = getenv('S3_REGION') ?: 'us-east-1';
    if ($bucket === '' || !$access_key || !$secret_key) {
        throw new RuntimeException("S3_BUCKET, S3_ACCESS_KEY_ID, and S3_SECRET_ACCESS_KEY are required.");
    }

    $config = [
        'version' => 'latest',
        'region' => $region,
        'credentials' => [
            'key' => $access_key,
            'secret' => $secret_key,
        ],
        'use_path_style_endpoint' => filter_var(getenv('S3_PATH_STYLE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ];
    $endpoint = getenv('S3_ENDPOINT');
    if ($endpoint) {
        if (strpos($endpoint, 'https://') !== 0) {
            throw new RuntimeException("S3_ENDPOINT must use HTTPS.");
        }
        $config['endpoint'] = $endpoint;
    }

    $client = new \Aws\S3\S3Client($config);
    return $client;
}

function item_storage_store_file($source_path, $stored_name, $content_type, $is_uploaded_file = false) {
    $safe_name = basename($stored_name);
    if ($safe_name === '' || $safe_name !== $stored_name || !is_file($source_path)) {
        error_log("Refusing to store an invalid item image path.");
        return false;
    }

    if (item_storage_uses_object_storage()) {
        try {
            item_storage_s3_client()->putObject([
                'Bucket' => trim((string)getenv('S3_BUCKET')),
                'Key' => $safe_name,
                'SourceFile' => $source_path,
                'ContentType' => $content_type ?: 'application/octet-stream',
            ]);
            return true;
        } catch (\Aws\Exception\AwsException $error) {
            error_log("Could not upload an item image to object storage: " . $error->getMessage());
            return false;
        } catch (RuntimeException $error) {
            error_log("Object storage is not configured correctly: " . $error->getMessage());
            return false;
        } catch (InvalidArgumentException $error) {
            error_log("Object storage rejected the item image configuration: " . $error->getMessage());
            return false;
        }
    }

    $directory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        error_log("Could not create the local item image directory.");
        return false;
    }
    $destination = $directory . DIRECTORY_SEPARATOR . $safe_name;
    $stored = $is_uploaded_file
        ? move_uploaded_file($source_path, $destination)
        : copy($source_path, $destination);
    if (!$stored) {
        error_log("Could not store an item image locally.");
    }
    return $stored;
}

function item_storage_url($stored_name) {
    $safe_name = basename((string)$stored_name);
    if ($safe_name === '' || $safe_name !== (string)$stored_name) {
        return '';
    }

    if (item_storage_uses_object_storage()) {
        try {
            $command = item_storage_s3_client()->getCommand('GetObject', [
                'Bucket' => trim((string)getenv('S3_BUCKET')),
                'Key' => $safe_name,
            ]);
            $request = item_storage_s3_client()->createPresignedRequest($command, '+1 hour');
            return (string)$request->getUri();
        } catch (\Aws\Exception\AwsException $error) {
            error_log("Could not create an item image URL: " . $error->getMessage());
            return '';
        } catch (RuntimeException $error) {
            error_log("Object storage is not configured correctly: " . $error->getMessage());
            return '';
        } catch (InvalidArgumentException $error) {
            error_log("Object storage rejected the item image URL configuration: " . $error->getMessage());
            return '';
        }
    }

    return 'uploads/' . rawurlencode($safe_name);
}
