#!/usr/bin/env bash
set -euo pipefail
# Called only after previous acceptance completes; mutates only that disposable container.
php tests/ci/tls-guard.php
expected_image='mcr.microsoft.com/mssql/server:2022-latest@sha256:4402d880dd4c34bfa7d8705e56a86cd6c88da80a1f6bbbe741f999e76264a090'
test "$(docker inspect --format '{{.Config.Image}}' "$LUMEN_CI_SQL_CONTAINER")" = "$expected_image"
test "$(docker port "$LUMEN_CI_SQL_CONTAINER" 1433/tcp)" = '127.0.0.1:1433'
LUMEN_CI_TLS_DIR=$(mktemp -d "$RUNNER_TEMP/lumen-tls.XXXXXXXX")
export LUMEN_CI_TLS_DIR
cleanup() {
  # Host key removal always; container removed by job service lifecycle even if cleanup fails.
  if docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" sh -c 'rm -rf /var/opt/mssql/lumen-ci-tls && test ! -e /var/opt/mssql/lumen-ci-tls' >/dev/null 2>&1; then
    echo 'TLS_CLEANUP: container key directory removed and absence checked.'
  else
    echo 'TLS_CLEANUP: container key removal unconfirmed; job service lifecycle required.' >&2
  fi
  rm -rf -- "$LUMEN_CI_TLS_DIR"
  test ! -e "$LUMEN_CI_TLS_DIR"
  echo 'TLS_CLEANUP: host fixture removed and absence checked.'
}
trap cleanup EXIT
python3 tests/ci/tls-fixture.py "$LUMEN_CI_TLS_DIR"
docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" mkdir -p /var/opt/mssql/lumen-ci-tls
# Only the server key is copied; CA private keys were already deleted by generator.
docker cp "$LUMEN_CI_TLS_DIR/server.key" "$LUMEN_CI_SQL_CONTAINER:/var/opt/mssql/lumen-ci-tls/server.key"
docker cp "$LUMEN_CI_TLS_DIR/server.pem" "$LUMEN_CI_SQL_CONTAINER:/var/opt/mssql/lumen-ci-tls/server.pem"
docker cp "$LUMEN_CI_TLS_DIR/mssql.conf" "$LUMEN_CI_SQL_CONTAINER:/var/opt/mssql/mssql.conf"
docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" chown -R 10001:0 /var/opt/mssql/lumen-ci-tls
docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" chown 10001:0 /var/opt/mssql/mssql.conf
docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" chmod 600 /var/opt/mssql/mssql.conf
docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" chmod 700 /var/opt/mssql/lumen-ci-tls
docker exec --user 0 "$LUMEN_CI_SQL_CONTAINER" chmod 400 /var/opt/mssql/lumen-ci-tls/server.key /var/opt/mssql/lumen-ci-tls/server.pem
docker restart "$LUMEN_CI_SQL_CONTAINER" >/dev/null
# Trust paths exist only in each child process, never GITHUB_ENV or system certificate dirs.
for mode in trusted wrong-ca hostname; do
  case "$mode" in
    trusted|hostname) ca="$LUMEN_CI_TLS_DIR/ca.pem"; ca_dir="$LUMEN_CI_TLS_DIR/ca-dir" ;;
    wrong-ca) ca="$LUMEN_CI_TLS_DIR/wrong-ca.pem"; ca_dir="$LUMEN_CI_TLS_DIR/wrong-ca-dir" ;;
  esac
  SSL_CERT_FILE="$ca" SSL_CERT_DIR="$ca_dir" php tests/sqlserver-tls.php "$mode"
done
