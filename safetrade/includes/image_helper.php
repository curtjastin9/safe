<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * Image compression helper used by the listing-photo upload flow (and,
 * later, ID verification).
 *
 * IMPORTANT: this function must never fatal the calling request. If GD
 * isn't enabled, or a file turns out to be corrupt, it returns a normal
 * failure array instead of crashing — the caller (dashboard.php) already
 * checks `success` and just skips that one photo.
 * ---------------------------------------------------------------------
 */

/**
 * Resize (if needed) and re-encode an image to shrink its file size while
 * keeping enough detail for face/ID matching.
 *
 * @param string $sourcePath      Path to the uploaded temp file.
 * @param string $destPath        Where to write the optimized file.
 * @param int    $maxDimension    Longest side, in px, after resize.
 * @param int    $quality         JPEG quality 0-100.
 * @return array{success:bool, original_size:int, final_size:int, width:int, height:int, error:?string}
 */
function compress_and_store_image(
    string $sourcePath,
    string $destPath,
    int $maxDimension = TARGET_IMAGE_MAX_DIMENSION,
    int $quality = TARGET_JPEG_QUALITY
): array {
    $fail = static fn(string $msg, int $w = 0, int $h = 0, int $orig = 0) => [
        'success' => false, 'original_size' => $orig, 'final_size' => 0,
        'width' => $w, 'height' => $h, 'error' => $msg,
    ];

    // Guard: GD not enabled (common on a fresh XAMPP install until you
    // uncomment `extension=gd` in php.ini). Without this check, calling
    // an undefined imagecreatefromjpeg() etc. below would be a fatal
    // error that takes down the whole request — this way it's just a
    // normal "couldn't process this photo" result.
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return $fail('Image processing is unavailable on this server (GD extension not enabled).');
    }

    try {
        if (!is_file($sourcePath)) {
            return $fail('Source file not found.');
        }

        $originalSize = filesize($sourcePath) ?: 0;

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return $fail('Unrecognized image format.', 0, 0, $originalSize);
        }

        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default        => false,
        };

        if (!$image) {
            return $fail('Unsupported or corrupt image.', $width, $height, $originalSize);
        }

        // Flatten transparency onto white before JPEG re-encode.
        if ($type === IMAGETYPE_PNG) {
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $flat;
        }

        $scale = min(1.0, $maxDimension / max($width, $height));
        $newWidth  = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        if ($scale < 1.0) {
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        if (!is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0755, true);
        }

        $ok = imagejpeg($image, $destPath, $quality);
        imagedestroy($image);

        if (!$ok) {
            return $fail('Failed to write compressed image.', $newWidth, $newHeight, $originalSize);
        }

        return [
            'success'       => true,
            'original_size' => $originalSize,
            'final_size'    => filesize($destPath) ?: 0,
            'width'         => $newWidth,
            'height'        => $newHeight,
            'error'         => null,
        ];
    } catch (Throwable $e) {
        // Belt-and-braces: any unexpected error (missing GD function,
        // memory limit, etc.) is caught here so it can never take the
        // whole "post item" request down with it.
        error_log('[SafeTrade] compress_and_store_image failed: ' . $e->getMessage());
        return $fail('Unexpected error while processing image.');
    }
}

/**
 * Same resize/compress pipeline as compress_and_store_image(), but
 * returns the finished JPEG bytes directly instead of writing them to
 * a file — for storing images as a BLOB in the database rather than on
 * disk. This is what makes uploads (product photos, group photos,
 * government IDs, face selfies) survive a real deployment instead of
 * depending on a writable, persistent local filesystem.
 *
 * @return array{success:bool, data:?string, mime:?string, original_size:int, final_size:int, width:int, height:int, error:?string}
 */
function compress_image_to_blob(
    string $sourcePath,
    int $maxDimension = TARGET_IMAGE_MAX_DIMENSION,
    int $quality = TARGET_JPEG_QUALITY
): array {
    $fail = static fn(string $msg, int $w = 0, int $h = 0, int $orig = 0) => [
        'success' => false, 'data' => null, 'mime' => null,
        'original_size' => $orig, 'final_size' => 0, 'width' => $w, 'height' => $h, 'error' => $msg,
    ];

    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return $fail('Image processing is unavailable on this server (GD extension not enabled).');
    }

    try {
        if (!is_file($sourcePath)) {
            return $fail('Source file not found.');
        }

        $originalSize = filesize($sourcePath) ?: 0;

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return $fail('Unrecognized image format.', 0, 0, $originalSize);
        }

        [$width, $height, $type] = $info;

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default        => false,
        };

        if (!$image) {
            return $fail('Unsupported or corrupt image.', $width, $height, $originalSize);
        }

        if ($type === IMAGETYPE_PNG) {
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $flat;
        }

        $scale = min(1.0, $maxDimension / max($width, $height));
        $newWidth  = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        if ($scale < 1.0) {
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        $ok = imagejpeg($image, null, $quality);
        $data = ob_get_clean();
        imagedestroy($image);

        if (!$ok || $data === false || $data === '') {
            return $fail('Failed to encode compressed image.', $newWidth, $newHeight, $originalSize);
        }

        return [
            'success'       => true,
            'data'          => $data,
            'mime'          => 'image/jpeg',
            'original_size' => $originalSize,
            'final_size'    => strlen($data),
            'width'         => $newWidth,
            'height'        => $newHeight,
            'error'         => null,
        ];
    } catch (Throwable $e) {
        error_log('[SafeTrade] compress_image_to_blob failed: ' . $e->getMessage());
        return $fail('Unexpected error while processing image.');
    }
}

