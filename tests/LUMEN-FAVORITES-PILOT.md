# Separate Lumen favorite store — local, disabled pilot

This is a first preference slice, not independent login, a complete user migration,
ERP sync, or a final DB-engine decision. SQL Server is the current adapter because
it matches the existing application/accepted CI runtime; no new installation was made.

## Ownership and identity

LOGO remains owner of authentication/permissions and its sales representative.
Lumen owns favorite preferences and the explicit actor link. Scope is
`(LUMEN_LOGO_CONNECTION_ID, FIRMA, LOGO_PERSONEL) -> local UUID ACTOR_ID`.
`CODE` is never used as a global Lumen identity. Period is not part of a personal
preference. Same person ref in another company or ERP connection is another scope.
The actor link is prepared outside runtime after review; no automatic provisioning,
reuse of the raw external ref as local identity, ERP import, or dual writes.

Only `gor_kart_favori` uses the new store when enabled. Other preferences and
LOGO `$dbh` stay on their current path. This code does not make the homepage or
login work while LOGO is offline: the existing bootstrap still connects to LOGO.

## Defaults and failure behavior

- `LUMEN_FAVORITES_ENABLED` absent/empty/`0`: old ERP favorite read/write/cache.
- `1`: scoped Lumen favorite read/write. Any other value fails closed.
- Missing connection/config/schema/actor link: read returns empty/default favorite;
  save returns generic 503 and no ERP fallback, actor creation or stale ERP cache.
- Valid session/current role/company prefix required; POST cannot select actor,
  user, company or connection. Existing `kontrol.php` authentication remains.
- CSRF must be string and valid, POST only; malformed favorite array is 400.
- CSV/path normalization and 40-card limit are preserved. Favorites do not grant
  module permission; `index.php` still renders only `visible_menu_items`.
- UI restores the previous star on failed/network/invalid-JSON save and serializes
  favorite requests. The whole list has last-write semantics across tabs/devices;
  versioned conflict handling is outside this slice.

## Preparation before any pilot activation (NOT done)

Set a stable non-secret `LUMEN_LOGO_CONNECTION_ID` (lowercase slug), explicitly
separate `LUMEN_DB_SERVER/NAME/USER/PASS`, and a verified SQL TLS certificate.
The factory requires encryption and certificate validation, rejects `sa`, ERP DB
name/shared user, actual DB mismatch, sysadmin and CREATE TABLE permission. It
never consumes a supplied DSN or inherits AKL credentials. Final permission review
must still verify the runtime account has only SELECT on actor links and
SELECT/INSERT/UPDATE on favorites, with no other server/DB/object privileges.

`database/lumen/migrations/001_preferences.sql` is an unapplied draft deliberately
outside `sql/`, so the current LOGO installer cannot pick it up. It requires an
explicit reviewed migration session context and rejects system DBs. It creates
only two tables, with scoped PK/unique/FK constraints, transaction/rollback, no
reuse/DROP. Run only against a separately verified Lumen target with a migration
account after a future explicit migration decision. Runtime performs no DDL.

No actual actor link or existing user preference was imported. A future import
must be a separate dry-run/reviewed task: verify LOGO person ref + connection/company,
assign immutable local UUID, compare user/value counts, and handle ambiguous legacy
USER_CODE rows explicitly. Do not infer identity from a duplicate CODE.

Pilot starts empty; without explicit import, prior favorites remain in ERP and do
not appear in the pilot. Turning the flag off restores those unchanged ERP values;
it does not copy new Lumen values back. Re-enable reads preserved Lumen values.

## Local verification and remaining real acceptance

```
php tests/favorites-regression.php
python3 tests/favorites-http-regression.py
node tests/favorites-js.test.cjs
SKIP_RECTOR=1 bash scripts/validate.sh
python3 tests/ci-workflow-regression.py
```

PHP suite: synthetic PDO + actual connection/scope/repository/getter functions.
HTTP suite: actual save endpoint body over loopback, auth/PDO/connection fixture.
JS suite: actual index handler with DOM/network double, no browser rendering.
No full production bootstrap, real LOGO auth or new schema execution is claimed.

`tests/sqlserver-favorites.php` is a CI-only real SQL runner. It needs
an explicitly approved GitHub-hosted CI context, the fixed fresh localhost
`LumenTest_Fav_CI` DB, runtime identity and existing opt-in write flag; it refuses nonempty DB, applies the draft only to that synthetic DB, seeds explicit
synthetic links, and tests ten same-scope concurrent saves, company/user/connection
isolation, constraint rollback, FK/PK rejection, missing mapping and wrong credentials.
The third fresh DB is wired into SQL CI. A separate runtime login receives only
SELECT on links and SELECT/INSERT/UPDATE on favorites; actual DDL/mapping-write and
other test DB access are rejected. Runtime factory metadata is checked through the
fixture's trusted loopback connector. Strict TLS is tested negatively against the
container's untrusted self-signed chain; production factory must not bypass that
failure. **Positive TLS with a verified certificate/hostname is not covered.**
No production TLS trust bypass has been introduced.

Publication and CI results for this slice are recorded in the separate acceptance report.
The successful `04fbb06` run predates this slice and proves only the earlier package.
