<?php
class AuthController {
  // Normalize input: accept camelCase and snake_case
  private static function normalizeInput(array $input): array {
    $map = [
      'fullName'   => 'full_name',
      'studentId'  => 'student_id',
      // 'department' stays same
      // 'phone' stays same
      // 'photo' stays same
    ];
    foreach ($map as $from => $to) {
      if (array_key_exists($from, $input) && !array_key_exists($to, $input)) {
        $input[$to] = $input[$from];
      }
    }
    return $input;
  }

  // UPDATE helper
  private static function updateAndReturnUser($userId, array $input, $tokenEmail = null) {
    $allowed = ['full_name','phone','student_id','department','photo'];
    $fields  = [];
    $params  = [];

    foreach ($allowed as $k) {
      if (array_key_exists($k, $input)) {
        $fields[] = "$k = ?";

        $val = $input[$k];
        $params[] = ($val === '') ? null : trim((string)$val);
      }
    }

    // Keep email in sync with Firebase
    if ($tokenEmail) {
      $fields[] = "email = ?";
      $params[] = $tokenEmail;
    }

    if (!empty($fields)) {
      $sql = "UPDATE user_data SET " . implode(', ', $fields) . " WHERE id = ?";
      $params[] = $userId;
      DB::conn()->prepare($sql)->execute($params);
    }

    $stmt = DB::conn()->prepare("SELECT id, custom_id, email, full_name, phone, student_id, department, role, photo FROM user_data WHERE id=?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
  }


  // POST /auth/signup
  public static function signup() {
    try {
      Config::boot();
      $user = requireAuth();

      $input = json_decode(file_get_contents('php://input'), true);
      if (!is_array($input)) $input = $_POST;
      $input = self::normalizeInput($input);

      $tokenEmail = $user['firebase']['email'] ?? null;

      $row = self::updateAndReturnUser($user['id'], $input, $tokenEmail);

      Response::json([
        'ok'=>true,
        'user'=>[
          'id'=>(int)$row['id'], 'custom_id'=>$row['custom_id'], 'email'=>$row['email'],
          'full_name'=>$row['full_name'], 'phone'=>$row['phone'], 'student_id'=>$row['student_id'],
          'department'=>$row['department'], 'role'=>$row['role'], 'photo'=>$row['photo'],
        ]
      ]);
    } catch (Throwable $e) {
      Response::json(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], 500);
    }
  }

  public static function signin() {
    try {
      Config::boot();
      $user = requireAuth();

      $input = json_decode(file_get_contents('php://input'), true);
      if (!is_array($input)) $input = $_POST;
      $input = self::normalizeInput($input);

      $tokenEmail = $user['firebase']['email'] ?? null;
      if ($tokenEmail && isset($user['email']) && strcasecmp($user['email'], $tokenEmail) === 0) {
        $tokenEmail = null;
      }

      $row = self::updateAndReturnUser($user['id'], $input, $tokenEmail);

      Response::json([
        'ok'=>true,
        'user'=>[
          'id'=>(int)$row['id'], 'custom_id'=>$row['custom_id'], 'email'=>$row['email'],
          'full_name'=>$row['full_name'], 'phone'=>$row['phone'], 'student_id'=>$row['student_id'],
          'department'=>$row['department'], 'role'=>$row['role'], 'photo'=>$row['photo'],
        ]
      ]);
    } catch (Throwable $e) {
      Response::json(['ok'=>false,'error'=>'server_error','message'=>$e->getMessage()], 500);
    }
  }

}
