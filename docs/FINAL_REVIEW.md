# Wuyoo — 远程最终独立复核

审计日期：2026-10-27（Asia/Shanghai）。唯一受审目标：Contabo `vmi3663018:/www/wuyoo`。本轮只新增本报告，不修改框架、配置、vendor、锁文件或已有文档；不提交、Push、部署或启用站点。

> 后续修复状态（2026-10-27）：下文保留审计时事实；R1/R2 的修复和本轮验证见第 8 节。服务器已按后续授权初始化 Git。

## 1. 总体结论与基线

**现有成果完整，具备受控实际 Web/API 项目试用条件。没有发现需要重新设计架构的理由。** 本轮确认问题为 **P0：0、P1：0、P2：0、P3：2**，均是分页的不可信输入边界；不等同于 F1–F6 正常行为回归失败，也没有证明 SQL 注入、越权或生产数据损坏。

不把此结论扩大为“任意业务、部署方式及全部 Laravel API 均可直接正式投产”。涉及公开分页接口时应先完成第 3 节的输入处理；正式上线还应完成实际业务、权限、TLS/代理、容量及部署配置验收。

### 环境和项目身份

- `ssh contabo` 后 `pwd -P` 为 `/www/wuyoo`，`hostname` 为 `vmi3663018`。`composer.json` 标识为 `wuyoo/framework`，PSR-4 映射 `Wuyoo\\ => core/`、`App\\ => app/`，主目录及核心源码均存在。
- 入口实际为 `public/index.php → init.php → Application`。没有新增或发现替代入口 `bootstrap.php`。
- **远程项目当前未初始化 Git，无法依据提交历史验证代码来源。** 现场没有 `.git`，Git status/log 无法提供基线；STATUS 中关于迁移及移除 Git 的叙述仅是历史记录，本轮没有用它替代源码证据，没有 git init/clone 或自动同步。
- PHP 要求 `^8.3`，Composer platform 为 `8.3.0`；Database、View、Pagination 固定 `13.38.0`。34 个已安装包的版本及 source reference 与 lock 一致。
- 审计开始对远程 2232 个现有文件记录 SHA-256；写报告前逐文件复核没有新增、删除或内容变化。临时源副本完全由远程目标复制，排除 `.env*`、`config/local.php` 和既有 storage，使用空运行目录。
- 本机仅用于读取并取得独立 `/Users/kk/Documents/ChatGPT/wuyoo-tests` 测试工具；没有读取本机框架作为受审实现，没有向远程正式项目复制或覆盖源码。远程旧测试归档采用旧路径布局，因此采用已检查 `WUYOO_TEST_ROOT` 保护的外部套件。

关键文件 SHA-256（审计前后不变）：

| 文件 | SHA-256 |
| --- | --- |
| composer.json | `f19cc80534103e2225fd5605ac78616451a80e5ccfc691eb9ed1adba963ff78c` |
| composer.lock | `369b74bbfb76428d33912e90226c35c56572823f149867d3c4e4915df702e05b` |
| core/Application.php | `5a1d46c11a7a3647b719a96d18b188937466dabd42a45bb7e3f0e7dcd55300b4` |
| core/Support/Scope.php | `551f2b1aed9d183a4cc69c548fb97085babbbc2b489bb478cece1849e8a96a5b` |
| core/Routing/ControllerResolver.php | `0ed680b7c0a920f2e23f69583a3c9dd28a4c843bd649411ab9b25e3afcd223a3` |
| core/Http/Request.php | `88eab3e12d849cd504bfe21ad5cc31f7d696f6c82ce6cb5732a5e43164edf54f` |
| core/Http/Response.php | `8fea079c64283a54372f1603356ed43d99569b8882360a613e97eae5c762cab6` |

原始快照 JSON 指纹：`39fd8ebfebbca77914214d92d8abb83dae1e98ec588ec94661de73952d1d6169`。这是本轮文件基线，不是 Git 提交或来源认证。

## 2. 成果完整性

下表均以本轮远程源码和重新执行的回归为依据；不沿用旧报告的“通过”作为本轮结果。行号均为当前远程文件。

