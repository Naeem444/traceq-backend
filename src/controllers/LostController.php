<?php

class LostController {
  // POST /report-lost-item

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
// GET /lost-items
public static function all() {
  // Enforce Firebase Bearer token for every request
  $claims = requireAuth(); // call ensures auth

  try {
    $pdo = DB::conn();

    $where = [];
    $args  = [];

    if (isset($_GET['item_type']) && $_GET['item_type'] !== '') {
      $where[] = 'lr.item_type = ?';
      $args[]  = (string)$_GET['item_type'];
    }
    if (isset($_GET['location']) && $_GET['location'] !== '') {
      $where[] = 'lr.location = ?';
      $args[]  = (string)$_GET['location'];
    }
    if (isset($_GET['q']) && $_GET['q'] !== '') {
      $q = '%'.trim((string)$_GET['q']).'%';
      $where[] = '(lr.title LIKE ? OR lr.description LIKE ? OR lr.item_model LIKE ?)';
      $args[]  = $q; $args[] = $q; $args[] = $q;
    }

    $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

    // main query (join reporter)
    $sql = "
      SELECT
        lr.id,
        lr.custom_id,
        lr.item_type,
        lr.item_model,
        lr.title,
        lr.description,
        lr.location,
        lr.location_details,
        lr.lost_date,
        lr.lost_time,
        lr.report_time,
        u.custom_id   AS user_custom_id,
        u.full_name   AS user_full_name,
        u.photo       AS user_photo,
        u.department  AS user_department
      FROM lost_reports lr
      JOIN user_data u ON u.id = lr.user_id
      $whereSql
      ORDER BY lr.report_time DESC, lr.id DESC
    ";
    $stm = $pdo->prepare($sql);
    $stm->execute($args);
    $rows = $stm->fetchAll(\PDO::FETCH_ASSOC);

    if (!$rows) {
      return Response::json(['ok' => true, 'items' => []], 200);
    }

    // batch-load photos by custom_id
    $custIds  = array_values(array_unique(array_column($rows, 'custom_id')));
    $photoMap = [];

    if (!empty($custIds)) {
      $in = implode(',', array_fill(0, count($custIds), '?'));
      $ph = $pdo->prepare("
        SELECT lost_custom_id, photo
        FROM lost_item_photos
        WHERE lost_custom_id IN ($in)
        ORDER BY id ASC
      ");
      foreach ($custIds as $i => $cid) {
        $ph->bindValue($i+1, $cid, \PDO::PARAM_STR);
      }
      $ph->execute();
      while ($r = $ph->fetch(\PDO::FETCH_ASSOC)) {
        $photoMap[$r['lost_custom_id']][] = $r['photo']; // raw S3 key
      }
    }

    // build absolute CDN URLs
    $cdnBase = rtrim((string)(
      $_ENV['AWS_CLOUDFRONT_DOMAIN'] ?? getenv('AWS_CLOUDFRONT_DOMAIN') ?? ''
    ), '/');

    $toCdn = function ($key) use ($cdnBase) {
      $k = ltrim(trim((string)$key), '/');
      if ($k === '') return null;
      if (preg_match('#^https?://#i', $k)) return $k; // already absolute
      return $cdnBase !== '' ? ($cdnBase.'/'.$k) : ('/'.$k);
    };

    // response
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
        'lost_date'         => $r['lost_date'],
        'lost_time'         => $r['lost_time'],
        'report_time'       => $r['report_time'],

        // UI-ready CDN URLs
        'photos'            => $photos,

        'reported_by'       => [
          'custom_id'   => $r['user_custom_id'],
          'full_name'   => $r['user_full_name'],
          'photo'       => $toCdn($r['user_photo']), /* CDN-ify if relative */
          'department'  => $r['user_department'],
        ],
      ];
    }

    return Response::json(['ok' => true, 'items' => $items], 200);

  } catch (\Throwable $e) {
    error_log('[lost.all] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    return Response::json(['ok' => false, 'error' => 'server_error'], 500);
  }
}

public static function destroy($idOrCustom) {
  $claims = requireAuth();
  try {
    $pdo = DB::conn();

    $tokenUid = (string)($claims['sub'] ?? '');
    if ($tokenUid === '') {
      return Response::json(['ok'=>false,'error'=>'unauthorized'], 403);
    }

    $me = $pdo->prepare("SELECT id, role, firebase_uid FROM user_data WHERE firebase_uid = ? LIMIT 1");
    $me->execute([$tokenUid]);
    $meRow = $me->fetch(PDO::FETCH_ASSOC);
    if (!$meRow) return Response::json(['ok'=>false,'error'=>'user_not_found'], 403);
    $isAdmin = ($meRow['role'] === 'admin');

    $q = $pdo->prepare("
      SELECT lr.id, lr.custom_id, u.firebase_uid AS owner_uid
      FROM lost_reports lr
      JOIN user_data u ON u.id = lr.user_id
      WHERE lr.id = ? OR lr.custom_id = ?
      LIMIT 1
    ");
    $q->execute([(int)$idOrCustom, (string)$idOrCustom]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return Response::json(['ok'=>false,'error'=>'not_found'], 404);

    $isOwner = ($row['owner_uid'] === $tokenUid);
    if (!$isAdmin && !$isOwner) {
      return Response::json(['ok'=>false,'error'=>'forbidden'], 403);
    }

    $pdo->beginTransaction();
    // FK on lost_item_photos(lost_custom_id)->lost_reports(custom_id) will cascade
    $del = $pdo->prepare("DELETE FROM lost_reports WHERE id = ? OR custom_id = ? LIMIT 1");
    $del->execute([(int)$idOrCustom, (string)$idOrCustom]);
    $pdo->commit();

    http_response_code(204);
    return;
  } catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[lost.destroy] '.$e->getMessage());
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
      return Response::json(['ok'=>true,'items'=>[]]); // empty if user not found
    }

    // Lost reports for this user
    $stm = $pdo->prepare("
      SELECT
        lr.id, lr.custom_id, lr.item_type, lr.item_model, lr.title, lr.description,
        lr.location, lr.location_details, lr.lost_date, lr.lost_time, lr.report_time
      FROM lost_reports lr
      WHERE lr.user_id = ?
      ORDER BY lr.report_time DESC, lr.id DESC
    ");
    $stm->execute([(int)$target['id']]);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return Response::json(['ok'=>true,'items'=>[]]);

    // Batch-load photos by lost custom_id
    $custIds = array_values(array_unique(array_column($rows, 'custom_id')));
    $photoMap = [];

    if (!empty($custIds)) {
      $placeholders = implode(',', array_fill(0, count($custIds), '?'));
      $ph = $pdo->prepare("
        SELECT lost_custom_id, photo
        FROM lost_item_photos
        WHERE lost_custom_id IN ($placeholders)
        ORDER BY id ASC
      ");
      foreach ($custIds as $i => $cid) {
        $ph->bindValue($i+1, $cid, PDO::PARAM_STR);
      }
      $ph->execute();
      while ($r = $ph->fetch(PDO::FETCH_ASSOC)) {
        $photoMap[$r['lost_custom_id']][] = $r['photo']; // raw keys
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
        'lost_date'         => $r['lost_date'],
        'lost_time'         => $r['lost_time'],
        'report_time'       => $r['report_time'],
        'photos'            => $photos,
        'reported_by'       => [
          'custom_id'   => $target['custom_id'],
          'full_name'   => $target['full_name'],
          'photo'       => $toCdn($target['photo']),
          'department'  => $target['department'],
        ],
      ];
    }

    return Response::json(['ok'=>true,'items'=>$items]);

  } catch (\Throwable $e) {
    error_log('[lost.byUser] '.$e->getMessage());
    return Response::json(['ok'=>false,'error'=>'server_error'], 500);
  }
}


}
