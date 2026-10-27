# Wuyoo 独立架构与代码审计

审计日期：2026-10-27（Asia/Shanghai）。基线：`dd03cb1`，分支 `codex/wuyoo-framework`；开始时工作区干净。以下行号对应此基线。本次仅新增本报告，没有修复框架、升级依赖、提交、推送或部署。

## 1. 总体结论

**核心框架已真实实现，结构总体合理，超过演示骨架；但“已全面完成、可直接正式使用”的结论不成立。** HTTP/MVC、显式 DI、主要 Eloquent 操作、原版 Blade 和可选 Redis 均有重新执行的证据。没有发现空实现或自研 SQL 内核冒充 Eloquent，也没有发现本轮证据足以支持的 P0/P1 问题。

已确认 **P0：0，P1：0，P2：6，P3：1**，按共同根因计数；Blade 的两种表现合并为一个生命周期问题。另列兼容边界和未验证项，不混入已确认 Bug 数量。主要缺陷集中在成熟组件与请求生命周期的衔接，而非 Query Builder、Grammar 或事务算法本身。

**不建议当前版本直接正式投用。** 建议先完成第 7 节的有限修复，补齐隔离 MySQL/PostgreSQL 实库和实际 FPM 部署验证，再用于功能范围明确的项目。不需要推倒重写，不需要新增 Repository、完整 Laravel Application 或通用自动装配容器。未发现 P0/P1 不等于证明所有安全和数据一致性场景均正确。

## 2. 实际架构与调用链

```text
public/index.php → init.php
  → Config + Application + 显式服务工厂 + 实例 Router（注册后冻结）
  → Request::capture → Application::handle → using 创建 Scope
  → 全局 Middleware → Router::match → 路由 Middleware
  → ControllerResolver（显式构造、Action 参数反射与转换）
  → normalize → Blade（仅 View 响应需要）→ Session::apply
  → finally 关闭已初始化资源、重置桥接状态 → Response::send

DB / Model → Scope::get(Database) → 官方 Capsule / DatabaseManager
  → ConnectionFactory → Connection → PDO
  → 官方 Query / Eloquent Builder、Grammar、Processor

Blade 适配器 → 官方 FileViewFinder / Factory / EngineResolver
  → 薄 Compiler 子类 → 原版 BladeCompiler → 磁盘 PHP 缓存
```

- `core/` 是框架，`app/` 是业务；入口符合要求，无 `bootstrap.php`。Controller 不要求继承基类，业务示例没有强加 DAO/Repository。
- Scope 缓存本次请求的实例，并检测工厂循环依赖。Database、Session、Blade、Redis、Mongo 按需解析；普通健康检查不会建立数据库连接。
- Router 是借鉴 Macaw API/占位符的重写实现，并非原 Macaw 的完整兼容替换。静态哈希索引、动态分桶和注册时编译均真实存在；裸正则路径、旧静态调用不支持。
- Composer 安装 34 个固定运行时包，无完整 `laravel/framework`、Foundation、Artisan。Eloquent 的内部 Container 与 Wuyoo Scope 分工不同，保留它们合理。
- Eloquent resolver、Paginator resolver、Blade Container/Component 等仍有上游静态桥接。串行 Scope 试图清理这些状态，但 F3 说明清理尚不完整。并发/嵌套请求明确被拒绝，不能据此称为通用 Worker 框架。

## 3. 已确认问题

### F1 · P2 · 异常响应绕过 Session 收尾，轮换后客户端丢失会话

**位置**：`core/Application.php:135–143`、`:97–99`；`core/Security/Session.php:37`、`:45–60`。

**原因**：`Session::apply()` 仅在成功分支运行。Controller、中间件或模板抛异常后，`using()` 先执行 `Session::close()`，清空当前 ID；外层 catch 才创建错误 Response，已无法附加新 Cookie 和会话缓存策略。

**触发与实证**：先访问写入 `user=alice` 的路由，再携带 Cookie 访问如下 Action：

```php
function (Session $session) {
    $session->regenerate();
    throw new HttpException(422, 'Business validation failed');
}
```

