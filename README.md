# Wuyoo

轻量 PHP MVC 框架：实例路由、请求级依赖注入、Eloquent 13、原版 Blade。最低 PHP **8.3**，推荐生产 PHP **8.5**。不依赖完整 Laravel Framework。

## 启动

需要 Composer 2，以及 ctype、fileinfo、filter、json、mbstring、PDO、session、tokenizer 扩展。MySQL 加 pdo_mysql，PostgreSQL 加 pdo_pgsql。

```sh
composer install
SESSION_SECURE=false php -S 127.0.0.1:8080 -t public public/index.php
curl http://127.0.0.1:8080/api/health
curl http://127.0.0.1:8080/api/hello/Ada
```

访问 `/` 可直接渲染首页，无须数据库、Redis 或 MongoDB。示例入口始终转发到框架，开发服务器适合验证动态请求；静态文件交由 Nginx 的 try_files 服务。
配置读取进程环境变量；**不自动读取 `.env` 文件**。也可创建被 Git 忽略的 `config/local.php`，返回与配置文件同层级的数组覆盖本机配置。

```php
<?php
return ['app' => ['debug' => true, 'secure_cookies' => false], 'database' => [
    'default' => 'mysql',
    'connections' => ['mysql' => [
        'host' => '127.0.0.1', 'database' => 'wuyoo',
        'username' => 'wuyoo', 'password' => 'your-local-password',
    ]],
]];
```

生产环境 `APP_DEBUG=false`（默认），HTTPS 下 `SESSION_SECURE=true`（默认）。Nginx 文档根必须是 `public/`，配置示例见 `docs/nginx.conf.example`。仅 storage/ 需要 FPM 用户写权限；上传目录位于 public 外。不要以 root 运行 FPM。启用 OPcache；部署时更新模板源码，必要时清理 `storage/cache/views/` 中编译文件以避免跨发布复用。

## 请求生命周期

`public/index.php -> init.php -> Application 初始化配置/路由/工厂 -> handle(Request) 创建 Scope -> 全局中间件 -> 路由/路由中间件 -> Controller -> 规范化 Response -> Blade 渲染 -> Session Cookie -> 清理资源 -> Response::send()`。

Application 持有不含当前请求数据的配置、路由和工厂。每次请求创建 Scope，Request、Controller、Service、数据库适配器和 Blade 都从该 Scope 获取。DB/Redis/Session/MongoDB 按需创建；连接和模板对象不会在普通 API 请求中提前初始化。

Controller 不需继承基类或手动绑定。Resolver 自动构造路由指定的 Controller：简单类/接口构造参数从 Scope 已注册服务获取，未注册时使用明确的参数默认值；仅可空而无默认值仍报错。缺失依赖的错误包含 Controller、参数和类型，不递归构造任意服务。Union、Intersection、Variadic 及不可直接实例化的 Controller 要求显式 Factory。已注册的 Controller 实例/Factory 优先，Factory 或构造函数异常原样抛出，由请求异常处理器统一处理。自动构造的 Controller 在同一 Scope 内复用，跨请求重新创建。

Action 参数绑定独立保留：按参数名绑定 `{id}` 并严格处理 int/float/bool/BackedEnum，对象参数必须已注册。拒绝 private、static 和魔术 Action；不从 URL 直接拼接类名。

返回 Response、View、数组/JsonSerializable、字符串、HTTP 状态整数或 null（204）均可；只由 Response 输出。中间件签名为 `(Request $request, callable $next): Response`，调用 `$next($request)`。全局、分组、单路由按声明次序进入，反向退出，支持短路。

中间件可以用 `$next($newRequest)` 替换下游 Request；每个 next 边界先同步 Scope，再执行下游。全局中间件可在路由匹配前改写路径/方法；路由中间件只能在路径/方法不变时替换 query、header 等数据。Router 已匹配的参数不会重新绑定。

一旦通过 `Scope::get(Request::class)` 读取 Request（包括 Controller/Service 构造、Action 注入、Session 初始化或分页 resolver），本 Scope 即拒绝替换成另一实例，抛出 LogicException，未捕获时返回 500。同一实例仍可传递。应将改写中间件排在这些消费者之前；不会自动重建已初始化服务。Scope 同样禁止用 `set()` 覆盖已经 `get()` 过的其他服务，以免旧依赖引用继续存活。中间件自身传入的 `$request` 是该层的快照，不会在下游替换后原地改变，退出阶段不要假设它已被同步。

