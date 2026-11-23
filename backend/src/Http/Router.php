<?php
declare(strict_types=1);
namespace App\Http;

use App\Core\ApiException;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $opts];
    }
    public function get(string $p, callable $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, callable $h, array $o = []): void { $this->add('POST', $p, $h, $o); }
    public function put(string $p, callable $h, array $o = []): void { $this->add('PUT', $p, $h, $o); }
    public function delete(string $p, callable $h, array $o = []): void { $this->add('DELETE', $p, $h, $o); }

    /** @return array{0:callable,1:array,2:array} */
    public function match(Request $req): array
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $regex, $h, $o]) {
            if (preg_match($regex, $req->path, $mm)) {
                $pathMatched = true;
                if ($m === $req->method) {
                    return [$h, array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY), $o];
                }
            }
        }
        throw $pathMatched ? new ApiException(405, 'method_not_allowed', 'الطريقة غير مسموحة') : new ApiException(404, 'not_found', 'المورد غير موجود');
    }
}