PHP 8.3/8.4/8.5 的 `handle()` 验证一致；另用 PHP 8.3 真实 HTTP 重现：422 响应没有 `Set-Cookie`，没有 `Cache-Control`；携带原 Cookie 再读取 `user`，结果为 `null`。原因是 `regenerate(true)` 已删除旧 ID，新 ID 又没有发回。首次创建 Session 后抛异常也会丢失客户端关联。未轮换的已有 Session 不会因此一概丢失，不能扩大结论。

**最小修复**：把业务异常转 Response 的过程放进有效 Scope；让成功和错误 Response 共用 `Session::apply()` 路径，然后再 finally 关闭 Session。初始化和清理本身的失败保留外层兜底。增加轮换后 422/500、模板异常和新会话异常的 HTTP 回归。

**兼容性**：不改正常 Action/API；错误响应会正确附带 Cookie 和 `private, no-store`，属于预期行为修复。

### F2 · P2 · 中间件传入的新 Request 与 Controller/服务收到的 Request 不一致

**位置**：`core/Application.php:74`、`:83–89`、`:128–135`、`:163–169`；`core/Routing/ControllerResolver.php:54–56`。

**原因**：Scope 和分页闭包保存最初的 Request；pipeline 会将 `$next($newRequest)` 传递给 Router，但 Action 的 Request 从旧 Scope 取出。路由匹配和业务读取因此可以基于不同请求。

**最小重现**：全局中间件将 `/original` 替换成 `new Request('GET', '/rewritten', ['page' => 3], headers: ['X-User' => 'verified'])` 并调用 next。`/rewritten` Action 被执行，但注入的 Request 返回 `/original`、`X-User=null`，`Paginator::resolveCurrentPage()` 为 1。三版本均复现。

**影响**：URL 重写、输入归一化、通过替换对象传递验证后的信息会出现静默不一致。这里只证实信息错位，未证实具体业务的认证绕过。

**最小修复**：明确替换契约，并统一 Router、Scope 和分页读取源。若保留替换能力，在下游解析服务前同步 Scope，分页 resolver 改为读取 Scope 的有效 Request；对 Session/业务服务已经捕获旧 Request 后的再次替换明确拒绝。不能只补一行 `Scope::set()` 就声称所有已构造服务已同步。若产品决定完全不支持替换，应在 next 边界明确报错并写入文档，而不是继续静默混用。

**兼容性**：正常 `$next($request)` 不变；替换 Request 的既有行为需要明确修正，属于可观察的契约调整。

### F3 · P2 · Blade 上下文只覆盖部分渲染入口，组件缓存跨 Scope 失配

**位置**：`core/View/Blade.php:32–48`、`:57–70`；`core/Application.php:89`。上游追踪：`vendor/illuminate/view/Component.php:174–200`、`:451–468`。

**共同根因**：每个 Scope 创建新 Factory/Container，但适配器只在 `Blade::render()` 临时设置全局 Container，且只执行 `Component::forgetFactory()`；上游组件视图映射缓存仍是静态的。Factory 的直接出口又绕过该上下文边界。

**表现 A：串行内联类组件**。在测试组件命名空间定义简单组件：

```php
final class InlineCard extends \Illuminate\View\Component
{
    public function render() { return '<b>inline card</b>'; }
}
```

模板 `<x-inline-card />`，同一进程连续两次 `handle()`：第一次 200，第二次 500，错误为 `No hint path defined for [__components]`。首次渲染将 `__components::hash` 存入静态缓存，并只给旧 Factory 注册 namespace；新 Factory 复用缓存名却没有 namespace。三版本均复现；返回普通命名视图的类组件对照为 `[200, 200]`。单次 FPM 请求不等于该串行场景，不能说所有类组件都失效。

**表现 B：Controller/CLI 直接渲染自定义分页模板**。分页模板包含普通 `<x-notice>`，在 Scope 内调用 `Book::paginate(2)->links('audit-page')->render()`，得到 `Target [Illuminate\Contracts\View\Factory] is not instantiable.`。Paginator resolver 返回裸 Factory，此入口没有激活 Blade Container。在正常 `Response::view` 模板内部调用 links 时，外层已激活上下文，不能混为一谈。

