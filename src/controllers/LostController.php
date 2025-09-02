<?php

class LostController {
  // POST /report-lost-item
  // Body (minimum):
  // {
  //   "custom_id": "U2509....",                 // REQUIRED: backend user's custom_id
  //   "item_type": "Phone",
  //   "title": "iPhone 12 lost",
  //   "photo_keys": ["lost-item-photos/lost_xxx.jpg"] // optional (text keys only)
  //   // optional: item_model, description, location, location_details,
  //   //           lost_date (YYYY-MM-DD), lost_time (HH:MM or HH:MM:SS),
  //   //           secret_hint, verification_secret
  // }
  public static function create() {
    $claims = requireAuth(); // must verify Firebase ID token

    try {
      $pdo = DB::conn();
      $b   = json_decode(file_get_contents('php://input'), true) ?: [];

      // --- inputs ---
      $customUserId     = trim((string)($b['custom_id'] ?? ''));
      $item_type        = trim((string)($b['item_type'] ?? ''));
      $item_model       = trim((string)($b['item_model'] ?? ''));
      $title            = trim((string)($b['title'] ?? ''));
      $description      = trim((string)($b['description'] ?? ''));
      $location         = trim((string)($b['location'] ?? ''));
      $location_details = trim((string)($b['location_details'] ?? ''));
      $secret_hint      = trim((string)($b['secret_hint'] ?? ''));
      $verification_secret = (string)($b['verification_secret'] ?? '');

      // Normalize date/time: '' -> NULL (STRICT mode safe)
      $lost_date = isset($b['lost_date']) && $b['lost_date'] !== '' ? (string)$b['lost_date'] : null;
      $lost_time = isset($b['lost_time']) && $b['lost_time'] !== '' ? (string)$b['lost_time'] : null;

      // Validate / normalize formats
      if ($lost_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lost_date)) {
        return Response::json(['ok'=>false,'error'=>'invalid_lost_date','message'=>'lost_date must be YYYY-MM-DD'], 422);
      }
      if ($lost_time !== null) {
        if (preg_match('/^\d{2}:\d{2}$/', $lost_time)) {
          $lost_time .= ':00'; // HH:MM -> HH:MM:SS
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $lost_time)) {
          return Response::json(['ok'=>false,'error'=>'invalid_lost_time','message'=>'lost_time must be HH:MM or HH:MM:SS'], 422);
        }
      }

      $photo_keys = [];
      if (isset($b['photo_keys']) && is_array($b['photo_keys'])) {
        $photo_keys = $b['photo_keys'];
      } elseif (isset($b['photos']) && is_array($b['photos'])) {
        $photo_keys = $b['photos'];
      }

      // --- required fields ---
      if ($customUserId === '') {
        return Response::json(['ok'=>false,'error'=>'custom_id_required'], 422);
      }
      if ($item_type === '' || $title === '') {
        return Response::json(['ok'=>false,'error'=>'validation_failed','message'=>'item_type and title are required'], 422);
      }

      // --- resolve user by custom_id ---
      $uSel = $pdo->prepare("SELECT id, firebase_uid, is_active FROM user_data WHERE custom_id = ? LIMIT 1");
      $uSel->execute([$customUserId]);
      $urow = $uSel->fetch(\PDO::FETCH_ASSOC);
      if (!$urow) {
        return Response::json(['ok'=>false,'error'=>'user_not_found'], 404);
      }
      if ((int)$urow['is_active'] === 0) {
        return Response::json(['ok'=>false,'error'=>'account_disabled'], 403);
      }

      // Cross-check token owner → Firebase 'sub'
      $tokenUid = (string)($claims['sub'] ?? '');
      if ($tokenUid !== '' && !empty($urow['firebase_uid']) && $tokenUid !== $urow['firebase_uid']) {
        return Response::json(['ok'=>false,'error'=>'ownership_mismatch'], 403);
      }

      // Hash secret if provided
      $secret_hash = null;
      if ($verification_secret !== '') {
        $secret_hash = password_hash($verification_secret, PASSWORD_DEFAULT);
      } elseif (isset($b['secret_hash']) && $b['secret_hash'] !== '') {
        // allow pre-hashed (e.g., from a migration)
        $secret_hash = (string)$b['secret_hash'];
      }

      $pdo->beginTransaction();

      $ins = $pdo->prepare("
        INSERT INTO lost_reports
          (user_id, item_type, item_model, title, description, location, location_details,
           lost_date, lost_time, secret_hint, secret_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ");
      $ins->execute([
        (int)$urow['id'],
        $item_type,
        $item_model !== '' ? $item_model : null,
        $title,
        $description !== '' ? $description : null,
        $location !== '' ? $location : null,
        $location_details !== '' ? $location_details : null,
        $lost_date,            // NULL if not provided
        $lost_time,            // NULL if not provided
        $secret_hint !== '' ? $secret_hint : null,
        $secret_hash
      ]);

      $id = (int)$pdo->lastInsertId();

      // --- fetch generated custom_id from BEFORE INSERT trigger ---
      $sel = $pdo->prepare("SELECT custom_id, report_time FROM lost_reports WHERE id = ?");
      $sel->execute([$id]);
      $created = $sel->fetch(\PDO::FETCH_ASSOC);
      $custom = $created ? $created['custom_id'] : null;

      // --- save photos (text keys only) ---
      if ($custom && $photo_keys) {
        $p = $pdo->prepare("INSERT INTO lost_item_photos (lost_custom_id, photo) VALUES (?, ?)");
        foreach ($photo_keys as $k) {
          $k = trim((string)$k);
          if ($k !== '') {
            $p->execute([$custom, $k]);
          }
        }
      }

      $pdo->commit();

      return Response::json([
        'ok' => true,
        'lost' => [
          'id'         => $id,
          'custom_id'  => $custom,
          'report_time'=> $created['report_time'] ?? null,
          'photos_added' => is_array($photo_keys) ? count(array_filter($photo_keys)) : 0
        ]
      ], 201);

    } catch (\Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      error_log('[lost.create] '.$e->getMessage());
      return Response::json(['ok'=>false,'error'=>'server_error'], 500);
    }
  }
}
