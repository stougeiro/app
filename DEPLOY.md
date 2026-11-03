# Deploy

This document describes the deployment tool: its boundaries, configuration,
commands and operational behavior.

The deploy tool is repository tooling. It is not part of the application
runtime and must never be uploaded to the server. A deployment copies only
`application/`, `public_html/` and `vendor/`.

## Layout

```text
./deploy                     executable shim
tools/deploy/                tool source, console, Dockerfile and example
tools/deploy/src/            deploy classes
tools/deploy/deploy.example.json
deploy.json                  local configuration (not versioned)
.deploy/                     local state and secrets (not versioned)
```

`deploy.json` and `.deploy/` are ignored by Git. They contain target addresses,
environment values and SSH references.

## Requirements

The default command uses Docker. The deploy image is built on demand from
`tools/deploy/Dockerfile` and contains PHP, Composer, rsync, OpenSSH, scp,
curl and tar.

To run without Docker, use `--local` or set `DEPLOY_LOCAL=1`:

```bash
./deploy --local help
DEPLOY_LOCAL=1 ./deploy help
```

Local mode requires PHP 8.2+, Composer, rsync, ssh, scp, curl and tar.

## Quick start

```bash
./deploy init
```

Then:

1. edit `deploy.json`;
2. add `.deploy/env/<target>.json`;
3. place SSH keys in `.deploy/ssh/` or use keys available under `~/.ssh/`;
4. add per-target overrides under `.deploy/override/<target>/`;
5. validate the connection:

```bash
./deploy check --target=staging
```

Deploy:

```bash
./deploy plan --target=staging
./deploy push --target=staging
```

Production commonly requires an explicit plan:

```bash
./deploy plan --target=production
./deploy push --target=production
```

When the environment changes too:

```bash
./deploy plan --target=production --with-env
./deploy push --target=production --with-env
```

## Targets

`deploy.json` has `defaults` and `targets`. The only valid target names are
`development`, `staging` and `production`.

```json
{
  "defaults": {
    "ssh": {
      "port": 22,
      "identity": ".deploy/ssh/deploy_ed25519"
    },
    "permissions": {
      "dir": "755",
      "file": "644",
      "storage": "775"
    },
    "backup": {
      "enabled": true,
      "keep": 3
    },
    "requiresPlan": false
  },
  "targets": {
    "staging": {
      "ssh": {
        "host": "staging.example.com",
        "user": "deploy"
      },
      "root": "/home/deploy/staging",
      "webroot": "/home/deploy/public_html/staging",
      "url": "https://staging.example.com"
    },
    "production": {
      "ssh": {
        "host": "example.com",
        "user": "deploy",
        "identity": ".deploy/ssh/production_ed25519"
      },
      "root": "/home/deploy/app",
      "webroot": "/home/deploy/public_html",
      "url": "https://example.com",
      "requiresPlan": true,
      "preserve": [
        "/uploads/***",
        "/.well-known/***",
        "/staging/***",
        "/development/***"
      ],
      "backup": {
        "keep": 10
      }
    }
  }
}
```

### Fields

| Field | Meaning |
|---|---|
| `ssh.host` | SSH host or IP |
| `ssh.user` | SSH user |
| `ssh.port` | SSH port, default `22`, valid `1..65535` |
| `ssh.identity` | SSH key; absolute, `~/...`, or relative to the project root |
| `root` | Remote directory that receives `application/` and `vendor/` |
| `webroot` | Remote directory that receives the contents of `public_html/` |
| `url` | Public application URL used by verification |
| `preserve` | Webroot paths protected from deletion during transfer |
| `permissions.dir` | Directory mode |
| `permissions.file` | File mode |
| `permissions.storage` | Storage directory mode |
| `backup.enabled` | Enables remote snapshots before deploy |
| `backup.keep` | Number of remote snapshots to keep |
| `requiresPlan` | Requires a matching `plan` before `push` |

`root` and `webroot` must be absolute paths. They must differ, and `root` must
not be inside `webroot`.

### Same server, different environments

