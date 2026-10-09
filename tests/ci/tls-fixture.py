"""Ephemeral test certificate generator. No trust-store installation; no key output."""
from pathlib import Path
import os
import subprocess
import sys


def generate(directory):
    root = Path(directory)
    if not root.is_dir() or root.is_symlink() or list(root.iterdir()):
        raise ValueError('Empty private fixture directory required')
    root.chmod(0o700)
    def run(*args):
        result = subprocess.run(['openssl', *args], cwd=root, capture_output=True)
        if result.returncode: raise RuntimeError('Test certificate generation failed')
    (root/'ca.cnf').write_text('''[req]
prompt=no
distinguished_name=dn
x509_extensions=ca
[dn]
CN=Lumen ephemeral CI CA
[ca]
basicConstraints=critical,CA:TRUE,pathlen:0
keyUsage=critical,keyCertSign,cRLSign
subjectKeyIdentifier=hash
''')
    (root/'server.cnf').write_text('''[req]
prompt=no
distinguished_name=dn
[dn]
CN=127.0.0.1
[leaf]
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=IP:127.0.0.1
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
''')
    old_umask = os.umask(0o077)
    try:
        run('req','-x509','-newkey','rsa:2048','-nodes','-sha256','-days','2','-config','ca.cnf','-keyout','ca.key','-out','ca.pem')
        run('req','-new','-newkey','rsa:2048','-nodes','-sha256','-config','server.cnf','-keyout','server.key','-out','server.csr')
        run('x509','-req','-in','server.csr','-CA','ca.pem','-CAkey','ca.key','-set_serial','0x'+os.urandom(16).hex(),'-days','2','-sha256','-extfile','server.cnf','-extensions','leaf','-out','server.pem')
        # Independent wrong root; private key is discarded before any client test.
        run('req','-x509','-newkey','rsa:2048','-nodes','-sha256','-days','2','-subj','/CN=Lumen wrong CI CA','-keyout','wrong-ca.key','-out','wrong-ca.pem')
        for name in ('ca.key','wrong-ca.key','server.csr','ca.cnf','server.cnf'):
            (root/name).unlink()
        (root/'empty-ca-dir').mkdir(mode=0o700)
        (root/'mssql.conf').write_text('''[network]
tlscert = /var/opt/mssql/lumen-ci-tls/server.pem
tlskey = /var/opt/mssql/lumen-ci-tls/server.key
tlsprotocols = 1.2
forceencryption = 1
''')
        for file in root.iterdir():
            if file.is_file(): file.chmod(0o600)
    finally:
        os.umask(old_umask)


if __name__=='__main__':
    # CLI is CI-only. Local unit tests call generate in auto-cleaned temporary dirs.
    required={'GITHUB_ACTIONS':'true','RUNNER_OS':'Linux','RUNNER_ARCH':'X64','RUNNER_ENVIRONMENT':'github-hosted','GITHUB_REPOSITORY':'husodrn46/Lumen','GITHUB_EVENT_NAME':'workflow_dispatch','LUMEN_CI_SQL_PREPARE':'synthetic-ci-approved'}
    if any(os.environ.get(k)!=v for k,v in required.items()) or len(sys.argv)!=2:
        sys.exit('Ephemeral CI gate rejected')
    root=Path(sys.argv[1]); parent=Path(os.environ.get('RUNNER_TEMP',''))
    if not parent.is_absolute() or root.parent.resolve()!=parent.resolve() or not root.name.startswith('lumen-tls.'):
        sys.exit('Fixture path gate rejected')
    generate(root)
