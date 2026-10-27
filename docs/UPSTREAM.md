# 上游与裁剪记录

## 固定来源

采用 Laravel 13 正式版 `v13.38.0`（2026-10-20 发布），主仓库 Commit：`c42f9abd4a3d2f9a3cd6be109255ddce8092ceb8`。
对应源码：[laravel/framework](https://github.com/laravel/framework/tree/v13.38.0/src/Illuminate)。
Composer 安装的是官方 Illuminate subtree split，并非复制完整 Framework。

| 组件 | Tag | Split Commit | 许可证 |
| --- | --- | --- | --- |
| illuminate/database | v13.38.0 | add3b4602c77c38edbee26be9ab55e7eec621182 | MIT |
| illuminate/view | v13.38.0 | 91e08653f191bd08c85d53de0fd4059ea1a547c9 | MIT |
| illuminate/pagination | v13.38.0 | 见 docs/upstream-lock.json | MIT |
| NoahBuscher/Macaw | master 固定提交 | cb439b1fd68bafa38d66306b57633681038d424b | MIT |

`composer.lock` 是安装依据；`docs/upstream-lock.json` 记录全部 34 个运行时包的版本、Commit、仓库和许可证。直接组件用精确版本约束，因此 `composer validate` 的 exact version 提示是有意锁定来源产生的普通警告。
Macaw 原许可证保存在 `docs/licenses/Macaw-MIT.txt`；Illuminate 许可证在包内及 `docs/licenses/Illuminate-MIT.txt`。

## 路由分析与改造

上游 Macaw 使用静态 routes/methods/callbacks 数组，先查静态路径，再替换 `(:num)` / `(:any)` / `(:all)` 并扫描正则，控制器由 `Controller@method` 直接实例化、调用和输出。
Wuyoo 的 Router / Route 以此 API、占位符及分发语义为基础重新组织源码：

- 将全局静态数组改成应用持有的实例，静态路径用哈希表，动态规则只在注册时编译，并按第一个字面路径段分桶。
- 新增 `{name}`、约束、嵌套 prefix/namespace/middleware；规范化重复路由并拒绝同签名注册。
- 静态路径优先于动态规则；同一动态桶内按注册次序。不同约束发生交集时由开发者明确调整顺序；不做正则语言的交集分析。
- 分离匹配与分发，只执行一次匹配结果；安全 Controller Resolver、参数类型检查、404/405 Allow、自动 OPTIONS、显式 HEAD 优先及 GET 回退。
- 不支持任意裸正则路径及旧静态全局 Router 调用。正则仅来自显式 `where`，须来自可信应用代码。

参考 [bramus/router](https://github.com/bramus/router) 的分组与 namespace、[simplePHPRouter](https://github.com/steampixel/simplePHPRouter) 的路径匹配与方法匹配分离；没有复制这两个项目的源码。

## Database 调用路径及裁剪

`DB/Model -> Database -> Capsule Manager -> DatabaseManager -> ConnectionFactory -> PDO Connection -> Query/Eloquent Builder -> MySql/Postgres Grammar/Processor`。
Capsule 自带的 Illuminate Container 仅供上游内部配置和连接工厂使用，不承担 Wuyoo Controller 自动装配。PDO 在首次真正执行 SQL 时连接。

`tools/prune-upstream.php` 在 Composer `pre-autoload-dump` 阶段执行，物理删除以下外围模块，然后才生成 classmap，避免留下指向已删文件的 classmap：

- `illuminate/database/Console/`
- `illuminate/database/Migrations/`
- `illuminate/database/Eloquent/Factories/`
- `illuminate/database/Seeder.php`
- `illuminate/database/DatabaseServiceProvider.php`

保留 Query Builder、Eloquent、Connection、事务/Savepoint、Grammar、Processor、Schema、分页及所有关联的基础支持包；SQL 内核源码不作改写。MySQL、PostgreSQL 是业务目标，SQLite 用于无外部依赖的快速验证。连接工厂中未启用驱动的上游实现也保留，以免改动工厂内部职责。

`Model` 直接依赖 HasRelationships / HasAttributes 等 Trait，其关联、Cast 与 Builder 互相引用；强删会改变属性访问及查询行为。因此保留源码但不承诺完整高级关系、复杂 Cast、Observer、API Resource、Factory 等功能。未绑定 Eloquent 事件分发器，没有框架级 Observer/EventBus。

[database](https://github.com/mrjgreen/database) 与 [EasyQueryBuilder](https://github.com/knifelemon/EasyQueryBuilder) 仅作独立化方案参考；未引入或重写其 Query Builder。

## Blade 选择与独立化

`Blade adapter -> FileViewFinder -> View Factory -> EngineResolver -> CompilerEngine -> BladeCompiler -> 缓存 PHP -> View render`。
保留原版 Blade 的编译器、Factory、组件、转义、继承、include、stack 和缓存机制。Factory 需要 Filesystem、Events Dispatcher 和 Container，保留这些依赖比另造编译/执行运行时可靠，因此未选择 BladeOne。

两个很小的编译器子类仅替换 ComponentTagCompiler 的业务组件命名空间猜测，改从 `config/view.php` 获取命名空间，避免依赖 Laravel Foundation Application。`@csrf` 改从请求级 Session 获取 token。其余语法使用上游编译器。
匿名组件及简单类组件可用；依赖 Laravel Application/路由/认证/翻译/Session helper 的指令或组件不属于承诺范围（如 `@auth`、`@can`、`route()`、`asset()`）。不宣称支持 Laravel 全部特性。

请求适配在首次获取 Factory 时激活 Container，并持续到 Scope 收尾，以覆盖直接分页模板入口。固定版本 `Component::flushCache()` 会清除包含 `__components` 视图名的静态缓存，`forgetFactory()` 清除静态 Factory 引用；两者在激活和关闭时调用，避免内联组件复用上一 Scope 的 namespace 映射。上游 `View::render()` 自行处理成功/异常后的渲染栈清理；适配器只在 Scope 边界恢复先前 Container，不修改 vendor。

Events 包带入的 Bus/Pipeline 等源码按官方依赖保留，不注册为应用事件或队列系统。Illuminate Container 不向业务层提供反射式自动装配。
Carbon / Collections / Support / Brick Math 等保留成熟实现；未引入 Laravel Foundation、Console/Artisan、Monolog、Twig、PHPUnit、Pest 或 PHPStan。

## PHP 与 Polyfill

根包 `php:^8.3`，依赖求解平台固定 `8.3.0`，原版 Illuminate 13 同样要求 `^8.3`。自研代码只用 PHP 8.3 可解析的语法。
没有自研 Polyfill 或伪造 `replace`。上游必要的 Symfony Polyfill 通过原依赖保留（本次合计磁盘约 452 KiB），原生能力存在时由上游 bootstrap 跳过相应补偿；不为削文件而破坏官方依赖声明。没有兼容 PHP 8.2 及更早版本的承诺。

## 恢复和升级

需要恢复已删源码时，在隔离副本中执行 `composer reinstall illuminate/database --no-scripts`，或者按上表 Commit 下载原包。恢复后重新生成 autoload 时应先调整裁剪清单，否则脚本会再次裁剪。

升级时先核对新正式 Tag / Commit、各包真实依赖和 PHP 要求，再同时修改根依赖、lock、upstream-lock.json 与本文，复查删除清单，执行 8.3/8.4/8.5 及真实 SQL/HTTP 验证。不要直接编辑 vendor 内核修补问题。
