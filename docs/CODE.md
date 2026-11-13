# Code

This document describes the Code tool: its boundaries, configuration,
commands and operational behavior.

The Code tool is repository tooling. It is not part of the application
runtime and must never be uploaded to the server. A deployment copies only
`application/`, `public/` and `vendor/`.

## Layout

```text
./sourcerer                   unified tool entrypoint
tools/contract/               shared tool contracts
tools/manager/                pluggable CLI runtime
tools/support/                shared tool support
tools/code/                   Code tool and example
tools/manager/Dockerfile      tool runtime image
tools/code/src/               Code classes
tools/code/src/Command/       Code commands
tools/code/resources/stub/    default redirect stubs for retired targets
tools/code/code.example.json
.sourcerer/code/config.json   local configuration (not versioned)
.sourcerer/code/              local state and secrets (not versioned)
```

Local `.sourcerer/code/` also contains:

```text
.sourcerer/code/stub/index.php   redirect stub used when a target is retired
.sourcerer/code/stub/.htaccess   rewrite stub used when a target is retired
.sourcerer/code/releases/        local release manifest mirror
.sourcerer/code/events/          local deploy event mirror
.sourcerer/code/auth/            local SSH key and password files
```

The whole `.sourcerer/` directory is ignored by Git. It contains target
addresses, local state and SSH references.

Local `backups/` and `lock/` directories are not created. Backups and the
production lock are remote, under `<root>/.deploy/`.

Remote `.deploy/` remains the deployment state on the server and is created by
the tool.

The local public directory is `public/`. On the server, the target `webroot`
usually points to `public_html/` or another document root.

## Local requirements

Docker is the only local requirement. The tool image is built on demand from
`tools/manager/Dockerfile` and contains PHP 8.2, Composer, rsync, OpenSSH, scp,
sshpass, curl and tar.

The image is tagged `stougeiro/sourcerer:local`. `./sourcerer` rebuilds it
automatically when `tools/manager/Dockerfile` changes. To rebuild explicitly:

```bash
docker build -t stougeiro/sourcerer:local tools/manager
```

## Operator

The operator is shared by all tools and lives in:

```text
.sourcerer/operator.json
```

Configure it with:

```bash
./sourcerer operator --name="Developer" --email="developer@example.com"
```

- `name` and `email` are required.
- All tool commands require the operator.
- `operator` and `install` are the only commands that work without it.
- Identity is declarative metadata, not authentication.

## Production lock

Only `production` uses a deploy lock:

```text
<root>/.deploy/lock/owner.json
```

- `push --target=production` and `promote` into production acquire it.
- development/staging do not.
- A valid lock blocks the operation before build, maintenance, backup or transfer.
- Locks older than 3h are considered stale and replaced automatically.
- `--force` on `push`/`promote` replaces a valid production lock and writes a
  `lock.override` event with the previous owner.

## Requirements

Requirements are read from `composer.json`:

- `require.php` defines the minimum PHP version.
- `require.ext-*` defines required extensions.
- `require-dev` is ignored.

`check` and `push` validate the **web PHP runtime** via a temporary probe:

```text
public/_sr-check-<random>.php
```

The probe reports PHP version and loaded extensions, then is removed. Failures
abort before maintenance and transfer. Redirected targets skip the probe because
their stub `.htaccess` redirects every path.

## Artifact integrity

Each release manifest stores:

```json
"artifactHash": "sha256:..."
```

The hash is calculated by the local tool over `release.tar.gz`.

After upload, the remote hash is checked using `php`, `sha256sum`, `shasum` or
`openssl`. If no remote hash tool exists, deploy warns and records
`artifactVerified: false` in the event. A hash mismatch aborts and rolls back.

On promote, the downloaded tarball is always verified locally before it is sent
to the destination.

## Retention

Remote retention is configured per target/global:

```json
"keep": {
  "backup": 3,
  "release": 10,
  "event": 200
}
```

- pruning runs after a successful push/promote;
- the current release is never removed;
- local `.sourcerer/code/releases` and `.sourcerer/code/events` keep the full mirrored history;
- `keep.backup` controls remote backups.

## Quick start

```bash
./sourcerer operator --name="Developer" --email="developer@example.com"
./sourcerer install
```

Then:

1. edit `.sourcerer/code/config.json`;
2. place SSH auth files in `.sourcerer/code/auth/` or use files under `~/.ssh/`;
3. add per-target overrides under `.sourcerer/code/override/<target>/`;
4. validate the connection:

