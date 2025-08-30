<?php
require __DIR__ . '/../vendor/autoload.php';
try {
  $pdo = DB::conn();
  echo 'DB OK';
} catch (Throwable $e) {
  http_response_code(500);
  echo 'DB FAIL: ' . $e->getMessage();
}
