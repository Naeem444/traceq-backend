
<?php

class DB {
  private static $pdo = null;

  public static function conn() {
    if (self::$pdo instanceof \PDO) {
      return self::$pdo;
    }

    Config::boot(); // loads .env into $_ENV

    // single url
    $url = $_ENV['MYSQL_URL'] ?? $_ENV['DATABASE_URL'] ?? null;

    $host = null; $port = null; $db = null; $user = null; $pass = null;

    if ($url) {
      $parts = parse_url($url);
      if ($parts === false || !isset($parts['host'], $parts['path'])) {
        error_log('[DB] Invalid MYSQL_URL/DATABASE_URL format');
        return self::failEnv('Invalid MYSQL_URL/DATABASE_URL');
      }
      $host = $parts['host'];
      $port = isset($parts['port']) ? (int)$parts['port'] : 3306;
      $db   = ltrim($parts['path'], '/ ');
      $user = isset($parts['user']) ? urldecode($parts['user']) : null;
      $pass = isset($parts['pass']) ? urldecode($parts['pass']) : null;
    } else {
      // Discrete env vars (all required)
      $host = $_ENV['DB_HOST']        ?? $_ENV['MYSQLHOST']     ?? null;
      $port = (int)($_ENV['DB_PORT']  ?? $_ENV['MYSQLPORT']     ?? 0);
      $db   = $_ENV['DB_NAME']        ?? $_ENV['MYSQLDATABASE'] ?? null;
      $user = $_ENV['DB_USER']        ?? $_ENV['MYSQLUSER']     ?? null;
      $pass = $_ENV['DB_PASS']        ?? $_ENV['MYSQLPASSWORD'] ?? null;
    }

    // Require everything (no hardcoded fallbacks)
    $missing = [];
    if (!$host) $missing[] = 'DB_HOST/MYSQLHOST or MYSQL_URL';
    if (!$db)   $missing[] = 'DB_NAME/MYSQLDATABASE or MYSQL_URL';
    if (!$user) $missing[] = 'DB_USER/MYSQLUSER or MYSQL_URL';
    if ($port === 0) $missing[] = 'DB_PORT/MYSQLPORT (or port in MYSQL_URL)';
    if (!empty($missing)) {
      return self::failEnv('Missing env: ' . implode(', ', $missing));
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    $opts = [
      \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES   => false,
      \PDO::ATTR_PERSISTENT         => false,
      \PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"
    ];

   

    try {
      self::$pdo = new \PDO($dsn, $user, $pass, $opts);
      return self::$pdo;
    } catch (\PDOException $e) {
      error_log('[DB] Connection failed: ' . $e->getMessage());
      Response::json([
        'ok'      => false,
        'error'   => 'db_connect_failed',
        'message' => 'Database connection failed'
      ], 500);
      exit;
    }
  }

  private static function failEnv($msg) {
    error_log('[DB] ' . $msg);
    Response::json([
      'ok'      => false,
      'error'   => 'env_missing',
      'message' => 'Server configuration error'
    ], 500);
    exit;
  }
}