```bash
./sourcerer code check --target=staging
```

Deploy:

```bash
./sourcerer code push --target=staging
```

## Targets

`.sourcerer/code/config.json` has `global` and `targets`. Valid target names are
`development`, `staging` and `production`.

A demonstration, API or any other environment that runs a different codebase is
not a target of this repository. It must have its own deploy configuration and
its own pipeline.

```json
{
  "global": {
    "ssh": {
      "host": "example.com",
      "port": 22,
      "user": "deploy",
      "auth": {
        "type": "key",
        "file": "auth/deploy_ed25519"
      }
    },
    "root": {
      "base": "/home/deploy/domains/example.com"
    },
    "preserve": [
      "/.well-known/***"
    ],
    "keep": {
      "backup": 3,
      "release": 10,
      "event": 200
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
      "keep": {
        "backup": 5
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
| `ssh.auth.type` | `key` or `pass` |
| `ssh.auth.file` | Auth file; absolute, `~/...`, or relative to the `.sourcerer/code` config directory |
| `root.base` | Absolute base directory used to build the remote root |
| `root.dir` | Optional single directory name appended to `root.base` |
| `root` | Remote directory that receives `application/` and `vendor/` |
| `webroot` | Absolute remote directory that receives the contents of `public/` |
| `url` | Public application URL used by verification |
| `preserve` | Webroot paths protected from deletion during transfer |
| `keep.backup` | Remote backups to keep; default `3`, minimum `1` |
| `keep.release` | Remote releases to keep; default `10`, minimum `1` |
| `keep.event` | Remote events to keep; default `200`, minimum `1` |

`ssh.auth` is required. It may live in `global.ssh` when every target uses the
same credentials, or in `target.ssh` when a target differs. A target `auth`
object replaces the global one entirely.

Key auth:

```json
"auth": {
  "type": "key",
  "file": "auth/deploy_ed25519"
}
```

Password auth:

```json
"auth": {
  "type": "pass",
  "file": "auth/locaweb.pass"
}
```

Relative `file` paths resolve from the `.sourcerer/code` config directory.
`~/...` and absolute paths are kept as-is. `auth` is always a file; there is no
inline password.

Password files are read exactly as stored, without trimming. Create them without
a trailing newline:

```bash
printf '%s' 'senha' > .sourcerer/code/auth/locaweb.pass
chmod 600 .sourcerer/code/auth/locaweb.pass
```

Password auth uses `sshpass` inside the tool image. Key auth keeps the existing
`BatchMode=yes` behavior. Password auth uses `BatchMode=no` and
`StrictHostKeyChecking=accept-new` so the first browser-free connection does not
hang on a host-key prompt.

The legacy `ssh.identity` field is rejected. Replace it with `ssh.auth`.

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
.sourcerer/code/override/<target>/
├── application/
└── public/
```

They are applied after the build:

- `application/` is mirrored into `root`;
- `public/` is mirrored into `webroot`.

`vendor/` is always the Composer build and never receives overrides.

Override transfers never use `--delete`; they only add or replace files.

Every target must provide its environment through the local override:

```text
.sourcerer/code/override/<target>/application/env.json
```

Remote `application/env.json` is never read, reused, promoted or restored from
backup. `check` warns when the override is missing; `push` and `rollback` fail
before making changes.


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
| `./sourcerer operator --name=<name> --email=<email>` | Configures the shared operator identity |
| `./sourcerer install` | Creates `.sourcerer` layout and tool configs |
| `./sourcerer code check --target=<name>` | Verifies SSH, writable paths, storage and transport |
| `./sourcerer code push --target=<name>` | Builds vendor, creates a release, backs up, transfers, verifies and prunes backups |
| `./sourcerer code promote --from=<name>` | Promotes the current release to the next target and retires the source |
| `./sourcerer code releases` | Shows local release history |
| `./sourcerer code releases --target=<name>` | Analyzes a single target in local history |
| `./sourcerer code releases --sync` | Syncs all targets; must be used alone |
| `./sourcerer code rollback --target=<name> --list` | Lists backups for the target |
| `./sourcerer code rollback --target=<name> [--timestamp=<Ymd-His>]` | Restores a backup; latest when omitted |

`--list` and `--timestamp` are only valid for
`rollback` and cannot be used together. `--sync` must be used alone
and cannot be combined with `--target`. `--force` on `push`/`promote` only
overrides an active production lock.

## Push behavior

A push:

