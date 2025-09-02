<?php

class FoundController {
  // POST /report-found-item

  public static function create() {
    $claims = requireAuth(); 

    try {
      $pdo = DB::conn();
      $b   = json_decode(file_get_contents('php://input'), true) ?: [];

      
      $customUserId     = trim((string)($b['custom_id'] ?? ''));
      $item_type        = trim((string)($b['item_type'] ?? ''));
      $item_model       = trim((string)($b['item_model'] ?? ''));
      $title            = trim((string)($b['title'] ?? ''));
      $description      = trim((string)($b['description'] ?? ''));
      $location         = trim((string)($b['location'] ?? ''));
      $location_details = trim((string)($b['location_details'] ?? ''));


      $found_date = isset($b['found_date']) && $b['found_date'] !== '' ? (string)$b['found_date'] : null;
      $found_time = isset($b['found_time']) && $b['found_time'] !== '' ? (string)$b['found_time'] : null;

      if ($found_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $found_date)) {
        return Response::json(['ok'=>false,'error'=>'invalid_found_date','message'=>'found_date must be YYYY-MM-DD'], 422);
      }
      if ($found_time !== null) {
        if (preg_match('/^\d{2}:\d{2}$/', $found_time)) {
          $found_time .= ':00';
        } elseif (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $found_time)) {
          return Response::json(['ok'=>false,'error'=>'invalid_found_time','message'=>'found_time must be HH:MM or HH:MM:SS'], 422);
        }
      }

      $photo_keys = [];
      if (isset($b['photo_keys']) && is_array($b['photo_keys'])) {
        $photo_keys = $b['photo_keys'];
      } elseif (isset($b['photos']) && is_array($b['photos'])) {
        $photo_keys = $b['photos'];
      }

      // required fields 
      if ($customUserId === '') {
        return Response::json(['ok'=>false,'error'=>'custom_id_required'], 422);
      }
      if ($item_type === '' || $title === '') {
        return Response::json(['ok'=>false,'error'=>'validation_failed','message'=>'item_type and title are required'], 422);
      }

      // resolve user by custom_id 
      $uSel = $pdo->prepare("SELECT id, firebase_uid, is_active FROM user_data WHERE custom_id = ? LIMIT 1");
      $uSel->execute([$customUserId]);
      $urow = $uSel->fetch(\PDO::FETCH_ASSOC);
      if (!$urow) {
        return Response::json(['ok'=>false,'error'=>'user_not_found'], 404);
      }
      if ((int)$urow['is_active'] === 0) {
        return Response::json(['ok'=>false,'error'=>'account_disabled'], 403);
      }

      // Cross-check token owner (Firebase 'sub')
      $tokenUid = (string)($claims['sub'] ?? '');
      if ($tokenUid !== '' && !empty($urow['firebase_uid']) && $tokenUid !== $urow['firebase_uid']) {
        return Response::json(['ok'=>false,'error'=>'ownership_mismatch'], 403);
      }

      $pdo->beginTransaction();

      $ins = $pdo->prepare("
        INSERT INTO found_reports
          (user_id, item_type, item_model, title, description, location, location_details,
           found_date, found_time)
        VALUES (?,?,?,?,?,?,?,?,?)
      ");
      $ins->execute([
        (int)$urow['id'],
        $item_type,
        $item_model !== '' ? $item_model : null,
        $title,
        $description !== '' ? $description : null,
        $location !== '' ? $location : null,
        $location_details !== '' ? $location_details : null,
        $found_date,  
        $found_time  
      ]);

      $id = (int)$pdo->lastInsertId();

      // fetch generated custom_id (set by BEFORE INSERT trigger)
      $sel = $pdo->prepare("SELECT custom_id, report_time FROM found_reports WHERE id = ?");
      $sel->execute([$id]);
      $created = $sel->fetch(\PDO::FETCH_ASSOC);
      $custom  = $created ? $created['custom_id'] : null;

      if ($custom && $photo_keys) {
        $p = $pdo->prepare("INSERT INTO found_item_photos (found_custom_id, photo) VALUES (?, ?)");
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
        'found' => [
          'id'           => $id,
          'custom_id'    => $custom,
          'report_time'  => $created['report_time'] ?? null,
          'photos_added' => is_array($photo_keys) ? count(array_filter($photo_keys)) : 0
        ]
      ], 201);

    } catch (\Throwable $e) {
      if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
      error_log('[found.create] '.$e->getMessage());
      return Response::json(['ok'=>false,'error'=>'server_error'], 500);
    }
  }
}
