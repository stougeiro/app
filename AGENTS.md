# AGENTS

This file is the operational guide for agents working on this application.
Read `ARCHITECTURE.md` before changing code.

## Core rules

- This is a PHP 8.2 application for shared hosting, using Apache/FPM or mod_php.
- Keep reusable contracts in `stougeiro/base-contract`.
- Keep the FPM runtime in `stougeiro/base`.
- Keep app foundations and cross-cutting behavior in `application/core`.
- Keep application-local modules in `application/modules`.
- Keep service wiring in `application/provider`.
- Keep global policy in `application/config`.
- Keep only the entrypoint and public assets in `public_html`.
- Keep the deploy shim at `./deploy` and deploy source in `tools/deploy`.
- Read `DEPLOY.md` before changing deployment behavior.
- Treat `application/*` and `public_html/*` as the normal application change scope.
- Treat `./deploy` and `tools/deploy` as out of scope. Use them only when
  explicitly requested; do not modify them without explicit permission.

## Where to put code

| Need | Location |
|---|---|
| Contract reusable across runtimes/apps | `stougeiro/base-contract` |
| Runtime/foundation for FPM | `stougeiro/base` |
| App-wide foundation or behavior | `application/core` |
| Security service or middleware | `application/core/Security` |
| DI wiring | `application/provider` |
| Local feature/module | `application/modules/<Module>` |
| Global middleware | `application/config/middlewares.php` |
| Module middleware | `application/modules/<Module>/Config/middlewares.php` |
| Routes | `application/modules/<Module>/Config/routes.php` |
| Controller | `application/modules/<Module>/Controller` |
| View | `application/modules/<Module>/View` |
| Public CSS/assets | `public_html/assets` |
| Configuration | `application/config` |
| Tests | `tests` |
| Deploy CLI source | `tools/deploy` |
| Deploy shim | `./deploy` |
| Deploy config/state | `deploy.json`, `.deploy` (local only) |

## Module rules

### Local module

- Lives in `application/modules/<Module>`.
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
  Put public CSS under `public_html/assets`.
- Auth is not part of the current core. Do not add auth middleware or auth
  services unless the task explicitly asks for the Auth module.

## Errors and logs

- 5xx logs go to `error-YYYYMMDD.log`.
- 4xx logs go to `security-YYYYMMDD.log`.
- PHP-level failures use `php-error-YYYYMMDD.log` when possible.
- Do not add a second log channel for the same concern.
- Do not implement log retention in the application.
- `Fallback` is independent and intentionally outside application middleware.
- Error views belong in `application/modules/Error/View`; error rendering policy belongs
  in `application/core/Error`.

## Providers

- Providers only wire services and lifecycle.
- Do not put routes, controllers, views or business rules in providers.
- Register providers explicitly in `application/config/app.php`.
- Keep provider order intentional when one provider depends on another.

## Deploy

- Read `DEPLOY.md` before using or configuring the deploy tool.
- The normal change scope is `application/*` and `public_html/*`.
- `./deploy` and `tools/deploy` are consumer tooling. Do not modify them unless
  the task explicitly requests it and the user grants permission.
- Keep the executable shim at `./deploy` and the source in `tools/deploy`.
- The deploy tool is repository tooling. Do not require it from `application/`,
  providers or modules.
- Do not upload `tools/deploy/` to the server. A deploy transfers only
  `application/`, `public_html/` and `vendor/`.
- Do not commit `deploy.json`, `.deploy/`, environment files, plans, backups or
  SSH keys.
- Keep deploy behavior covered by `tests/Feature/Deploy` and entrypoint behavior
  covered by `tests/Feature/EntrypointTest.php`.
- Use `./deploy help` or `./deploy --local help` to validate the shim.

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
composer cover:deploy
```

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
- Commit deploy secrets, environment values, plans or SSH keys.
- Put deploy source under `public_html` or require it from the runtime.
- Modify `./deploy` or `tools/deploy` without explicit permission.
- Add abstractions without a real second use case.
- Suppress errors or logs without an explicit operational reason.
- Edit the `base` or `base-contract` repositories from this app unless the task
  explicitly requires it.

## When uncertain

- Prefer the smallest change that fits the existing layers.
- Follow the nearest existing pattern.
- If the change affects boundaries, middleware order, logging, security or
  deployment, read `DEPLOY.md` where applicable and ask before implementing.
- If a task appears to require changing deploy tooling, stop and ask before
  editing.