**最小修复**：统一模板上下文的生命周期，使直接分页渲染也覆盖 make/render；可以在已明确串行的 Scope 内激活并 finally 恢复上游 Container。使用上游公开的 `Component::flushCache()` 在必要的 Scope 边界清除失配的组件缓存，同时 `forgetFactory()`；不要仅通过把 Blade 变成应用单例掩盖问题。若选择保留缓存，需要同时保证每个新 Factory 的 namespace 和内联视图文件均已建立。

**兼容性**：保留原版编译器、组件和 links API，不修改 vendor。首先保证正确性；是否进一步保留反射缓存，应在测量后决定。

### F4 · P2 · `cursorPaginate()` 未接入当前请求游标，翻页静默返回第一页

**位置**：`core/Application.php:83–91`、`:108–111`。对照：`vendor/illuminate/pagination/PaginationState.php:31–33`、`AbstractCursorPaginator.php:504–521`。

**原因**：仅设置普通 Paginator resolver，遗漏独立的 `CursorPaginator::currentCursorResolver()`。官方 Builder 默认从后者取得 cursor，缺省为 null。

**重现**：隔离 SQLite 建立 ID 1–5 的记录。第一页 `Book::orderBy('id')->cursorPaginate(2)` 得到 next cursor；下一 Scope 将该值放入 Request query 的 `cursor`，执行同一调用仍返回 `[1,2]`。显式传入第四参数 cursor 的对照返回 `[3,4]`。三版本结果一致。

**影响范围**：游标分页的默认请求集成失效；普通 `paginate()`、`simplePaginate()` 和逐行遍历 `cursor()` 已通过测试，不能将三者混同。README 未单独展示 cursorPaginate，但保留的原生 API 在此并未完整接入。

**最小修复**：注册请求级 cursor resolver，验证输入为字符串后用官方 `Cursor::fromEncoded()` 解析；Scope 结束时重置，支持自定义 cursor 参数名。补两页正向/反向及连续请求测试。

**兼容性**：修正默认翻页行为；显式第四参数用法不变。若决定不支持此 API，至少应明确列为未集成，不能以“分页全部兼容”替代说明。

### F5 · P2 · 用已解码的路由路径生成分页 URL，保留字符破坏链接

**位置**：`core/Http/Request.php:32–40`；`core/Application.php:87`。

**原因**：Request 只保存解码后的 path，Paginator 又把它当作可直接发送的 URL path。路由匹配形式与 URL 编码形式职责混在一起。

**重现**：Request URI 为 `/books/tag%23one`，Scope 中 `Book::paginate(2)->nextPageUrl()` 实际返回 `/books/tag#one?page=2`。浏览器将 `#one?page=2` 视为 fragment，不会把它作为路径和 query 发给服务器；应保留 `/books/tag%23one?page=2`。三版本均复现。`%3F` 等保留字符存在同类问题。

**最小修复**：Request 同时保留经过校验的编码路径和供 Router 使用的解码路径；Paginator 使用编码路径。不要对整个已解码路径执行一次 `urlencode()`，以免把分隔斜杠也编码掉。测试 `%23`、`%3F`、`%25`、中文和已有 query。

**兼容性**：保留现有路由参数解码行为，只修复生成的 URL；无需修改上游分页器。

### F6 · P2 · DB 事务包装声明支持 callable，实际只接受 Closure

**位置**：`core/Database/DB.php:33`；`vendor/illuminate/database/Concerns/ManagesTransactions.php:23`。

**原因**：Wuyoo 接收 `callable`，却直接传给上游 `transaction(Closure $callback, ...)`，没有转换。

**重现**：在有效 SQLite Scope 内定义 `function auditCallable() { return 42; }`，`DB::transaction('auditCallable')` 抛 TypeError：`Argument #1 ($callback) must be of type Closure, string given`。闭包对照 `DB::transaction(fn () => 42)` 正常返回 42。数组 callable、可调用对象同样不满足上游 Closure 类型。这是本地包装的类型契约错误，不是事务内核错误。

