<?php
class AuthController {
  // --- Parse body robustly (JSON, x-www-form-urlencoded, multipart) ---
  private static function getBody(): array {
    // Prefer JSON if content-type says json or the raw body looks like JSON
    $raw = file_get_contents('php://input');
    $ct  = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    $isLikelyJson = stripos($ct, 'application/json') !== false || (strlen($raw) && ($raw[0] === '{' || $raw[0] === '['));
    if ($isLikelyJson) {
      $data = json_decode($raw, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
      }
      // If JSON failed but $_POST has values (e.g., multipart), fall through
    }

    // Fallbacks: form or multipart
    if (!empty($_POST)) return $_POST;

    // As a last resort, try to parse urlencoded raw body
    $parsed = [];
    if ($raw && strpos($raw, '=') !== false) {
      parse_str($raw, $parsed);
    }
    return is_array($parsed) ? $parsed : [];
  }

  // Normalize input: accept camelCase and snake_case
  private static function normalizeInput(array $input): array {
    $map = [
      'fullName'   => 'full_name',
      'studentId'  => 'student_id',
      // department, phone, photo kept as-is
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

    $sets   = [];
    $params = [];

    foreach ($allowed as $k) {
      // Only update when the key exists in incoming payload (even if it’s empty string)
      if (array_key_exists($k, $input)) {
        $sets[] = "$k = :$k";
        // Convert "" to NULL so you can clear a field intentionally
        $val = $input[$k];
        $params[":$k"] = ($val === '') ? null : trim((string)$val);
      }
    }

    // Always keep email in sync with Firebase (when provided)
    if ($tokenEmail) {
      $sets[] = "email = :email";
      $params[":email"] = $tokenEmail;
    }

    if (!empty($sets)) {
      $sql = "UPDATE user_data SET " . implode(', ', $sets) . " WHERE id = :id";
      $params[":id"] = $userId;

      $pdo = DB::conn();
      // Ensure we actually throw on errors (important if your DB class didn’t set it)
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
    }

    $stmt = DB::conn()->prepare("SELECT id, custom_id, email, full_name, phone, student_id, department, role, photo FROM user_data WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
  }

  // POST /auth/signup
  public static function signup() {
    try {
      Config::boot();
      $user = requireAuth(); // must return ['id'=>..., 'firebase'=>['email'=>...]] at minimum

      $input = self::getBody();
      $input = is_array($input) ? $input : [];
      $input = self::normalizeInput($input);

      $tokenEmail = $user['firebase']['email'] ?? null;

      $row = self::updateAndReturnUser($user['id'], $input, $tokenEmail);

      Response::json([
        'ok' => true,
        'user' => [
          'id' => (int)$row['id'],
          'custom_id' => $row['custom_id'],
          'email' => $row['email'],
          'full_name' => $row['full_name'],
          'phone' => $row['phone'],
          'student_id' => $row['student_id'],
          'department' => $row['department'],
          'role' => $row['role'],
          'photo' => $row['photo'],
        ],
      ]);
    } catch (Throwable $e) {
      Response::json(['ok'=>false, 'error'=>'server_error', 'message'=>$e->getMessage()], 500);
    }
  }
}
