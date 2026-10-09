#!/usr/bin/env python3
"""Bounded loopback HTTP test; no app bootstrap, credentials, external DB or install."""
import hashlib
import http.client
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parent
php = shutil.which('php')
if not php:
    raise SystemExit('ENGEL: php gerekli')
checks = 0

def check(ok, message):
    global checks
    checks += 1
    if not ok:
        raise AssertionError(message)

with tempfile.TemporaryDirectory(prefix='lumen-http-') as private:
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    with open(Path(private) / 'server.log', 'w+') as log:
        server = subprocess.Popen([php, '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}',
            '-t', private, str(root / 'support/http-router.php')], stdout=log, stderr=log)
        try:
            for _ in range(50):
                if server.poll() is not None:
                    log.seek(0)
                    raise RuntimeError('Fixture server başlatılamadı: ' + log.read())
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('Fixture server zaman aşımı')

            def request(path, data=b'', headers=None, method='POST'):
                c = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
                try:
                    c.request(method, path, body=data, headers=headers or {'Content-Type': 'application/json'})
                    r = c.getresponse()
                    status, content_type, body = r.status, r.getheader('Content-Type'), r.read()
                    check(content_type == 'application/json; charset=utf-8', 'JSON Content-Type: '+path)
                    return status, json.loads(body)
                finally:
                    c.close()

            status, body = request('/body', '{"label":"Türkçe","n":1.25}'.encode())
            check(status == 200 and body['body']['label'] == 'Türkçe' and body['body']['n'] == 1.25, 'UTF-8/object request')
            for raw in [b'{', b'[]', b'null', b'12', b'"abc"']:
                status, body = request('/body', raw)
                check(status == 400 and body['ok'] is False, 'Malformed/list/scalar rejected')
            status, body = request('/body', b'')
            check(status == 200 and body['body'] == [], 'Empty body preserved')
            boundary = b'{"v":"' + b'x' * (1048576 - 8) + b'"}'
            check(len(boundary) == 1048576, 'Boundary fixture length')
            status, body = request('/body', boundary)
            check(status == 200, 'Exactly 1 MiB accepted')
            status, body = request('/body', boundary + b' ')
            check(status == 413 and body['ok'] is False, 'Over 1 MiB rejected')
            token = 'a' * 64
            for hdr in ['Bearer '+token, 'bEaReR '+token.upper()]:
                status, body = request('/bearer', headers={'Authorization': hdr})
                check(status == 200 and body['token'] == token, 'Standard bearer preserved')
            for hdr in ['', 'Bearer '+token+' trailing', 'prefix Bearer '+token, 'Bearer sha256:'+token]:
                status, body = request('/bearer', headers={'Authorization': hdr})
                check(status == 200 and body['token'] == '', 'Strict bearer rejection')
            status, body = request('/issue')
            check(status == 200 and len(body['token']) == 64 and all(c in '0123456789abcdef' for c in body['token']), 'Wire token remains 64 hex')
            check(body['stored'] == 'sha256:'+hashlib.sha256(body['token'].encode()).hexdigest(), 'Storage hash differs from wire token')
            for mode in ['legacy', 'hashed']:
                status, body = request('/auth?mode='+mode, headers={'Authorization': 'Bearer '+token})
                check(status == 200 and body['session']['personel'] == 7, 'Legacy/new bearer authentication fixture')
                update = body['events'][-1]
                check(update['params'][':h'] == 'sha256:'+hashlib.sha256(token.encode()).hexdigest(), 'Legacy/new storage upgrade wiring')
            for suffix, headers, expected in [('', {}, 401), ('?mode=invalid', {'Authorization':'Bearer '+token}, 401),
                ('?mode=unavailable', {'Authorization':'Bearer '+token}, 503),
                ('?mode=short-schema', {'Authorization':'Bearer '+token}, 503),
                ('?mode=revoked-during-auth', {'Authorization':'Bearer '+token}, 401),
                ('?mode=wrong-scope', {'Authorization':'Bearer '+token}, 503)]:
                status, body = request('/auth'+suffix, headers=headers)
                check(status == expected and body['ok'] is False and 'Synthetic' not in body['mesaj'], 'Auth fail-closed/status/private error')
            status, body = request('/logout', method='GET')
            check(status == 405, 'Logout method gate')
            status, body = request('/logout', headers={'Authorization':'Bearer '+token})
            check(status == 200 and body['ok'] is True, 'Actual logout body accepts valid bearer fixture')
            idem_key = 'a81876de-f852-4db6-8e4c-1cc2172a7a96'
            wire_headers = {'Idempotency-Key': idem_key.upper(), 'Content-Type': 'application/json'}
            status, protocol = request('/idempotency-protocol', b'{"cari_id":60,"kalemler":[{"stok_id":5,"miktar":2}]}', wire_headers)
            check(status == 200 and protocol['key'] == idem_key, 'HTTP idempotency header passes and normalizes')
            status, reordered = request('/idempotency-protocol', b'{"kalemler":[{"miktar":2,"stok_id":5}],"cari_id":60}', wire_headers)
            check(status == 200 and reordered['hash'] == protocol['hash'], 'Same parsed content/object order fingerprint')
            status, changed = request('/idempotency-protocol', b'{"cari_id":60,"kalemler":[{"stok_id":5,"miktar":3}]}', wire_headers)
            check(status == 200 and changed['hash'] != protocol['hash'], 'Changed content fingerprint via real HTTP body')
            for bad in ['', 'short', idem_key+',second']:
                status, body = request('/idempotency-protocol', b'{}', {'Idempotency-Key': bad})
                check(status == 400 and body['ok'] is False, 'Invalid/combined key headers rejected')
            status, body = request('/idempotency-protocol', b'{}')
            check(status == 200 and body['key'] is None, 'Legacy header absence')
            status, body = request('/anything')
            check(status == 404, 'Router cannot serve application files')
            print(f'TAMAM: {checks} HTTP assertion (gerçek loopback HTTP; PDO sentetik, PHP {subprocess.check_output([php,"-r","echo PHP_VERSION;"], text=True)}).')
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)
