# Deploy

This document describes the deployment tool: its boundaries, configuration,
commands and operational behavior.

The deploy tool is repository tooling. It is not part of the application
runtime and must never be uploaded to the server. A deployment copies only
`application/`, `public/` and `vendor/`.

## Layout

```text
./sourcerer                  unified tool entrypoint
tools/contract/              shared tool contracts
tools/cli/                   pluggable CLI runtime
tools/deploy/                deploy tool and example
tools/cli/Dockerfile         tool runtime image
tools/deploy/src/            deploy classes
tools/deploy/src/Command/    deploy commands
tools/deploy/deploy.example.json
deploy.json                  local configuration (not versioned)
.deploy/                     local state and secrets (not versioned)
```

`deploy.json` and `.deploy/` are ignored by Git. They contain target addresses
and SSH references.

The local public directory is `public/`. On the server, the target `webroot`
usually points to `public_html/` or another document root.

## Requirements

Docker is the only local requirement. The tool image is built on demand from
`tools/cli/Dockerfile` and contains PHP 8.2, Composer, rsync, OpenSSH, scp,
curl and tar.

The image is tagged `stougeiro/sourcerer:local`. If the Dockerfile changes,
rebuild it explicitly:

```bash
docker build -t stougeiro/sourcerer:local tools/cli
```

## Quick start

```bash
./sourcerer deploy init
```

Then:

1. edit `deploy.json`;
2. place SSH keys in `.deploy/ssh/` or use keys available under `~/.ssh/`;
3. add per-target overrides under `.deploy/override/<target>/`;
4. validate the connection:

```bash
./sourcerer deploy check --target=staging
```

Deploy:

```bash
./sourcerer deploy push --target=staging
```

## Targets

`deploy.json` has `defaults` and `targets`. The only valid target names are
`development`, `staging` and `production`.

