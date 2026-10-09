#!/usr/bin/env python3
"""Fingerprint regression; temporary files only, no system certificate writes."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
spec = importlib.util.spec_from_file_location("snapshot", Path(__file__).parent / "ci/tls-trust-snapshot.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class SnapshotTests(unittest.TestCase):
    def test_repeat_reads_are_stable(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "ca.pem").write_text("synthetic certificate")
            self.assertEqual(module.snapshot(root), module.snapshot(root))
    def test_changed_bytes_detected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / "ca.pem"
            path.write_text("one")
            before = module.snapshot(root)
            path.write_text("two")
            self.assertNotEqual(before, module.snapshot(root))
    def test_changed_permissions_detected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / "ca.pem"
            path.write_text("one")
            path.chmod(0o600)
            before = module.snapshot(root)
            path.chmod(0o400)
            self.assertNotEqual(before, module.snapshot(root))
    def test_changed_symlink_target_detected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "one").write_text("same")
            (root / "two").write_text("same")
            link = root / "hash.0"
            link.symlink_to("one")
            before = module.snapshot(root)
            link.unlink()
            link.symlink_to("two")
            self.assertNotEqual(before, module.snapshot(root))

if __name__ == "__main__":
    unittest.main()
