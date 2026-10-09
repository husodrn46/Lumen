import importlib.util
from pathlib import Path
import os
import stat
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('tls_fixture',ROOT/'tests/ci/tls-fixture.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)


class CertificateTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='lumen-local-tls-')
        cls.root=Path(cls.temp.name);fixture.generate(cls.root)
    @classmethod
    def tearDownClass(cls): cls.temp.cleanup()
    def verify(self,ca,*options):
        return subprocess.run(['openssl','verify','-CAfile',str(self.root/ca),'-purpose','sslserver',*options,str(self.root/'server.pem')],capture_output=True)
    def test_ca_chain_and_ip_san(self):
        self.assertEqual(self.verify('ca.pem','-verify_ip','127.0.0.1').returncode,0)
    def test_wrong_ca_rejected(self):
        self.assertNotEqual(self.verify('wrong-ca.pem','-verify_ip','127.0.0.1').returncode,0)
    def test_wrong_hostname_rejected(self):
        self.assertNotEqual(self.verify('ca.pem','-verify_hostname','localhost').returncode,0)
    def test_leaf_not_ca(self):
        text=subprocess.check_output(['openssl','x509','-in',str(self.root/'server.pem'),'-noout','-text'],text=True)
        self.assertIn('CA:FALSE',text);self.assertIn('TLS Web Server Authentication',text);self.assertIn('IP Address:127.0.0.1',text)
    def test_short_lifetime(self):
        self.assertEqual(subprocess.run(['openssl','x509','-in',str(self.root/'server.pem'),'-checkend','259200','-noout'],capture_output=True).returncode,1)
    def test_key_matches_certificate(self):
        key=subprocess.check_output(['openssl','pkey','-in',str(self.root/'server.key'),'-pubout'],stderr=subprocess.DEVNULL)
        cert=subprocess.check_output(['openssl','x509','-in',str(self.root/'server.pem'),'-pubkey','-noout'])
        self.assertEqual(key,cert)
    def test_private_files_and_ca_key_discarded(self):
        self.assertEqual(stat.S_IMODE(self.root.stat().st_mode),0o700)
        self.assertEqual(stat.S_IMODE((self.root/'server.key').stat().st_mode),0o600)
        self.assertFalse((self.root/'ca.key').exists());self.assertFalse((self.root/'wrong-ca.key').exists())
    def test_never_reuses_directory(self):
        before=(self.root/'server.key').read_bytes()
        with self.assertRaises(ValueError):fixture.generate(self.root)
        self.assertEqual(before,(self.root/'server.key').read_bytes())
    def test_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            link=Path(tmp)/'link';link.symlink_to(self.root,target_is_directory=True)
            with self.assertRaises(ValueError):fixture.generate(link)


class GateTests(unittest.TestCase):
    def test_local_generator_cli_rejected_no_key_created(self):
        with tempfile.TemporaryDirectory() as tmp:
            result=subprocess.run(['python3',str(ROOT/'tests/ci/tls-fixture.py'),tmp],env={'PATH':os.environ['PATH'],'PYTHONDONTWRITEBYTECODE':'1'},capture_output=True)
            self.assertNotEqual(result.returncode,0);self.assertEqual(list(Path(tmp).iterdir()),[])
    def test_php_and_shell_before_docker_rejected(self):
        for args in [['php','tests/sqlserver-tls.php','trusted'],['bash','tests/ci/sqlserver-tls.sh']]:
            result=subprocess.run(args,cwd=ROOT,env={'PATH':os.environ['PATH']},capture_output=True)
            self.assertEqual(result.returncode,2);self.assertEqual(result.stdout,b'');self.assertIn(b'gate rejected',result.stderr)
    def test_container_config_owner_and_permissions(self):
        script=(ROOT/'tests/ci/sqlserver-tls.sh').read_text()
        self.assertIn('chown 10001:0 /var/opt/mssql/mssql.conf',script)
        self.assertIn('chmod 600 /var/opt/mssql/mssql.conf',script)
        self.assertLess(script.index('chmod 600 /var/opt/mssql/mssql.conf'),script.index('docker restart'))
    def test_shell_syntax(self):
        subprocess.run(['bash','-n',str(ROOT/'tests/ci/sqlserver-tls.sh')],check=True)


if __name__=='__main__':unittest.main()