```json
{
  "defaults": {
    "ssh": {
      "host": "example.com",
      "port": 22,
      "user": "deploy",
      "identity": ".deploy/ssh/deploy_ed25519"
    },
    "root": {
      "base": "/home/deploy/domains/example.com"
    },
    "preserve": [
      "/.well-known/***"
    ],
    "backup": {
      "keep": 3
    }
  },
  "targets": {
    "staging": {
      "root": {
        "dir": "staging"
      },
      "webroot": "/home/deploy/domains/example.com/public_html/staging",
      "url": "https://staging.example.com"
    },
    "production": {
      "webroot": "/home/deploy/domains/example.com/public_html",
      "url": "https://example.com",
      "backup": {
        "keep": 5
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
| `root.base` | Absolute base directory used to build the remote root |
| `root.dir` | Optional single directory name appended to `root.base` |
| `root` | Remote directory that receives `application/` and `vendor/` |
| `webroot` | Absolute remote directory that receives the contents of `public/` |
| `url` | Public application URL used by verification |
| `preserve` | Webroot paths protected from deletion during transfer |
| `backup.keep` | Number of remote backups to keep; default `3`, minimum `1` |

`root` is an object:

```json
"root": {
  "base": "/home/deploy/domains/example.com",
  "dir": "staging"
}
```

The final root is `base + "/" + dir`, or just `base` when `dir` is omitted.
`root.base` and `webroot` must be absolute paths. `root` and `webroot` must
differ, and `root` must not be inside its own `webroot`.

Relative paths, `.`/`..` segments, `~` and backslashes are rejected.

`root.dir` must be a single directory segment. Slashes, `.`, `..`, `~` and
backslashes are rejected.

`root.base` must already exist and be writable. When `root.dir` is set and the
final root does not exist, `check` creates only that direct child with `mkdir`
(no recursive creation). When `root.dir` is omitted, `root.base` is the final
root and must exist.

The webroot is never created automatically. If it does not exist, `check` and
`push` fail.

## Same server and target isolation

Targets on the same SSH host and port must not share the final `root`,
`webroot` or `url`. Production is the reference: no target may reuse its paths
or URL.

Nesting is allowed because shared hosting often serves subdomains from folders
inside `public_html`:

```text
production.webroot = /home/deploy/domains/example.com/public_html
development.webroot = /home/deploy/domains/example.com/public_html/dev
```

When a target webroot contains another target webroot:

- the child must be a **direct child** (`/public_html/dev`);
- the parent excludes the child from transfers;
- the parent excludes the child from backups;
- rollback of the parent never deletes the child.

Deeper nesting is rejected.

## Overrides

Per-target overrides live under:

```text
.deploy/override/<target>/
├── application/
└── public/
```

They are applied after the build:

- `application/` is mirrored into `root`;
- `public/` is mirrored into `webroot`.

`vendor/` is always the Composer build and never receives overrides.

Override transfers never use `--delete`; they only add or replace files.

Environment values belong in an override:

```text
.deploy/override/production/application/env.json
```

No environment file is managed by the deploy tool.

`check` warns when neither the local override nor the remote file exists:

```text
.deploy/override/<target>/application/env.json
root/application/env.json
```

`push` aborts before maintenance and transfer when both are missing.

## Uploads

Application uploads belong to:

```text
application/storage/upload/
```

`application/storage` is excluded from the application transfer, so uploads
remain untouched during `push`. They are included in remote backups and
restored by `rollback`.

## Commands

| Command | Purpose |
|---|---|
| `./sourcerer deploy init` | Creates `.deploy/` directories and copies the example to `deploy.json` |
| `./sourcerer deploy check --target=<name>` | Verifies SSH, writable paths, storage and transport |
| `./sourcerer deploy push --target=<name>` | Builds vendor, backs up, transfers, verifies and prunes backups |
| `./sourcerer deploy rollback --target=<name> --list` | Lists backups for the target |
| `./sourcerer deploy rollback --target=<name> [--timestamp=<Ymd-His>]` | Restores a backup; latest when omitted |

`init` does not accept `--target`. `--list` and `--timestamp` are only valid for
`rollback` and cannot be used together.

## Push behavior

A push:

1. validates the target;
2. checks SSH, paths, storage and transport;
3. builds vendor with `composer install --no-dev --classmap-authoritative`;
4. enables maintenance;
5. creates a remote backup of the current state;
6. transfers `application/`, `public/` and `vendor/`;
7. applies overrides;
8. disables maintenance;
9. verifies `GET url` returns `200` and `GET url/missing` returns `404`;
10. prunes old backups.

On failure, the push re-enables maintenance, restores the backup created at the
start and verifies again. If the restore fails, maintenance remains active.

## Maintenance

Maintenance is controlled by a `.maintenance` marker in the target webroot.
When the marker exists, `public/index.php` returns `503` with `Retry-After` and
does not boot the application.

## Backups and rollback

Backups are remote-only:

```text
root/.deploy/backups/<target>/<Ymd-His>/
├── root.tar.gz
├── webroot.tar.gz
└── manifest.json
```

`root.tar.gz` contains `application/` and `vendor/`. `webroot.tar.gz` contains
the target webroot, excluding direct child webroots of other targets.

`rollback --list` prints available backups, newest first. With `--timestamp`,
the matching backup is restored. Without it, the newest backup is used.

Rollback:

1. checks the target;
2. enables maintenance;
3. removes `application/`, `vendor/` and the target webroot contents;
4. preserves direct child webroots;
5. extracts the backup;
6. disables maintenance;
7. verifies `200` and `404`.

## Verification and tests

From the repository root:

```bash
composer validate --strict
composer stan
composer pest
composer cover:deploy
```

The deploy test suite lives in `tests/Feature/Deploy` and covers configuration,
target isolation, SSH, shell, maintenance, push, backups and rollback.
Entrypoint behavior under maintenance and fallback is covered by
`tests/Feature/EntrypointTest.php`.
