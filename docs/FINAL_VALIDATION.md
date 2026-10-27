# Wuyoo 最终集成验收

日期：2026-10-27（Asia/Shanghai）。结论：**达到小范围真实项目试用条件；不等同于生产上线验收通过。**

补充：最终验收后，按用户要求完成 F7 定点修复及回归，见第 6 节。其他章节保留原验收阶段的执行范围与计数。

本报告只计入本轮实际执行结果，没有将历史报告计作本轮通过。开始时工作区已有 F1–F6 修复及未跟踪的审计报告、生命周期测试；均保留。未重新全面审计源码或 Laravel，未升级框架依赖、提交 Git、Push 或部署。

## 1. MySQL 验证结果：通过

- **MySQL Community Server 8.0.46 + PHP 8.5.8 / pdo_mysql**，官方 macOS x86_64 压缩包，直接解压到临时目录，没有安装系统服务。
- 新初始化独立 datadir，只监听 `127.0.0.1` 随机端口，关闭 MySQL X Plugin；独立数据库 `wuyoo_test_final`、专用测试账号。未连接本机原有 MySQL 或业务库。
- 现有 `tests/database.php` **6 组连续执行两次通过**：Eloquent create/find/save/delete、Cast/集合；Query Builder CRUD、where/whereIn、排序、聚合、JOIN/子查询；参数绑定；批量 Insert、Upsert；提交、异常回滚、嵌套 Savepoint；分页及 Blade links、cursor、chunkById；F6 四种 callable 的返回值、提交、回滚及事务层级恢复。
- 临时补充检查 **4 组通过**：原生 prepare（确认 `ATTR_EMULATE_PREPARES=false`）、中文及引号值绑定；`cursorPaginate()` 自定义游标名和跨 Scope 正反向翻页、编码 URL；未提交事务在异常 Scope 收尾时回滚、约束异常后查询恢复、PDO 断开；拒绝连接后下一 Scope 成功；实际 `config/database.php` 的 `DB_*` 环境变量选中正确驱动和测试数据库。
- 同一现有 SQL 脚本还在独立 **MySQL 5.7.42** 实例连续通过两次，仅作补充兼容记录，不代替 8.0 结果或建议使用旧版本。

## 2. PostgreSQL 验证结果：通过

- **PostgreSQL 16.15 + PHP 8.5.8 / pdo_pgsql**。官方源码在临时目录构建，独立 initdb、UTF-8、随机回环端口及独立 socket 目录，数据库 `wuyoo_test_final`。
- 现有 `tests/database.php` **6 组连续执行两次通过**，覆盖范围同 MySQL。
- 临时补充检查 **4 组通过**：绑定及游标分页、未提交事务回滚与约束异常恢复、PDO 释放与连接拒绝后的 Scope 恢复、真实环境变量配置加载。
- MySQL `AUTO_INCREMENT` 与 PostgreSQL `BIGSERIAL` 的插入主键路径、各自 Upsert、分页及 Savepoint 均以真实查询及结果断言验证，未用 SQL 字符串编译结果代替执行。没有扩大到所有 SQL 方言特性的兼容承诺。

两种实库都使用隔离测试账号和本地测试连接；认证策略及 TLS 不代表生产配置。测试准备阶段的 PostgreSQL 构建顺序及 socket 路径长度问题已在临时环境解决，没有修改框架。

## 3. Nginx / PHP-FPM 验证结果：通过

环境：**macOS x86_64、Nginx 1.25.0、PHP 8.5.8 FPM、Zend OPcache 8.5.8**。独立配置、日志、PID 和随机回环端口；非 root 运行，未更改系统或生产 Nginx/FPM 配置。

- 临时适配现有 `tests/http.php`，将请求发送到 Nginx/FPM，**6 组连续执行两次通过**。复用现有 HTTP fixture，主页及 `/api/*` 仍执行实际 `public/index.php -> init.php -> Application`。
- 验证主页 Blade、MVC/Controller/Service、JSON API、HEAD、OPTIONS、405/Allow、Cookie、JSON 校验及 400/422、请求体 413、500 隐藏异常详情。
- Session/CSRF、客户端隔离、轮换后旧 ID 失效，以及 422、500、Blade 异常后的 Cookie 和会话数据保留均通过；上传 MIME/随机文件名回归通过。
- HTTP 补充检查通过：Middleware 替换 Request 后 query/分页读取一致；路由匹配后改变路径明确失败且响应隐藏详情。
- 另设直接执行实际前端入口的虚拟主机，验证主页、health、Controller、404、非入口 PHP 文件拒绝及隐藏文件拒绝。
- 同一 FPM worker 连续完成 **40 轮主页及 runtime 请求**，响应正常，无请求 Cookie 继承；Blade 编译缓存文件时间及大小保持不变。实际状态显示 `fpm-fcgi`、OPcache 已启用并缓存 131 个脚本，存在缓存命中。这是兼容性检查，不是性能基准。

