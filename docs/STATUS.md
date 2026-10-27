# Wuyoo 状态

更新：2026-10-27。阶段 A–E 已完成，源码与运行依赖准备完毕，未启用生产站点。

## 服务器迁移与测试外置（2026-10-27）

- 开发目标明确为 `ssh contabo`（主机 `vmi3663018`）上的 `/www/wuyoo`；本机 checkout 仅为镜像。服务器原先停留在最初版本，本次迁入此前 F1–F7 修复及 Controller/DX 优化，并核对源码校验值。后按用户要求移除迁入服务器的 `.git`；历史仅在本机镜像保留，未新增提交或 Push。
- 迁移前完整备份保留在 `/root/wuyoo-backups/before-controller-dx-20261027.tar.gz`。锁定依赖一并迁入，服务器从原 lock 执行 Composer install，无依赖升级；未修改站点或系统服务配置。
- 已删除服务器正式源码中的 BookController、Book Model、Greeting 和 Book 页面。迁移过滤 `.DS_Store`、`._*`、`__MACOSX` 等 macOS 附带文件/目录，最终扫描为零。
- 按用户要求，测试放在独立 `/www/wuyoo-tests`，正式源码中没有 `tests/`。外部脚本要求 `WUYOO_TEST_ROOT` 指向隔离源码副本；Composer 和 CI 中已移除原测试路径，README 给出外部验证命令。外部目录保留全部既有回归及测试夹具，不作为框架运行依赖。
- 服务器 Linux PHP 8.3.32 / 8.4.23 / 8.5.8 各执行外部核心 20 组、生命周期 7 组、HTTP 7 组、SQLite 7 组，全部通过；此前迁移后的原 Composer 套件同样三版本通过。三版本平台检查、Composer validate、PHP 8.3 lint（正式源码 36 个、外部测试 9 个文件）及差异检查通过。系统 PHP 8.3.6 另实测首页、JSON 及已删除业务路由。
- 验证仅用服务器项目外副本与隔离 SQLite；临时运行时、缓存、日志、数据库及源码副本清理。未重跑 MySQL/PostgreSQL/Redis/MongoDB 实库、FPM 或压测，未启用生产站点。完整矩阵结果另保留于 `/root/wuyoo-backups/server-validation-20261027.json`。

## Controller 与应用骨架优化（2026-10-27）

- ControllerResolver 自动构造路由指定的普通 Controller，仅注入 Scope 已注册的简单类/接口依赖，或使用明确默认值；复杂参数要求显式 Factory。已有 Controller 实例/Factory 优先，异常不降级，不递归自动构造其他服务。
- Scope 仅新增显式 `getOrCreate(id, factory)` 入口，与 `get()` 共用实例缓存、观察状态保护、循环检测和异常清理。Application、Router、Request/Response 生命周期保持原有实现。
- 正式骨架保留 HomeController、Blade 首页和 JSON/健康端点；移除 BookController、Book 页面、Greeting 及示例 Controller 绑定。Book Model 移入 `tests/fixtures/Book.php`，SQL 与分页回归继续使用；原有测试和审计记录保留。
- README 改为普通 Controller 零绑定、服务显式注册和通用 Model 示例，补充新增路由、Blade、JSON 的最小流程。
- 从原 lock 执行 `composer install` 成功，无依赖升级或 vendor 内核修改。最终测试矩阵在项目外副本运行，排除本机配置；PHP 8.3.32 / 8.4.23 / 8.5.8 各通过 `composer test`（20 组核心 + 7 组生命周期）、`composer test:http`（7 组）、SQLite（7 组）和平台检查，F1–F7 回归均保留通过。补充 self/parent 与 Factory 优先边界后三版本各重跑 20 组核心通过。
- 三版本均实际新建并验证文档中的 Controller、Logger 构造注入、路由、Eloquent Model、Blade 页面及 JSON API，使用独立 SQLite 文件；PHP 8.3 源码 lint、Composer validate 和 `git diff --check` 通过。临时验证副本及生成文件清理，不新增测试体系或开发依赖。
- 本轮未重跑 MySQL/PostgreSQL/Redis/MongoDB 实库、FPM 或压测，无提交、推送或部署。

## 审计后生命周期修复（2026-10-27）

本轮从 `codex/wuyoo-framework` / `dd03cb1` 开始；开始时只有未跟踪的 `docs/ARCHITECTURE_AUDIT.md`，报告原文保留。以下为本轮本地实测，不以此前远端结果代替。

