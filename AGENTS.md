# AGENTS

This file is the operational guide for agents working on this application.
Read `ARCHITECTURE.md` before changing code.

## Core rules

- This is a PHP 8.2 application for shared hosting, using Apache/FPM or mod_php.
- Keep reusable contracts in `stougeiro/base-contract`.
- Keep the FPM runtime in `stougeiro/base`.
- Keep app foundations and cross-cutting behavior in `project/core`.
- Keep application-local modules in `project/app`.
- Keep service wiring in `project/provider`.
- Keep global policy in `project/config`.
- Keep only the entrypoint and public assets in `public_html`.

## Where to put code

| Need | Location |
|---|---|
| Contract reusable across runtimes/apps | `stougeiro/base-contract` |
| Runtime/foundation for FPM | `stougeiro/base` |
| App-wide foundation or behavior | `project/core` |
| DI wiring | `project/provider` |
| Local feature/module | `project/app/<Module>` |
| Global middleware | `project/config/middlewares.php` |
| Module middleware | `project/app/<Module>/Config/middlewares.php` |
| Routes | `project/app/<Module>/Config/routes.php` |
| Controller | `project/app/<Module>/Controller` |
| View | `project/app/<Module>/View` |
| Public CSS/assets | `public_html/assets` |
| Configuration | `project/config` |
| Tests | `project/tests` |

## Module rules

### Local module

- Lives in `project/app/<Module>`.
- Extends `App\Core\Module\ModuleProviderAbstract`.
- Controllers extend `App\Core\Http\ControllerAbstract`.
- Registers routes, middlewares and views through its provider.
- Is listed explicitly in `project/config/app.php`.
- May use `App\Core`.

### Installed module

- Is a separate Composer package.
- Depends only on `stougeiro/base-contract`.
- Must not depend on `App\Core`, `stougeiro/base`, or this application.
- The app only installs it via Composer and registers its provider in
  `project/config/app.php`.
- Do not document or generate installable module packages inside this app.

## HTTP and middleware

- Global middleware runs for every request and belongs in
  `project/config/middlewares.php`.
- Module/controller middleware belongs to the module config.
- `OPTIONS` is handled by the controller base. Do not implement it manually.
- `App\Core\Http\SecurityHeadersMiddleware` is opt-in. Do not enable it unless
  the task explicitly asks.
- CSP uses `style-src 'self'`. Do not add inline `<style>` or inline styles.
  Put public CSS under `public_html/assets`.

## Errors and logs

- 5xx logs go to `error-YYYYMMDD.log`.
- 4xx logs go to `security-YYYYMMDD.log`.
- PHP-level failures use `php-error-YYYYMMDD.log` when possible.
- Do not add a second log channel for the same concern.
- Do not implement log retention in the application.
- `Fallback` is independent and intentionally outside application middleware.
- Error views belong in `project/app/Error/View`; error rendering policy belongs
  in `project/core/Error`.

## Providers

- Providers only wire services and lifecycle.
- Do not put routes, controllers, views or business rules in providers.
- Register providers explicitly in `project/config/app.php`.
- Keep provider order intentional when one provider depends on another.

## Dependencies

- Add runtime dependencies to `project/composer.json`.
- Add development dependencies to `require-dev`.
- Commit `project/composer.lock`.
- Do not commit `project/vendor`.
- Production vendor is generated externally with:

```bash
composer install --no-dev --optimize-autoloader
```

## Verification

From `project/`:

```bash
composer validate --strict
composer stan
composer pest
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
- Expose `project/` through the web server.
- Add inline CSS or scripts under the current CSP.
- Treat Docker as a production requirement.
- Add abstractions without a real second use case.
- Suppress errors or logs without an explicit operational reason.
- Edit the `base` or `base-contract` repositories from this app unless the task
  explicitly requires it.

## When uncertain

- Prefer the smallest change that fits the existing layers.
- Follow the nearest existing pattern.
- If the change affects boundaries, middleware order, logging, security or
  deployment, ask before implementing.
