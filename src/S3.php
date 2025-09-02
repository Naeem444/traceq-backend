<?php

use Aws\S3\S3Client;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;

class S3Store {
    private $client;
    private $bucket;
    private $region;
    private $cloudfrontDomain;

    public function __construct() {
        Config::boot();
        $key    = trim((string)Config::get('AWS_ACCESS_KEY_ID'));
        $secret = trim((string)Config::get('AWS_SECRET_ACCESS_KEY'));
        $this->region = trim((string)Config::get('AWS_REGION', 'us-east-1'));
        $this->bucket = trim((string)Config::get('AWS_S3_BUCKET'));
        $this->cloudfrontDomain = trim((string)Config::get('AWS_CLOUDFRONT_DOMAIN', ''));

        // Validate env early
        $missing = [];
        if ($key === '')    $missing[] = 'AWS_ACCESS_KEY_ID';
        if ($secret === '') $missing[] = 'AWS_SECRET_ACCESS_KEY';
        if ($this->region === '') $missing[] = 'AWS_REGION';
        if ($this->bucket === '') $missing[] = 'AWS_S3_BUCKET';
        if ($missing) {
            $msg = 'Missing AWS env: ' . implode(', ', $missing);
            error_log('[S3 ENV] ' . $msg);
            throw new RuntimeException($msg);
        }

        try {
            $this->client = new S3Client([
                'version'     => '2006-03-01',
                'region'      => $this->region,
                'credentials' => new Credentials($key, $secret),
            ]);
        } catch (Throwable $e) {
            error_log('[S3 CLIENT] ' . $e->getMessage());
            throw $e;
        }
    }

    public function client() { return $this->client; }
    public function bucket() { return $this->bucket; }

    // Map logical type to folder (no date subdirs)
    public function dirFor($prefix) {
        $map = [
            'lost'  => 'lost-item-photos',
            'found' => 'found-item-photos',
        ];
        return $map[$prefix] ?? 'uploads';
    }

    // Build object key WITHOUT date, e.g. "lost-item-photos/lost_ab12cd34ef56.jpg"
    public function objectKey($dir, $filename) {
        return rtrim($dir, '/') . '/' . ltrim($filename, '/');
    }

    // Filename like "lost_<random>.ext" (no timestamp)
    public function generateFilename($originalName, $prefix = '') {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg');
        $base = $prefix !== '' ? $prefix : 'file';
        $random = bin2hex(random_bytes(8)); // 16 hex chars
        return "{$base}_{$random}.{$ext}";
    }

    // Presign PUT (return headers the browser must send)
    public function presignPut($key, $contentType = 'image/jpeg', $ttl = 900) {
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'ContentType' => $contentType,
        ]);
        $request = $this->client->createPresignedRequest($cmd, "+{$ttl} seconds");
        return [
            'url'     => (string)$request->getUri(),
            'headers' => [
                'Content-Type' => $contentType,
            ],
        ];
    }

    public function presignGet($key, $ttl = 300) {
        $cmd = $this->client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);
        $request = $this->client->createPresignedRequest($cmd, "+{$ttl} seconds");
        return (string)$request->getUri();
    }

    public function publicUrl($key) {
        if ($this->cloudfrontDomain !== '') {
            return "https://{$this->cloudfrontDomain}/" . ltrim($key, '/');
        }
        return "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/" . ltrim($key, '/');
    }

    public function delete($key) {
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
            return true;
        } catch (AwsException $e) {
            error_log('[S3 delete] ' . $e->getMessage());
            return false;
        }
    }
}
