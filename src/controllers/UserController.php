<?php

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

class UserController
{

    private static function env(string $k, $default = '')
    {
        if (isset($_ENV[$k])) return $_ENV[$k];
        $v = getenv($k);
        return $v !== false ? $v : $default;
    }

    private static function s3Region(): string
    {
        $r = (string)(self::env('AWS_S3_REGION', '') ?: self::env('AWS_REGION', 'us-east-1'));
        return trim($r);
    }

    private static function s3Bucket(): string
    {
        return trim((string) self::env('AWS_S3_BUCKET', ''));
    }

    private static function s3Client(): S3Client
    {
        $key    = trim((string) self::env('AWS_ACCESS_KEY_ID', ''));
        $secret = trim((string) self::env('AWS_SECRET_ACCESS_KEY', ''));
        $region = self::s3Region();

        if ($key === '' || $secret === '' || $region === '' || self::s3Bucket() === '') {
            throw new RuntimeException('Missing AWS env (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_S3_REGION/AWS_REGION, AWS_S3_BUCKET)');
        }

        return new S3Client([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => new Credentials($key, $secret),
        ]);
    }

    private static function cloudfrontHost(): string
    {
        $cf = trim((string) self::env('AWS_CLOUDFRONT_DOMAIN', ''));
        if ($cf === '') return '';
        // allow either "dxxx.cloudfront.net" or "https://dxxx.cloudfront.net"
        return preg_replace('#^https?://#i', '', $cf);
    }

    private static function publicUrlFromKey(string $key): string
    {
        if ($key === '') return '';
        if (preg_match('#^https?://#i', $key)) return $key; // already absolute

        $cf = self::cloudfrontHost();
        if ($cf !== '') {
            return 'https://' . rtrim($cf, '/') . '/' . ltrim($key, '/');
        }
        $bucket = self::s3Bucket();
        $region = self::s3Region();
        return "https://{$bucket}.s3.{$region}.amazonaws.com/" . ltrim($key, '/');
    }

