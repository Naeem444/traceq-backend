<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/controllers/AuthController.php';
require __DIR__ . '/../src/controllers/UserController.php';

// Base path 
$BASE_PATH = '/traceq-backend/public';


header('Access-Control-Allow-Origin: http://localhost:5173');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

// Auth
$router->add('POST', '/auth/signup', function () { AuthController::signup(); });
$router->add('POST', '/auth/signin', function () { AuthController::signin(); });

// User profile
$router->add('POST', '/user/update-profile', function () { UserController::updateProfile(); });

// Me
$router->add('GET', '/me', function () { UserController::me(); });
// Health check
$router->add('GET', '/health', function () {
    Response::json(['ok' => true, 'service' => 'traceq-backend']);
});



// Dispatch
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $BASE_PATH);
