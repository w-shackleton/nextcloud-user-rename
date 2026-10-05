#!/usr/bin/env bash
# End-to-end test for occ user:rename against a THROWAWAY Nextcloud instance.
#
#   NC_DIR=/path/to/nextcloud BASE_URL=http://127.0.0.1:8085 \
#   DUMP_CMD="mysqldump -unctest -pnctest nctest" \
#   SQL_CMD="mysql -unctest -pnctest nctest" tests/Integration/rename.sh
#
# Creates users alice and bob, gives alice files, versions, trash, favorites,
# shares, comments, a calendar, an app password and an avatar, renames
# alice -> alicia and checks that everything still works and that "alice" is
# gone from the database dump and the data directory.
set -uo pipefail

NC_DIR=${NC_DIR:?set NC_DIR to the Nextcloud server directory}
BASE_URL=${BASE_URL:-http://127.0.0.1:8085}
DUMP_CMD=${DUMP_CMD:?set DUMP_CMD to a command that dumps the database as SQL}
SQL_CMD=${SQL_CMD:-}   # optional: runs SQL from stdin, for fixtures without an API
OLD=${OLD:-alice}
NEW=${NEW:-alicia}
PASS='Rename-test-pass-1'
BOB_PASS='Rename-test-pass-2'

occ() { php "$NC_DIR/occ" "$@" </dev/null; }
DATA_DIR=$(occ config:system:get datadirectory)
DAV="$BASE_URL/remote.php/dav"
OCS="$BASE_URL/ocs/v2.php"

failures=0
pass() { echo "  ok   $*"; }
fail() { echo "  FAIL $*"; failures=$((failures + 1)); }
check() { local desc=$1; shift; if "$@" >/dev/null 2>&1; then pass "$desc"; else fail "$desc"; fi; }

http_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
ocs() { curl -s -H 'OCS-APIRequest: true' -H 'Accept: application/json' "$@"; }
file_id() { # user pass path
	curl -s -u "$1:$2" -X PROPFIND -H 'Depth: 0' "$DAV/files/$1/$3" \
		--data '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>' \
		| sed -n 's:.*<oc\:fileid>\([0-9]*\)</oc\:fileid>.*:\1:p'
}

echo "== Reset"
occ user:delete "$OLD" >/dev/null 2>&1
occ user:delete "$NEW" >/dev/null 2>&1
occ user:delete bob >/dev/null 2>&1
occ group:delete team >/dev/null 2>&1

echo "== Fixtures"
OC_PASS=$PASS occ user:add --password-from-env --display-name 'Alice A' "$OLD" >/dev/null
OC_PASS=$BOB_PASS occ user:add --password-from-env bob >/dev/null
occ group:add team >/dev/null
occ group:adduser team bob >/dev/null
occ group:adduser team "$OLD" >/dev/null
occ user:setting "$OLD" settings email "$OLD@example.com"

A=(-u "$OLD:$PASS")
echo v1 > /tmp/user_rename_v1.txt
echo v2 > /tmp/user_rename_v2.txt
curl -s "${A[@]}" -T /tmp/user_rename_v1.txt "$DAV/files/$OLD/report.txt" >/dev/null
sleep 1
curl -s "${A[@]}" -T /tmp/user_rename_v2.txt "$DAV/files/$OLD/report.txt" >/dev/null
curl -s "${A[@]}" -X MKCOL "$DAV/files/$OLD/Project" >/dev/null
curl -s "${A[@]}" -T /tmp/user_rename_v1.txt "$DAV/files/$OLD/Project/plan.txt" >/dev/null
curl -s "${A[@]}" -T /tmp/user_rename_v1.txt "$DAV/files/$OLD/deleteme.txt" >/dev/null
curl -s "${A[@]}" -X DELETE "$DAV/files/$OLD/deleteme.txt" >/dev/null
curl -s "${A[@]}" -X PROPPATCH "$DAV/files/$OLD/report.txt" \
	--data '<?xml version="1.0"?><d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:set><d:prop><oc:favorite>1</oc:favorite></d:prop></d:set></d:propertyupdate>' >/dev/null
ocs "${A[@]}" -X POST "$OCS/apps/files_sharing/api/v1/shares" -d path=/report.txt -d shareType=0 -d shareWith=bob >/dev/null
ocs "${A[@]}" -X POST "$OCS/apps/files_sharing/api/v1/shares" -d path=/Project -d shareType=1 -d shareWith=team >/dev/null
ocs "${A[@]}" -X POST "$OCS/apps/files_sharing/api/v1/shares" -d path=/Project -d shareType=3 >/dev/null
FILE_ID=$(file_id "$OLD" "$PASS" report.txt)
curl -s "${A[@]}" -X POST -H 'Content-Type: application/json' "$DAV/comments/files/$FILE_ID" \
	--data '{"actorType":"users","verb":"comment","message":"hello","objectType":"files"}' >/dev/null
curl -s "${A[@]}" -o /dev/null "$BASE_URL/index.php/avatar/$OLD/64"
occ dav:create-calendar "$OLD" work >/dev/null 2>&1
OC_PASS=$PASS occ user:auth-tokens:add --password-from-env "$OLD" >/dev/null 2>&1
curl -s "${A[@]}" -X MKCOL "$DAV/photos/$OLD/albums/Holiday" >/dev/null
if [ -n "$SQL_CMD" ]; then
	echo "INSERT INTO oc_twofactor_totp_secrets (user_id, secret, state, last_counter) VALUES ('$OLD', 'fixture', 2, -1);" | $SQL_CMD 2>/dev/null
fi
# bob accesses the shares so his mounts exist
curl -s -u "bob:$BOB_PASS" -X PROPFIND -H 'Depth: 1' "$DAV/files/bob/" >/dev/null

check "fixture: report.txt has fileid" test -n "$FILE_ID"
check "fixture: data/$OLD exists" test -d "$DATA_DIR/$OLD"

echo "== Scan and dry run"
occ user-rename:scan "$OLD"
occ user:rename --dry-run "$OLD" "$NEW"

echo "== Negative cases"
check "refuses renaming to existing user (other case)" bash -c "! php '$NC_DIR/occ' user:rename -n '$OLD' BOB"
check "refuses unknown user" bash -c "! php '$NC_DIR/occ' user:rename -n nosuchuser '$NEW'"
check "refuses invalid new uid" bash -c "! php '$NC_DIR/occ' user:rename -n '$OLD' 'bad/name'"
occ maintenance:mode --on >/dev/null
check "refuses while in maintenance mode" bash -c "! php '$NC_DIR/occ' user:rename -n '$OLD' '$NEW'"
occ maintenance:mode --off >/dev/null
check "nothing changed by refused runs" test -d "$DATA_DIR/$OLD"

echo "== Rename"
if occ user:rename -n "$OLD" "$NEW"; then pass "user:rename exit code"; else fail "user:rename exit code"; fi
check "maintenance mode is off again" bash -c "php '$NC_DIR/occ' config:system:get maintenance | grep -qv true"

echo "== Verify"
N=(-u "$NEW:$PASS")
check "occ user:info $NEW" occ user:info "$NEW"
check "occ user:info $OLD fails" bash -c "! php '$NC_DIR/occ' user:info '$OLD'"
check "display name kept" bash -c "php '$NC_DIR/occ' user:info '$NEW' | grep -q 'Alice A'"
check "email kept" bash -c "php '$NC_DIR/occ' user:setting '$NEW' settings email | grep -q '$OLD@example.com'"
check "group membership kept" bash -c "php '$NC_DIR/occ' group:list --output=json | grep -q '\"$NEW\"'"
check "data/$NEW exists" test -d "$DATA_DIR/$NEW/files"
check "data/$OLD is gone" test ! -e "$DATA_DIR/$OLD"
check "login as $NEW (PROPFIND)" test "$(http_code "${N[@]}" -X PROPFIND -H 'Depth: 1' "$DAV/files/$NEW/")" = 207
check "login as $OLD fails" test "$(http_code -u "$OLD:$PASS" -X PROPFIND -H 'Depth: 0' "$DAV/files/$OLD/")" = 401
check "file content intact" test "$(curl -s "${N[@]}" "$DAV/files/$NEW/report.txt")" = v2
check "same fileid" test "$(file_id "$NEW" "$PASS" report.txt)" = "$FILE_ID"
check "version still listed" bash -c "test \$(curl -s -u '$NEW:$PASS' -X PROPFIND -H 'Depth: 1' '$DAV/versions/$NEW/versions/$FILE_ID' | grep -o '<d:response>' | wc -l) -ge 2"
check "trash still listed" bash -c "curl -s -u '$NEW:$PASS' -X PROPFIND -H 'Depth: 1' '$DAV/trashbin/$NEW/trash' | grep -q deleteme.txt"
check "favorite kept" bash -c "curl -s -u '$NEW:$PASS' -X PROPFIND -H 'Depth: 0' '$DAV/files/$NEW/report.txt' --data '<?xml version=\"1.0\"?><d:propfind xmlns:d=\"DAV:\" xmlns:oc=\"http://owncloud.org/ns\"><d:prop><oc:favorite/></d:prop></d:propfind>' | grep -q '<oc:favorite>1</oc:favorite>'"
check "comment author is $NEW" bash -c "curl -s -u '$NEW:$PASS' -X PROPFIND -H 'Depth: 1' '$DAV/comments/files/$FILE_ID' | grep -q '<oc:actorId>$NEW</oc:actorId>'"
check "calendar reachable" test "$(http_code "${N[@]}" -X PROPFIND -H 'Depth: 0' "$DAV/calendars/$NEW/work/")" = 207
check "bob still sees user share" bash -c "curl -s -u 'bob:$BOB_PASS' -X PROPFIND -H 'Depth: 1' '$DAV/files/bob/' | grep -q report.txt"
check "bob still sees group share" bash -c "curl -s -u 'bob:$BOB_PASS' -X PROPFIND -H 'Depth: 1' '$DAV/files/bob/' | grep -q Project"
check "shares list owner $NEW" bash -c "curl -s -u '$NEW:$PASS' -H 'OCS-APIRequest: true' -H 'Accept: application/json' '$OCS/apps/files_sharing/api/v1/shares' | grep -q '\"uid_owner\":\"$NEW\"'"
check "photos album kept" bash -c "curl -s -u '$NEW:$PASS' -X PROPFIND -H 'Depth: 1' '$DAV/photos/$NEW/albums/' | grep -q Holiday"
if [ -n "$SQL_CMD" ]; then
	check "TOTP secret moved" bash -c "echo \"SELECT user_id FROM oc_twofactor_totp_secrets WHERE secret='fixture'\" | $SQL_CMD 2>/dev/null | grep -qx '$NEW'"
fi
check "can still upload" test "$(http_code "${N[@]}" -T /tmp/user_rename_v1.txt "$DAV/files/$NEW/after.txt")" = 201
check "files:scan clean" occ files:scan "$NEW"

echo "== Leftovers of '$OLD' in the database"
occ user-rename:scan "$OLD"
# History and logs that legitimately keep the old name:
#   activity*            subject parameters of past events
#   addressbookchanges   CardDAV sync log of the old card URI
#   bruteforce_attempts  the failed login as $OLD above
ALLOWED='^(oc_activity|oc_activity_mq|oc_addressbookchanges|oc_bruteforce_attempts)$'
by_table=$($DUMP_CMD 2>/dev/null | grep -E "\\b$OLD\\b" | grep -v "$OLD@example.com" \
	| sed -nE 's/^INSERT INTO `([^`]+)`.*/\1/p' | sort | uniq -c)
echo "$by_table" | sed '/^$/d; s/^/    /'
unexpected=$(echo "$by_table" | awk '{print $2}' | grep -vE "$ALLOWED" | sed '/^$/d')
if [ -z "$unexpected" ]; then
	pass "no unexpected '$OLD' rows in database dump"
else
	fail "'$OLD' still in: $(echo $unexpected)"
fi

echo
if [ "$failures" -eq 0 ]; then echo "ALL CHECKS PASSED"; else echo "$failures CHECK(S) FAILED"; fi
exit "$failures"