**测试脚本最小修正**：Nginx 实际返回合法状态行 `HTTP/1.1 422`，现有解析要求状态码后必须有空白，误报为 0。仅修改 `tests/http.php` 一行正则，允许状态码后为行尾；修正后三版本原 HTTP 套件及 FPM 套件均通过。没有修改框架运行代码。

**生命周期区别**：上述结果来自 FPM 同一 worker 的多个独立 PHP 请求；`tests/lifecycle.php` 则验证同进程、同一 Application 的连续 `handle()`。两者均实际执行，不能据此宣称支持常驻 Worker、嵌套或并发 `handle()`。

## 4. F1–F6 回归结果：通过，可重复执行

| 项目 | 本轮证据 | 结果 |
| --- | --- | --- |
| F1 异常响应 Session 收尾 | 生命周期脚本；CLI HTTP 和 Nginx/FPM 下的会话创建、轮换、422/500/模板异常 | 通过 |
| F2 Request 替换契约 | 生命周期脚本验证全局/路由替换、DI 身份一致、消费后拒绝替换、匹配后拒绝改变方法/路径；FPM 补充 HTTP 检查 | 通过 |
| F3 Blade 上下文及缓存 | 生命周期脚本的组件、直接 Factory/分页、异常恢复、连续 handle；FPM 渲染与缓存复用 | 通过 |
| F4 请求游标 | 生命周期脚本的默认/自定义游标、正反向/无效输入/清理；MySQL 8 与 PostgreSQL 16 实库跨 Scope 翻页 | 通过 |
| F5 分页 URL 编码 | 生命周期脚本的保留字符、中文、query 及链接往返；两种实库的编码游标链接 | 通过 |
| F6 transaction callable | SQLite、MySQL 8、PostgreSQL 16 中函数名、实例方法、invokable、Closure 的提交/返回值/回滚 | 通过 |

| CLI 运行时 | `composer test` | `composer test:http` | SQLite `tests/database.php` |
| --- | --- | --- | --- |
| PHP 8.3.32 | 21 组通过（核心 14 + 生命周期 7） | 6 组通过 | 7 组通过 |
| PHP 8.4.23 | 21 组通过 | 6 组通过 | 7 组通过 |
| PHP 8.5.8 | 21 组通过 | 6 组通过 | 7 组通过 |

PHP 8.3 生命周期脚本额外连续执行两次均通过。HTTP 状态行解析修正后，8.3/8.4/8.5 原 HTTP 套件各重跑一次通过。实库及 FPM 的重复执行结果见上。

## 5. 最终工程检查

| 检查 | 结果 |
| --- | --- |
| 隔离副本 `composer install` | 从现有 lock 安装 34 包并执行既有裁剪成功；无 update，lock 与原工作区一致 |
| Composer / PSR-4 | `validate` exit 0；`dump-autoload --optimize --strict-psr` exit 0，生成 1010 个类 |
| 严格校验告警 | `validate --strict` **exit 1，未通过严格模式**；仅三项 Illuminate 精确版本约束告警，符合固定上游版本的既有决策，不修改约束 |
| 依赖安全审计 | `composer audit --locked` exit 0，本次查询无已知安全公告 |
| PHP 最低版本 | platform.php 保持 `8.3.0`；三版本 `check-platform-reqs` 均通过；47 个自研源码/配置/测试 PHP 文件在 8.3 lint 零错误（排除 Blade 源模板） |
| 敏感配置 | 未跟踪文件清单未发现敏感配置；根目录没有 `.env*` 或 `config/local.php`；既有 ignore 覆盖这些配置及运行数据 |
| Git Diff | `git diff --check` 通过；定向检查变更未发现新增调试调用。开始时已有注释删除等修改，本轮保留，未当作本轮变更或擅自清理 |
| 本轮修改边界 | 对开始时所有跟踪及未跟踪源文件作 SHA-256 比较，既有内容仅 `tests/http.php` 的上述一行变化；另新增本报告 |

依赖安全审计结果只代表执行当时的公告库；本轮没有重新扫描全部 vendor 文件。

## 6. F7 / P3：后续定点修复通过

原验收通过真实 Nginx/FPM 请求 `/api/hello/%FF`，复现 **500**，响应隐藏详情。当时按验收范围暂缓修复。随后用户明确要求修复，完成以下最小变更：

- `core/Http/Request.php`：仅在 `rawurldecode()` 后的既有路径校验中增加 `mb_check_encoding($path, 'UTF-8')`，复用 `HttpException(400, 'Invalid request path')`。无需改动 Router、Application 或依赖。
- `tests/run.php`：新增非法 UTF-8 路径边界回归，包括 `%FF`、孤立续字节、截断序列、过长编码、代理码点、超出 Unicode 范围及原始非法字节；同时验证中文/emoji、`%25FF` 单次解码及 query 不影响路径校验。
- `tests/http.php`：新增真实前端入口回归，断言非法路径返回 **400**、不暴露路由/正则内部异常、HEAD 无响应体；合法中文/emoji 路由参数及字面 `%FF` 正常。

