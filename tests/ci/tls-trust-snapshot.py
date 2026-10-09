#!/usr/bin/env python3
"""Read-only fingerprint of trust directory entries and resolved certificate contents."""
import hashlib
import os
from pathlib import Path
import sys

def snapshot(root):
    root = Path(root)
    digest = hashlib.sha256()
    root_info = root.lstat()
    digest.update(str((root_info.st_mode, root_info.st_uid, root_info.st_gid)).encode() + b"\0")
    for path in sorted(root.rglob("*")):
        info = path.lstat()
        digest.update(str(path.relative_to(root)).encode() + b"\0")
        digest.update(str((info.st_mode, info.st_uid, info.st_gid)).encode() + b"\0")
        if path.is_symlink():
            digest.update(os.readlink(path).encode() + b"\0")
        if path.is_file():
            digest.update(path.read_bytes())
    return digest.hexdigest()

if __name__ == "__main__":
    print(snapshot(sys.argv[1]))
