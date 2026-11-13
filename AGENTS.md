# AGENTS

This file is the operational guide for agents working on this application.
Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) before changing code.

## Core rules

- This is a PHP 8.2 application for shared hosting, using Apache/FPM or mod_php.
- Keep reusable contracts in `stougeiro/base-contract`.
- Keep the FPM runtime in `stougeiro/base`.
- Keep app foundations and cross-cutting behavior in `application/core`.
- Keep application-local modules in `application/module`.
- Keep service wiring in `application/provider`.
- Keep global policy in `application/config`.
- Keep only the entrypoint and public assets in `public`.
- Keep shared tool contracts in `tools/contract`.
- Keep the pluggable tool runtime in `tools/manager`.
- Keep tool source in `tools/<tool>` and register tools in
  `tools/manager/tools.php`.
- Use `./sourcerer` as the single tool entrypoint.
- Run `./sourcerer operator` and `./sourcerer install` before using tools.
- `.sourcerer/operator.json` is mandatory for tool commands.
- Keep reference documentation in `docs/`; only `README.md` and `AGENTS.md`
  live at the repository root.
- Read [docs/CODE.md](docs/CODE.md) before changing deployment behavior.
- Treat `application/*` and `public/*` as the normal application change scope.
- Keep tools independent from the application runtime; the application must
  never require `tools/`.

## Where to put code

| Need | Location |
|---|---|
| Contract reusable across runtimes/apps | `stougeiro/base-contract` |
| Runtime/foundation for FPM | `stougeiro/base` |
| App-wide foundation or behavior | `application/core` |
| Security service or middleware | `application/core/Security` |
| DI wiring | `application/provider` |
| Local feature/module | `application/module/<Module>` |
| Global middleware | `application/config/middlewares.php` |
| Module middleware | `application/module/<Module>/Config/middlewares.php` |
| Routes | `application/module/<Module>/Config/routes.php` |
| Controller | `application/module/<Module>/Controller` |
| View | `application/module/<Module>/View` |
| Public CSS/assets | `public/assets` |
| Configuration | `application/config` |
| Tests | `tests` |
| Shared tool contracts | `tools/contract` |
| CLI runtime | `tools/manager` |
| Code tool source | `tools/code` |
| Schema tool source | `tools/schema` |
| Tool entrypoint | `./sourcerer` |
| Shared tool support | `tools/support` |
| Tool local control/config | `.sourcerer/<tool>` (local only) |
| Schema versioned state | `schema/<database>` |

## Module rules

### Local module

- Lives in `application/module/<Module>`.
- Extends `App\Core\Module\ModuleProviderAbstract`.
- Controllers extend `App\Core\Http\ControllerAbstract`.
- Registers routes, middlewares and views through its provider.
- Is listed explicitly in `application/config/app.php`.
- May use `App\Core`.

### Installed module

- Is a separate Composer package.
- Depends only on `stougeiro/base-contract`.
- Must not depend on `App\Core`, `stougeiro/base`, or this application.
- The app only installs it via Composer and registers its provider in
  `application/config/app.php`.
- Do not document or generate installable module packages inside this app.

## HTTP and middleware

- Global middleware runs for every request and belongs in
  `application/config/middlewares.php`.
- Module/controller middleware belongs to the module config.
- `OPTIONS` is handled by the controller base. Do not implement it manually.
- `App\Core\Security\Middleware\SecurityHeadersMiddleware` is opt-in. Do not
  enable it unless the task explicitly asks.
- `CsrfMiddleware` and `RateLimitMiddleware` are opt-in per module/controller.
  Defaults live in `application/config/security.php`.
- CSP uses `style-src 'self'`. Do not add inline `<style>` or inline styles.
  Put public CSS under `public/assets`.
- Auth is not part of the current core. Do not add auth middleware or auth
  services unless the task explicitly asks for the Auth module.

## Errors and logs

- 5xx logs go to `error-YYYYMMDD.log`.
- 4xx logs go to `security-YYYYMMDD.log`.
- PHP-level failures use `php-error-YYYYMMDD.log` when possible.
- Do not add a second log channel for the same concern.
- Do not implement log retention in the application.
- `Fallback` is independent and intentionally outside application middleware.
- Error views belong in `application/module/Error/View`; error rendering policy
  belongs in `application/core/Error`.

## Providers

- Providers only wire services and lifecycle.
- Do not put routes, controllers, views or business rules in providers.
- Register providers explicitly in `application/config/app.php`.
- Keep provider order intentional when one provider depends on another.

## Code tool

- Read [docs/CODE.md](docs/CODE.md) before using or configuring the Code tool.
- The normal change scope is `application/*` and `public/*`.
- The tool source lives in `tools/code` and runs through `./sourcerer code`.
- Successful pushes create immutable releases under remote `.deploy/releases`;
  promote reuses the same releaseId across targets.
- Environment values come only from
  `.sourcerer/code/override/<target>/application/env.json`; remote or backed-up
  env files are never reused.
