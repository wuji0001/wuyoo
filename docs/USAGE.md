# Wuyoo 使用手册

Wuyoo 是轻量的 PHP MVC 框架，适合需要路由、Controller、数据库和模板的 Web 站点及 JSON API。它使用固定版本的 Laravel 13 Eloquent、Query Builder 和原版 Blade，但不包含完整 Laravel Application。熟悉 Laravel 的开发者可以沿用这些组件的基本用法；服务注册、路由、请求与响应应使用本手册中的 Wuyoo API。

## 安装与启动

需要 PHP **8.3 或以上**（推荐 8.5）、Composer 2，以及 PHP 扩展 `ctype`、`fileinfo`、`filter`、`json`、`mbstring`、`PDO`、`session`、`tokenizer`。MySQL 需要 `pdo_mysql`，PostgreSQL 需要 `pdo_pgsql`。Redis 和 MongoDB 的依赖按需安装。

在项目根目录运行：

```sh
composer install
SESSION_SECURE=false php -S 127.0.0.1:8080 -t public public/index.php
```

访问 `http://127.0.0.1:8080/` 或 `/api/health`。首页和健康检查不需要数据库。开发服务器命令将请求转给入口，适合验证动态请求；生产静态文件由 Web 服务器处理。`init.php` 已负责加载依赖、注册服务和加载路由，不需要额外初始化脚本。

目录说明：

