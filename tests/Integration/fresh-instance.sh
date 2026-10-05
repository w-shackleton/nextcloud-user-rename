#!/usr/bin/env bash
# (Re)creates a throwaway Nextcloud instance for rename.sh: drops the
# database, wipes the data directory, installs, and enables user_rename.
#   NC_DIR=... DATA_DIR=... DB_NAME=nctest DB_USER=nctest DB_PASS=nctest tests/Integration/fresh-instance.sh
set -euo pipefail
NC_DIR=${NC_DIR:?}
DATA_DIR=${DATA_DIR:?}
DB_NAME=${DB_NAME:-nctest}
DB_USER=${DB_USER:-nctest}
DB_PASS=${DB_PASS:-nctest}
BASE_URL=${BASE_URL:-http://127.0.0.1:8085}

mysql -u"$DB_USER" -p"$DB_PASS" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;"
rm -rf "$DATA_DIR"
rm -f "$NC_DIR/config/config.php"
php "$NC_DIR/occ" maintenance:install --database mysql --database-name "$DB_NAME" \
	--database-user "$DB_USER" --database-pass "$DB_PASS" --database-host localhost \
	--admin-user admin --admin-pass 'Admin-pass-123' --data-dir "$DATA_DIR"
php "$NC_DIR/occ" config:system:set trusted_domains 1 --value="${BASE_URL#http://}"
php "$NC_DIR/occ" config:system:set overwrite.cli.url --value="$BASE_URL"
php "$NC_DIR/occ" app:enable user_rename