- **F1 已修复**：Controller、中间件、输出污染及模板异常均在有效 Scope 中转为 Response，再执行一次 Session::apply，最后关闭会话。首次创建、已有会话轮换、422/500 均保留 Cookie、缓存策略和已写入数据。初始化/清理故障保留外层兜底；清理时继续释放其他资源，记录清理异常，并保留已抛出的原始异常。
- **F2 已修复**：每个 next 边界同步有效 Request，Router、构造注入、Action、Session 和分页统一读取该实例。Scope::get 取出的值不能再被 set 替换成另一个值；因此任何 Request 消费者初始化后都明确拒绝替换。路由匹配后拒绝改变方法/路径，保持已绑定路由参数一致。替换中间件应放在 Request 消费者之前；同一实例继续传递不受影响。
- **F3 已修复**：Blade 首次使用 Factory 时激活请求级 Container，直接 Factory、Controller/CLI 分页和普通 View 共享边界。Scope 结束清理 Factory 状态，使用固定版本公开的 Component::flushCache / forgetFactory，恢复先前 Container；内联和命名组件、异常后再次渲染、串行 handle 均验证。
- **F4 已修复**：独立 CursorPaginator resolver 从有效 Request 读取默认/自定义游标名，使用官方 Cursor::fromEncoded，结束时重置。正向、反向、显式游标、无效输入、连续 Scope 和 Request 替换后的翻页均验证。
- **F5 一并修复**：Request 增加 encodedPath，按路径段规范编码，保留斜杠且不重复编码百分号。Router 仍读解码后的 path；分页 URL 使用 encodedPath。保留上游 withQueryString/appends 的显式语义，验证保留字符、中文、嵌套 query 和生成链接的往返。
- **F6 一并修复**：事务 callable 在边界转为 Closure；函数名、实例方法、invokable、Closure 均实际验证返回值、提交、异常回滚及事务层级恢复。
- **F7 / P3 已修复（最终验收后补充）**：Request 在路径解码后用 `mb_check_encoding(..., 'UTF-8')` 拒绝非法字节并返回 400。新增构造边界及真实 HTTP 回归，覆盖无效、截断、过长编码、代理码点及越界码点；中文/emoji、单次解码和 query 边界保持正常，HEAD 错误无响应体。PHP 8.3/8.4/8.5 各执行 `composer test` 22 组、HTTP 7 组、SQLite 7 组通过；Nginx/PHP 8.5 FPM + OPcache 的 HTTP 7 组连续两次通过。原验收与补充结果见 `docs/FINAL_VALIDATION.md` 第 6 节。

本轮无依赖升级、vendor 内核修改、架构重建、提交、推送或部署。Composer platform 仍为 8.3.0。

| 运行时 | 本轮执行 | 结果 |
| --- | --- | --- |
| PHP 8.3.32 | composer test：原有 14 + 新增生命周期 7 组；test:http 6 组；SQLite 7 组；check-platform-reqs | 全部通过 |
| PHP 8.4.23 | 同上 | 全部通过 |
| PHP 8.5.8 | 同上 | 全部通过 |

新增测试位于 `tests/lifecycle.php`，接入 composer test；真实 Cookie 异常回归在 `tests/http.php`，事务 callable 回归在 `tests/database.php`。隔离复制 `dd03cb1` 源码并运行新增测试，分别确认 F1–F6 在基线失败、修复后通过。测试副本随验证清理，不依赖临时审计目录。

`composer install` 从原 lock 执行成功；`composer validate` 通过，仅保留有意固定上游版本的 exact constraint 警告。47 个源码/测试文件通过 PHP 8.3 lint；最终三版本各重跑 7 组生命周期测试通过，`git diff --check` 通过，composer.lock 无变更。

本轮数据库仅使用隔离 SQLite；未连接生产数据库。MySQL/PostgreSQL/Redis/MongoDB 实库、FPM/Nginx/OPcache、并发及压测未在本轮重跑，仍需发布前验证。afterCommit、Observer 和完整 Laravel helper 生态的兼容边界不因这些修复而扩大。

## 已完成

