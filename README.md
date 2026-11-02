![PHP](https://img.shields.io/badge/PHP-%20^8.2-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![Pest-php](https://img.shields.io/badge/Tests-Passed-019733)
![License](https://img.shields.io/badge/License-MIT-777)

# App

A small PHP 8.2 application foundation for shared hosting.

The application runs on Apache with PHP-FPM/mod_php and follows the shared-hosting
layout: the application lives outside the public directory, while `public_html`
contains only the front controller, `.htaccess` and public assets.

It is built on `stougeiro/base`, `stougeiro/base-contract`, `stougeiro/router`,
`stougeiro/http`, `stougeiro/view`, `stougeiro/cache`, `stougeiro/session`,
`stougeiro/event` and `stougeiro/pdo-database`.

The goal is an explicit, inspectable application with no hidden module discovery,
no build step required at runtime, and behavior that is predictable on ordinary
shared servers.

## ✨ Features

- **Shared-hosting layout**: application in `project/`, web root in `public_html/`.
- **Explicit wiring**: providers in `provider/`, modules in `app/`.
- **Module example**: HelloWorld controller, view and middlewares.
- **Error handling**: separate development and production error pages.
- **Log separation**: application errors, security events and PHP errors are
  written to different files.
- **Daily logs**: application logs use `YYYYMMDD` file suffixes.
- **Services**: cache, view, session, events, routing, database and logging are
  registered explicitly.
- **Docker development**: PHP 8.2 + Apache with the vendor directory kept inside
  the image.
- **Quality tooling**: PHPStan Level 9 and a Pest suite.

---

## 📦 Installation

### Requirements

- PHP 8.2 or newer.
- `ext-json`.
- `ext-pdo_sqlite` for the default database configuration.
- Apache with `mod_rewrite` for the front controller.
- A writable `project/storage` directory.

### Local setup

```bash
git clone https://github.com/stougeiro/app.git
cd app/project

composer install
cp env-example.json env.json
```

For production dependencies, generate the vendor externally:

```bash
composer install --no-dev --optimize-autoloader
```

---

## 📁 Layout

```text
app/
├── project/
│   ├── app/          application modules
│   ├── bootstrap/    runtime bootstrap
│   ├── config/       application configuration
│   ├── contract/     application-level contracts
│   ├── provider/     service providers
│   ├── storage/      cache, logs, sessions and temporary files
│   ├── tests/        Pest tests
│   └── vendor/       Composer dependencies
│
└── public_html/      document root
    ├── .htaccess
    └── index.php
```

The application must never be exposed through the web server. The document root
is `public_html`.

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
// project/config/app.php
return [
    'providers' => [
        // ...
    ],

    'modules' => [
        // ...
    ],
];
```

The default database configuration uses SQLite in `project/storage`.

---

## 🧾 Logging

Logs are written under:

```text
project/storage/log/
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
cd project && composer install && cd ..
docker compose up -d
```

Open:

```text
http://localhost/
```

The development container uses PHP 8.2 + Apache, serves `public_html` as the
document root, keeps `vendor/` inside the image and bind-mounts `project/storage`
for real-time log inspection.

Apache access and error logs are also written under `project/storage/log` in the
Docker environment.

---

## 🚀 Shared hosting

The production deployment is a normal PHP application. Docker is not required.

```text
project/       -> application directory outside the web root
public_html/   -> web root
```

Steps:

1. install dependencies externally with `--no-dev`;
2. upload `project/` and `public_html/` to the server;
3. create `project/env.json`;
4. make `project/storage` writable by the application user;
5. point the document root to `public_html`.

On shared hosting, Apache access/error logs and HTTP-level rate limiting usually
belong to the provider. Application logs remain under `project/storage/log`.

---

## 🧪 Development

From `project/`:

```bash
composer validate
composer stan
composer pest
```

The test suite covers the application bootstrap, module rendering, error pages,
log classification and daily PHP error log configuration.

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
