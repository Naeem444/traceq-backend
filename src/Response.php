<?php
// src/Response.php
class Response {
    public static function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        
        // Ensure CORS headers are always sent
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    public static function error($message, $statusCode = 400) {
        self::json(['error' => $message], $statusCode);
    }
    
    public static function success($data = [], $message = null) {
        $response = ['success' => true];
        if ($data) {
            $response['data'] = $data;
        }
        if ($message) {
            $response['message'] = $message;
        }
        self::json($response);
    }
}