| 成果 | 当前实现依据 | 本轮结果 |
| --- | --- | --- |
| F1 异常与 Session 收尾 | `Application.php:145–161` 在有效 Scope 内转错误 Response 后统一 apply；`:108–132` finally 清理；`Security/Session.php:44–60` | 新会话、轮换、422/500、模板异常均保留关联与 Cache-Control；清理故障继续清理其他资源，原异常保留 |
| F2 Request 替换契约 | `Application.php:185–205` 每个 next 边界同步 Request，匹配后禁止改方法/路径；`Support/Scope.php:19–25` 禁止替换已观察值 | Router、构造注入、Action、Session、分页一致；过晚替换明确失败 |
| F3 Blade 生命周期 | `View/Blade.php:53–79` 首次 Factory 激活 Container，公开 flushCache/forgetFactory，关闭时恢复上下文 | 直接 Factory/分页、内联及命名组件、异常后渲染、连续 Scope 通过 |
| F4 游标分页 | `Application.php:97–100,125` 独立 CursorPaginator resolver 接入有效 Request 并重置 | 正反向、显式及自定义游标、连续 Scope 和 Request 替换通过；另见 R1 的畸形结构边界 |
| F5 URL 编码 | `Http/Request.php:33–42` 保存解码 path 与规范 encodedPath；`Application.php:95` 分页使用后者 | 保留字符、中文、百分号、query/custom page name 往返通过 |
| F6 事务 callable | `Database/DB.php:33` 在边界用 Closure::fromCallable | 函数、实例方法、invokable、Closure 的返回值、提交/回滚通过 |
| F7 非法 UTF-8 路径 | `Http/Request.php:38–39` 验证解码后 UTF-8 | 三版本及 HTTP 验证非法编码返回 400，合法 Unicode 与单次解码保留 |
| Controller 自动构造/DI | `Routing/ControllerResolver.php:44–80` 与 `Scope.php:38–62` | 无绑定 Controller、空构造、已注册类/接口、显式默认值、Factory/实例优先、self/parent、循环检测和异常恢复通过 |

Application/Scope/Request/Response 是同一条实际可执行调用链，没有发现关键文件遗失、冲突标记、残留 `.orig/.rej`、重复 bootstrap、BookController/Greeting 业务残留或待实现占位。正式骨架只有 HomeController、首页和只读 JSON/健康端点。

## 3. 已确认问题

### R1 · P3 · 结构不完整的游标触发 HTTP 500

**位置及方法**：`core/Application.php:97–100` 的 currentCursorResolver；`core/Http/ExceptionHandler.php:14–25` 的 render。上游依据：`vendor/illuminate/pagination/Cursor.php:42–49,116–136`，`vendor/illuminate/database/Concerns/BuildsQueries.php:483–538` 的 paginateUsingCursor。

**原因/触发**：官方 Cursor::fromEncoded 会接受包含 `_pointsToNextItems` 的 JSON 对象，但不验证某个查询实际需要的排序列。应用直接把它交给 `orderBy('id')->cursorPaginate(2)`；缺少 id 时，Cursor::parameter('id') 抛 UnexpectedValueException，统一异常处理转换为 500。正常游标解析已经修复；这是继承自上游的输入边界，不是本地重写 SQL 导致。

**复现证据**：隔离 SQLite 记录 id=1、2、3，请求游标为 `eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9`（即 `{"_pointsToNextItems":true}` 的 base64url）。PHP 8.3.32、8.4.23、8.5.8 均返回 500，诊断异常为 `Unable to find parameter [id] in pagination item.`。合法 id=2 游标均返回 200 / `[3]`；不能解码的 JSON 游标均返回第一页。因此不能声称所有无效游标都 500。

**影响**：公开游标接口可由客户端制造服务器错误与日志噪声；未证实数据写入、SQL 注入或内部信息泄漏。诊断时隔离副本显式启用 debug；正式默认错误隐藏已有回归覆盖。

**最小合理修复**：在具体分页查询的输入边界，根据实际排序键校验游标参数完整性、值类型及方向标志，失败返回 400/422（或明确定义的第一页策略），再调用原版分页器。框架可仅对通用结构做薄校验，不能在全局 resolver 猜测所有查询的排序列；不要全局吞掉所有 UnexpectedValueException，也不要修改 vendor 或重写分页算法。多列排序必须验证全部键。

**兼容影响**：合法游标和显式 Cursor API 不变，仅改变畸形结构输入；现有正常游标测试继续保留。

### R2 · P3 · 极大页码造成 offset 溢出，PHP 8.5 返回 500

