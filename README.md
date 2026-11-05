![PHP](https://img.shields.io/badge/PHP-%20^8.2-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![Pest-php](https://img.shields.io/badge/Tests-Passed-019733)
![License](https://img.shields.io/badge/License-MIT-777)

# App

A small PHP 8.2 application foundation for shared hosting.

The application runs on Apache with PHP-FPM/mod_php and follows the shared-hosting
layout: the application lives outside the public directory, while `public`
contains only the front controller, `.htaccess` and public assets.

It is built on `stougeiro/base`, `stougeiro/base-contract`, `stougeiro/router`,
`stougeiro/http`, `stougeiro/view`, `stougeiro/cache`, `stougeiro/session`,
`stougeiro/event` and `stougeiro/pdo-database`.

The goal is an explicit, inspectable application with no hidden module discovery,
no build step required at runtime, and behavior that is predictable on ordinary
shared servers.

See [ARCHITECTURE.md](ARCHITECTURE.md) for boundaries, [DEPLOY.md](DEPLOY.md)
for the deployment tool and [AGENTS.md](AGENTS.md) for the operational rules
used by agents and contributors.

## ✨ Features

- **Shared-hosting layout**: application in `application/`, web root in `public/`.
- **Explicit wiring**: providers in `provider/`, modules in `app/`.
- **Module example**: HelloWorld controller, view and middlewares.
- **Error handling**: separate development and production error pages.
- **Log separation**: application errors, security events and PHP errors are
  written to different files.
- **Daily logs**: application logs use `YYYYMMDD` file suffixes.
- **Services**: cache, view, session, events, routing, database and logging are
  registered explicitly.
- **Security primitives**: optional CSRF, rate limit and security headers
  middleware under `core/Security`.
- **Docker development**: PHP 8.2 + Apache with the vendor directory kept inside
  the image.
- **Deploy CLI**: repeatable SSH deploys with per-target layout, overrides,
  maintenance mode, automatic backups and rollback.
- **Quality tooling**: PHPStan Level 9 and a Pest suite.

---

## 📦 Installation

### Requirements

- PHP 8.2 or newer.
- `ext-json`.
- `ext-pdo_sqlite` for the default database configuration.
- Apache with `mod_rewrite` for the front controller.
- A writable `application/storage` directory.

### Local setup

```bash
git clone https://github.com/stougeiro/app.git
cd app

composer install
cp application/env-example.json application/env.json
```

For production dependencies, generate the vendor externally:

```bash
composer install --no-dev --optimize-autoloader
```

---

## 📁 Layout

```text
.
├── application/
│   ├── modules/      application modules
│   ├── bootstrap/    runtime bootstrap
│   ├── config/       application configuration
│   ├── core/         app-level foundations
│   ├── database/     database-facing code
│   ├── provider/     service providers
│   └── storage/      cache, logs, sessions, uploads and temporary files
├── sourcerer         unified tool entrypoint
├── tools/contract/    shared tool contracts
├── tools/cli/         pluggable CLI runtime
├── tools/deploy/      deploy tool source
├── deploy.json       local deploy configuration (not versioned)
├── .deploy/          local deploy state and secrets (not versioned)
├── public/           document root
├── tests/            Pest tests
├── vendor/           Composer dependencies
├── composer.json
├── composer.lock
├── phpunit.xml
└── phpstan.neon
```

The application must never be exposed through the web server. The document root
is `public`.

---

## ⚙️ Configuration

Environment:

```json
{
    "environment": "development"
}
```

Use `production` on a deployed server.

Providers and modules are declared explicitly:

```php
// application/config/app.php
return [
    'providers' => [
        // ...
    ],

    'modules' => [
        // ...
    ],
];
```

The default database configuration uses SQLite in `application/storage`.

---

## 🧾 Logging

Logs are written under:

```text
application/storage/log/
```

Files are separated by purpose and date:

```text
error-YYYYMMDD.log
security-YYYYMMDD.log
php-error-YYYYMMDD.log
```

- `error-*`: application 5xx errors;
- `security-*`: request 4xx events such as 404 and 405;
- `php-error-*`: PHP-level errors and the final fallback.

The `php-error` daily path is configured on a best-effort basis. If the hosting
environment blocks `ini_set`, the application keeps running and the host/PHP
configuration remains in use.

Log retention is an operational concern and is not implemented by the
application.

---

## 🐳 Docker development

Docker is a development convenience, not a production requirement.

```bash
# from the repository root
composer install
docker compose up -d
```

Open:

```text
http://localhost/
```

The development container uses PHP 8.2 + Apache, serves `public` as the
document root, keeps `vendor/` inside the image and bind-mounts `application/`
for real-time code and log inspection.

Apache access and error logs are also written under `application/storage/log` in the
Docker environment.

---

## 🚢 Deploy

The repository includes an optional deploy tool for repeatable SSH deployments.
It runs through the unified `./sourcerer` entrypoint, requires Docker locally and
keeps the application runtime independent from deployment concerns.

```bash
./sourcerer deploy init
./sourcerer deploy check --target=staging
./sourcerer deploy push --target=staging
./sourcerer deploy rollback --target=staging --list
```

Targets are declared in `deploy.json`, with separate `root` and `webroot` paths,
per-target overrides, maintenance mode, remote backups and rollback.

See [DEPLOY.md](DEPLOY.md) for configuration, commands and operational details.

---

## 🚀 Shared hosting

The production deployment is a normal PHP application. Docker is not required.
The manual upload flow below remains valid when the deploy CLI is not used.

```text
application/   -> application directory outside the web root
public/        -> contents go to the document root (often public_html)
vendor/        -> Composer dependencies outside the web root
```

Steps:

1. install dependencies externally with `--no-dev`;
2. upload `application/`, `public/` and `vendor/` to the server;
3. create `application/env.json`;
4. make `application/storage` writable by the application user;
5. point the document root to the uploaded `public` contents (often `public_html`).

On shared hosting, Apache access/error logs and HTTP-level rate limiting usually
belong to the provider. Application logs remain under `application/storage/log`.

---

## 🧪 Development

From the repository root:

```bash
composer validate
composer stan
composer pest
composer cover:deploy
```

The test suite covers the application bootstrap, module rendering, error pages,
log classification, daily PHP error log configuration, security primitives,
maintenance/fallback entrypoint behavior and the deploy tool.

Deploy coverage is measured separately so the application coverage scope stays
unchanged.

---

## 🧠 Why?

Shared hosting is heterogeneous and constrained. The application is designed
around those limits:

- no long-running workers or persistent application state;
- the web root is a fixed public directory;
- dependencies are resolved before deployment;
- modules and providers are explicit and easy to inspect;
- error handling and logs remain useful without requiring platform-specific
  infrastructure.

Contracts live in `stougeiro/base-contract`; the FPM runtime lives in
`stougeiro/base`; this repository shows how the application wires both together.

---

## 🤝 Contributions

Contributions are welcome. Feel free to open issues or submit pull requests.

<br>

[<img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" width="170"/>](https://www.buymeacoffee.com/stougeiro)
