# Schema

Schema is the structural database state tool of the Sourcerer toolset.

It versions **structure**, not instructions:

```text
GENESIS → Release → Release → ...
```

Each release is immutable. Changes are always new releases, including regressions.

## Scope

Supported:

- tables and columns;
- primary keys;
- foreign keys;
- unique constraints and indexes;
- views;
- sequences (driver-dependent).

Not supported:

- triggers;
- procedures;
- functions;
- data management;
- backups and restores.

If a database contains a trigger, procedure or function, Schema refuses to operate. Domain behavior belongs in the application.

## Artifacts

```text
schema/<database>/
├── HEAD
├── genesis/
│   ├── genesis.json
│   ├── schema.json
│   └── release.sql
├── releases/<releaseId>/
│   ├── release.json
│   ├── schema.json
│   ├── migration.sql
│   └── release.sql
└── checkpoints/<releaseId>.json
```

- `release.sql`: complete standalone snapshot.
- `migration.sql`: forward change from the parent release.
- There is no `rollback.sql`.
- Genesis has only `release.sql`.
- Genesis and regressions create checkpoints automatically.
- A stage release only becomes a checkpoint after `schema integrity`.

Local scratch data lives outside version control:

```text
.schema/<database>/
├── stage.json
├── indexes/
├── targets/
└── tmp/
```

## Configuration

`schema.json` is independent from `application/config/database.php` and is not versioned.

```json
{
  "databases": {
    "default": {
      "driver": "sqlite",
      "version": "3",
      "targets": {
        "local": {
          "transport": "local",
          "database": "application/storage/database.sqlite"
        },
        "development": {
          "transport": "ssh",
          "database": "/srv/dev/database.sqlite",
          "ssh": {
            "host": "example.com",
            "port": 22,
            "user": "deploy",
            "identity": ".schema/ssh/deploy_ed25519",
            "php_binary": "/opt/alt/php82/usr/bin/php"
          }
        }
      }
    }
  }
}
```

Targets are fixed: `local`, `development`, `staging`, `production`.

Each database has one driver. The remote transport is SSH plus an explicit PHP CLI binary. There is no direct remote PDO transport.

## Commands

Currently implemented:

```bash
./sourcerer schema init
./sourcerer schema status
./sourcerer schema status --target=local
```

Planned:

```bash
./sourcerer schema diff
./sourcerer schema stage
./sourcerer schema release --generate --alias=AA
./sourcerer schema rollback --to=Q
./sourcerer schema integrity --release=AA
./sourcerer schema update --target=development --release=AA
```

## Init

```bash
./sourcerer schema init
```

- reads the `local` target;
- inspects the database;
- refuses triggers, procedures and functions;
- writes Genesis, `release.sql` and the automatic `GENESIS` checkpoint;
- sets `HEAD` to `GENESIS`.

## Status

```bash
./sourcerer schema status
```

Shows the local artifact state: HEAD, genesis hash, checkpoint and pending stage.

```bash
./sourcerer schema status --target=local
```

Connects to the target and reports `CLEAN` or `DRIFT` against known releases.

## SQLite

SQLite supports `ADD COLUMN`, `RENAME COLUMN` and `DROP COLUMN` with restrictions. It has no `ALTER COLUMN`.

Changes to type, nullability, defaults, primary keys or constraints require a table rebuild:

```sql
PRAGMA foreign_keys=OFF;
BEGIN IMMEDIATE;
CREATE TABLE new_table (...);
INSERT INTO new_table SELECT ... FROM old_table;
DROP TABLE old_table;
ALTER TABLE new_table RENAME TO old_table;
CREATE INDEX ...;
CREATE VIEW ...;
PRAGMA foreign_key_check;
COMMIT;
PRAGMA foreign_keys=ON;
```

Large tables require a maintenance window, free disk space and a backup. Schema does not manage data or backups.

## Verification

```bash
composer validate --strict
composer stan
composer pest
composer cover:schema
```