| 目录或文件 | 用途 |
| --- | --- |
| `public/index.php` | HTTP 入口；Web 根目录设为 `public/` |
| `routes/web.php`、`routes/api.php` | 注册路由 |
| `app/Http/Controllers`、`app/Models` | 应用 Controller 和 Model；按需创建目录 |
| `resources/views` | Blade 视图 |
| `config/` | 应用、数据库及其他配置；`services.php` 注册服务 |
| `core/` | 框架代码，命名空间 `Wuyoo\` |
| `storage/` | 模板缓存、日志、Session 和上传文件 |

业务命名空间为 `App\`。普通 Controller 不必继承基类。框架不提供 Artisan、Laravel Foundation、迁移或完整 Laravel helper / Facade 生态。

### 配置

`config/*.php` 从**进程环境变量**读取配置；框架不会自动加载 `.env`。也可创建被 Git 忽略的 `config/local.php`，用同层级数组递归覆盖配置。以下是该文件的完整示例，密码仅用于本地开发：

```php
<?php

return [
    'app' => ['debug' => true, 'secure_cookies' => false],
    'database' => [
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'host' => '127.0.0.1',
                'database' => 'wuyoo_dev',
                'username' => 'wuyoo_dev',
                'password' => 'local-password',
            ],
        ],
    ],
];
```

`APP_DEBUG` 默认关闭，`SESSION_SECURE` 默认开启。`config/app.php` 的 `max_body_bytes` 默认 2 MiB。其余键及默认值见各配置文件。需要在 Controller 中读取配置时，可注入 `Wuyoo\Support\Config`，用 `$config->get('app.name', 'Wuyoo')` 读取。

### Nginx / PHP-FPM

参考 `docs/nginx.conf.example`，将站点根目录设为项目 `public/`，通过 `try_files $uri $uri/ /index.php?$query_string` 转发，FastCGI 的 `SCRIPT_FILENAME` 指向 `public/index.php`，并根据服务器修改 FPM socket。只允许入口 PHP 执行、拒绝隐藏文件；设置请求大小限制，并与 PHP 的 `post_max_size`、`upload_max_filesize` 和 `app.max_body_bytes` 协调。HTTPS 需要另行配置 TLS，正确传递 HTTPS 状态。

确保 FPM 用户可写 `storage/cache/views`、`storage/logs`、`storage/sessions` 和需要时的 `storage/uploads`；不要以 root 运行 FPM。生产关闭调试、启用安全 Cookie 与 OPcache，保护配置和凭据。部署模板变更时必要时清除 `storage/cache/views/` 中的编译文件。框架不自动信任 `X-Forwarded-*`，代理边界需自行配置。

## 路由

两个路由文件各返回接收 `Wuyoo\Routing\Router` 的函数；初始化时依次加载 `web`、`api`，之后路由不可再注册。下面是加入现有注册函数**内部**的路由语法示例；假设 `EntryController` 已实现对应方法，类需在文件顶部 `use`：

```php
$router->get('/entries/{id}', [EntryController::class, 'show'], [], ['id' => '[0-9]+']);
$router->post('/entries', [EntryController::class, 'store']);
$router->put('/entries/{id}', [EntryController::class, 'update']);
$router->patch('/entries/{id}', [EntryController::class, 'update']);
$router->delete('/entries/{id}', [EntryController::class, 'destroy']);
$router->map(['GET', 'POST'], '/example', static fn (): string => 'OK');
```

`get` 等方法的第三个参数是中间件数组，第四个参数是按参数名索引的正则约束；也支持 `head`、`options`、`any`。`{id}` 默认匹配一个非空路径段，Action 按名称接收。还支持旧式 `(:num)`、`(:any)`、`(:all)` 占位符，分别按顺序传值；不要把任意路径当作裸正则。

分组可嵌套，支持 `prefix`、`namespace` 和 `middleware`。例如，在 `routes/api.php` 已有的 `api` 分组内：

```php
$router->group(['prefix' => 'v1', 'namespace' => 'App\\Http\\Controllers'], static function (\Wuyoo\Routing\Router $router): void {
    $router->get('/entries/{id}', 'EntryController@show');
});
```

此路由路径为 `/api/v1/entries/{id}`。数组形式 `[EntryController::class, 'show']` 不依赖分组的 `namespace`。全局、分组、单路由中间件按声明顺序进入，返回时反向退出。

静态路径优先于动态路径；同一动态桶内按注册顺序匹配。静态路径存在时，即使它不支持当前方法，也不会再尝试动态路径。重复路由签名会报错，交叉的动态约束应自行安排顺序。路径不存在返回 404；路径存在但方法不允许返回 405 和 `Allow`。HEAD 优先匹配显式 HEAD，否则回退 GET，发送时无响应体；已知路径未显式定义 OPTIONS 时自动返回 204 和 `Allow`。非法路径或路由参数类型不合法可返回 400。

## Controller 与依赖注入

普通 Controller 无需注册：创建 `app/Http/Controllers/ExampleController.php`，再在路由中引用即可。

```php
<?php

namespace App\Http\Controllers;

use Wuyoo\Http\Request;
use Wuyoo\Http\Response;
use Wuyoo\Support\Logger;

final class ExampleController
{
    public function __construct(private Logger $logger) {}

    public function show(Request $request, int $id): Response
    {
        $this->logger->info('Entry requested', ['id' => $id]);
        return Response::json(['id' => $id, 'filter' => $request->query('filter')]);
    }
}
```

例如注册 `$router->get('/example/{id}', [ExampleController::class, 'show']);`，并在路由文件顶部导入该类。Action 必须是公开、非静态的普通实例方法。命名路由参数按名称绑定，支持 `string`、`int`、`float`、`bool` 和 backed enum；转换失败返回 400。其他对象类型从当前请求 Scope 获取，因此 `Request` 可直接注入。Action 参数不支持 union、intersection 或 variadic。

Controller 的简单类或接口构造参数从 Scope 已注册的服务获取，未注册时可使用明确的参数默认值；只有 `?Type` 而无默认值并不能替代注册。框架已注册 `Request`、`Config`、`Logger`、`Session`、`RedisCache` 等；它不会递归自动构造任意应用服务。服务、复杂构造参数或需要标量且没有默认值的 Controller，应在 `config/services.php` 返回的函数内显式绑定。例如假设应用已创建 `App\Services\EntryService`，其构造函数接收 `Logger`，则在该函数内添加：

```php
$app->bind(\App\Services\EntryService::class, static fn (\Wuyoo\Support\Scope $scope) =>
    new \App\Services\EntryService($scope->get(\Wuyoo\Support\Logger::class))
);
```

同样可绑定 Controller 的工厂；已有 Controller 绑定优先于自动构造。工厂返回值在当前 Scope 内复用，不要在工厂闭包或静态变量中保存跨请求的用户、Request 或 Session。

Action 可返回 `Response`、`View`、数组或 `JsonSerializable`（转 JSON）、字符串（HTML）、100–599 状态整数或 `null`（204）。不要 `echo` 输出。常用返回方式：

```php
return \Wuyoo\Http\Response::view('entries.index', ['entries' => $entries]);
return \Wuyoo\Http\Response::html('<p>OK</p>');
return \Wuyoo\Http\Response::json(['message' => 'created'], 201);
return \Wuyoo\Http\Response::redirect('/entries', 303);
```

这些是独立返回方式，按需择一。重定向仅接受安全的站内绝对路径；状态码支持 301、302、303、307、308。

## 数据库与 Eloquent

配置 `DB_CONNECTION=mysql` 或 `pgsql`，以及 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`；PostgreSQL 还可配置 `DB_SSLMODE`。两个连接的默认端口分别为 3306、5432。连接按需建立。请先在自己的开发数据库创建表；框架不提供迁移命令，也不要用生产数据库测试。

以 `entries` 表（`id`、`title`、`status`、`created_at`、`updated_at`）为例，Model 为 `app/Models/Entry.php`：

```php
<?php

namespace App\Models;

use Wuyoo\Database\Model;

final class Entry extends Model
{
    protected $fillable = ['title', 'status'];
    protected $casts = ['status' => 'integer'];
}
```

Model 默认按 Eloquent 约定使用 `entries` 表和时间戳；不存在时间戳列时可在 Model 中设置 `public $timestamps = false;`。在 PHP 文件的命名空间后导入 `App\Models\Entry` 和 `Wuyoo\Database\DB`。以下是 Controller Action 或 `Application::using()` 回调内部的用法片段：

```php
$rows = DB::table('entries')->where('status', 1)->orderByDesc('id')->get();
$first = DB::table('entries')->where('status', 1)->first(); // 不存在时为 null
$entry = Entry::find(1); // 不存在时为 null
$entry = Entry::create(['title' => 'PHP', 'status' => 1]);
$entry->title = 'Modern PHP';
$entry->save();
DB::table('entries')->where('id', $entry->id)->update(['status' => 0]);
DB::table('entries')->where('id', $entry->id)->delete();
```

Query Builder 的 `get()` 返回 Collection，元素通常是行对象；Eloquent 查询返回 Model Collection。可以用 `$rows->count()`、`$rows->pluck('title')`、`$rows->map(fn ($row) => $row->title)`、`$rows->filter(fn ($row) => $row->status == 1)` 处理结果。`first()`、`find()` 应处理 `null`，不要假定记录一定存在。

JOIN 示例假设开发数据库另有 `categories(id, name)` 和 `entries.category_id`：

```php
$rows = DB::table('entries')
    ->join('categories', 'entries.category_id', '=', 'categories.id')
    ->select('entries.id', 'entries.title', 'categories.name as category')
    ->orderBy('entries.id', 'desc')
    ->get();
```

分页使用原版 Builder，在请求中自动读取 `page`：

```php
$entries = Entry::orderBy('id')->paginate(20);
$entries->withQueryString(); // 需要保留筛选参数时显式调用
```

视图中可用 `{{ $entries->links() }}`。默认页码缺失、非数字或非正数时使用第 1 页；超过 1,000,000 返回 400。客户端指定每页数量时，先用 `Wuyoo\Http\PaginationInput::perPage($request->query('per_page', 20))` 校验 1–1,000，再传给 `paginate`。显式传入的页码或游标需由应用校验；`PaginationInput::page()`、`cursor()` 可用于外部输入。游标分页需明确排序，无法解码的请求游标回到第一页，结构或排序键不合法时返回 400；字段的业务类型仍需自行校验。

事务与批量操作：

```php
DB::transaction(function (): void {
    DB::table('entries')->insert([
        ['title' => 'PHP', 'status' => 1],
        ['title' => 'Blade', 'status' => 1],
    ]);
    DB::table('entries')->where('status', 0)->update(['status' => 1]);
}); // 异常时回滚
```

也可使用 `upsert()`；冲突键和唯一索引要求取决于数据库。多连接使用 `DB::connection('pgsql')->table('entries')`。Query Builder 和基础 Eloquent 由固定的 Illuminate 组件提供，但不因此承诺完整 Laravel 的高级关系、Observer、事件或其他 Foundation 功能。

CLI 使用数据库时也须创建 Scope，以下为项目根目录 CLI 脚本的主体：

```php
$app = require __DIR__.'/init.php';
$app->using(new \Wuyoo\Http\Request(), static function (\Wuyoo\Support\Scope $scope): void {
    $count = \App\Models\Entry::count();
    $scope->get(\Wuyoo\Support\Logger::class)->info('Entries counted', ['count' => $count]);
});
```

## Blade 模板

视图 `entries.index` 对应 `resources/views/entries/index.blade.php`。Controller 使用 `Response::view('entries.index', ['entries' => $entries])` 传参。`{{ $value }}` 默认转义；`{!! $value !!}` 输出原始 HTML，只用于可信内容。

布局 `resources/views/layouts/main.blade.php`：

```blade
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><title>@yield('title')</title></head>
<body>@yield('content')</body>
</html>
```

子视图可以使用 `@extends('layouts.main')`、`@section`、`@if`、`@foreach` 和 `@include('entries.part')`；被 include 的文件需实际存在。匿名组件放在 `resources/views/components/`，例如已有 `notice.blade.php`，可写 `<x-notice type="info">提示内容</x-notice>`。类组件命名空间由 `config/view.php` 的 `component_namespace` 设置。不是所有 Laravel Application 指令或 helper 都可用，例如不要假定 `route()`、`@auth` 存在。

编译文件位于 `storage/cache/views/`，目录必须可写。部署后必要时清理其中的文件；不要删除目录。View 和分页链接应在所属请求或 CLI Scope 内渲染。

## Request、Response 与验证

Action 注入 `Wuyoo\Http\Request` 后可读取：

```php
$term = $request->query('q', '');
$title = $request->input('title');
$header = $request->header('accept');
$preference = $request->cookie('preference', 'normal');
$data = $request->validate(['title' => 'required|string|max:200', 'status' => 'integer|in:0,1']);
```

`input()` 合并 query 和表单或 JSON 数据，后者覆盖同名 query；`json()` 解析 JSON 对象，空 body 返回空数组，格式错误或非对象返回 400。`method`、`path` 是属性。验证失败返回 422，只返回规则指定且存在的字段，不自动强制转换。支持 `required`、`nullable`、`string`、`integer`、`boolean`、`email`、`array`、`in`、`min`、`max`；`min/max` 对带 `integer` 规则的值比较数值，否则比较字符串长度或数组项数。

`Response::json()` 和 `Response::view()` 可传状态码，`withHeader()`、`withCookie()` 返回新 Response：

```php
return \Wuyoo\Http\Response::json(['ok' => true], 201)
    ->withHeader('X-Example', 'value')
    ->withCookie('preference', 'compact', ['secure' => false]); // 仅本地 HTTP
```

Cookie 默认 Secure、HttpOnly、SameSite=Lax；本地 HTTP 可对自定义 Cookie 明确传 `secure => false`，生产 HTTPS 保持安全设置。上传可通过 `$request->file('image')` 获取 `UploadedFile` 或 `null`；其 `store($directory)` 默认只接受真实 JPEG/PNG、限制 5 MiB 并随机命名。整体请求仍受默认 2 MiB 上限约束。

## Session、CSRF 与 Middleware

注入 `Wuyoo\Security\Session` 后，使用 `$session->put('name', 'Ada')`、`$session->get('name', 'guest')`。登录或提升权限后调用 `$session->regenerate()`。Session 按需启动并保存在 `storage/sessions/`；启动后响应附带 Session Cookie 和 `Cache-Control: private, no-store`。本地 HTTP 可设置 `SESSION_SECURE=false`，生产 HTTPS 保持默认安全配置。

现有 `routes/web.php` 分组已启用 `CsrfMiddleware`。将 Web 写路由放在该分组内，并在 POST 表单中写 `@csrf`；该指令生成隐藏 `_token`。也可用 `X-CSRF-Token` 请求头。校验失败返回 419。现有 API 分组不自动启用 CSRF 或认证；Cookie 认证的 API 也应加 CSRF，其他 API 按业务实现认证。

自定义中间件可以是闭包。以下片段放在路由注册函数中，给单条路由附加响应头：

```php
$middleware = static function (\Wuyoo\Http\Request $request, callable $next): \Wuyoo\Http\Response {
    return $next($request)->withHeader('X-Example', 'value');
};
$router->get('/example', static fn (): string => 'OK', [$middleware]);
```

中间件也可直接返回 Response 以短路；类名形式需先在 `config/services.php` 注册可调用对象的工厂，再在路由数组中传类名。全局中间件通过服务注册函数中的 `$app->middleware(...)` 添加。替换 Request 必须在任何消费者从 Scope 读取它之前进行；路由匹配后不能改变方法或路径。

## Redis、MongoDB、日志与异常

Redis 缓存需要 `ext-redis`，通过 `REDIS_HOST`、`REDIS_PORT`、`REDIS_USERNAME`、`REDIS_PASSWORD`、`REDIS_DATABASE` 配置。向 Controller 注入 `Wuyoo\Cache\RedisCache`：

```php
$this->cache->put('entries:count', 12, 300); // 秒
$count = $this->cache->get('entries:count', 0);
$this->cache->forget('entries:count');
```

这里假设构造函数已有 `private \Wuyoo\Cache\RedisCache $cache`。值以 JSON 存储，默认 TTL 为 300 秒；TTL 不大于 0 时删除键。默认键前缀 `wuyoo:` 可在 `config/redis.php` 或本地覆盖配置中修改。

MongoDB 是可选适配：先启用 `ext-mongodb`，再执行 `composer require 'mongodb/mongodb:^2.1'`，设置 `MONGODB_URI`、`MONGODB_DATABASE`。注入 `Wuyoo\Database\Mongo` 后可通过 `$mongo->database()->selectCollection('entries')` 获取官方 Library 的 Collection。该模块与 SQL Model 独立；当前项目尚未验证 MongoDB 实库读写、认证或 TLS。

注入 `Wuyoo\Support\Logger` 后可用 `debug`、`info`、`warning`、`error`，例如 `$logger->info('Entry saved', ['id' => $id])`。日志写入 `storage/logs/YYYY-MM-DD.log`，不要把密钥或个人敏感数据写入消息。

需要返回明确 HTTP 错误时可抛出 `new \Wuyoo\Http\HttpException(404, 'Not Found')`。异常处理器根据 `/api` 路径或 `Accept` 中的 JSON 选择 JSON 或 HTML；非 HTTP 异常为 500。生产保持 `APP_DEBUG=false`，避免暴露错误详情。

## 完整 Web 示例

以下示例使用开发数据库中的 `entries` 表。MySQL 建表语句如下；PostgreSQL 把 `BIGINT AUTO_INCREMENT` 改为 `BIGSERIAL`，其余保持不变：

```sql
CREATE TABLE entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    status INTEGER NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

创建上文的 `App\Models\Entry` 和 `layouts/main.blade.php`。在 `routes/web.php` 顶部导入 `App\Http\Controllers\EntryController`，并在已有 CSRF 分组内部添加：

```php
$router->get('/entries', [EntryController::class, 'index']);
$router->post('/entries', [EntryController::class, 'store']);
```

创建 `app/Http/Controllers/EntryController.php`：

```php
<?php

namespace App\Http\Controllers;

use App\Models\Entry;
use Wuyoo\Http\Request;
use Wuyoo\Http\Response;

final class EntryController
{
    public function index(): Response
    {
        $entries = Entry::orderBy('id', 'desc')->paginate(10);
        return Response::view('entries.index', ['entries' => $entries]);
    }

    public function store(Request $request): Response
    {
        $data = $request->validate(['title' => 'required|string|max:200']);
        Entry::create(['title' => $data['title'], 'status' => 1]);
        return Response::redirect('/entries', 303);
    }
}
```

创建 `resources/views/entries/index.blade.php`：

```blade
@extends('layouts.main')
@section('title', '条目')
@section('content')
    <h1>条目</h1>
    <form method="post" action="/entries">
        @csrf
        <label>标题 <input name="title" required maxlength="200"></label>
        <button type="submit">添加</button>
    </form>
    @if ($entries->count() === 0)
        <p>暂无条目。</p>
    @else
        <ul>
            @foreach ($entries as $entry)
                <li>{{ $entry->title }}</li>
            @endforeach
        </ul>
    @endif
    {{ $entries->links() }}
@endsection
```

配置开发数据库后访问 `/entries`，提交表单会保存条目并重定向回列表。分页默认不自动保留所有 query 参数。

## 完整 API 示例

创建 `app/Http/Controllers/MessageController.php`：

```php
<?php

namespace App\Http\Controllers;

use Wuyoo\Http\Request;
use Wuyoo\Http\Response;

final class MessageController
{
    public function store(Request $request): Response
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        return Response::json(['message' => 'Hello, '.$data['name']], 201);
    }
}
```

在 `routes/api.php` 顶部导入 `App\Http\Controllers\MessageController`，并在已有 `api` 分组内部添加：

```php
$router->post('/messages', [MessageController::class, 'store']);
```

本地启动后测试：

```sh
curl -i -X POST http://127.0.0.1:8080/api/messages \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Ada"}'
```

成功返回 201 和 `{"message":"Hello, Ada"}`；无效输入返回 422。这个演示端点没有认证，实际写接口应按业务加认证与授权。

## 常见问题与使用边界

- **500 / 找不到服务**：检查构造参数或中间件类是否在 `config/services.php` 显式绑定，标量参数是否有默认值；查看 `storage/logs/`。普通无依赖 Controller 不需绑定。
- **419**：确认写路由位于 CSRF 分组、表单含 `@csrf`，浏览器保存 Session Cookie；本地 HTTP 确认 `SESSION_SECURE=false`。
- **数据库连接失败**：检查 PHP PDO 驱动、进程环境变量、数据库权限和网络。`.env` 不会自动加载；CLI 与 FPM 的环境可能不同。
- **模板或 Session 失败**：检查对应 `storage/` 目录存在且运行用户可写。模板名使用点号分隔，不传文件路径。
- **Laravel 功能缺失**：没有 Artisan、迁移、Laravel Foundation、自动 `.env`、完整 Facade/helper 或完整验证器。仅对本手册和项目记录中已支持的用法作兼容承诺；复杂 Eloquent 功能不在保证范围。
- 核心只支持串行 Scope，拒绝嵌套或并发请求；未实现或验证常驻 Worker、协程服务器。不要在静态变量、持久中间件或工厂闭包中保留请求数据。
