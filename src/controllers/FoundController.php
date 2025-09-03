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

   // GET /found-items
  public static function all() {
    
    $claims = requireAuth(); 

    try {
      $pdo = DB::conn();

      // ---- filters ----
      $where = [];
      $args  = [];

      if (isset($_GET['item_type']) && $_GET['item_type'] !== '') {
        $where[] = 'fr.item_type = ?';
        $args[]  = (string)$_GET['item_type'];
      }
      if (isset($_GET['location']) && $_GET['location'] !== '') {
        $where[] = 'fr.location = ?';
        $args[]  = (string)$_GET['location'];
      }
      if (isset($_GET['admin_dropoff']) && $_GET['admin_dropoff'] !== '') {
        $where[] = 'fr.admin_dropoff = ?';
        $args[]  = (int)!!$_GET['admin_dropoff'];
      }
      if (isset($_GET['q']) && $_GET['q'] !== '') {
        $q = '%'.trim((string)$_GET['q']).'%';
        $where[] = '(fr.title LIKE ? OR fr.description LIKE ? OR fr.item_model LIKE ?)';
        $args[]  = $q; $args[] = $q; $args[] = $q;
      }

      $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

      // ---- main query (join who found) ----
      $sql = "
        SELECT
          fr.id,
          fr.custom_id,
          fr.item_type,
          fr.item_model,
          fr.title,
          fr.description,
          fr.location,
          fr.location_details,
          fr.found_date,
          fr.found_time,
          fr.report_time,
          fr.admin_dropoff,
          u.custom_id   AS user_custom_id,
          u.full_name   AS user_full_name,
          u.photo       AS user_photo,
          u.department  AS user_department
        FROM found_reports fr
        JOIN user_data u ON u.id = fr.user_id
        $whereSql
        ORDER BY fr.report_time DESC, fr.id DESC
      ";
      $stm = $pdo->prepare($sql);
      $stm->execute($args);
      $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

      if (!$rows) {
        return Response::json(['ok'=>true, 'items'=>[]]);
      }

      // ---- load photos for all custom_ids in one go ----
      $custIds = array_values(array_unique(array_column($rows, 'custom_id')));
      $photoMap = [];

      if (!empty($custIds)) {
        $placeholders = implode(',', array_fill(0, count($custIds), '?'));
        $phSql = "
          SELECT found_custom_id, photo
          FROM found_item_photos
          WHERE found_custom_id IN ($placeholders)
          ORDER BY id ASC
        ";
        $ph = $pdo->prepare($phSql);
        foreach ($custIds as $i => $cid) {
          $ph->bindValue($i+1, $cid, PDO::PARAM_STR);
        }
        $ph->execute();
        while ($r = $ph->fetch(PDO::FETCH_ASSOC)) {
          $photoMap[$r['found_custom_id']][] = $r['photo']; // raw key
        }
      }

      // ---- CDN mapping (only here, not globally) ----
      $cdnBase = rtrim((string)($_ENV['AWS_CLOUDFRONT_DOMAIN'] ?? ''), '/');
      $toCdn = function($key) use ($cdnBase) {
        $k = ltrim(trim((string)$key), '/');
        if ($k === '') return null;
        if (preg_match('#^https?://#i', $k)) return $k; // already full URL
        return $cdnBase !== '' ? ($cdnBase.'/'.$k) : ('/'.$k);
      };

      // ---- shape response ----
      $items = [];
      foreach ($rows as $r) {
        $cid   = $r['custom_id'];
        $keys  = $photoMap[$cid] ?? [];
        // convert to full URLs here
        $photos = array_values(array_filter(array_map($toCdn, $keys)));

        $items[] = [
          'id'                => (int)$r['id'],
          'custom_id'         => $cid,
          'item_type'         => $r['item_type'],
          'item_model'        => $r['item_model'],
          'title'             => $r['title'],
          'description'       => $r['description'],
          'location'          => $r['location'],
          'location_details'  => $r['location_details'],
          'found_date'        => $r['found_date'],
          'found_time'        => $r['found_time'],
          'report_time'       => $r['report_time'],
          'admin_dropoff'     => (int)$r['admin_dropoff'],

          // UI-ready CDN URLs:
          'photos'            => $photos,

          // Finder
          'found_by'          => [
            'custom_id'   => $r['user_custom_id'],
            'full_name'   => $r['user_full_name'],
            'photo'       => $toCdn($r['user_photo']), // works for key or full URL
            'department'  => $r['user_department'],
          ],
        ];
      }

      return Response::json(['ok'=>true, 'items'=>$items]);
    } catch (Throwable $e) {
      error_log('[found.all] '.$e->getMessage());
      return Response::json(['ok'=>false,'error'=>'server_error'], 500);
    }
  }