先在隔离副本保留旧 Request 实现运行新增测试：构造边界未抛出预期异常，HTTP 实际 500、期望 400，均失败；应用修复后：

| 运行环境 | 实际结果 |
| --- | --- |
| PHP 8.3.32 / 8.4.23 / 8.5.8 | 每版 `composer test` 22 组、`composer test:http` 7 组、SQLite 7 组，全部通过 |
| Nginx 1.25.0 + PHP 8.5.8 FPM + OPcache | 更新后的 HTTP 7 组连续两次通过，实际前端入口 `%FF` 返回 400；同一 worker 连续 40 轮请求及缓存复用检查通过 |
| PHP 8.3 lint / Git Diff | 三个变更 PHP 文件语法检查、`git diff --check` 通过 |

**F7 已修复，不再是待办项。** 本次未重跑 MySQL/PostgreSQL，原实库验收结果见第 1、2 节；第 7 节的其他未验证范围仍然适用。修复验证使用独立临时配置，测试服务已停止，无生产配置修改、提交、推送或部署。

补充证据目录：`/var/folders/8c/jxn9mw713jx_3trc7tzd9x_40000gn/T/wuyoo-f7-wml009j3/evidence/`，包括 `before-core.log`、`before-http.log`、`matrix.json`、`fpm-http-{0,1}.log`、`fpm-final.log` 和 `fpm-results.json`；该临时目录可能被系统清理。

## 7. 未验证范围与试用条件

以下均为**未验证或不在承诺范围**，不是通过项：

- 目标 Linux/生产同版本 Nginx/FPM 配置、HTTPS/TLS、反向代理、生产文件权限及安全加固；FPM 多 worker 并发、长期运行和压测。
- PHP 8.3/8.4 FPM：本轮只有对应 CLI/开发 HTTP 服务器通过；FPM 实测版本为 8.5.8。
- FPM 业务路由直接访问实库的端到端组合：本轮 SQL 实库验证在 CLI Scope 执行，FPM 验证 MVC/HTTP/Session/Blade。
- 实库认证失败、TLS、查询中断线/自动重连、故障切换、死锁重试、锁竞争、事务 DDL 差异、海量 cursor 内存行为及全部数据库版本组合。
- Redis/MongoDB 实库本轮未重跑；GitHub CI 未执行。复杂关系/Cast、Observer/Event、afterCommit 和完整 Laravel helper 生态不因本次通过而扩大支持范围。

**建议进入小范围真实项目试用**：使用已验证的 PHP 8.5 与 MySQL 8.0 或 PostgreSQL 16 路径，业务遵守显式 DI 和请求 Scope 边界，不依赖未承诺的 Laravel 功能；上线前对实际部署组合补验上述环境项。当前没有需要重新调整 Eloquent/Application 架构的验收发现。

## 执行证据与复核

临时源码、测试适配器、补充 probes 和完整日志位于：

`/var/folders/8c/jxn9mw713jx_3trc7tzd9x_40000gn/T/wuyoo-final-2oa_btqy/evidence/`

主要证据：`matrix.json`、`engineering.json`、`mysql8-{0,1,extra}.log`、`pgsql-{0,1}.log`、`pgsql-extra-final.log`、`fpm-http-{0,1}.log`、`fpm-final.log`、`fpm-results.json`、`status-lines.log`。临时适配仅复用现有测试，没有向仓库新增测试基础设施。该目录可能被系统清理，本报告保留主要结论。

运行时：`/tmp/wuyoo-php/{8.3,8.4,8.5}/php`；Composer：`/tmp/wuyoo-composer.phar`。复核命令为明确运行时调用 Composer `install`、`test`、`test:http`，以及 `TEST_DB_DRIVER=sqlite php tests/database.php`。实库按 README 的 `WUYOO_TEST_DATABASE=1`、`TEST_DB_DRIVER`、`TEST_DB_DATABASE=wuyoo_test_*`、`TEST_DB_HOST/PORT/USERNAME/PASSWORD` 指向新建隔离实例运行，切勿复用业务实例。

MySQL 二进制来自 [Oracle 官方下载](https://cdn.mysql.com/Downloads/MySQL-8.0/mysql-8.0.46-macos15-x86_64.tar.gz)，PostgreSQL 来自 [官方源码包](https://ftp.postgresql.org/pub/source/v16.15/postgresql-16.15.tar.bz2)，FPM 来自 [StaticPHP 构建下载](https://dl.static-php.dev/static-php-cli/common/php-8.5.8-fpm-macos-x86_64.tar.gz)。仅用于本轮临时验收。

验收结束后，本轮启动的 MySQL/PostgreSQL/Nginx/FPM 测试进程已停止，独立测试数据目录已删除；日志及复核脚本保留在上述临时目录。未触碰生产数据库、配置或站点。
