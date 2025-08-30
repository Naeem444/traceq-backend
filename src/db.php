<?php
class DB {
  private static $pdo = null;

  public static function conn() {
    if (self::$pdo instanceof \PDO) {
      return self::$pdo;
    }

    Config::boot();

    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $db   = $_ENV['DB_NAME'] ?? 'traceq';
    $user = $_ENV['DB_USER'] ?? 'root';
    $pass = $_ENV['DB_PASS'] ?? '';

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $opts = [
      \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
      self::$pdo = new \PDO($dsn, $user, $pass, $opts);
      return self::$pdo;
    } catch (\PDOException $e) {
    
      Response::json([
        'ok'     => false,
        'error'  => 'db_connect_failed',
        'message'=> 'Database connection failed'
      ], 500);
    }
  }
}