**位置及方法**：`core/Application.php:91–94` 的 currentPageResolver 只判断正整数；`vendor/illuminate/database/Query/Builder.php:3274` 的 forPage 计算 `(page - 1) * perPage`，`:3214–3218` 的 offset 转 int；`Application.php:140–143` 将运行时诊断转异常。

**原因/触发**：64 位 PHP 下 `9223372036854775807` 能通过 FILTER_VALIDATE_INT，但乘上每页条数后越过整型范围。原版 Builder 转换溢出的 float 时，8.3/8.4 与 8.5 表现不同。

**复现证据**：相同 SQLite 三行数据，执行 `orderBy('id')->paginate(2)`，请求 `page=9223372036854775807`。8.3.32 / 8.4.23 返回 200，current_page 为极大值但 data 又是 `[1,2]`，from/to 约为 `1.8446744073709552e19`；8.5.8 返回 500，ErrorException 为 `The float 1.8446744073709552E+19 is not representable as an int, cast occurred`，位置为上游 Builder.php:3218。

**影响**：极端输入导致错误页数据或 500，普通分页不受影响；未证实数据库损坏。不能将本次 SQLite 输出扩大为所有驱动/所有页大小的相同结果。

**最小合理修复**：在输入层确定合理的页码上限，同时限制业务可选 perPage，并确保 `(page-1)*perPage` 可安全表示（例如按 `intdiv(PHP_INT_MAX, perPage)+1` 的数学上界再取更小的业务上限）。超界明确返回 400/422 或按已公布策略处理。不要依赖溢出 cast，也不改上游 SQL Grammar。业务直接传入显式 page/perPage 时同样需要校验，单改默认 resolver 覆盖不到所有入口。

**兼容影响**：合法范围内分页保持不变；只有此前不可靠的极端参数行为改变。

### 可复现的最小公共夹具

以下代码只应放在项目外，`WUYOO_TEST_ROOT` 指向已准备的隔离源码副本；本轮没有向正式源码加入测试路由。两项复现都不需要真实数据库。

```php
<?php
$root = realpath(getenv('WUYOO_TEST_ROOT') ?: '');
if (!$root || $root === '/www/wuyoo') { throw new RuntimeException('Use an isolated source copy'); }
require $root.'/vendor/autoload.php';
use Wuyoo\Application;
use Wuyoo\Support\Config;
use Wuyoo\Http\Request;
use Wuyoo\Database\DB;
$app = new Application($root, new Config(['database' => [
    'default' => 'test', 'connections' => ['test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]],
]]));
foreach (['cursor', 'page'] as $kind) {
    $app->router->get('/api/'.$kind, function () use ($kind) {
        DB::connection()->statement('CREATE TABLE entries (id INTEGER PRIMARY KEY)');
        DB::table('entries')->insert([['id'=>1], ['id'=>2], ['id'=>3]]);
        $q = DB::table('entries')->orderBy('id');
        return ($kind === 'cursor' ? $q->cursorPaginate(2) : $q->paginate(2))->toArray();
    });
}
foreach ([['cursor', 'eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9'], ['page', '9223372036854775807']] as [$kind, $value]) {
    $r = $app->handle(new Request('GET', '/api/'.$kind, [$kind => $value]));
    echo $kind.' '.$r->status.' '.$r->body.PHP_EOL;
}
```

## 4. 架构、OOP、冗余及开发体验

