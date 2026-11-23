<?php
declare(strict_types=1);
namespace App\Http;

final class Response
{
    public function __construct(public int $status = 200, public string $body = '', public array $headers = [], public ?string $file = null) {}

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
    public static function redirect(string $to, int $status = 302): self { return new self($status, '', ['Location' => $to]); }
    public static function download(string $path, string $mime, string $name): self
    {
        return new self(200, '', ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="' . rawurlencode($name) . '"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], $path);
    }
    public static function bytes(string $data, string $mime, string $name): self
    {
        return new self(200, $data, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="' . rawurlencode($name) . '"', 'Cache-Control' => 'private, no-store']);
    }

    /** نسخة بروابط مُعدّلة للمسار الفرعي (APP_BASE_PATH)؛ مطابقة للأصل عند التشغيل من جذر النطاق. */
    public function prepared(): self
    {
        if (BasePath::get() === '') { return $this; }
        $r = clone $this;
        if (isset($r->headers['Location'])) { $r->headers['Location'] = BasePath::url($r->headers['Location']); }
        if ($r->file === null && str_starts_with($r->headers['Content-Type'] ?? '', 'text/html')) { $r->body = BasePath::rewriteHtml($r->body); }
        return $r;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        foreach ($this->prepared()->headers as $k => $v) { header("$k: $v"); }
        if ($this->file !== null) { readfile($this->file); return; }
        echo $this->prepared()->body;
    }
}
