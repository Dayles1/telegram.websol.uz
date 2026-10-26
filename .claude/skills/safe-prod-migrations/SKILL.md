---
name: safe-prod-migrations
description: Rules for changing the database schema or data of this project without breaking the live production database. Load before creating, editing or deleting any migration, seeder, model table/column mapping, or running any artisan db/migrate command.
---

# Changing the schema without breaking production

Production has real data (users, phones, catalogs, message history). The rewrite keeps the
same database. Every change must be deployable while the old code is still running, and
reversible.

## Never

- Never edit or delete a migration that has already run in production. Add a new one.
- Never run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, or `db:seed`
  against anything but a local/test database. Check `.env` `DB_DATABASE` first.
- Never drop or rename a column/table in the same deploy that stops using it.
- Never change a column type in place when data could be truncated or reinterpreted.
- Never put a migration and a long data backfill in one transaction on a big table.

## Expand, migrate, contract

1. **Expand**: add the new table/column (nullable or with default), new index. Old code
   keeps working.
2. **Dual-write / backfill**: new code writes both; a separate idempotent command
   (`php artisan data:backfill-...`, chunked by id, resumable) copies old data.
3. **Switch reads** to the new structure.
4. **Contract**: only after a release has run on the new structure and a backup exists,
   drop the old column/table in its own migration.

Renames are expand/contract, not `renameColumn`.

## Baseline

The old migrations are messy, so the rewrite starts from a **schema baseline**:
`php artisan schema:dump` taken from a production copy, committed as
`database/schema/mysql-schema.sql`, with old migration files removed only after verifying
the `migrations` table in production lists all of them. New migrations come after the
baseline. Tests run on the same engine as prod (MySQL/MariaDB in Docker or CI), not sqlite,
so the real migrations are what tests use.

## Every migration

- Has a working `down()`, or a comment saying why it is irreversible.
- Adds indexes for every column used in `WHERE`/`ORDER BY` of hot queries
  (`telegram_messages(status, send_at)`, `(message_group_id, status)`, FKs).
- Status columns stay `string`; values come from a PHP backed enum. Adding a value never
  needs a migration.
- Is tested locally against a fresh copy of the production dump:
  restore dump → `php artisan migrate --pretend` → `php artisan migrate` → smoke test →
  `php artisan migrate:rollback --step=1`.

## Before deploy

- Backup: `mysqldump --single-transaction --routines` of production, kept off-server.
- Read `migrate --pretend` output for production and paste it in the PR.
- Stop queue workers during a deploy that touches tables they write; restart after
  (`php artisan queue:restart`).
