#!/usr/bin/env bash
set -euo pipefail
phase=runner-context
trap 'echo "TLS_DIAG: namespace runner phase=$phase." >&2' ERR
# Called only after previous acceptance completes; mutates only that disposable container.
php tests/ci/tls-guard.php
expected_image='mcr.microsoft.com/mssql/server:2022-latest@sha256:4402d880dd4c34bfa7d8705e56a86cd6c88da80a1f6bbbe741f999e76264a090'
test "$(docker inspect --format '{{.Config.Image}}' "$LUMEN_CI_SQL_CONTAINER")" = "$expected_image"
test "$(docker port "$LUMEN_CI_SQL_CONTAINER" 1433/tcp)" = '127.0.0.1:1433'
host_trust_before=$(python3 tests/ci/tls-trust-snapshot.py /etc/ssl/certs)
host_openssl_before=$(python3 tests/ci/tls-trust-snapshot.py /usr/lib/ssl)
host_namespace=$(readlink /proc/self/ns/mnt)
export LUMEN_CI_TLS_HOST_NAMESPACE="$host_namespace"
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
  test "$(python3 tests/ci/tls-trust-snapshot.py /etc/ssl/certs)" = "$host_trust_before"
  test "$(python3 tests/ci/tls-trust-snapshot.py /usr/lib/ssl)" = "$host_openssl_before"
  test "$(readlink /proc/self/ns/mnt)" = "$host_namespace"
  echo 'TLS_ISOLATION: host trust snapshot and mount namespace unchanged.'
}
trap cleanup EXIT
phase=fixture-generation
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
phase=container-restart
docker restart "$LUMEN_CI_SQL_CONTAINER" >/dev/null
# Trust paths exist only in each child process, never GITHUB_ENV or system certificate dirs.
phase=namespace-child
for mode in trusted wrong-ca hostname; do
  case "$mode" in
    trusted|hostname) ca="$LUMEN_CI_TLS_DIR/ca.pem"; ca_dir="$LUMEN_CI_TLS_DIR/ca-dir" ;;
    wrong-ca) ca="$LUMEN_CI_TLS_DIR/wrong-ca.pem"; ca_dir="$LUMEN_CI_TLS_DIR/wrong-ca-dir" ;;
  esac
  # Defaults used by native OpenSSL consumers now point into the same private CA view.
  cp "$ca" "$ca_dir/ca-certificates.crt"
  chmod 600 "$ca_dir/ca-certificates.crt"
  # Copy only package files and symlinks; never traverse the system private key directory.
  test -L /usr/lib/ssl/private
  test "$(readlink -f /usr/lib/ssl/private)" = /etc/ssl/private
  ssl_view="$LUMEN_CI_TLS_DIR/openssl-view-$mode"
  mkdir -m 700 "$ssl_view"
  cp -a --no-preserve=ownership /usr/lib/ssl/. "$ssl_view/"
  chmod 700 "$ssl_view"
  if test ! -e "$ssl_view/cert.pem" && test ! -L "$ssl_view/cert.pem"; then
    ln -s /etc/ssl/certs/ca-certificates.crt "$ssl_view/cert.pem"
  fi
  SSL_CERT_FILE="$ca" SSL_CERT_DIR="$ca_dir" sudo -E unshare --mount --propagation private \
    bash tests/ci/tls-namespace.sh "$ca_dir" "$(command -v php)" "$(id -u)" "$(id -g)" "$mode"
done