Dev, staging and production may share the same SSH host, user and port as long
as each target uses its own `root`, `webroot` and `url`. On the same server,
production must not reuse the `url`, `root` or `webroot` of another target.

Example:

```json
"targets": {
  "development": {
    "root": "/home/deploy/development",
    "webroot": "/home/deploy/public_html/development",
    "url": "https://dev.example.com"
  },
  "staging": {
    "root": "/home/deploy/staging",
    "webroot": "/home/deploy/public_html/staging",
    "url": "https://staging.example.com"
  },
  "production": {
    "root": "/home/deploy/app",
    "webroot": "/home/deploy/public_html",
    "url": "https://example.com"
  }
}
```

### Nested webroots

When `dirname(webroot)` is not `root`, the entrypoint needs an absolute require
for the target root. The deploy `check` warns when the override is missing:

```text
.deploy/override/<target>/public_html/index.php
```

The override is applied after the build, mirroring the target layout.

## Environment files

Environment values live in:

```text
.deploy/env/<target>.json
```

The file must declare the matching environment:

```json
{
  "environment": "staging",
  "APP_KEY": "..."
}
```

`push --with-env` writes the environment to `root/application/env.json` and
verifies the remote hash after writing.

Environment files and SSH keys are secrets. They must not be committed.

## Overrides

Per-target overrides live under:

```text
.deploy/override/<target>/
├── application/
├── public_html/
└── vendor/
```

They are applied after the build:

- `application/` and `vendor/` are mirrored into `root`;
- `public_html/` is mirrored into `webroot`.

Override transfers never use `--delete`; they only add or replace files.

## Commands

| Command | Purpose |
|---|---|
| `./deploy init` | Creates `.deploy/` directories and copies the example to `deploy.json` |
| `./deploy check --target=<name>` | Verifies SSH, remote PHP, rsync availability, remote env and layout |
| `./deploy plan --target=<name> [--with-env]` | Records the source, target and env hashes in `.deploy/plans/<target>.json` |
| `./deploy push --target=<name> [--with-env]` | Builds vendor, transfers, verifies and consumes the plan |
| `./deploy maintenance --target=<name>` | Reports the remote maintenance marker and HTTP status |
| `./deploy rollback --target=<name> [--backup=<timestamp>]` | Restores a remote snapshot; latest when omitted |

`init` does not accept `--target`. `plan` and `push` accept `--with-env`.
`rollback` accepts `--backup`.

## Push behavior

A push:

1. validates the target;
2. reads and validates the remote environment;
3. builds vendor with `composer install --no-dev --classmap-authoritative`;
4. enables maintenance;
5. creates a remote snapshot when backups are enabled;
6. writes the environment when requested;
7. transfers `application/`, `public_html/` and `vendor/`;
8. applies permissions and overrides;
9. disables maintenance;
10. verifies `GET url` returns `200` and `GET url/missing` returns `404`;
11. prunes old snapshots and consumes the plan.

On failure, the push attempts to re-enable maintenance and restore the snapshot.
If no snapshot exists, maintenance remains active. Rollback failures are
reported and maintenance is not disabled.

## Maintenance

Maintenance is controlled by a `.maintenance` marker in the webroot. When the
marker exists, `public_html/index.php` returns `503` with `Retry-After` and does
not boot the application.

The deploy tool enables maintenance before changing the application and
disables it only after verification succeeds.

## Backups and rollback

Backups are remote-only:

```text
root/.deploy/backups/<target>/<Ymd-His>/
├── root.tar.gz
├── webroot.tar.gz
└── manifest.json
```

Snapshots are never copied to the local machine. `rollback` validates the
snapshot name, manifest and target layout before restoring.

## Verification and tests

From the repository root:

```bash
composer validate --strict
composer stan
composer pest
composer cover:deploy
```

The deploy test suite lives in `tests/Feature/Deploy` and covers configuration,
environment files, plans, SSH, shell, maintenance and deployer push/rollback.
Entrypoint behavior under maintenance and fallback is covered by
`tests/Feature/EntrypointTest.php`.
