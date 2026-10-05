# User Rename

WARNING: This code is AI-generated. It's been used successfully on a real Nextcloud instance.

A Nextcloud app that renames a local user's uid, which is also their login
name, with an `occ` command:

```
sudo -u www-data php occ user:rename --dry-run alice alicia   # always first
sudo -u www-data php occ user:rename alice alicia
```

For local (Database backend) accounts, Nextcloud has no user ID separate from
the login name. `oc_users.uid` is the login name, and dozens of tables and the
data directory refer to the user by it. This app rewrites all of them in one
database transaction and moves `data/<uid>/`.

Tested on Nextcloud 35.0.1 with MariaDB. `info.xml` declares 32–36. Rules
for tables or columns missing from a version are skipped automatically.

## Before you run it

1. **Back up the database and the data directory.**
2. Run with `--dry-run` and read the output (see below).
3. Leave maintenance mode **off**. `occ` doesn't load app commands in
   maintenance mode, so the command turns it on itself and turns it off when
   it finishes, including on failure.
4. Run `occ` as the web server user, because the data directory gets renamed.

## What happens

1. **Preflight checks. Any failure aborts the run.**
   - The user exists, the uid matches exactly (case-sensitive), the user is
     in the Database backend, and no other backend also knows the name.
   - The new uid is valid and unused (case-insensitive).
   - No object store as primary storage, and no server-side encryption.
   - The home folder is at `<datadirectory>/<uid>`.
   - No rows already reference the new uid. These would be leftovers from a
     deleted user with that name. Clean them up, or pass `--force` to merge
     them into the renamed account.
2. **Database rewrite.** Every rule in `lib/Handler/` runs in one
   transaction. Rows are selected first and compared case-sensitively in PHP,
   so `_ci` collations can't turn a rename of `alice` into one of `Alice`.
3. **Data directory.** `data/<old>` is renamed to `data/<new>` before the
   commit. If that fails, the database is rolled back. If the commit fails,
   the directory is moved back.
4. **After the commit:**
   - The avatar folder in appdata is moved.
   - User caches are cleared.
   - `dav:sync-system-addressbook` runs in a fresh process.
   - `OCA\UserRename\Event\UserRenamedEvent` is dispatched.
   - A scan reports anything still referencing the old uid.

Sessions and app passwords are **deleted** by default. Pass `--keep-tokens`
to rename them instead, which keeps app passwords working.

## Afterwards

- **Remove and re-add the account in every desktop and mobile client.** The
  WebDAV URL contains the uid (`/remote.php/dav/files/<uid>/`).
  - The desktop application can be fixed by stopping the app, editing its
    config file, and restarting the app.
  - The Android app - completely uninstall and reinstall the app.
- Restart php-fpm or the web server, or flush APCu/Redis.
- The federated cloud ID changes to `<new>@<host>`. Shares with other servers
  may need re-creating.
- History keeps the old name in a few places: activity entries, @mentions in
  comment text, and the CardDAV change log (`addressbookchanges`).
- If you hit login rate-limit errors, this means you have a client trying to
  use the old username still. Stop the app and run `DELETE FROM oc_bruteforce_attempts;`
  on the MySQL server.

## What is covered

- **Core:**
  - users, groups, preferences, accounts and profile
  - sessions and app passwords, 2FA providers, backup codes and WebAuthn
  - shares, the home storage id and mounts
  - DAV properties, comments, reactions, tags and favorites
  - background job arguments, AI task tables, and external storage credentials
- **Bundled apps:**
  - DAV: calendar, contacts and subscription principals, delegation, absence and direct links
  - Files: trash, versions, reminders and ownership transfers
  - Files sharing: federated shares received
  - Other: external storage and workflow user scopes, user status, recent contacts, webhooks and federated invites
- **Shipped apps:** Activity, Notifications, Teams (circles), Photos albums,
  TOTP, File locks and Text sessions.

`occ user-rename:scan <uid>` shows where a uid appears: once for the columns
listed above, and once for any other column whose name looks user-related.
Use it before a rename to spot apps this tool doesn't cover. `--strict` makes
`user:rename` refuse to run when such references exist.

## Not supported

- Object storage as primary storage
- Server-side encryption
- LDAP, SAML and OIDC users
- Oracle databases
- The new `sharing` app (its schema is still changing; the scanner reports
  it)
- Third-party apps without a rule. The scanner reports them. To add a rule,
  write an `IRenameHandler` and register it in `HandlerRegistry`.

## Development

```
composer install
composer test:unit
```

The integration test needs a **throwaway** instance. It wipes and reinstalls
the database:

```
NC_DIR=... DATA_DIR=... tests/Integration/fresh-instance.sh
NC_DIR=... DUMP_CMD="mysqldump -u.. -p.. --skip-extended-insert db" \
  SQL_CMD="mysql -N -u.. -p.. db" tests/Integration/rename.sh
```

`rename.sh` sets up fixtures through WebDAV and OCS, renames `alice` to
`alicia`, checks 33 things, and fails if `alice` remains anywhere in a
database dump outside the history tables.
