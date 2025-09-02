<?php
// src/config.php
// Minimal Config helper using vlucas/phpdotenv (composer).
// No namespaces (per project convention).

// We "use" the class from composer; harmless if Dotenv not installed yet.
use Dotenv\Dotenv;

class Config {
    private static $loaded = false;

    // Load .env from project root once
    public static function boot() {
        if (self::$loaded) return;

        // project root = one level above /src
        $root = dirname(__DIR__);

        // Load .env if present
        if (class_exists('Dotenv\Dotenv') && file_exists($root . '/.env')) {
            // safeLoad(): missing file/keys won’t throw
            $dotenv = Dotenv::createImmutable($root);
            $dotenv->safeLoad();
        }

        // Mark loaded so we don't repeat
        self::$loaded = true;
    }

    /**
     * Get config value from (priority):
     * 1) $_ENV
     * 2) getenv()
     * else default
     */
    public static function get($key, $default = null) {
        self::boot();

        // Prefer $_ENV
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        // Fallback to getenv
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }

        return $default;
    }
}