1. validates the target;
2. acquires the production lock when applicable;
3. checks SSH, paths, storage, requirements and transport;
4. computes the release hash and id;
5. builds vendor with `composer install --no-dev --classmap-authoritative`;
6. creates the clean release archive and artifact hash;
7. enables maintenance;
8. creates a remote backup of the current state, including storage;
9. transfers `application/`, `public/` and `vendor/`;
10. applies overrides;
11. disables maintenance;
12. verifies `GET url` returns `200` and `GET url/missing` returns `404`;
13. uploads the release tarball, verifies its hash when possible, and mirrors only the manifest locally;
14. writes `.deploy/current.json` and a push event;
15. prunes old backups, releases and events;
16. syncs manifests/events from all targets best-effort.

On failure, the push re-enables maintenance, restores the backup created at the
start and verifies again. If the restore fails, maintenance remains active.

## Releases and promote

Every successful push creates an immutable release.

Local mirror:

```text
.sourcerer/code/releases/<releaseId>/manifest.json
```

Target:

```text
<root>/.deploy/releases/<releaseId>/
├── release.tar.gz
└── manifest.json
```

`release.tar.gz` contains `application/` (without `env.json`/`storage`),
`public/` (without `.maintenance`), `vendor/` and `composer.json`/`composer.lock`.
The local mirror never stores the tarball.

Promotion is rigid:

```text
development → staging → production
```

```bash
./sourcerer code promote --from=development
./sourcerer code promote --from=staging
```

`promote` never skips a target. It reads the source `current.json`, downloads
the release artifact from the source target, deploys it to the next target,
applies that target's overrides and verifies it. The same `releaseId` is
preserved.

After a successful promotion, the source target is retired:

- maintenance is enabled;
- the webroot receives only `index.php` and `.htaccess` stubs;
- `index.php` redirects with `302` to the next target URL;
- `.htaccess` routes every path to `index.php`;
- `application/`, `vendor/` and storage are removed, including `env.json`;
- the target root keeps only `.deploy/`;
- `.deploy/` remains, including backups, releases and events.

Stubs are customizable in `.sourcerer/code/stub/`. The canonical `index.php` placeholder
is `%url%`. Missing stub files fall back to the tool defaults in
`tools/code/resources/stub/`.

A target with nested webroots cannot be retired; promotion aborts.

## Release history

Events are append-only and immutable:

```text
<root>/.deploy/events/<UTC>-<target>-<action>-<releaseId>-<rand>.json
```

Local mirrors:

```text
.sourcerer/code/releases/<releaseId>/manifest.json
.sourcerer/code/events/<event>.json
```

Reading is always local:

```bash
./sourcerer code releases
./sourcerer code releases --target=staging
```

Synchronization is explicit and always covers every target:

```bash
./sourcerer code releases --sync
```

`--sync` does not render the release tree and must be used alone. It tries every
target, reports success/error per target, continues on failure and exits
non-zero if any target fails.

After a successful `push` or `promote`, the same all-target sync runs
best-effort and never changes the deploy result.


## Maintenance

Maintenance is controlled by a `.maintenance` marker in the target webroot.
When the marker exists, `public/index.php` returns `503` with `Retry-After` and
does not boot the application.

## Backups and rollback

Backups are remote-only:

```text
root/.deploy/backups/<Ymd-His>/
├── root.tar.gz
├── webroot.tar.gz
├── storage.tar.gz
└── manifest.json
```

`root.tar.gz` contains `application/` and `vendor/`, excluding `storage/` and
`env.json`. `webroot.tar.gz` contains the target webroot, excluding direct
child webroots of other targets. `storage.tar.gz` is created when
`application/storage` exists.

The deployment manifest also records the current `releaseId`, `contentHash`,
`version` and `previousReleaseId`.

`rollback --list` prints available backups, newest first. With `--timestamp`,
the matching backup is restored. Without it, the newest backup is used.

Rollback:

1. checks the target;
2. enables maintenance;
3. removes `application/`, `vendor/` and the target webroot contents;
4. preserves direct child webroots;
5. extracts the backup and storage;
6. removes any restored `application/env.json`;
7. reapplies the local override, including env;
8. disables maintenance;
9. verifies `200` and `404`.

## Verification and tests

From the repository root:

```bash
composer validate --strict
composer stan
composer pest
composer cover:code
```

The Code test suite lives in `tests/Feature/Code` and covers configuration,
target isolation, SSH, shell, maintenance, push, backups and rollback.
Entrypoint behavior under maintenance and fallback is covered by
`tests/Feature/EntrypointTest.php`.
