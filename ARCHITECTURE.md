# App Architecture

This document describes the application boundaries, the direction of
dependencies, and where each kind of code should live.

It targets small and medium PHP 8.2 applications running on shared hosting with
Apache/FPM or mod_php. It is not a design for long-running runtimes such as
Swoole, and it does not define how third-party installable modules are authored.

## Dependency direction

```text
public_html
    ↓
bootstrap / config
    ↓
provider
    ↓
application/modules → application/core
    ↓
stougeiro/base → stougeiro/base-contract
```

The rules are:

- `public_html` is the entrypoint and public asset directory only;
- `application/core` contains application foundations and cross-cutting behavior;
- `application/modules` contains application-local modules;
- `application/core` must not depend on `application/modules`;
- `application/modules` may depend on `application/core`;
- `provider` wires external services and may use `application/core`;
- reusable contracts belong to `stougeiro/base-contract`, not to this app;
- the FPM runtime belongs to `stougeiro/base`, not to this app.

## Application layout

```text
application/
├── modules/      application-local modules
├── bootstrap/    runtime bootstrap
├── config/       application configuration
├── core/         application foundations (HTTP, security, logging, errors)
├── database/     database-facing application code or fixtures
├── provider/     service providers
└── storage/      cache, logs, sessions and temporary files

tests/            Pest tests
vendor/           Composer dependencies

public_html/
├── assets/       public CSS and other static assets
├── .htaccess
└── index.php
```

Nothing under `application/` should be exposed by the web server.

## Where code belongs

| Code | Location |
|---|---|
| Reusable contract or abstract base for any runtime | `stougeiro/base-contract` |
| FPM runtime implementation | `stougeiro/base` |
| App-wide foundation or cross-cutting behavior | `application/core` |
| Security service or middleware | `application/core/Security` |
| Service construction and container bindings | `application/provider` |
| Application-local feature | `application/modules/<Module>` |
| Global middleware policy | `application/config/middlewares.php` |
| Module/controller middleware | `application/modules/<Module>/Config/middlewares.php` |
| Module routes | `application/modules/<Module>/Config/routes.php` |
| Module controller | `application/modules/<Module>/Controller` |
| Module view | `application/modules/<Module>/View` |
| Public CSS/assets | `public_html/assets` |
| Application configuration | `application/config` |
| Tests | `tests` |
| Deploy tool source | `tools/deploy` |
| Deploy shim | `./deploy` |
| Deploy configuration/state | `deploy.json`, `.deploy` (local only) |

Prefer the smallest location that matches the responsibility. Do not put
business rules in providers, and do not put runtime services in `public_html`.

## Local application modules

A local module belongs to this application and may use `App\Core`.

```text
application/modules/HelloWorld/
├── Config/
│   ├── middlewares.php
│   └── routes.php
├── Controller/
│   └── HelloWorldController.php
├── HelloWorldModuleProvider.php
├── Middleware/
└── View/
```

The provider extends:

```php
App\Core\Module\ModuleProviderAbstract
```

The controller extends:

```php
App\Core\Http\ControllerAbstract
```

The module provider is the single entrypoint. It declares the module surface:

```php
final class HelloWorldModuleProvider extends ModuleProviderAbstract
{
    public function configure(): void
    {
        $this->middlewares(__DIR__ . '/Config/middlewares.php');
        $this->routes(__DIR__ . '/Config/routes.php');
        $this->views('hello', __DIR__ . '/View');
    }
}
```

A local module is listed in `application/config/app.php`:

```php
'modules' => [
    HelloWorldModuleProvider::class,
],
```

Local modules are not automatically discovered.

## Installed modules

An installed module is a separate Composer package. The app consumes it, but does
not define how it is authored or packaged.

The app only needs to know:

1. install it with Composer;
2. register its module provider in `application/config/app.php`.

```php
'modules' => [
    Vendor\Module\ModuleProvider::class,
],
```

An installed module must depend only on `stougeiro/base-contract` and must not
depend on `App\Core`, `stougeiro/base`, or this application.

Authoring, publishing, versioning and scaffolding of installable modules belong
to the module package or a future module skeleton, not to this app.

## Providers

Providers are for wiring and lifecycle, not business logic.

Use a provider when the application needs to:

- bind an interface to an implementation;
- construct a vendor service from configuration;
- register a singleton used by the app;
- boot or terminate a service.

Do not use a provider for routes, controllers, views or domain rules.

Providers are listed explicitly in `application/config/app.php`.

## Controllers and HTTP

Local controllers extend `App\Core\Http\ControllerAbstract`. Installed modules
should extend `Sourcerer\Contract\Http\ControllerAbstract` through their own
package.

Controllers declare the verbs they answer:

```php
protected array $allowed = ['get'];
```

`OPTIONS` is always answered by the controller base with `204 No Content` and an
`Allow` header.

Views use the module alias registered by the module provider. Public styles are
linked from `public_html/assets`.

## Middleware

### Global middleware

Global middleware runs for every request and is listed in:

```text
application/config/middlewares.php
```

Use it for application-wide policy:

- security headers;
- request IDs;
- global rate limiting;
- global authentication/tenant selection.

### Module/controller middleware

Module middleware is declared in:

```text
application/modules/<Module>/Config/middlewares.php
```

It is loaded by the module provider and applies to the module wildcard (`*`) or
to specific controllers.

