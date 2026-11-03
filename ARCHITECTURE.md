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
app modules → core
    ↓
stougeiro/base → stougeiro/base-contract
```

The rules are:

- `public_html` is the entrypoint and public asset directory only;
- `core` contains application foundations and cross-cutting behavior;
- `app` contains application-local modules;
- `core` must not depend on `app`;
- `app` may depend on `core`;
- `provider` wires external services and may use `core`;
- reusable contracts belong to `stougeiro/base-contract`, not to this app;
- the FPM runtime belongs to `stougeiro/base`, not to this app.

## Project layout

```text
project/
├── app/          application-local modules
├── bootstrap/    runtime bootstrap
├── config/       application configuration
├── core/         application foundations (HTTP, security, logging, errors)
├── database/     database-facing application code or fixtures
├── provider/     service providers
├── storage/      cache, logs, sessions and temporary files
├── tests/        Pest tests
└── vendor/       Composer dependencies

public_html/
├── assets/       public CSS and other static assets
├── .htaccess
└── index.php
```

Nothing under `project/` should be exposed by the web server.

## Where code belongs

| Code | Location |
|---|---|
| Reusable contract or abstract base for any runtime | `stougeiro/base-contract` |
| FPM runtime implementation | `stougeiro/base` |
| App-wide foundation or cross-cutting behavior | `project/core` |
| Security service or middleware | `project/core/Security` |
| Service construction and container bindings | `project/provider` |
| Application-local feature | `project/app/<Module>` |
| Global middleware policy | `project/config/middlewares.php` |
| Module/controller middleware | `project/app/<Module>/Config/middlewares.php` |
| Module routes | `project/app/<Module>/Config/routes.php` |
| Module controller | `project/app/<Module>/Controller` |
| Module view | `project/app/<Module>/View` |
| Public CSS/assets | `public_html/assets` |
| Application configuration | `project/config` |
| Tests | `project/tests` |

Prefer the smallest location that matches the responsibility. Do not put
business rules in providers, and do not put runtime services in `public_html`.

## Local application modules

A local module belongs to this application and may use `App\Core`.

```text
project/app/HelloWorld/
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

A local module is listed in `project/config/app.php`:

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
2. register its module provider in `project/config/app.php`.

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

Providers are listed explicitly in `project/config/app.php`.

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
project/config/middlewares.php
```

Use it for application-wide policy:

- security headers;
- request IDs;
- global rate limiting;
- global authentication/tenant selection.

### Module/controller middleware

Module middleware is declared in:

```text
project/app/<Module>/Config/middlewares.php
```

It is loaded by the module provider and applies to the module wildcard (`*`) or
to specific controllers.

Use it for behavior owned by a single module.

## Errors

Error behavior is split intentionally:

- `project/core/Error/ViewErrorRenderer.php`: rendering policy;
- `project/app/Error/ErrorModuleProvider.php`: view alias registration;
- `project/app/Error/View`: development and production error pages;
- `project/config/error.php`: renderer, messages and snippet configuration.

Development pages may show diagnostic detail. Production pages expose only the
status and a safe message.

`Fallback` belongs to the runtime and is intentionally independent. It uses
inline HTML/CSS and is not controlled by application middleware.

## Logging

Application logging is classified by status:

- `project/storage/log/error-YYYYMMDD.log`: 5xx;
- `project/storage/log/security-YYYYMMDD.log`: 4xx;
- `project/storage/log/php-error-YYYYMMDD.log`: PHP-level failures and fallback.

`ClassifiedLog` owns the classification. `PhpErrorLog` configures the PHP error
log on a best-effort basis.

Log retention is an operational responsibility. The application does not delete
or rotate old logs.

## Security

Security primitives live under `project/core/Security`:

- `Csrf`: session-backed token generation and validation;
- `RateLimit`: cache-backed request limiting;
- `Middleware/CsrfMiddleware`;
- `Middleware/RateLimitMiddleware`;
- `Middleware/SecurityHeadersMiddleware`.

CSRF and rate limiting are opt-in per module/controller through the module
middleware map. Security headers are opt-in globally through
`project/config/middlewares.php`. The CSRF and rate limit defaults are defined in
`project/config/security.php`.

`App\Core\Security\Middleware\SecurityHeadersMiddleware` is optional and is
listed in `project/config/middlewares.php` when enabled.

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

Tests live in `project/tests` and use Pest.

From `project/`:

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
project/       → application directory outside the web root
public_html/   → web root
```

`vendor/` is generated outside the server and copied with the application. The
shared host does not need Composer at runtime.

## Anti-patterns

- Putting vendor services in `public_html`.
- Putting business logic in providers.
- Making `core` depend on `app` modules.
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
