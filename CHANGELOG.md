# Changelog

All notable changes to this project are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.1.0 - 2026-10-09

### Added

- `occ user:rename <old> <new>` renames a local (Database backend) user's uid
  across the database and the data directory, in one transaction, with
  preflight checks and `--dry-run`, `--force`, `--keep-tokens` and `--strict`
  options.
- `occ user-rename:scan <uid>` reports every column that still references a
  uid, including columns from apps without a rename rule.
- Rename rules for core, bundled apps (DAV, Files, Files sharing, external
  storage, workflows, user status, contacts interaction, webhooks, federated
  invites) and shipped apps (Activity, Notifications, Teams, Photos, TOTP,
  File locks, Text).
- `OCA\UserRename\Event\UserRenamedEvent` for other apps to react to a rename.