**最小修复**：传递前用 `Closure::fromCallable($callback)` 转为闭包；增加函数名、实例方法、invokable 和普通闭包的返回值/回滚测试。不重写事务处理。

**兼容性**：实现现有 callable 声明，不破坏闭包调用，也不改变连接参数和返回值。

### F7 · P3 · 非法 UTF-8 路径被当作服务器故障

**位置**：`core/Http/Request.php:36–40`；`core/Routing/Route.php:40`、`:56–58`。

Request 解码 `%FF` 后未验证 UTF-8；动态路由正则使用 `u`，`preg_match` 失败并抛 RuntimeException。访问 `/api/items/%FF`，三版本 `handle()` 及 PHP 8.3 真实 HTTP 均返回 500；调试证据为 `Malformed UTF-8 characters, possibly incorrectly encoded`。

影响是客户端坏请求被记为服务端错误，制造错误日志/监控噪声；未证实越权、代码执行或服务崩溃。最小修复是在解码后用已有 mbstring 能力验证 UTF-8，不合法返回 400。合法路径 API 不变；仅改变非法输入的状态码。

## 4. Eloquent 专项结论

### 来源、裁剪与依赖

- lock 中 Database 为 `v13.38.0 / add3b4602c77c38edbee26be9ab55e7eec621182`，View 为 `v13.38.0 / 91e08653f191bd08c85d53de0fd4059ea1a547c9`；34 个包的版本与 commit 均和 `docs/upstream-lock.json` 一致。
- 在不带 vendor 的独立源码副本执行 `composer install`，34 个包全量重建成功，lock 未变。按相对路径和 SHA-256 比较本地与新装的 Database 217、View 55、Pagination 20 个文件：没有新增、缺失或内容差异。这证明这些已安装组件与 lock 重建结果一致，不只是在信任来源声明。
- `tools/prune-upstream.php` 在 autoload 生成前删除 Console、Migrations、Factories、Seeder、DatabaseServiceProvider，未改写 SQL 内核。源码链路也与[固定提交的官方 Capsule](https://github.com/illuminate/database/blob/add3b4602c77c38edbee26be9ab55e7eec621182/Capsule/Manager.php)一致。
- Relations、HasAttributes、Cast、Collection、Support 没有被强删，这是正确选择。Events 包因 Blade 保留，但 Eloquent 不绑定 dispatcher；“Events 包存在”不能推断 Observer/Model 回调可用。
- 删除边缘目录并不代表全包符号完全闭合：例如残留 `MigrationServiceProvider.php` 引用已删除的迁移类，Migration 事件和 UseFactory 属性仍有相关引用。没有发现这些路径进入已验证 CRUD 的证据；应将它们明确视为不支持的外围入口，不应为了消除引用继续扩大核心裁剪。

### 已验证行为

基础测试重新覆盖 `create/find/get/first/save/update/delete`、集合、where/orWhere/whereIn、JOIN、子查询、聚合、批量 insert、upsert、分页、cursor 和 chunkById。补充独立验证覆盖：

| 项目 | 本轮证据 |
| --- | --- |
| Dirty tracking | 修改 title 后 isDirty=true；保存后 false，wasChanged=true |
| 批量赋值 | `Book::create` 传入不可填充 id=99，实际新 id=6，没有覆盖受保护主键 |
| 时间戳及基础 Cast | 自动时间戳存在；整数、JSON array、boolean、decimal:2 返回正确值 |
| 非自增字符串主键 | `code=k1` 创建和 find 成功 |
| 多连接 | 两个独立 SQLite 内存连接分别写入不同记录，互不串库 |
| 事务异常 | 唯一约束冲突抛官方 UniqueConstraintViolationException，事务级别回到 0，随后事务和查询成功 |
| Savepoint/资源清理 | 嵌套回滚保留外层提交；手动嵌套结束级别为 0；未提交事务在 Scope 结束回滚，下一 Scope 数据干净 |
| 参数绑定 | 注入样式字符串作为值绑定，未成为 SQL；Connection 仍使用官方 bindValue/prepareBindings |

这些运行证据来自 SQLite，不能替代另外两种数据库的执行结果。

### MySQL 与 PostgreSQL 分别核查的范围

使用各自真实 Connection/Grammar，配置未使用端口并确认 PDO 仍是惰性 Closure，仅生成 SQL，没有建立网络连接：

| 行为 | MySQL Grammar | PostgreSQL Grammar |
| --- | --- | --- |
| 标识符 | 反引号 | 双引号 |
| where / whereIn | `?` 占位符，值独立 bindings | `?` 占位符，值独立 bindings |
| Upsert | `on duplicate key update ... values(...)` | `on conflict (...) do update set ... excluded...` |
| Savepoint | `SAVEPOINT trans2` | `SAVEPOINT trans2` |

**MySQL/PostgreSQL 实库本轮均未验证。** 本机只找到旧 MySQL 5.7 可执行文件，没有可用的隔离目标 MySQL 8.x/PostgreSQL 实例或 Docker。没有连接现有数据库，更没有用数据库名带 test 前缀代替实例隔离。此前 STATUS 中的远端成功记录不计入本次独立验证。

因此，原生 prepare、各驱动 insert ID、实际 upsert 唯一键行为、事务/DDL 差异、死锁重试、断线恢复及锁竞争仍需在目标实例重跑。也没有证明复杂关系、复杂 Cast、海量 cursor 的 PDO 缓冲行为、MongoDB 实库能力。

### 不能扩大解释的兼容边界

- `DB::connection()->afterCommit()` 在 Scope 中实测抛 `Transactions Manager has not been set.`；Capsule 未绑定 `db.transactions`。普通事务通过不等于完整事务回调 API 已集成。当前文档主要承诺事务/Savepoint，将此作为兼容边界说明，不额外计为已承诺功能的 Bug。
- Eloquent 事件回调/Observer 不应被业务用来执行必须发生的校验或副作用；未配置 dispatcher 时不能假设它们会运行。原版事件机制见[Laravel 13 Eloquent 文档](https://github.com/laravel/docs/blob/13.x/eloquent.md)。
- `DB` 仅包装 manager/connection/table/transaction，不等同 Laravel Facade 的完整表面 API。关系、Resource、Factory 等保留符号也不等同已承诺兼容。
- 建议保留现有 SQL/属性/事务成熟实现。当前物理裁剪主要减少发行文件，未提供请求性能提升证据；升级维护应继续以 lock、固定提交和差异校验为准。

## 5. Router、Controller、HTTP 与 Blade

**Router/Controller**：现有测试已重新证明静态优先、同签名拒绝、动态约束、Macaw 占位符、namespace 分组、404/405/Allow、自动 OPTIONS、显式 HEAD 和 GET 回退。静态路径存在但方法不符时直接 405 是已有明确语义，不按 Bug 处理。动态字面桶优先于通配桶，桶内按注册顺序；不存在完整的全局注册顺序承诺。

Controller 工厂显式注册，参数反射只作用于路由明确指定的 Action；禁止 private/static/魔术 Action，不从 URL 拼接任意控制器类。int 溢出、Enum 不合法、服务循环依赖、输出污染、PHP warning 转异常、Middleware 顺序和短路均有通过证据。union/variadic 参数明确不支持，不应笼统宣称 Laravel 式全部绑定能力。F2 是当前主要调用链缺陷。

**Request/Response/安全**：JSON 必须为对象，畸形输入 400；已验证表单/JSON、422 验证、2 MiB 请求限制、Header 注入拒绝、本地 Redirect、Cookie 默认属性、CSRF 接受/拒绝、Session 隔离、真实上传 MIME/随机文件名。HEAD 和 204 不输出 body。生产模式的常规异常不包含内部 SQL、路径或原始消息。日志不序列化对象，并对常见敏感键脱敏。没有发现现有示例默认开放未认证写 API。

这些证据不覆盖业务自写 raw SQL、原始 HTML、上传白名单扩展或认证逻辑。`Request::capture` 发生在 PHP 解析表单之后，FPM/Nginx 的 body 和上传限制仍须实际验证，不能以应用层长度检查替代部署层边界。

**Blade**：确认使用原版 Blade；本地只替换组件命名空间猜测和 csrf 指令适配。重新验证转义/原始输出、extends、section、include、循环、stack、匿名组件、自定义指令；补充验证命名类组件和模板修改后的缓存失效：`old &lt;x&gt;` → `new &lt;x&gt;`。正常缓存重用输出一致。

顶层 View 名称白名单拒绝目录穿越，`__` 数据键不能覆盖内部变量。模板仍是可信 PHP 代码，include 中的动态路径应由业务控制。`@auth` 实测因缺少 `auth()` 抛异常；未知 `@unknownDirective` 原样输出，这是上游编译器行为，不是“所有不支持指令都有清晰诊断”。应保持支持矩阵，不能宣称全 Laravel 兼容。组件静态视图缓存的行为可核对[固定提交的官方 Component](https://github.com/illuminate/view/blob/91e08653f191bd08c85d53de0fd4059ea1a547c9/Component.php)，具体失配见 F3。

## 6. OOP、冗余与性能判断

- Application 目前是组合根和生命周期协调器，尚不足以判定为 God Object；路由、调用解析、HTTP、SQL、模板各有独立职责。但 `using()` 和 `handle()` 同时协调多种上游静态状态，是已证实缺陷的集中位置。先修正边界，不急于拆成大量 Manager/接口。
- Scope 是有实际用途的小型显式工厂容器；不需要为了形式引入通用反射自动装配。Controller 无强制继承，Model 只覆写连接解析以实现惰性初始化，Blade 子类仅替换一个上游集成点，均属合理的薄适配。
- DB 静态桥在 active Scope 内受控，不是持久连接单例。当前只能说已验证的数据库/Session 串行路径能清理，不能声称所有 Eloquent/Blade 静态缓存都完全隔离。业务仍不得在持久闭包、静态成员或中间件对象中保存当前用户。
- 未发现需要增加 Repository、EventBus、ORM 工厂层来解决的问题。DatabaseManager 已处理连接/事务/方言，重复封装这些机制会增加风险。
- 静态路由哈希、预编译正则和按需初始化有源码证据；没有吞吐、P95、并发或长期内存基线，**不作性能优秀/低劣或优于其他框架的结论**。动态匹配仍需扫描候选桶，Controller 有反射，Blade 每 Scope 建对象；没有测量证明其是应优先优化的热点。
- 示例没有已证实的 N+1；但测试数据很小，不能外推大数据内存行为。仅将之后的性能验证集中在真实路由规模、FPM/OPcache、分页查询和目标 PDO cursor 行为。
- 运维改进建议（不计 Bug）：当前 500 日志仅记录异常类型和顶层位置，安全但诊断信息有限；可增加请求关联 ID、无参数堆栈及脱敏后的 previous exception 类型。不要直接恢复完整 SQL/密码/请求体日志。清理异常也应单独记录，避免覆盖原始失败原因。

## 7. 可直接交给后续 Codex 的修复优先级

以下是后续开发任务，不是本轮已实施的变更：

1. **先修 F1、F2**：统一成功/异常响应收尾，明确 Request 替换契约；添加真实 HTTP Session 轮换异常测试，以及全局/路由 Middleware 与 Controller/Session/分页读取一致性的测试。正常短路、HEAD、错误隐藏必须继续通过。
2. **修 F3**：统一 Blade 上下文边界、处理跨 Scope 组件缓存；验证内联组件连续两次渲染、自定义分页组件在 Controller/CLI 和普通 Blade 页面中渲染、发生异常后全局 Container 恢复。保留原版编译器，不改 vendor。
3. **修 F4、F5、F6**：接入并清理 cursor resolver；保存用于生成 URL 的编码路径；将事务 callable 转 Closure。以实际翻页结果、浏览器可发送的 URL、回调返回值及异常回滚为验收条件。
4. **修 F7，并补兼容说明**：非法 UTF-8 返回 400；明确 afterCommit、Observer、未知 Blade 指令、Controller 参数类型的边界。不要将不支持的功能默认为 Laravel 完整行为。
5. **发布前验证**：按 README/CI 配置创建独立 MySQL 8.x、PostgreSQL 实例，分别重跑 CRUD/事务/Savepoint/Upsert/分页/异常分支；再以目标 Nginx/FPM、OPcache、TLS 和权限配置验证 HTTP。不得以本轮 SQLite 和 SQL 字符串检查代替实库结果。

每步保持 PHP 8.3 可解析、Composer platform=8.3.0；不得升级依赖或增加完整 Laravel，仅在清晰边界内修复并回归。

## 8. 本轮验证与复现记录

运行使用 Git 跟踪文件复制出的隔离目录，不带本机 `config/local.php`，从 lock 安装 vendor；临时模板、数据库、上传和 Session 文件均在副本或临时目录。Redis 是审计中新启动的独立本机进程，绑定随机端口且关闭持久化，验证后终止。没有访问生产数据库。

| 验证 | 本轮结果 |
| --- | --- |
| PHP 8.3.32 / 8.4.23 / 8.5.8 | 三个运行时实际执行；默认 PATH 的 PHP 8.1.20 未用于验证 |
| `composer install` | PHP 8.3 从 lock 干净安装 34 包并裁剪成功，无 update，lock 未变 |
| `composer validate` / `check-platform-reqs` / `audit --locked` | 全部 exit 0；validate 只有精确版本约束警告；audit 当时未发现已知安全公告 |
| PHP 8.3 语法 | 2,094 个源码/依赖 PHP 文件，无错误；排除 Blade 源模板和运行缓存 |
| `composer test` | 三版本各 14 组通过 |
| `composer test:http` | 三版本各 5 组通过；含真实 HTTP、Cookie、CSRF、上传 |
| `tests/database.php`（强制 SQLite） | 三版本各 6 组通过；含串行 Scope 未结束事务回滚 |
| `composer test:redis` | 独立 Redis 7.0.11，三版本均通过 JSON/TTL/删除测试 |
| 独立边界 probes | F1–F5、F7 在三版本复现；F6 与扩展 SQL/类型契约在 PHP 8.3 复现 |
| 补充 HTTP | PHP 8.3 重现 F1 丢 Cookie/会话、F7 非法 UTF-8 返回 500 |
| MySQL / PostgreSQL | 各自 SQL 编译、bindings、惰性 PDO 已核查；实库未验证 |
| MongoDB | 只验证缺少可选依赖时的明确失败；实库、认证、TLS 未验证 |
| FPM / Nginx / OPcache / 压测 / GitHub CI | 本轮未执行；不引用此前“通过”代替独立结果 |

复用的 Composer 命令以明确 PHP 运行时调用，例如：

```sh
/tmp/wuyoo-php/8.3/php /tmp/wuyoo-composer.phar install --no-interaction --prefer-dist --no-progress
/tmp/wuyoo-php/8.3/php /tmp/wuyoo-composer.phar test
/tmp/wuyoo-php/8.3/php /tmp/wuyoo-composer.phar test:http
TEST_DB_DRIVER=sqlite /tmp/wuyoo-php/8.3/php tests/database.php
```

本次临时副本为 `/var/folders/8c/jxn9mw713jx_3trc7tzd9x_40000gn/T/wuyoo-independent-audit-xd8tkh54`。其 `audit-temp/` 保留 `probes.php`、`sql-extra.php`、`http-extra.php`、三版本结果和命令日志，便于当前机器复核；属于临时证据，不是仓库新增测试或交付文档，系统清理后可能消失。各问题上文已提供不依赖该目录的复现条件、关键调用和预期/实际结果。

## 9. 真实项目适用性判断

这是一套方向正确、已有可运行核心的轻量 MVC 基础，保留成熟 Eloquent/Blade 比继续裁剪内部依赖更可靠。当前主要短板是集成边界的回归覆盖和发布环境证据，尚不能按“功能调用成功过”推断整个生命周期可靠。

完成上述有限修复并通过目标 SQL/FPM 验证后，可用于需求与文档边界一致的传统 PHP Web/API 项目。现状适合开发验证，不建议直接用于正式业务；更不应未经额外设计就承诺协程并发、多租户复杂静态状态隔离或完整 Laravel 生态兼容。