## 路由、Controller、Service

`routes/web.php` / `routes/api.php` 返回接收 Router 的注册函数。配置加载完成后路由冻结。

```php
$router->get('/hello/{name}', [HomeController::class, 'hello']);
$router->get('/legacy/(:any)', [HomeController::class, 'hello']);
$router->group(['prefix' => 'v1', 'namespace' => 'App\\Http\\Controllers'], function ($router) {
    $router->get('/hello/{name}', 'HomeController@hello');
});
```

支持 get/post/put/patch/delete/head/options/map/any。静态路径优先；动态交叉约束按文档规则排序。404 / 405 带正确 Allow，HEAD 优先显式路由再回退 GET，发送时去除响应体；OPTIONS 对已知路径自动返回 204。

新增 Controller 只需创建类并注册路由。例如在 `app/Http/Controllers/StatusController.php` 中：

```php
namespace App\Http\Controllers;

use Wuyoo\Support\Logger;

final class StatusController
{
    public function __construct(private Logger $logger) {}

    public function show(): array
    {
        $this->logger->info('Status requested');
        return ['status' => 'ok']; // 自动转换为 JSON
    }
}
```

在 `routes/api.php` 的 api 分组中添加 `$router->get('/status', [StatusController::class, 'show']);`（先导入该类），即可访问 `/api/status`。Logger 是框架已注册服务；无构造依赖的 Controller 同样无须绑定。

应用服务仍在 `config/services.php` 显式注册；只有超出简单构造规则的 Controller 才需要 Factory：

```php
$app->bind(MyService::class, fn (Wuyoo\Support\Scope $s) => new MyService($s->get(Wuyoo\Support\Logger::class)));
// 如构造函数还需要没有默认值的字符串配置：
$app->bind(MyController::class, fn ($s) => new MyController($s->get(MyService::class), 'custom'));
```

服务注册和 Factory 定义属于应用配置，返回的服务在每个 Scope 内缓存。不要在持久 Factory 闭包中捕获请求或用户对象。也可把已创建的 Controller 对象作为路由 handler，或在 Scope 中显式 `set()` 实例；直接对象的生命周期由调用方负责。

正式应用骨架只包含 `HomeController` 首页/JSON、健康检查及空服务注册入口，没有预置业务 Model 或业务路由。以下 Model、数据库表和模板片段仅为文档示例，按需创建。

Web 分组默认挂 CSRF。增加 Web POST 时将其放入现有分组。API 默认只有只读端点；写接口需明确添加业务认证中间件。使用 Cookie 认证的 API 同样必须挂 CSRF，不能因 `/api` 前缀自动豁免。

## 数据库与 Model

设置 `DB_CONNECTION=mysql` 或 `pgsql`、`DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`。PostgreSQL 可设置 `DB_SSLMODE`；生产跨主机连接应按环境配置 TLS。

示例表（由开发者在自己的开发数据库执行，框架不带迁移）：

```sql
-- MySQL；PostgreSQL 将 BIGINT AUTO_INCREMENT 换为 BIGSERIAL
CREATE TABLE entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL UNIQUE,
    status INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

创建 `app/Models/Entry.php`，直接继承 Eloquent 适配基类，无须注册 Model：

```php
namespace App\Models;

final class Entry extends \Wuyoo\Database\Model
{
    protected $fillable = ['title', 'status'];
    protected $casts = ['status' => 'integer'];
}
```

在请求或 CLI 作用域中使用：

```php
use Wuyoo\Database\DB;
use App\Models\Entry;

