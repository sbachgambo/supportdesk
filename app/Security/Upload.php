<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Config;
use ZipArchive;

/**
 * Upload (§10.7) — the highest-severity surface. Every control here is required;
 * uploads are the most common path from "website" to "attacker owns the server".
 *
 *   - Stored OUTSIDE the webroot (storage/uploads/); no URL resolves to a file.
 *   - Random names (16 bytes hex), NO extension on disk. original_name is DB-only
 *     and is never used to build a path — path traversal has nowhere to land.
 *   - Dual validation: extension allowlist AND finfo content sniff must AGREE.
 *     $_FILES['type'] (client-supplied) is ignored entirely.
 *   - SVG is excluded (XML that can carry script).
 *   - Images are re-encoded through GD → strips EXIF and appended payloads (the
 *     polyglot JPEG/PHP trick). Re-encode failure → reject.
 *   - Caps: per-file bytes, per-ticket count; zip uncompressed size capped (zip bomb).
 *
 * process() takes the original name and a temp path (the controller verifies
 * is_uploaded_file first in production) so the class is unit-testable.
 */
final class Upload
{
    /** ext => acceptable finfo MIME types. Both must agree. */
    private const MIME_MAP = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/plain', 'text/csv', 'application/csv'],
        'doc'  => ['application/msword', 'application/x-ole-storage'],
        // OOXML files are zip containers; libmagic commonly reports application/zip.
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'zip'  => ['application/zip'],
    ];

    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Max total uncompressed size allowed inside a zip (zip-bomb guard): 200 MiB. */
    private const ZIP_MAX_UNCOMPRESSED = 209715200;

    public static function storageDir(): string
    {
        return (defined('P3A_ROOT') ? P3A_ROOT : dirname(__DIR__, 2)) . '/storage/uploads';
    }

    /**
     * Validate and store one file. Returns:
     *   ['ok'=>true, 'stored_name','mime_type','size_bytes','sha256','original_name']
     *   ['ok'=>false, 'error'=>message]
     */
    public static function process(string $originalName, string $tmpPath): array
    {
        if (!is_file($tmpPath)) {
            return self::err('No file was received.');
        }

        $size = filesize($tmpPath);
        $maxBytes = Config::int('UPLOAD_MAX_BYTES', 10485760);
        if ($size === false || $size <= 0) {
            return self::err('The file is empty.');
        }
        if ($size > $maxBytes) {
            return self::err('The file exceeds the ' . (int) ($maxBytes / 1048576) . ' MB limit.');
        }

        // Extension allowlist (from the DISPLAY name; never used as a path).
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::MIME_MAP[$ext])) {
            return self::err('That file type is not allowed.');
        }

        // Content sniff (authoritative) — must AGREE with the extension. finfo can
        // warn/return false on odd input; suppress the warning (not @) and treat an
        // undetectable type as a rejection.
        $detected = self::quietly(static function () use ($tmpPath): string {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $type = $finfo->file($tmpPath);
            return $type === false ? '' : $type;
        });
        if ($detected === '' || !in_array($detected, self::MIME_MAP[$ext], true)) {
            return self::err('The file content does not match its extension.');
        }

        // zip-bomb guard for archives (including OOXML which are zips).
        if ($detected === 'application/zip' || $ext === 'zip') {
            $bombError = self::checkZipBomb($tmpPath);
            if ($bombError !== null) {
                return self::err($bombError);
            }
        }

        $storedName = bin2hex(random_bytes(16));
        $dest = self::storageDir() . '/' . $storedName;

        if (in_array($ext, self::IMAGE_EXT, true)) {
            // Re-encode through GD → strips EXIF + any appended payload.
            if (!self::reencodeImage($tmpPath, $dest, $ext)) {
                return self::err('The image could not be processed and was rejected.');
            }
        } else {
            if (file_put_contents($dest, (string) file_get_contents($tmpPath)) === false) {
                return self::err('The file could not be stored.');
            }
        }
        self::quietly(static fn() => chmod($dest, 0600));

        return [
            'ok'            => true,
            'stored_name'   => $storedName,
            'mime_type'     => $detected,
            'size_bytes'    => (int) filesize($dest),
            'sha256'        => hash_file('sha256', $dest),
            'original_name' => self::sanitizeName($originalName),
        ];
    }

    /** Re-encode an image, normalising format and stripping non-pixel data. */
    private static function reencodeImage(string $src, string $dest, string $ext): bool
    {
        $data = file_get_contents($src);
        if ($data === false) {
            return false;
        }
        // GD warns on malformed input (exactly the case we reject) — suppress the
        // warning locally rather than crashing, without using @ (§16).
        return self::quietly(static function () use ($data, $dest, $ext): bool {
            $img = imagecreatefromstring($data);
            if ($img === false) {
                return false;
            }
            $ok = match ($ext) {
                'png'         => imagepng($img, $dest),
                'jpg', 'jpeg' => imagejpeg($img, $dest, 90),
                'gif'         => imagegif($img, $dest),
                'webp'        => imagewebp($img, $dest),
                default       => false,
            };
            imagedestroy($img);
            return $ok;
        });
    }

    /**
     * Run $fn with a temporary no-op error handler so library warnings (GD, chmod on
     * odd filesystems) don't surface as output or become exceptions. Not @ (§16).
     */
    private static function quietly(callable $fn): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    private static function checkZipBomb(string $path): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null; // cannot inspect; the size cap still applies
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return 'The archive could not be read.';
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $total += (int) $stat['size'];
            }
        }
        $zip->close();
        return $total > self::ZIP_MAX_UNCOMPRESSED ? 'The archive is too large when uncompressed.' : null;
    }

    /** Display-only sanitisation of the original name (never a path). */
    public static function sanitizeName(string $name): string
    {
        $name = basename($name);                       // strip any directory parts
        $name = preg_replace('/[\r\n\x00]+/', '', $name) ?? $name;
        return mb_substr($name, 0, 255);
    }

    private static function err(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
