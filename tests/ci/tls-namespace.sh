#!/usr/bin/env bash
set -euo pipefail
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
# /usr/lib/ssl paths on the fixed Ubuntu runner must resolve to this trust directory.
test "$(readlink -f /usr/lib/ssl/certs)" = /etc/ssl/certs
test "$(readlink -f /usr/lib/ssl/cert.pem)" = /etc/ssl/certs/ca-certificates.crt
mounted=0
cleanup_namespace() {
  if test "$mounted" -eq 1; then
    umount /etc/ssl/certs
    echo 'TLS_CLEANUP: private CA mount explicitly unmounted.'
  fi
}
trap cleanup_namespace EXIT
mount --bind "$ca_dir" /etc/ssl/certs
mounted=1
mount -o remount,bind,ro /etc/ssl/certs
case ",$(findmnt -n -o OPTIONS --mountpoint /etc/ssl/certs)," in *,ro,*) ;; *) exit 2 ;; esac
# Verify write denial inside the mounted view; no host path can be reached here.
if touch /etc/ssl/certs/lumen-write-probe 2>/dev/null; then
  rm -f /etc/ssl/certs/lumen-write-probe
  exit 2
fi
echo 'TLS_ISOLATION: separate private mount namespace; CA view read-only; write probe rejected.'
# PHP verifies the original unprivileged runner identity and empty effective capabilities.
export LUMEN_CI_TLS_RUNNER_UID="$runner_uid" LUMEN_CI_TLS_RUNNER_GID="$runner_gid"
setpriv --reuid="$runner_uid" --regid="$runner_gid" --init-groups \
  --bounding-set=-all --inh-caps=-all --ambient-caps=-all --no-new-privs \
  "$php_bin" tests/sqlserver-tls.php "$mode"