$entries = DB::table('entries')->where('status', 1)->orderByDesc('id')->limit(20)->get();
$entry = Entry::create(['title' => 'PHP', 'status' => 1]);
$entry->title = 'Modern PHP';
$entry->save();
DB::transaction(function () {
    DB::table('entries')->upsert([['title' => 'New', 'status' => 1]], ['title'], ['status']);
});
$page = Entry::orderBy('id')->paginate(20); // page 来自当前请求
$page->withQueryString(); // 显式保留筛选等 query；也可 appends(['sort' => 'title'])
$cursorPage = Entry::orderBy('id')->cursorPaginate(20, ['*'], 'after'); // 自定义游标名
// Blade 中 {{ $page->links() }} 使用框架内置、正确转义的分页视图。
```

多连接使用 `DB::connection('pgsql')->table(...)` 或 Model 原有 connection 配置。JOIN、子查询、分页、聚合、cursor、chunkById 与事务/Savepoint 使用原版机制。复杂关系/Cast/Observer 非保证范围，详见 UPSTREAM。

CLI 脚本同样有明确资源边界：

```php
$app = require __DIR__.'/init.php';
$app->using(new Wuyoo\Http\Request(), function (Wuyoo\Support\Scope $scope) {
    $count = App\Models\Entry::count();
    $scope->get(Wuyoo\Support\Logger::class)->info('Entries counted', ['count' => $count]);
});
```

`DB` 是仅在作用域内激活的兼容桥，不是持久连接单例。finally 中回滚未结束事务、断开连接、清理 Model resolver/boot、Paginator 和 Session 数据。
普通页码、游标（默认 `cursor`，支持自定义名称）和 query 都来自有效 Request；缺失或不能解码的游标使用第一页。分页路径使用 `Request::encodedPath`，Router/Action 继续使用解码后的 `path`。Query String 的保留遵循上游 `withQueryString()` / `appends()` API，不隐式追加所有参数。
核心仅支持串行处理；会拒绝嵌套/并发请求。未实现 Worker 服务器，也不承诺 Swoole/RoadRunner/协程直接兼容。业务不要在路由闭包、静态字段、持久中间件对象或 Model boot 中保存请求/用户对象。

## View 与 HTTP

Controller 中 `return Response::view('home', ['title' => 'Wuyoo', 'message' => 'Hello']);` 即可渲染模板。自定义页面只需在 `resources/views/` 创建对应的 `.blade.php` 文件。下面的 `entries/show.blade.php` 以 Controller 传入的 `$entry` 为例：

```blade
@extends('layouts.app')
@section('content')
    <h1>{{ $entry->title }}</h1>
    <x-notice type="success">已保存</x-notice>
    <form method="post" action="/entries">
        @csrf
        <input name="title" value="{{ $entry->title }}">
    </form>
