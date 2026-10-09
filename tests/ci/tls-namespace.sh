#!/usr/bin/env bash
set -euo pipefail
phase=namespace-context
trap 'echo "TLS_DIAG: namespace setup phase=$phase." >&2' ERR
# Only invoked by the guarded ephemeral CI runner. No host certificate writes.
test "$#" -eq 5
ca_dir=$1; php_bin=$2; runner_uid=$3; runner_gid=$4; mode=$5
"$php_bin" tests/ci/tls-guard.php
test "$(id -u)" -eq 0
test "$runner_uid" -ne 0
test "$(readlink /proc/self/ns/mnt)" != "$LUMEN_CI_TLS_HOST_NAMESPACE"
case "$mode" in
  trusted|hostname) test "$ca_dir" = "$LUMEN_CI_TLS_DIR/ca-dir" ;;
  wrong-ca) test "$ca_dir" = "$LUMEN_CI_TLS_DIR/wrong-ca-dir" ;;
  *) exit 2 ;;
esac
test -d "$ca_dir" && test ! -L "$ca_dir"
test -f "$ca_dir/ca-certificates.crt"
phase=openssl-default-cert-directory
# /usr/lib/ssl paths on the fixed Ubuntu runner must resolve to this trust directory.
test "$(readlink -f /usr/lib/ssl/certs)" = /etc/ssl/certs
phase=openssl-default-cert-bundle
if test -e /usr/lib/ssl/cert.pem || test -L /usr/lib/ssl/cert.pem; then
  test "$(readlink -f /usr/lib/ssl/cert.pem)" = /etc/ssl/certs/ca-certificates.crt
else
  echo 'TLS_DIAG: optional default CA bundle alias absent; isolated CA directory and process CA file required.'
fi
ssl_view="$LUMEN_CI_TLS_DIR/openssl-view-$mode"
test -d "$ssl_view" && test ! -L "$ssl_view"
test "$(readlink -f "$ssl_view/cert.pem")" = /etc/ssl/certs/ca-certificates.crt
mounted=0
ssl_mounted=0
cleanup_namespace() {
  if test "$ssl_mounted" -eq 1; then
    umount /usr/lib/ssl
    echo 'TLS_CLEANUP: private OpenSSL bundle view explicitly unmounted.'
  fi
  if test "$mounted" -eq 1; then
    umount /etc/ssl/certs
    echo 'TLS_CLEANUP: private CA mount explicitly unmounted.'
  fi
}
trap cleanup_namespace EXIT
phase=readonly-bind-mount
mount --bind "$ca_dir" /etc/ssl/certs
mounted=1
mount -o remount,bind,ro /etc/ssl/certs
case ",$(findmnt -n -o OPTIONS --mountpoint /etc/ssl/certs)," in *,ro,*) ;; *) exit 2 ;; esac
mount --bind "$ssl_view" /usr/lib/ssl
ssl_mounted=1
mount -o remount,bind,ro /usr/lib/ssl
case ",$(findmnt -n -o OPTIONS --mountpoint /usr/lib/ssl)," in *,ro,*) ;; *) exit 2 ;; esac
if touch /usr/lib/ssl/lumen-write-probe 2>/dev/null; then
  rm -f /usr/lib/ssl/lumen-write-probe
  exit 2
fi
test "$(readlink -f /usr/lib/ssl/cert.pem)" = /etc/ssl/certs/ca-certificates.crt
echo 'TLS_ISOLATION: private OpenSSL default bundle alias present; second read-only view verified.'
# Verify write denial inside the mounted view; no host path can be reached here.
if touch /etc/ssl/certs/lumen-write-probe 2>/dev/null; then
  rm -f /etc/ssl/certs/lumen-write-probe
  exit 2
fi
echo 'TLS_ISOLATION: separate private mount namespace; CA view read-only; write probe rejected.'
phase=openssl-default-trust-verification
# Verify native default paths inside the view without custom CA environment variables.
case "$mode" in
  trusted)
    env -u SSL_CERT_FILE -u SSL_CERT_DIR openssl verify -purpose sslserver -verify_ip 127.0.0.1 "$LUMEN_CI_TLS_DIR/server.pem" >/dev/null 2>&1
    echo 'TLS_ISOLATION: OpenSSL default trust paths verify the server certificate and IP.' ;;
  wrong-ca)
    if env -u SSL_CERT_FILE -u SSL_CERT_DIR openssl verify -purpose sslserver -verify_ip 127.0.0.1 "$LUMEN_CI_TLS_DIR/server.pem" >/dev/null 2>&1; then exit 2; fi ;;
  hostname)
    if env -u SSL_CERT_FILE -u SSL_CERT_DIR openssl verify -purpose sslserver -verify_hostname localhost "$LUMEN_CI_TLS_DIR/server.pem" >/dev/null 2>&1; then exit 2; fi ;;
esac
phase=unprivileged-strict-php
# PHP verifies the original unprivileged runner identity and empty effective capabilities.
export LUMEN_CI_TLS_RUNNER_UID="$runner_uid" LUMEN_CI_TLS_RUNNER_GID="$runner_gid"
setpriv --reuid="$runner_uid" --regid="$runner_gid" --init-groups \
  --bounding-set=-all --inh-caps=-all --ambient-caps=-all --no-new-privs \
  "$php_bin" tests/sqlserver-tls.php "$mode"
