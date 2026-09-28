#!/usr/bin/env python3
"""Package public_html into dist/integra-code-hostinger.zip for upload.

Excludes local-only files: inc/config.php (dev config), SQLite databases,
sessions, logs and dev tooling. Usage: python3 dev/build.py
"""
import os
import sys
import zipfile
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "public_html")
DIST = os.path.join(ROOT, "dist")

EXCLUDE_FILES = {"inc/config.php"}
EXCLUDE_SUFFIXES = (".sqlite", ".sqlite-wal", ".sqlite-shm", ".log")
EXCLUDE_DIR_CONTENTS = ("storage/sessions/", "storage/logs/")


def included(rel: str) -> bool:
    if rel in EXCLUDE_FILES or rel.endswith(EXCLUDE_SUFFIXES):
        return False
    if rel.startswith(EXCLUDE_DIR_CONTENTS) and not rel.endswith(".gitkeep"):
        return False
    return True


def main() -> int:
    os.makedirs(DIST, exist_ok=True)
    target = os.path.join(DIST, "integra-code-hostinger.zip")
    count = 0
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as zf:
        for base, _dirs, files in os.walk(SRC):
            for name in files:
                full = os.path.join(base, name)
                rel = os.path.relpath(full, SRC).replace(os.sep, "/")
                if included(rel):
                    zf.write(full, rel)
                    count += 1
    size = os.path.getsize(target) / 1024
    print(f"{datetime.now():%Y-%m-%d %H:%M} · {count} files · {size:.0f} KB -> {target}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
