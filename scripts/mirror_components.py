#!/usr/bin/env python3
"""Download pinned MainWP child zips, verify SHA-256, and describe the R2 keys.

This script does not publish by itself. publish-update.yml uploads the files
it writes. It is not run by a pull request.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
PINS = Path(__file__).resolve().parent / "component-pins.json"


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def download(url: str, dest: Path) -> None:
    request = urllib.request.Request(url, headers={"User-Agent": "reactwoo-component-mirror"})
    with urllib.request.urlopen(request, timeout=120) as response:
        dest.write_bytes(response.read())


def main() -> int:
    parser = argparse.ArgumentParser(description="Mirror pinned maintenance component zips")
    parser.add_argument("--dest-dir", default="", help="Directory for downloaded zips and manifests")
    args = parser.parse_args()

    pins = json.loads(PINS.read_text(encoding="utf-8"))
    dest_root = Path(args.dest_dir) if args.dest_dir else ROOT / "dist" / "components"
    dest_root.mkdir(parents=True, exist_ok=True)

    components = []
    for pin in pins.get("components") or []:
        slug = str(pin["slug"])
        version = str(pin["version"])
        expected = str(pin["sha256"]).lower()
        source = str(pin["source"])
        if not source.startswith("https://downloads.wordpress.org/plugin/"):
            raise SystemExit(f"Refusing unexpected source for {slug}")

        zip_path = dest_root / f"{slug}.zip"
        download(source, zip_path)
        actual = sha256_file(zip_path)
        if actual != expected:
            zip_path.unlink(missing_ok=True)
            raise SystemExit(f"SHA-256 mismatch for {slug} {version}: {actual}")

        version_key = f"plugins/{slug}/{version}/{slug}.zip"
        latest_key = f"plugins/{slug}/latest/{slug}.zip"
        meta = {
            "slug": slug,
            "version": version,
            "plugin_file": pin.get("plugin_file", ""),
            "sha256": actual,
            "source": source,
            "artifact_key": version_key,
            "latest_key": latest_key,
        }
        meta_path = dest_root / f"{slug}.json"
        meta_path.write_text(json.dumps(meta, indent=2) + "\n", encoding="utf-8")
        components.append(
            {
                "slug": slug,
                "version": version,
                "sha256": actual,
                "zip": str(zip_path),
                "manifest": str(meta_path),
                "keys": [version_key, latest_key],
                "manifest_keys": [
                    f"plugins/{slug}/{version}/manifest.json",
                    f"plugins/{slug}/latest.json",
                ],
            }
        )
        print(f"{slug} {version} {actual}")

    summary = dest_root / "mirror-manifest.json"
    summary.write_text(json.dumps({"components": components}, indent=2) + "\n", encoding="utf-8")
    print(f"Wrote {summary}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
