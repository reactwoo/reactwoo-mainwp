#!/usr/bin/env python3
"""Create the support connector zip. CI writes an unversioned filename."""

from __future__ import annotations

import json
import os
import re
import zipfile
from pathlib import Path

INCLUDE_DIRS = ["includes"]
INCLUDE_FILES = ["reactwoo-support-connector.php", "readme.txt"]


def main() -> None:
    repo_root = Path(__file__).resolve().parent.parent
    pkg = json.loads((repo_root / "package.json").read_text(encoding="utf-8"))
    cfg = pkg.get("reactwooBuild") or {}
    folder = str(cfg.get("pluginFolder") or "reactwoo-support-connector")
    source = repo_root / str(cfg.get("sourceDir") or folder)
    zip_name = str(cfg.get("zipFile") or f"{folder}.zip")
    ci = os.environ.get("CI", "").lower() in ("1", "true", "yes")
    if not ci:
        text = (source / str(cfg.get("mainPhp") or f"{folder}.php")).read_text(encoding="utf-8", errors="replace")
        match = re.search(r"^\s*\*\s*Version:\s*([^\s\r\n]+)", text, re.MULTILINE)
        if match:
            stem = Path(zip_name).stem
            zip_name = f"{stem}-{match.group(1).strip()}{Path(zip_name).suffix or '.zip'}"

    out = repo_root / zip_name
    if out.exists():
        out.unlink()

    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as zf:
        for dirname in INCLUDE_DIRS:
            dirpath = source / dirname
            if not dirpath.is_dir():
                continue
            for root, _dirs, files in os.walk(dirpath):
                for filename in files:
                    filepath = Path(root) / filename
                    rel = filepath.relative_to(source).as_posix()
                    zf.write(filepath, arcname=f"{folder}/{rel}")
        for filename in INCLUDE_FILES:
            filepath = source / filename
            if filepath.is_file():
                zf.write(filepath, arcname=f"{folder}/{filename}")

    with zipfile.ZipFile(out, "r") as zf:
        names = zf.namelist()
        if any("\\" in name or name.startswith(f"{folder}/{folder}/") for name in names):
            raise RuntimeError("Invalid connector zip structure")
        if f"{folder}/{folder}.php" not in names:
            raise RuntimeError("Connector main file missing from zip")

    print(f"Created: {out}")


if __name__ == "__main__":
    main()
