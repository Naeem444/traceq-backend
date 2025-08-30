<?php
class UserController {
  // POST /user/update-profile
  // Auth required
  // Body (JSON or form): any of full_name, phone, student_id, department, photo
  public static function updateProfile() {
    Config::boot();
    $user = requireAuth();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;

    $allowed = ['full_name','phone','student_id','department','photo'];
    $fields = [];
    $params = [];

    foreach ($allowed as $k) {
      if (array_key_exists($k, $input)) {               // allow clearing with empty string if you want
        $fields[] = "$k = ?";
        $params[] = ($input[$k] === '') ? null : trim((string)$input[$k]);
      }
    }

    if (empty($fields)) {
      Response::json(['ok'=>true, 'message'=>'Nothing to update']);
    }

    $sql = "UPDATE user_data SET " . implode(', ', $fields) . " WHERE id = ?";
    $params[] = $user['id'];
    DB::conn()->prepare($sql)->execute($params);

    $stmt = DB::conn()->prepare("SELECT id, custom_id, email, full_name, phone, student_id, department, role, photo FROM user_data WHERE id=?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

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
        'photo'      => $row['photo'],
      ]
    ]);
  }
    // GET /me
  public static function me() {
    Config::boot();
    $user = requireAuth();

    $stmt = DB::conn()->prepare("SELECT id, custom_id, email, full_name, phone, student_id, department, role, photo FROM user_data WHERE id=?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

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
        'photo'      => $row['photo'],
      ]
    ]);
  }
}
