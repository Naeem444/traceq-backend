<?php
use Dotenv\Dotenv;

class Config {
    public static function boot(): void {
        static $booted = false;
        if ($booted) return;
        $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        $booted = true;
    }
}
