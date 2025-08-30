<?php
function requireAuth(): array {
  Config::boot();

  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
    Response::json(['ok'=>false,'error'=>'auth_required','message'=>'Missing Authorization: Bearer <token>'], 401);
  }
  $token = trim($m[1]);

  try {
    $claims = FirebaseAuth::verify($token);
  } catch (Exception $e) {
    $msg = $e->getMessage();
    $code = 'invalid_token';
    if (stripos($msg, 'expired') !== false) $code = 'token_expired';
    elseif (stripos($msg, 'Malformed') !== false || stripos($msg, 'kid') !== false) $code = 'invalid_token';
    Response::json(['ok'=>false,'error'=>$code,'message'=>$msg], 401);
  }

  $uid   = $claims['uid'];
  $email = $claims['email'] ?? null;

  $pdo = DB::conn();

  // Find user; create if first time
  $stmt = $pdo->prepare("SELECT id, custom_id, email, role, is_active FROM user_data WHERE firebase_uid = ?");
  $stmt->execute([$uid]);
  $user = $stmt->fetch();

  if (!$user) {
    $role = 'user';

    if (!empty($_ENV['ADMIN_EMAILS']) && $email) {
      $adminList = array_map('trim', explode(',', $_ENV['ADMIN_EMAILS']));
      if (in_array(strtolower($email), array_map('strtolower', $adminList), true)) {
        $role = 'admin';
      }
    }

    $ins = $pdo->prepare("INSERT INTO user_data (firebase_uid, email, role, is_active) VALUES (?, ?, ?, 1)");
    $ins->execute([$uid, $email, $role]);

    $stmt->execute([$uid]);
    $user = $stmt->fetch();
  }

  if ((int)$user['is_active'] !== 1) {
    Response::json(['ok'=>false,'error'=>'account_disabled','message'=>'Account disabled'], 403);
  }

  // firebase claim may be missing or array
  $provider = null;
  if (isset($claims['firebase'])) {
    if (is_object($claims['firebase'])) {
      $provider = $claims['firebase']->sign_in_provider ?? null;
    } elseif (is_array($claims['firebase'])) {
      $provider = $claims['firebase']['sign_in_provider'] ?? null;
    }
  }

  $user['firebase'] = [
    'uid'      => $uid,
    'email'    => $email,
    'provider' => $provider,
  ];

  return $user;
}