- The Code config lives in `.sourcerer/code/config.json`.
- Release/event identity comes from `.sourcerer/operator.json`.
- `code releases` reads the local mirror; `--sync` mirrors all targets.
- Valid targets are `development`, `staging` and `production`. Do not add
  targets for environments that run a different codebase (for example a
  demonstration or an API); those belong to their own repository and pipeline.
- The Code tool is repository tooling. Do not require it from `application/`,
  providers or modules.
- Do not upload `tools/code/` to the server. A deploy transfers only
  `application/`, `public/` and `vendor/`.
- Do not commit `.sourcerer/`, environment files or SSH keys.
- Remote `.deploy/` remains the deployment state on the server and is created
  by the tool.
- Keep Code behavior covered by `tests/Feature/Code` and entrypoint behavior
  covered by `tests/Feature/EntrypointTest.php`.
- Use `./sourcerer code --help` to validate the CLI.
- Docker is the only local runtime requirement for `./sourcerer`.

- SSH auth lives in `.sourcerer/code/auth/` with `ssh.auth.type` (`key` or `pass`) and `ssh.auth.file`; never commit auth files.
- Password auth uses `sshpass`; key auth keeps the existing key flow.

## Schema tool

- Read [docs/SCHEMA.md](docs/SCHEMA.md) before using or configuring the Schema tool.
- Schema versions database structure only: tables, columns, keys, indexes, views and sequences.
- Do not add support for triggers, procedures or functions. They belong to the application domain.
- Schema must refuse to operate when unsupported objects are present.
- The Schema config lives in `.sourcerer/schema/config.json` and is independent
  from `application/config/database.php`; do not import it.
- Local Schema scratch data lives in `.sourcerer/schema/local`.
- Versioned Schema state lives in `schema/<database>` and is committed.
- Local changes happen in the `local` target. Environment targets only receive releases.
- `update` only accepts checkpointed releases.
- `rollback` creates a new forward-only release; never rewrite history.
- Keep Schema behavior covered by `tests/Feature/Schema`.

## Tools boundary

- Namespaces:
  - `Sourcerer\Tool\Contract` in `tools/contract/src`;
  - `Sourcerer\Tool\Manager` in `tools/manager/src`;
  - `Sourcerer\Tool\Code` in `tools/code/src`;
  - `Sourcerer\Tool\Schema` in `tools/schema/src`;
  - `Sourcerer\Tool\Support` in `tools/support/src`.
- `tools/manager/console.php` owns autoload, the tool registry, the project
  root, `install` and `operator`.
- Every tool implements `Sourcerer\Tool\Contract\ToolInterface`, receives the
  project root through `commands(string $projectRoot)` and exposes
  `install(string $projectRoot): array`.
- Tools must not load `application/bootstrap/app.php`, the application
  container or `stougeiro/base`.
- Tool code must not import application runtime classes: `App\*`, `STDW\*`, or
  `Sourcerer\*` outside the `Sourcerer\Tool\*` namespace.
- The application must never require `tools/`.
- Keep the tool runtime image in `tools/manager/Dockerfile`.
- Never use `@` to suppress filesystem or runtime warnings. Use
  `Sourcerer\Tool\Support\FileSystem` or explicit error handling.

## Dependencies

- Add runtime dependencies to `composer.json`.
- Add development dependencies to `require-dev`.
- Commit `composer.lock`.
- Do not commit `vendor`.
- Production vendor is generated externally with:

```bash
composer install --no-dev --optimize-autoloader
```

## Verification

From the repository root:

```bash
composer validate --strict
composer stan
composer pest
composer cover:code
composer cover:schema
```

Run the test suite as a non-root user. Filesystem and permission-sensitive
behavior must be tested, not skipped.

For Docker changes:

```bash
docker compose up -d --build
curl -i http://localhost/
curl -i http://localhost/missing
```

For shared-hosting-sensitive changes, verify the behavior without relying on
Docker-only paths or Apache vhost edits.

## Do not

- Add hidden module discovery.
- Put business logic in providers.
- Make `core` depend on `app`.
- Make installable modules depend on `App\Core`.
- Expose `application/` through the web server.
- Add inline CSS or scripts under the current CSP.
- Treat Docker as a production requirement.
- Commit `.sourcerer/`, environment values or SSH keys.
- Suppress filesystem/runtime warnings with `@`; use the shared `FileSystem`
  support or explicit error handling.
- Put tool source under `public` or require it from the runtime.
- Make `application/` depend on `tools/`.
- Make `tools/` depend on `application/` or the application bootstrap.
- Add abstractions without a real second use case.
- Suppress errors or logs without an explicit operational reason.
- Edit the `base` or `base-contract` repositories from this app unless the task
  explicitly requires it.

## When uncertain

- Prefer the smallest change that fits the existing layers.
- Follow the nearest existing pattern.
- If the change affects boundaries, middleware order, logging, security or
  deployment, read [docs/CODE.md](docs/CODE.md) where applicable and ask before
  implementing.
- If a task appears to require changing tool boundaries, stop and ask before
  editing.
