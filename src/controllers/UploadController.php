<?php

class UploadController {

    // POST /upload/presign
    // body: { type: "lost"|"found", files: [{name, type}, ...] }
    public static function generatePresignedUrls() {
        $user = requireAuth();

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $files = $input['files'] ?? null;
            $type  = $input['type']  ?? 'lost';
            if (!is_array($files) || !in_array($type, ['lost', 'found'], true)) {
                return Response::json(['ok'=>false,'error'=>'validation_failed'], 422);
            }

            $s3 = new S3Store();
            $dir = $s3->dirFor($type);
            $out = [];

            foreach ($files as $f) {
                $name = (string)($f['name'] ?? '');
                $mime = (string)($f['type'] ?? '');
                if ($name === '' || $mime === '' || !self::isValidImageType($mime)) {
                    $out[] = ['original_name'=>$name, 'error'=>'invalid_file_type'];
                    continue;
                }

                $unique   = $s3->generateFilename($name, $type);
                $key      = $s3->objectKey($dir, $unique);
                $presign  = $s3->presignPut($key, $mime, 900);

                $out[] = [
                    'original_name' => $name,
                    'file_key'      => $key,      // store THIS in DB later
                    'upload_url'    => $presign['url'],
                    'headers'       => $presign['headers'], // client must send these
                    'unique_name'   => $unique,
                ];
            }

            return Response::json(['ok'=>true, 'files'=>$out], 200);

        } catch (Throwable $e) {
            error_log('[upload.presign] ' . $e->getMessage());
            return Response::json(['ok'=>false,'error'=>'server_error'], 500);
        }
    }

    // POST /upload/confirm
    // body: { report_type: "lost"|"found", report_custom_id: "L2509....", files: [{file_key, original_name}] }
    // Stores ONLY the S3 object key in *_item_photos.photo
    public static function confirmUploads() {
        $user = requireAuth();

        try {
            $b = json_decode(file_get_contents('php://input'), true) ?: [];
            $reportType = (string)($b['report_type'] ?? '');
            $customId   = (string)($b['report_custom_id'] ?? '');
            $files      = $b['files'] ?? null;

            if (!in_array($reportType, ['lost','found'], true) || $customId === '' || !is_array($files)) {
                return Response::json(['ok'=>false,'error'=>'validation_failed'], 422);
            }

            $pdo = DB::conn();

            // Verify ownership: this user must own the report custom_id
            $reportsTable = $reportType === 'lost' ? 'lost_reports' : 'found_reports';
            $col          = 'custom_id';
            $own = $pdo->prepare("
                SELECT r.id
                FROM {$reportsTable} r
                JOIN user_data u ON u.id = r.user_id
                WHERE r.{$col} = ? AND u.firebase_uid = ?
                LIMIT 1
            ");
            $own->execute([$customId, $user['firebase_uid']]);
            if (!$own->fetch()) {
                return Response::json(['ok'=>false,'error'=>'access_denied'], 403);
            }

            // Insert rows
            $table      = $reportType === 'lost' ? 'lost_item_photos' : 'found_item_photos';
            $fkCol      = $reportType === 'lost' ? 'lost_custom_id'   : 'found_custom_id';
            $ins = $pdo->prepare("INSERT INTO {$table} ({$fkCol}, photo) VALUES (?, ?)");

            $saved = [];
            foreach ($files as $f) {
                $key = (string)($f['file_key'] ?? '');
                if ($key === '') continue;
                $ins->execute([$customId, $key]); // store only KEY
                $saved[] = [
                    'id'            => (int)$pdo->lastInsertId(),
                    'file_key'      => $key,
                    'original_name' => (string)($f['original_name'] ?? ''),
                ];
            }

            return Response::json(['ok'=>true, 'saved_files'=>$saved]);

        } catch (Throwable $e) {
            error_log('[upload.confirm] ' . $e->getMessage());
            return Response::json(['ok'=>false,'error'=>'server_error'], 500);
        }
    }

    // DELETE /upload/delete
    // body: { report_type: "lost"|"found", photo_id: number, file_key: "..." }
    public static function deleteFile() {
        $user = requireAuth();

        try {
            $b = json_decode(file_get_contents('php://input'), true) ?: [];
            $reportType = (string)($b['report_type'] ?? '');
            $photoId    = (int)($b['photo_id'] ?? 0);
            $fileKey    = (string)($b['file_key'] ?? '');

            if (!in_array($reportType, ['lost','found'], true) || $photoId <= 0 || $fileKey === '') {
                return Response::json(['ok'=>false,'error'=>'validation_failed'], 422);
            }

            $pdo = DB::conn();
            $table       = $reportType === 'lost' ? 'lost_item_photos' : 'found_item_photos';
            $fkCol       = $reportType === 'lost' ? 'lost_custom_id'   : 'found_custom_id';
            $reportsTable= $reportType === 'lost' ? 'lost_reports'     : 'found_reports';

            // Verify ownership through join
            $q = $pdo->prepare("
                SELECT p.id, p.photo AS file_key
                FROM {$table} p
                JOIN {$reportsTable} r ON r.custom_id = p.{$fkCol}
                JOIN user_data u ON u.id = r.user_id
                WHERE p.id = ? AND u.firebase_uid = ?
                LIMIT 1
            ");
            $q->execute([$photoId, $user['firebase_uid']]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!$row) return Response::json(['ok'=>false,'error'=>'access_denied'], 403);

            // Delete from S3
            $s3 = new S3Store();
            $s3->delete($fileKey);

            // Delete DB row
            $del = $pdo->prepare("DELETE FROM {$table} WHERE id = ?");
            $del->execute([$photoId]);

            return Response::json(['ok'=>true, 'message'=>'deleted']);

        } catch (Throwable $e) {
            error_log('[upload.delete] ' . $e->getMessage());
            return Response::json(['ok'=>false,'error'=>'server_error'], 500);
        }
    }

    private static function isValidImageType($mime) {
        return in_array($mime, [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'
        ], true);
    }
}
