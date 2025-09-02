<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/controllers/AuthController.php';
require __DIR__ . '/../src/controllers/UserController.php';
require __DIR__ . '/../src/controllers/UploadController.php';
require __DIR__ . '/../src/controllers/LostController.php';
require __DIR__ . '/../src/controllers/FoundController.php';
// Base path 
$BASE_PATH = '/traceq-backend/public';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

// Auth routes
$router->add('POST', '/auth/signup', function () { AuthController::signup(); });
$router->add('POST', '/auth/signin', function () { AuthController::signin(); });

// User profile routes
$router->add('POST', '/user/update-profile', function () { UserController::updateProfile(); });
$router->add('GET', '/me', function () { UserController::me(); });

// Uploads
$router->add('POST', '/upload/presign', function () { UploadController::generatePresignedUrls(); });
$router->add('POST', '/upload/confirm', function () { UploadController::confirmUploads(); });
$router->add('DELETE', '/upload/delete', function () { UploadController::deleteFile(); });

// Lost report
$router->add('POST', '/report-lost-item', function () { LostController::create(); });

// /report-found-item
$router->add('POST', '/report-found-item', function () {
  return FoundController::create();
});

// Health check
$router->add('GET', '/health', function () {
  $payload = [
    'ok'      => true,
    'service' => 'traceq-backend',
    'time'    => gmdate('c'),
    'db'  => [
      'connected' => false,
    ],
  ];

  try {
    $pdo = DB::conn();
    $row = $pdo->query("SELECT 1 AS ping, VERSION() AS mysql_version, DATABASE() AS db_name")->fetch();

    $payload['db'] = [
      'connected'     => true,
      'ping'          => (int)($row['ping'] ?? 0) === 1,
      'mysql_version' => $row['mysql_version'] ?? null,
      'db_name'       => $row['db_name'] ?? null,
    ];
  } catch (Throwable $e) {
   
    error_log('[HEALTH DB] ' . $e->getMessage());
    $payload['ok'] = false;
    $payload['error'] = 'db_connect_failed';
  }

  Response::json($payload, $payload['ok'] ? 200 : 500);
});


// Dispatch
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $BASE_PATH);