    private static function guessExt(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: '');
        if ($ext) return $ext;
        return 'jpg';
    }

    private static function guessContentType(string $ext, ?string $fallbackFromPhpUpload = null): string
    {
        // prefer provided mime (from $_FILES['photo']['type'])
        if ($fallbackFromPhpUpload && stripos($fallbackFromPhpUpload, '/') !== false) {
            return $fallbackFromPhpUpload;
        }
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg'=> 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp'=> 'image/webp',
            'heic'=> 'image/heic',
            'heif'=> 'image/heif',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    private static function userPhotoKey(string $customId, string $originalName): array
    {
        $ext  = self::guessExt($originalName);
        $rand = bin2hex(random_bytes(8));
        $key  = "user/{$customId}/avatar_{$rand}.{$ext}";
        $ct   = self::guessContentType($ext);
        return [$key, $ct];
    }

    /* ========= endpoints ========= */

    // GET /me  -> returns user with photo as URL (not raw key)
    public static function me()
    {
        Config::boot();
        $auth = requireAuth();

        $stmt = DB::conn()->prepare("SELECT id, custom_id, email, full_name, phone, student_id, department, role, photo
                                     FROM user_data WHERE id=?");
        $stmt->execute([$auth['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $photoKey = (string)($row['photo'] ?? '');
        $photoUrl = $photoKey ? self::publicUrlFromKey($photoKey) : '';

        Response::json([
            'ok'   => true,
            'user' => [
                'id'         => (int)$row['id'],
                'custom_id'  => $row['custom_id'],
                'email'      => $row['email'],
                'full_name'  => $row['full_name'],
                'phone'      => $row['phone'],
                'student_id' => $row['student_id'],
                'department' => $row['department'],
                'role'       => $row['role'],
                'photo'      => $photoUrl,  // URL for UI
                'photo_key'  => $photoKey,  // raw S3 key if you need it
            ],
        ]);
    }

    // OPTIONAL: POST /user/profile/presign  (no external S3 helper used)
    // Body: filename?, content_type?
    public static function presignProfilePhoto()
    {
        $auth = requireAuth();

        try {
            $customId = (string)($auth['custom_id'] ?? '');
            if ($customId === '') {
                return Response::json(['ok' => false, 'error' => 'user_not_found'], 404);
            }

            $filename    = isset($_POST['filename']) ? (string)$_POST['filename'] : 'avatar.jpg';
            $contentType = isset($_POST['content_type']) ? (string)$_POST['content_type'] : null;

            [$key, $defaultCt] = self::userPhotoKey($customId, $filename);
            $ct  = $contentType ?: $defaultCt;

            $client = self::s3Client();
            $bucket = self::s3Bucket();

            $cmd = $client->getCommand('PutObject', [
                'Bucket'      => $bucket,
                'Key'         => $key,
                'ContentType' => $ct,
            ]);
            $req = $client->createPresignedRequest($cmd, '+900 seconds');

            return Response::json([
                'ok'         => true,
                'key'        => $key,
                'put'        => [
                    'url'     => (string)$req->getUri(),
                    'headers' => ['Content-Type' => $ct],
                ],
                'public_url' => self::publicUrlFromKey($key),
            ]);
        } catch (\Throwable $e) {
            error_log('[user.presignProfilePhoto] ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => 'server_error'], 500);
        }
    }

    // POST /user/profile
    // Accepts:
    //   - multipart/form-data with fields: full_name? and file: photo
    //   - OR JSON: { full_name?: string }  (no file -> name-only update)
    // Uploads photo to S3 (user/{custom_id}/...), stores key in DB, returns URL + key.
    public static function updateProfile()
    {
        $auth = requireAuth();

        try {
            $pdo      = DB::conn();
            $meId     = (int)($auth['id'] ?? 0);
            $customId = (string)($auth['custom_id'] ?? '');

            if ($meId <= 0 || $customId === '') {
                return Response::json(['ok' => false, 'error' => 'user_not_found'], 404);
            }

            $isJson = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;

            $fullName = null;
            if ($isJson) {
                $body = json_decode(file_get_contents('php://input'), true) ?: [];
                $fullName = isset($body['full_name']) ? trim((string)$body['full_name']) : null;
            } else {
                $fullName = isset($_POST['full_name']) ? trim((string)$_POST['full_name']) : null;
            }

            $newPhotoKey = null;

            // Handle upload if file present
            if (!$isJson && isset($_FILES['photo']) && is_array($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $tmpPath   = $_FILES['photo']['tmp_name'];
                $origName  = $_FILES['photo']['name'] ?? 'avatar.jpg';
                $mimeFromPhp = $_FILES['photo']['type'] ?? null;

                [$key, $ctDefault] = self::userPhotoKey($customId, $origName);
                $contentType = self::guessContentType(self::guessExt($origName), $mimeFromPhp);

                $client = self::s3Client();
                $bucket = self::s3Bucket();

                $params = [
                    'Bucket'      => $bucket,
                    'Key'         => $key,
                    'Body'        => fopen($tmpPath, 'rb'),
                    'ContentType' => $contentType,
                ];
                // Optional: if you want public objects at object level (not needed when using CF OAI)
                $acl = trim((string) self::env('AWS_S3_ACL', ''));
                if ($acl !== '') {
                    $params['ACL'] = $acl; // e.g., 'public-read'
                }

                try {
                    $client->putObject($params);
                } catch (AwsException $e) {
                    error_log('[user.updateProfile:putObject] ' . $e->getMessage());
                    return Response::json(['ok' => false, 'error' => 's3_upload_failed'], 500);
                }

                $newPhotoKey = $key;
            }

            // Build update query
            $cols = []; $args = [];
            if ($fullName !== null && $fullName !== '') { $cols[] = 'full_name = ?'; $args[] = $fullName; }
            if ($newPhotoKey !== null && $newPhotoKey !== '') { $cols[] = 'photo = ?'; $args[] = $newPhotoKey; }

            if ($cols) {
                $args[] = $meId;
                $sql = 'UPDATE user_data SET ' . implode(', ', $cols) . ' WHERE id = ?';
                $pdo->prepare($sql)->execute($args);
            }

            // fetch updated
            $st = $pdo->prepare('SELECT custom_id, full_name, email, department, photo FROM user_data WHERE id = ? LIMIT 1');
            $st->execute([$meId]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $photoKey = (string)($u['photo'] ?? '');
            $photoUrl = $photoKey ? self::publicUrlFromKey($photoKey) : '';

            return Response::json([
                'ok'   => true,
                'user' => [
                    'custom_id'  => $u['custom_id'] ?? '',
                    'full_name'  => $u['full_name'] ?? '',
                    'email'      => $u['email'] ?? '',
                    'department' => $u['department'] ?? '',
                    'photo'      => $photoUrl,  // URL for UI
                    'photo_key'  => $photoKey,  // raw key in DB
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[user.updateProfile] ' . $e->getMessage());
            return Response::json(['ok' => false, 'error' => 'server_error'], 500);
        }
    }
}