Use it for behavior owned by a single module.

## Errors

Error behavior is split intentionally:

- `application/core/Error/ViewErrorRenderer.php`: rendering policy;
- `application/modules/Error/ErrorModuleProvider.php`: view alias registration;
- `application/modules/Error/View`: development and production error pages;
- `application/config/error.php`: renderer, messages and snippet configuration.

Development pages may show diagnostic detail. Production pages expose only the
status and a safe message.

`Fallback` belongs to the runtime and is intentionally independent. It uses
inline HTML/CSS and is not controlled by application middleware.

## Logging

Application logging is classified by status:

- `application/storage/log/error-YYYYMMDD.log`: 5xx;
- `application/storage/log/security-YYYYMMDD.log`: 4xx;
- `application/storage/log/php-error-YYYYMMDD.log`: PHP-level failures and fallback.

`ClassifiedLog` owns the classification. `PhpErrorLog` configures the PHP error
log on a best-effort basis.

Log retention is an operational responsibility. The application does not delete
or rotate old logs.

## Security

Security primitives live under `application/core/Security`:

- `Csrf`: session-backed token generation and validation;
- `RateLimit`: cache-backed request limiting;
- `Middleware/CsrfMiddleware`;
- `Middleware/RateLimitMiddleware`;
- `Middleware/SecurityHeadersMiddleware`.

CSRF and rate limiting are opt-in per module/controller through the module
middleware map. Security headers are opt-in globally through
`application/config/middlewares.php`. The CSRF and rate limit defaults are defined in
`application/config/security.php`.

`App\Core\Security\Middleware\SecurityHeadersMiddleware` is optional and is
listed in `application/config/middlewares.php` when enabled.

It applies:

- `X-Frame-Options`;
- `X-Content-Type-Options`;
- `Referrer-Policy`;
- `Permissions-Policy`;
- `Content-Security-Policy`.

The CSP uses `style-src 'self'`. Inline styles are therefore forbidden in
application responses. Error pages and the HelloWorld page load CSS from
`public_html/assets`.

The middleware is attached before and after the remaining chain so headers are
present even when an inner response is replaced. Errors that occur before the
pipeline or in `Fallback` remain outside this protection.

HSTS is intentionally not set by the application. It belongs to the HTTPS edge
or hosting configuration.

## Tests

Tests live in `tests` and use Pest.

From the repository root:

```bash
composer validate --strict
composer stan
composer pest
```

Tests must verify observable behavior:

- HTTP status, headers and body;
- module rendering;
- error pages;
- log classification;
- security headers;
- fallback behavior where applicable.

Do not test private implementation details or internal call order unless that
order is the behavior being protected.

## Shared hosting and Docker

Docker is a development environment. It is not a production requirement.

For production:

```bash
composer install --no-dev --optimize-autoloader
```

Then upload:

```text
application/   → application directory outside the web root
public_html/   → web root
vendor/        → Composer dependencies outside the web root
```

`vendor/` is generated outside the server and copied with the application. The
shared host does not need Composer at runtime.

The optional `./deploy` tool automates this transfer with per-target layout,
maintenance mode, verification, remote backups and rollback. The manual flow
remains valid. See [DEPLOY.md](DEPLOY.md).

## Deploy tooling

Deployment is repository tooling, not an application layer. It stays outside the
runtime dependency direction:

```text
./deploy (shim)
    ↓
tools/deploy/console.php
    ↓
tools/deploy/src
```

The rules are:

- treat `application/*` and `public_html/*` as the normal change scope;
- treat `./deploy` and `tools/deploy` as out of scope for application changes;
- `./deploy` is only the executable shim;
- the tool source lives in `tools/deploy`;
- the tool must not be required by `application/`, providers or modules;
- the tool must not be uploaded to the server;
- `deploy.json` and `.deploy/` are local configuration/state and are ignored by
  Git;
- secrets, environment values, plans and keys stay outside version control.

The tool deploys only:

```text
application/   → target root
public_html/   → target webroot
vendor/        → target root
```

Each target declares `root`, `webroot`, `url` and SSH settings in `deploy.json`.
The deploy tool validates paths, production collisions, environment files,
plans, maintenance mode, remote snapshots and rollback.

### Change scope

The normal application change scope is `application/*` and `public_html/*`.

`./deploy` and `tools/deploy` are outside that scope. They are consumer
tooling: use them to deploy, but do not modify them unless the task explicitly
requests a deploy-tool change and the user grants permission.

Application work must not refactor, move, rename or opportunistically "fix"
deploy tooling.

See [DEPLOY.md](DEPLOY.md) for configuration and command reference.

## Anti-patterns

- Putting vendor services in `public_html`.
- Putting business logic in providers.
- Making `application/core` depend on `application/modules`.
- Making an installable module depend on `App\Core`.
- Adding implicit module discovery.
- Adding inline `<style>` or inline scripts under `style-src 'self'`.
- Logging the same concern in two different channels.
- Treating Docker as a production runtime.
- Adding abstractions before a real second implementation exists.
- Hiding errors or suppressing logs without an operational reason.

## Current limits

- The runtime is FPM-oriented; long-running runtimes are not supported.
- Installable module packaging is not implemented in this repository.
- Log retention is external to the application.
- Security headers are opt-in through `config/middlewares.php`.
- Boot errors and `Fallback` are outside application middleware.
