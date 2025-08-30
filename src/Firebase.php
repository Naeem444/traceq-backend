<?php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

class FirebaseAuth {
  private static function getJWKS(): array {
    $url = $_ENV['FIREBASE_JWKS'] ?? 'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com';

    $cacheFile = __DIR__ . '/../cache/firebase_jwks.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 43200)) {
      $json = file_get_contents($cacheFile);
      $jwks = json_decode($json, true);
      if (is_array($jwks) && isset($jwks['keys'])) return $jwks;
    }

    $json = @file_get_contents($url);
    if ($json === false) throw new Exception('Unable to fetch JWKS');
    $jwks = json_decode($json, true);
    if (!isset($jwks['keys'])) throw new Exception('Bad JWKS format');

    @mkdir(dirname($cacheFile), 0775, true);
    @file_put_contents($cacheFile, $json);
    return $jwks;
  }

  public static function verify(string $idToken): array {
    JWT::$leeway = (int)($_ENV['JWT_LEEWAY'] ?? 60);

    $parts = explode('.', $idToken);
    if (count($parts) !== 3) throw new Exception('Malformed token');

    $header = json_decode(self::b64($parts[0]), true);
    $kid = $header['kid'] ?? null;
    if (!$kid) throw new Exception('Missing kid');

    $jwks = self::getJWKS();

    // parse set first
    $parsed = JWK::parseKeySet($jwks); 
    // array<string, Key>|array<int, Key> depending on lib version
    $key = $parsed[$kid] ?? null;

    // Fallback: find exact JWK by kid
    if (!$key) {
      foreach ($jwks['keys'] as $jwk) {
        if (($jwk['kid'] ?? '') === $kid) {
          $key = JWK::parseKey($jwk); // php-jwt v6+
          break;
        }
      }
    }
    if (!$key instanceof Key) throw new Exception('Unknown key id');

    // Verify + iat/nbf/exp with leeway
    $decoded = JWT::decode($idToken, $key);

    $projectId = $_ENV['JWT_AUDIENCE'] ?? '';
    $iss = "https://securetoken.google.com/{$projectId}";

    if (($decoded->aud ?? null) !== $projectId) throw new Exception('Invalid aud');
    if (($decoded->iss ?? null) !== $iss)       throw new Exception('Invalid iss');
    if (empty($decoded->sub))                   throw new Exception('Invalid sub');

    $out = (array)$decoded;
    $out['uid'] = $decoded->sub;
    return $out;
  }

  private static function b64(string $s): string {
    $pad = strlen($s) % 4; if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'));
  }
}
