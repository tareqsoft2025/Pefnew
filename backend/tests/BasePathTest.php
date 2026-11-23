<?php
declare(strict_types=1);

use App\Http\{BasePath, Response};
use PHPUnit\Framework\TestCase as Base;

/** لا يحتاج قاعدة بيانات: يتحقق من دعم المسار الفرعي (/Tasks). */
final class BasePathTest extends Base
{
    protected function tearDown(): void { BasePath::set(''); }

    public function testRootIsUnchanged(): void
    {
        BasePath::set('');
        $this->assertSame('/login', BasePath::url('/login'));
        $this->assertSame('/missions/3', BasePath::strip('/missions/3'));
        $r = Response::html('<a href="/missions">x</a>');
        $this->assertSame($r, $r->prepared());
    }

    public function testSubPath(): void
    {
        BasePath::set('/Tasks/');
        $this->assertSame('/Tasks', BasePath::get());
        $this->assertSame('/Tasks/login', BasePath::url('/login'));
        $this->assertSame('/Tasks/login', BasePath::url('/Tasks/login'));
        $this->assertSame('//cdn.example/x', BasePath::url('//cdn.example/x'));
        $this->assertSame('https://a.b/c', BasePath::url('https://a.b/c'));
        $this->assertSame('?page=2', BasePath::url('?page=2'));
        $this->assertSame('/', BasePath::strip('/Tasks'));
        $this->assertSame('/api/v1/config', BasePath::strip('/Tasks/api/v1/config'));
        $this->assertSame('/TasksX', BasePath::strip('/TasksX'));
    }

    public function testResponseRewrite(): void
    {
        BasePath::set('/Tasks');
        $html = '<link href="/assets/css/app.css"><a href=\'/missions/1\'>m</a><form action="/logout"></form>'
              . '<img src="//x.y/i.png"><a href="#main">s</a><a href="?p=2">n</a>';
        $p = Response::html($html)->prepared();
        $this->assertStringContainsString('href="/Tasks/assets/css/app.css"', $p->body);
        $this->assertStringContainsString("href='/Tasks/missions/1'", $p->body);
        $this->assertStringContainsString('action="/Tasks/logout"', $p->body);
        $this->assertStringContainsString('src="//x.y/i.png"', $p->body);
        $this->assertStringContainsString('href="#main"', $p->body);
        $this->assertSame('/Tasks/login', Response::redirect('/login')->prepared()->headers['Location']);
        $json = Response::json(['u' => '/api/v1/x']);
        $this->assertSame($json->body, $json->prepared()->body);
    }
}