- public/index.php -> init.php -> Application，配置、路由初始化和请求处理分离。
- 请求级 Scope 与显式 Controller/Service 工厂、标量/Enum/Request 参数绑定、统一返回转换。
- Macaw 路由实例改造：哈希静态路径、分桶预编译动态规则、分组、namespace、中间件、404/405、HEAD/OPTIONS。
- Request/Response、原生 error_log 日志、异常隐藏、JSON/表单、验证、上传、CSRF、安全 Cookie/Session。
- Laravel 13 Eloquent / Query Builder、MySQL/PostgreSQL、CRUD/JOIN/子查询/聚合/分页/links/事务/Savepoint/Upsert/cursor/chunkById。
- 原版 Blade、匿名组件、可配置类组件命名空间、自定义指令、编译缓存；薄适配移除组件查找对 Foundation Application 的依赖。
- phpredis 最小缓存；官方 MongoDB Library 可选适配及依赖缺失时明确报错。
- README 示例、Nginx 示例、上游/许可证/恢复路径、轻量测试脚本、PHP 版本矩阵 CI 配置。

## 实际验证

| 环境 | 验证 | 结果 |
| --- | --- | --- |
| macOS CLI PHP 8.3.32 | 核心 14 组、SQLite 6 组、真实 HTTP 5 组、平台要求 | 通过 |
| macOS CLI PHP 8.4.23 | 核心 14 组、SQLite 6 组、真实 HTTP 5 组、平台要求 | 通过 |
| macOS CLI PHP 8.5.8 | 核心 14 组、SQLite 6 组、真实 HTTP 5 组、平台要求 | 通过 |
| 服务器系统 CLI PHP 8.3.6 | /www/wuyoo 内核心 14 组、真实 HTTP 5 组 | 通过 |
| Linux CLI PHP 8.5.8 + MySQL 8.0.46 | 独立实例，5 组 SQL/Model/事务/分页验证 | 通过 |
| Linux CLI PHP 8.5.8 + PostgreSQL 16.15 | 独立实例，5 组 SQL/Model/事务/分页验证 | 通过 |
| Linux CLI PHP 8.5.8 + Redis 7.0.15 | 独立实例，JSON 值/空值/TTL/删除验证 | 通过 |
| 干净临时目录，PHP 8.3.32 | composer install --no-dev 从 lock 重建、裁剪及核心回归 | 通过 |
| 全部源码和安装依赖 | PHP 8.3 lint，2094 个 PHP 文件（不含 Blade/运行缓存） | 零语法错误 |
| Composer | platform.php=8.3.0；validate、check-platform-reqs、audit --locked | 通过，无已知安全公告 |

真实 HTTP 覆盖前端入口、MVC、JSON、HEAD 空响应、405/Allow/OPTIONS、Cookie、请求大小、错误隐藏、Session 轮换与客户端隔离、CSRF 成功/失败、上传 MIME 和随机文件名。
安全支持截止日期来自 PHP 官方表：8.3 为 2027-12-31；在此前复评最低版本，见 README。

真实 SQL 与 Redis 位于服务器 /tmp 下的独立测试进程，端口 13306/15432/16379；没有使用生产实例或数据库。验证结束后关闭并清理这些测试进程和数据。
验证使用的补丁版本只说明覆盖范围；生产应安装受支持的最新安全补丁。

## 上游精简

Laravel v13.38.0 / c42f9abd4a3d2f9a3cd6be109255ddce8092ceb8。
删除 Database Console、Migrations、Factories、Seeder、DatabaseServiceProvider；保留相互耦合的 Model 核心、关系/Cast 源码与 Blade 必需依赖，不承诺完整高级功能。
共 34 个固定运行时依赖，无 Laravel Foundation、Artisan、Monolog 或大型测试/分析依赖。
具体来源、Split Commit、许可证和恢复路径见 UPSTREAM.md / upstream-lock.json。

## 使用边界与未验证项

- MongoDB 扩展/Library 缺失时的失败路径已验证；未安装该可选依赖，未验证 MongoDB 实库 CRUD、认证或 TLS。
- 没有启用 Nginx/PHP-FPM 生产站点；FPM、OPcache、TLS、反向代理和生产压测未实测，未宣称任何性能数据。
- GitHub CI 配置已提供，未 push，未在 GitHub 执行；实测结果来自上述 CLI/HTTP 环境。
- 核心支持串行请求作用域，显式拒绝嵌套/并发请求；未实现或验证常驻 Worker 服务器。不可在业务静态状态、持久闭包或中间件中保存请求数据。

开发源码主目录：服务器 `/www/wuyoo`，仅保留正式源码与运行依赖，不在服务器建立 Git 仓库。服务器原外部测试目录 `/www/wuyoo-tests` 当前不存在；本机保留 Git 历史和外部测试镜像，需要验证时再取得套件。不自动配置虚拟主机。