@endsection
```

原版 `{{ }}` 转义、`{!! !!}` 可信原始 HTML、条件、循环、继承、include、push/stack、匿名组件、简单类组件与自定义指令可用。类组件命名空间在 `config/view.php` 配置。自定义指令通过请求级 `$scope->get(Blade::class)->directive(...)` 注册。Blade 不是 Laravel Application，`route()`、`@auth` 等依赖未提供。

Blade 首次渲染或获取 `factory()` 时激活本 Scope 的 Illuminate Container；直接 `factory()->make(...)->render()`、Controller/CLI 中 `paginate()->links('custom')->render()` 和 View 响应使用相同上下文。Scope 结束时清理组件缓存并恢复先前 Container。Factory、View 和分页渲染必须在所属 Scope 内完成，不要保存到后续请求使用。

Request 提供 query/input/json/header/cookie/file/rawBody/method/path/validate；JSON 要求对象，错误返回 400；验证只返回指定字段，错误 422。验证规则有 required/nullable/string/integer/boolean/email/array/in/min/max，不会自动把业务入参全部转型。只有 Controller 路由参数做显式类型绑定。

```php
$data = $request->validate(['title' => 'required|string|max:200', 'status' => 'integer|in:0,1']);
return Response::json($data, 201)->withHeader('X-Example', 'value');
return Response::redirect('/entries', 303);
return Response::html('<p>OK</p>')->withCookie('preference', 'compact');
```

Cookie 默认 Secure、HttpOnly、SameSite=Lax。Redirect 仅接受本站绝对路径。`Session` 提供 get/put/token/regenerate，登录/提权后调用 regenerate。上传用 `$request->file('image')->store($app->basePath.'/storage/uploads')`，默认仅接受真实 JPEG/PNG、限制大小、随机命名，客户端文件名不参与保存路径。生产 Nginx 与 PHP post_max_size/upload_max_filesize 的限制需要与应用 2 MiB 请求上限一致。

Controller、中间件和模板异常在有效 Scope 中转换为 Response，再统一附加已启动 Session 的 Cookie 和 `private, no-store`，最后关闭会话。异常不会回滚此前写入的 Session 数据；轮换后的新 ID 也会随错误响应返回。初始化或资源清理本身失败仍走外层错误兜底。

请求头不信任 X-Forwarded-*，反向代理应显式配置可信边界。统一异常生产响应不含 SQL、密钥或内部路径；日志记录异常类型及位置，省略原始异常消息/参数，用户自定义日志也不要传入敏感信息。生产 APP_DEBUG 禁用。

## 可选模块

Redis 需要 phpredis（ext-redis），配置 `REDIS_HOST/PORT/PASSWORD/USERNAME/DATABASE`。从 Scope 获取 `Wuyoo\Cache\RedisCache`，使用 get/put/forget；JSON 序列化、秒级 TTL、每个作用域内复用连接，结束后断开。不使用 PHP 对象反序列化、默认不启用持久跨请求连接。

MongoDB 使用官方 Library，按需安装：

```sh
# 先在 PHP 运行时启用 ext-mongodb
composer require 'mongodb/mongodb:^2.1'
```

配置 `MONGODB_URI`、`MONGODB_DATABASE`；Scope 获取 `Wuyoo\Database\Mongo`，调用 `database()->selectCollection('entries')` 使用官方 API。缺少扩展/Library 会明确失败。与 SQL ORM 独立，当前未验证 MongoDB 实库读写。

## 验证

不带 PHPUnit/Pest/PHPStan，正式源码目录不保留测试脚本。测试套件独立于运行源码；服务器原外部目录 `/www/wuyoo-tests` 当前已不存在，需要验证时另行取得套件并确认脚本路径；`fixtures/Book.php` 仅是外部 SQL/分页夹具。Composer 只保留依赖安装所需的裁剪脚本，没有指向已删除 `tests/` 的命令。

验证前准备项目外的源码副本，安装锁定依赖，排除本机 `config/local.php`、`.env*` 和已有运行数据，并创建空的 `storage/cache/views`、`storage/logs`、`storage/sessions`、`storage/uploads` 目录。设置 `WUYOO_TEST_ROOT` 指向此隔离副本；未设置或路径无效时，测试脚本会明确拒绝执行。以下 `php` 应替换为要验证的 PHP 8.3、8.4 或 8.5 运行时：

```sh
composer validate
composer check-platform-reqs
export WUYOO_TEST_ROOT=/absolute/path/to/isolated-wuyoo
php /www/wuyoo-tests/run.php
php /www/wuyoo-tests/lifecycle.php
php /www/wuyoo-tests/http.php
TEST_DB_DRIVER=sqlite php /www/wuyoo-tests/database.php
```

核心与生命周期共 27 组，HTTP 7 组，SQLite 7 组；SQLite 验证需要 pdo_sqlite。生命周期脚本自行创建并清理独立 SQLite 文件、模板和 Session；其他脚本的缓存、日志和上传均位于指定的隔离源码副本。完成后删除本轮副本。

真实 SQL 测试必须在**专用隔离实例**运行，只接受 `wuyoo_test_` 开头的数据库名：

```sh
WUYOO_TEST_DATABASE=1 TEST_DB_DRIVER=mysql TEST_DB_DATABASE=wuyoo_test_framework \
TEST_DB_PORT=13306 TEST_DB_USERNAME=wuyoo_test TEST_DB_PASSWORD=test_password php /www/wuyoo-tests/database.php
WUYOO_TEST_DATABASE=1 TEST_DB_DRIVER=pgsql TEST_DB_DATABASE=wuyoo_test_framework \
TEST_DB_PORT=15432 TEST_DB_USERNAME=wuyoo_test TEST_DB_PASSWORD=test_password php /www/wuyoo-tests/database.php
WUYOO_TEST_REDIS=1 TEST_REDIS_PORT=16379 php /www/wuyoo-tests/redis.php
```

`.github/workflows/verify.yml` 保留运行时矩阵及依赖检查；外部回归脚本不随框架源码分发，测试须另外取得该目录。未在远程 GitHub 运行。实测环境和未验证内容见 `docs/STATUS.md`。没有性能数字或性能优于其他框架的断言。

PHP 版本策略按 [PHP 官方支持表](https://www.php.net/supported-versions.php) 复核：PHP 8.3 安全支持于 **2027-12-31** 结束，应在此日期之前评估提高最低版本；升级会同步调整 Composer platform 和版本矩阵。
