<?php
declare(strict_types=1);
namespace App\Http;

final class Request
{
    public array $attrs = [];
    public function __construct(
        public string $method, public string $path, public array $query = [], public array $body = [],
        public array $headers = [], public array $files = [], public array $cookies = [], public string $ip = '0.0.0.0',
        public string $raw = '',
    ) {}

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = BasePath::strip($path);
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) { $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v; }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) { $headers['content-type'] = $_SERVER['CONTENT_TYPE']; }
        $raw = file_get_contents('php://input') ?: '';
        $body = $_POST;
        if (str_contains($headers['content-type'] ?? '', 'json') && $raw !== '') {
            $j = json_decode($raw, true);
            if (!is_array($j)) { throw new \App\Core\ApiException(400, 'bad_json', 'JSON غير صالح'); }
            $body = $j;
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $body, $headers, $_FILES, $_COOKIE, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $raw);
    }

    public function header(string $k): ?string { return $this->headers[strtolower($k)] ?? null; }
    public function bearer(): ?string
    {
        $h = $this->header('authorization');
        return $h && preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }
    public function input(string $k, mixed $d = null): mixed { return $this->body[$k] ?? $this->query[$k] ?? $d; }
    public function isApi(): bool { return str_starts_with($this->path, '/api/'); }
}