public static function destroy($id) {
  
  $claims = requireAuth();

  try {
    $pdo = DB::conn();
    // $tokenUid = (string)($claims['sub'] ?? '');
    // if ($tokenUid === '') {
    //   return Response::json(['ok'=>false,'error'=>'unauthorized'], 401);
    // }

    // Actor = token user
    // $me = $pdo->prepare("SELECT id, role FROM user_data WHERE firebase_uid = ? LIMIT 1");
    // $me->execute([$tokenUid]);
    // $meRow = $me->fetch(PDO::FETCH_ASSOC);
    // if (!$meRow) {
    //   return Response::json(['ok'=>false,'error'=>'user_not_found'], 403);
    // }

    // Load the report by numeric id
    $q = $pdo->prepare("SELECT id, custom_id, user_id FROM found_reports WHERE id = ? LIMIT 1");
    $q->execute([(int)$id]);
    $rep = $q->fetch(PDO::FETCH_ASSOC);
    if (!$rep) {
      return Response::json(['ok'=>false,'error'=>'not_found'], 404);
    }

    // $isAdmin = ($meRow['role'] === 'admin');
    // $isOwner = ((int)$rep['user_id'] === (int)$meRow['id']);

    // Allow if admin OR owner
    // if (!$isAdmin && !$isOwner) {
    //   return Response::json(['ok'=>false,'error'=>'forbidden'], 403);
    // }

    // Delete photos + report
    $pdo->beginTransaction();

    // (Explicit) remove photos for this report (also covered by FK cascade, but explicit as requested)
    $delPhotos = $pdo->prepare("DELETE FROM found_item_photos WHERE found_custom_id = ?");
    $delPhotos->execute([$rep['custom_id']]);

    // Remove the report
    $delReport = $pdo->prepare("DELETE FROM found_reports WHERE id = ? LIMIT 1");
    $delReport->execute([(int)$id]);

    $pdo->commit();
    return Response::json(['ok'=>true, 'deleted'=>['id'=>(int)$id]], 200);

  } catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[found.destroy] '.$e->getMessage());
    return Response::json(['ok'=>false,'error'=>'server_error'], 500);
  }
}


public static function byUser($userCustomId) {
  // Only enforce token presence; no role/self checks
  requireAuth();

  try {
    $pdo = DB::conn();

    // Load target user by custom_id
    $uSel = $pdo->prepare("
      SELECT id, custom_id, full_name, photo, department
      FROM user_data
      WHERE custom_id = ?
      LIMIT 1
    ");
    $uSel->execute([(string)$userCustomId]);
    $target = $uSel->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
      return Response::json(['ok'=>true,'items'=>[]]); 
    }

    // Found reports for this user
    $stm = $pdo->prepare("
      SELECT
        fr.id, fr.custom_id, fr.item_type, fr.item_model, fr.title, fr.description,
        fr.location, fr.location_details, fr.found_date, fr.found_time,
        fr.report_time, fr.admin_dropoff
      FROM found_reports fr
      WHERE fr.user_id = ?
      ORDER BY fr.report_time DESC, fr.id DESC
    ");
    $stm->execute([(int)$target['id']]);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return Response::json(['ok'=>true,'items'=>[]]);

    // Batch-load photos by found custom_id
    $custIds = array_values(array_unique(array_column($rows, 'custom_id')));
    $photoMap = [];

    if (!empty($custIds)) {
      $placeholders = implode(',', array_fill(0, count($custIds), '?'));
      $ph = $pdo->prepare("
        SELECT found_custom_id, photo
        FROM found_item_photos
        WHERE found_custom_id IN ($placeholders)
        ORDER BY id ASC
      ");
      foreach ($custIds as $i => $cid) {
        $ph->bindValue($i+1, $cid, PDO::PARAM_STR);
      }
      $ph->execute();
      while ($r = $ph->fetch(PDO::FETCH_ASSOC)) {
        $photoMap[$r['found_custom_id']][] = $r['photo']; // raw keys
      }
    }

    // CDN helper
    $cdnBase = rtrim((string)($_ENV['AWS_CLOUDFRONT_DOMAIN'] ?? ''), '/');
    $toCdn = function($key) use ($cdnBase) {
      $k = ltrim((string)$key, '/');
      if ($k === '') return null;
      if (preg_match('#^https?://#i', $k)) return $k;
      return $cdnBase !== '' ? ($cdnBase.'/'.$k) : ('/'.$k);
    };

    // Build response
    $items = [];
    foreach ($rows as $r) {
      $cid    = $r['custom_id'];
      $keys   = $photoMap[$cid] ?? [];
      $photos = array_values(array_filter(array_map($toCdn, $keys)));

      $items[] = [
        'id'                => (int)$r['id'],
        'custom_id'         => $cid,
        'item_type'         => $r['item_type'],
        'item_model'        => $r['item_model'],
        'title'             => $r['title'],
        'description'       => $r['description'],
        'location'          => $r['location'],
        'location_details'  => $r['location_details'],
        'found_date'        => $r['found_date'],
        'found_time'        => $r['found_time'],
        'report_time'       => $r['report_time'],
        'admin_dropoff'     => (int)$r['admin_dropoff'],
        'photos'            => $photos,
        'found_by'          => [
          'custom_id'   => $target['custom_id'],
          'full_name'   => $target['full_name'],
          'photo'       => $toCdn($target['photo']),
          'department'  => $target['department'],
        ],
      ];
    }

    return Response::json(['ok'=>true,'items'=>$items]);

  } catch (\Throwable $e) {
    error_log('[found.byUser] '.$e->getMessage());
    return Response::json(['ok'=>false,'error'=>'server_error'], 500);
  }
}



}