- **MVC/生命周期职责合理**：Application 负责组织流程与 Scope 收尾，Scope 负责请求内缓存和显式 Factory，Router 注册后冻结，ControllerResolver 只构造路由明确指定的 Controller。Config/Logger 可跨请求复用，请求、Session、数据库与 Blade 不作为应用可变单例保存。
- 构造自动注入限定于已注册的简单类/接口或明确默认值；服务不会被递归隐式构造。复杂构造仍显式 Factory，行为透明，减少 Controller 重复绑定而未引入通用自动装配体系。Action 的复杂联合类型/variadic 限制是既有契约，不作为缺陷重复列出。
- Eloquent、Blade 与分页存在上游静态桥接，框架在串行 Scope 内激活/清理并拒绝嵌套并发。不能把此模式推广为协程/常驻 Worker 支持，也不能把业务持久闭包捕获用户数据归因于 Scope 自动隔离能力。
- Database/DB/Model 是薄适配：官方 Capsule、Connection、Query/Eloquent Builder、Grammar、事务、Collection/Cast 保留。没有发现重复 SQL 编译器、事务算法或 Repository 层。未绑定 Eloquent dispatcher、afterCommit manager 等已有兼容边界继续有效，不因为 Events 包存在或本轮 SQL 测试通过而扩大承诺。
- Blade 仍是原版编译与缓存机制；两个小子类只替换组件命名空间发现。组件缓存按 Scope 清理以保证正确性，保留该设计；没有证据支持为性能再增加复杂缓存或绕过上游。
- Router 静态哈希与动态分桶、正则注册时编译真实存在；静态优先、405/Allow、HEAD/OPTIONS 已验证。不为已有明示路由优先规则重复报 Bug。
- 新 Controller 仅需类和路由；已注册 Logger 等构造依赖直接注入。Model 继承适配基类不需 DI 注册，Blade 新增模板即可，数组/JsonSerializable 自动 JSON。业务认证仍需业务明确配置，Cookie 认证写 API 需 CSRF；没有默认开放的写业务端点。
- XSS 转义、CSRF 成功/拒绝、Header/Cookie 注入边界、本地 redirect、随机上传名与 MIME allowlist、常规异常隐藏均有源码和本轮回归依据。未作完整渗透测试，不承诺业务 raw HTML/raw SQL 或自定义认证正确。
- 性能仅能确认惰性数据库连接、请求内复用、Blade 编译缓存与路由索引等实现存在；没有压测或吞吐/延迟数据，不声称优于其他框架。

## 5. 本轮实际运行验证

所有运行都在服务器项目外 `/tmp/wuyoo-final-k_jknbpo/` 或测试套件自行生成的独立临时目录执行。正式项目没有 tests 目录、测试路由、运行缓存或日志写入。本轮临时进程使用独立 PID、socket、数据目录和回环端口，不连接生产数据库。

| 环境 | 本轮实际执行 | 结果 |
| --- | --- | --- |
| 系统 PHP 8.3.6 CLI | 核心 run.php 20 组、真实 HTTP 7 组 | 全通过；系统缺 pdo_sqlite，不用它声称 SQLite 通过 |
| 临时 Linux PHP 8.3.32 CLI | 核心 20 + 生命周期 7 + HTTP 7 + SQLite 7；平台要求 | 全通过 |
| 临时 Linux PHP 8.4.23 CLI | 同上 | 全通过 |
| 临时 Linux PHP 8.5.8 CLI | 同上 | 全通过 |
| MySQL 8.0.46 + PHP 8.5.8 | 全新 datadir，回环端口 20809，独立 wuyoo_test_final；database.php 6 组 | 全通过 |
| PostgreSQL 16.15 + PHP 8.5.8 | 临时解包发行包，全新集群，回环端口 27497，独立 wuyoo_test_final；database.php 6 组 | 全通过 |
| Nginx 1.24.0 + PHP-FPM 8.3.6，配置启用 OPcache | 临时 Nginx/FPM 配置、独立 socket/回环端口；复用 HTTP 7 组，连续两轮 | 两轮均通过 |
| PHP 8.3.32 lint | 远程正式源码、配置、路由及工具共 36 个 PHP 文件 | 全通过 |
| Composer | 隔离副本 install、无 vendor 的独立副本从 lock 全量 install、validate、平台检查、audit --locked | 安装/检查通过；validate 仅固定版本普通警告；本轮安全公告查询未发现公告 |
| 独立分页边界探针 | R1/R2，三版本、隔离 SQLite | 复现上文问题；不计入“全部通过” |

数据库 6 组覆盖 Model create/find/save/delete、基础 Cast/Collection、参数绑定与注入样式字符串、查询/JOIN/子查询、事务回滚/Savepoint/upsert、四类 callable、分页/cursor/chunkById；SQLite 另有遗弃事务收尾与连续请求组。SQL 注入样式输入作为 binding 处理，未修改或重写 MySQL/PostgreSQL Grammar。

HTTP/FPM 覆盖入口 MVC/JSON、UTF-8、HEAD/OPTIONS/405、Cookie、JSON/体积限制、生产错误隐藏、Session 隔离及轮换、CSRF、异常 Cookie、实际上传。FPM 版本只实测系统 8.3.6；不能写成 8.4/8.5 FPM 均通过。FPM 套件仅替换临时测试脚本的服务器启动/清理为外部 Nginx/FPM，保留 7 组断言；没有改变框架文件。

