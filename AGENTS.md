# Wuyoo
- PHP >=8.3; recommend 8.5. Core must parse on 8.3. Composer platform stays 8.3.0.
- Core is `core/` (`Wuyoo\\`), business is `app/` (`App\\`). Entry is public/index.php -> init.php -> Application. Never add bootstrap.php.
- Explicit DI registrations; request services live only in a request Scope. No app-wide mutable request/user state.
- SQL uses pinned Laravel 13 Eloquent, original Blade; do not reimplement SQL grammars, transactions or template compiler.
- Run `composer install`. Regression scripts are not included on the server; obtain the external suite when needed and verify its paths before running against an isolated source copy. Commands/config: README.md.
- Never use a production database for tests. Do not push or enable a production site without authorization.
- Read docs/STATUS.md and docs/UPSTREAM.md first when resuming work.
- Development target is SSH host `contabo` (`vmi3663018`), `/www/wuyoo`. The local checkout is only a mirror; verify server files before reporting server work complete.
