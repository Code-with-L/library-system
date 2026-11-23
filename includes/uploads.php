<?php
/**
 * Cover upload validation helpers (Phase 2.1).
 *
 * These helpers are a reusable allowlist validator for the future cover-upload
 * endpoint. They do not implement the endpoint; they centralise validation so
 * Phase 3 can call the same checks. Validation includes extension, MIME type
 * and file-content checks, and prevents active script content from being
 * stored or served.
 */

/**
 * Expected extensions for each allowed MIME type.
 *
 * @return array<string, array<int, string>>
 */
function cover_allowed_mime_extensions(): array
{
    return [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp'],
    ];
}

/**
 * Return a validation error for an uploaded cover, or null when acceptable.
 *
 * @param array<string, mixed> $file
 */
function cover_upload_error(array $file): ?string
{
    if (!isset($file['name'], $file['tmp_name'], $file['error'])) {
        return 'No valid file uploaded.';
    }

    $errorCode = (int) $file['error'];
    if ($errorCode !== UPLOAD_ERR_OK) {
        return 'The file could not be uploaded.';
    }

    $tempPath = (string) $file['tmp_name'];
    if ($tempPath === '' || !is_file($tempPath)) {
        return 'The uploaded file is missing.';
    }

    $name = basename((string) $file['name']);
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
    if (!in_array($extension, COVER_ALLOWED_EXTENSIONS, true)) {
        return 'Unsupported file type. Allowed: jpg, jpeg, png, webp.';
    }

    $size = (int) $file['size'];
    if ($size > COVER_MAX_BYTES) {
        return 'The file is too large.';
    }

    $imageInfo = getimagesize($tempPath);
    if ($imageInfo === false || !isset($imageInfo['mime'])) {
        return 'The file is not a valid image.';
    }

    $mimeType = (string) $imageInfo['mime'];
    $allowedMimeTypes = cover_allowed_mime_extensions();
    if (!isset($allowedMimeTypes[$mimeType])) {
        return 'Unsupported image type.';
    }

    if (!in_array($extension, $allowedMimeTypes[$mimeType], true)) {
        return 'The file type does not match its extension.';
    }

    // Use finfo when available as an independent content-type check.
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detectedMime = finfo_file($finfo, $tempPath);
            finfo_close($finfo);
            if ($detectedMime === false) {
                return 'The file type could not be verified.';
            }
            if ($detectedMime !== $mimeType) {
                return 'The file content type does not match the extension.';
            }
        }
    }

    return null;
}

/**
 * Generate a safe server-side filename for a validated cover upload.
 *
 * The extension comes from the validated MIME type, never from the original
 * filename; the basename is random so path traversal and overwrites are not
 * possible. Call only after cover_upload_error() returns null.
 */
function cover_safe_filename(string $validatedMime): string
{
    $mimeToExtension = cover_allowed_mime_extensions();
    if (!isset($mimeToExtension[$validatedMime])) {
        throw new InvalidArgumentException('Unsupported image type for filename generation.');
    }
    $extensions = $mimeToExtension[$validatedMime];
    $bytes = random_bytes(16);
    return bin2hex($bytes) . '.' . $extensions[0];
}

/**
 * Return a validation error when the cover storage directory is missing or
 * unwritable, or null when it is ready.
 */
function cover_storage_error(): ?string
{
    $directory = COVER_UPLOAD_DIR;
    if (!is_dir($directory)) {
        return 'Cover storage directory is not available.';
    }
    if (!is_writable($directory)) {
        return 'Cover storage directory is not writable.';
    }
    return null;
}

/**
 * True when the file passes the cover upload validator.
 *
 * @param array<string, mixed> $file
 */
function cover_upload_is_valid(array $file): bool
{
    return cover_upload_error($file) === null;
}