PHP CLI 临时运行时取自 [static-php-cli 分发目录](https://dl.static-php.dev/static-php-cli/common/)，没有安装为系统 PHP。PostgreSQL 与缺失 libpq 只下载发行包并解包到临时目录，没有 apt install、系统服务创建或配置修改。准备阶段的临时 SQL 引号、FPM env 值和 PostgreSQL 动态库问题均在测试工具/隔离环境中解决后才记录上述通过结果，不算框架 Bug。

### 依赖完整性证据

在无 vendor 的副本从原 lock 全量安装 34 包，裁剪脚本正常运行，lock SHA-256 不变。逐路径及内容哈希比较远程安装与该重建副本：Database **217**、View **55**、Pagination **20** 个文件全部一致，无缺失/新增/内容修改。这比只读取包版本更强，但不是对全部 34 包每个文件做来源认证。

审计没有全文读取 Laravel；针对 Cursor、Builder offset、分页渲染及清理调用做局部阅读。Cursor 行为另与[固定版本官方源码](https://github.com/illuminate/pagination/blob/v13.38.0/Cursor.php)核对。文档检索服务失败时未将其当作证据，结论以远程固定版本源码及运行结果为准。

复用套件的基本命令如下；路径指本轮临时位置，清理后需重新准备：

```sh
cd /tmp/wuyoo-final-k_jknbpo/source
php /opt/novel-tools/composer.phar install --no-interaction --no-dev
export WUYOO_TEST_ROOT=/tmp/wuyoo-final-k_jknbpo/source
# PHP_BIN 替换为临时 runtime/8.3.32/php、8.4.23/php、8.5.8/php
"$PHP_BIN" ../tests/run.php
"$PHP_BIN" ../tests/lifecycle.php
"$PHP_BIN" ../tests/http.php
TEST_DB_DRIVER=sqlite "$PHP_BIN" ../tests/database.php
```

## 6. 未验证风险与可暂缓改进

以下不计入已确认 Bug 数量：

- 没有对生产站点执行验证或部署。正式 storage 目前为 root 所有，启用非 root FPM 前需按 README 为实际运行用户准备写权限；本轮仅调整临时副本权限，正式目录未改。TLS、可信代理、生产 PHP 请求/上传限制、实际站点根目录与错误页仍需部署验收。
- 没有 SQL 死锁/断线恢复/锁竞争压力测试，也未穷举 DDL 事务差异、大数据 cursor 的驱动缓冲、复杂关系/Cast、所有数据库版本及扩展版本。MySQL/PostgreSQL 实库仅搭配 PHP 8.5.8，不能将其扩为完整驱动×PHP 矩阵。
- 未重跑 Redis/MongoDB 实库；本轮只覆盖已有可选依赖缺失等核心回归。未验证 MongoDB CRUD/认证/TLS。
- 未验证常驻 Worker、协程并发或嵌套 Scope；文档明确不承诺，不要求本轮改架构。
- 当前 CI 只执行依赖检查，没有获取外置回归套件的步骤。可在后续发布流程中固定套件版本/校验和并执行回归，避免“CI 绿即行为全通过”的误解；本轮没有运行 GitHub CI。
- 既有 STATUS 保留多个阶段的历史测试口径，适合后续单独整理最新摘要；本轮不覆盖历史报告。没有 Git 影响追溯与可重复交付，可在独立变更管理任务中处理，但无需为本次审计临时初始化仓库。

## 7. 使用建议与后续优先级

1. **可以开始受控实际项目试用**：使用已验证的串行 HTTP/MVC、显式服务 DI、基本 Eloquent、Blade、Session/CSRF 与 JSON API；以实际业务验收和上线回滚方案为边界。
2. **公开分页接口前处理 R1/R2**：校验游标结构/排序键、页码与 perPage 上限；保留原版 Eloquent/分页器，增加少量针对性回归即可，不需要大型测试体系或框架重写。本轮未作修复。
3. **正式上线前必须完成部署与业务验收**：实际 FPM 用户权限、TLS/代理、生产配置、认证/授权及容量检查。隔离测试通过不能代替这些工作。
4. CI 套件分发、历史文档摘要和进一步性能测量可暂缓；不建议为了抽象层数量或删除依赖文件而改变当前结构。

本报告不改变 `ARCHITECTURE_AUDIT.md` 的历史内容。旧 F1–F6 在当前服务器实现及本轮回归中已关闭；新增两项是独立低优先级输入边界。最终逐文件检查确认仅增加 `docs/FINAL_REVIEW.md`，原有 2232 个文件内容不变，无删除。本轮临时服务均已退出，临时源副本、依赖重建、测试工具、运行时与数据库数据均已清理；正式服务与生产数据库未操作。


## 8. 后续修复记录（2026-10-27）

用户在审计完成后明确授权修复并更新服务器 Git。本节记录后续变更，前文的只读审计、无 Git 状态和问题复现均指修复前时间点。

- **R1 已修复（请求游标入口）**：新增 `Http/RequestCursor` 继承官方 Cursor，沿用官方 `fromEncoded`；检查 boolean 方向及非 null 标量参数，在实际 `parameter(name)` 读取时检查查询需要的排序键。因此复合排序和别名无需框架预先猜测，也不全局吞掉 UnexpectedValueException。输入错误返回安全的 400，其他服务器异常仍为 500。
- **R2 已修复（请求页码入口）**：`Http/PaginationInput::page` 拒绝超过 1,000,000 的页码，防止已复现的极大页码进入 offset 运算。`perPage` 为客户端可选页大小提供 1–1,000 校验；同时使用两项限制时 offset 保持在安全范围。普通缺失/非法/非正页码回第一页不变。
- Application 的默认/自定义 Request resolver 统一使用以上边界。原版 Builder 的显式 page/perPage/Cursor 参数不被拦截；不可信显式参数应使用 README 的 `PaginationInput` 示例。通用游标结构检查不是数据库列类型验证，业务仍应验证具体数字/日期等字段；没有宣称任意外部 SQL 参数均可直接传递。
- 未改写 SQL 查询、分页算法、事务或 vendor；未修改生产配置、锁文件或正式 storage。新类通过 PSR-4 正常加载，服务器原依赖目录保持不变。

### 本轮验证

修复前隔离快照三版本均复现 R1；R2 在 8.3.32/8.4.23 返回极大页码但错误首屏数据，在 8.5.8 返回 500。修复后三版本各 **47 组通过**：核心 20、生命周期 7、HTTP 7、SQLite 7、分页输入 6。新增回归覆盖两项原始输入返回 400、正常正反向分页、自定义名称、多列排序、别名、Query/Eloquent、显式参数校验、上限组合和实际 HTTP；无关 UnexpectedValueException 保持 500。

Composer install 在隔离副本从原 lock 执行成功，无依赖变化；三版本平台要求通过；正式源码 38 + 外置 PHP/Blade 12 个文件通过 PHP 8.3 lint；Composer validate 仅有既定精确版本警告。受测副本的三个变更核心文件逐字节与服务器实际文件一致，系统 PHP 8.3.6 还直接验证正式 autoload 下的两个 400 输入边界。

本轮没有重跑 MySQL/PostgreSQL 实库、FPM、Redis/MongoDB 或压测；第 5 节相应通过记录是修复前审计结果，不能计为修复后的测试矩阵。上线前的业务/部署/容量验收要求仍保留。

### Git 与回归材料

- 服务器仓库：`/www/wuyoo/.git`；分支 `codex/pagination-input-fixes`。先提交真实修复前快照 `964b6e1`，再提交修复及文档，保留清晰差异。新仓库不等同于恢复旧本机 Git 历史。
- Git 使用仓库局部 `Codex <codex@localhost>` 记录本次工作；无远端配置及 Push。vendor、私有 local.php、环境文件及 storage 运行数据保持忽略。
- 修复前完整备份：`/root/wuyoo-backups/before-pagination-fix-20261027T104305Z.tar.gz`。
- 可重放的完整外置套件：`/root/wuyoo-backups/pagination-regression-20261027-964b6e1.tar.gz`，含 `tests/pagination.php` 与夹具，使用 `WUYOO_TEST_ROOT` 指向隔离源码；正式目录没有测试文件。
- 套件 SHA-256：`9866f091fb17ef5a263c4a783c24f70bfd3ff27761e7ded2b2f3891bd6e359c4`。
