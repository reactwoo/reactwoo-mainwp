#!/usr/bin/env python3
"""Decide the connector catalog version from the plugin header.

The git tag is not a version. A portal tag such as v0.5.10 must not be
published as the connector version. The version sent to the update service
is the plugin header, and it must match the version constant and the zip.
"""

from __future__ import annotations

import argparse
import re
import sys
import zipfile
from pathlib import Path

HEADER_RE = re.compile(r"^[ \t]*\*[ \t]*Version:[ \t]*(\S+)", re.MULTILINE)
CONSTANT_RE = re.compile(
    r"define\(\s*'RW_CONNECTOR_VERSION'\s*,\s*'([^']+)'\s*\)"
)


class ReleaseError(Exception):
    """The publish must stop. The catalog is not changed."""


def normalize(value: str) -> str:
    return (value or "").strip().lstrip("vV")


def version_key(value: str) -> tuple:
    """Order versions the way PHP version_compare does for numeric releases."""
    parts = re.split(r"[.\-_]", normalize(value))
    key = []
    for part in parts:
        if not part:
            continue
        if part.isdigit():
            key.append((0, int(part)))
        else:
            key.append((1, part))
    return tuple(key)


def is_higher(left: str, right: str) -> bool:
    return version_key(left) > version_key(right)


def read_plugin_versions(text: str) -> tuple[str, str]:
    header = HEADER_RE.search(text)
    constant = CONSTANT_RE.search(text)
    return (
        header.group(1).strip() if header else "",
        constant.group(1).strip() if constant else "",
    )


def zip_plugin_versions(path: Path) -> tuple[str, str]:
    with zipfile.ZipFile(path) as archive:
        names = [
            name
            for name in archive.namelist()
            if name.endswith("reactwoo-support-connector.php")
        ]
        if not names:
            raise ReleaseError("Connector zip has no plugin header file.")
        text = archive.read(names[0]).decode("utf-8", "replace")
    return read_plugin_versions(text)


def decide(
    header: str,
    constant: str = "",
    zip_header: str = "",
    zip_constant: str = "",
    requested: str = "",
    catalog: str = "",
    restore: str = "",
) -> dict:
    """Return publish, version, and reason.

    publish is true only when the header is higher than the catalog, or when
    an explicit restore names that same header. The returned version is always
    the header.
    """
    header = normalize(header)
    constant = normalize(constant)
    zip_header = normalize(zip_header)
    zip_constant = normalize(zip_constant)
    requested = normalize(requested)
    catalog = normalize(catalog)
    restore = normalize(restore)

    if not header:
        raise ReleaseError("Connector plugin header has no version.")
    if constant and constant != header:
        raise ReleaseError(
            f"Plugin header {header} and RW_CONNECTOR_VERSION {constant} disagree."
        )
    if zip_header and zip_header != header:
        raise ReleaseError(
            f"Plugin header {header} and zip header {zip_header} disagree."
        )
    if zip_constant and zip_constant != header:
        raise ReleaseError(
            f"Plugin header {header} and zip RW_CONNECTOR_VERSION {zip_constant} disagree."
        )
    if requested and requested != header:
        raise ReleaseError(
            f"Requested version {requested} and plugin header {header} disagree."
        )
    if restore and restore != header:
        raise ReleaseError(
            f"Restore version {restore} and plugin header {header} disagree."
        )

    published = header
    if restore:
        return {
            "publish": True,
            "version": published,
            "reason": f"explicit restore of plugin header {published}",
        }
    if catalog and not is_higher(published, catalog):
        return {
            "publish": False,
            "version": published,
            "reason": f"{published} is not higher than catalog {catalog}",
        }
    return {
        "publish": True,
        "version": published,
        "reason": f"{published} is higher than catalog {catalog or '(none)'}",
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--main", required=True, type=Path)
    parser.add_argument("--zip", dest="zip_path", type=Path)
    parser.add_argument("--requested", default="")
    parser.add_argument("--catalog", default="")
    parser.add_argument("--restore", default="")
    parser.add_argument("--restore-file", type=Path)
    parser.add_argument("--github-output", type=Path)
    args = parser.parse_args(argv)

    text = args.main.read_text(encoding="utf-8", errors="replace")
    header, constant = read_plugin_versions(text)
    zip_header = ""
    zip_constant = ""
    if args.zip_path:
        zip_header, zip_constant = zip_plugin_versions(args.zip_path)

    restore = args.restore
    if args.restore_file and args.restore_file.is_file():
        file_restore = args.restore_file.read_text(encoding="utf-8").strip()
        if restore and file_restore and normalize(restore) != normalize(file_restore):
            raise ReleaseError(
                f"Restore input {restore} and restore file {file_restore} disagree."
            )
        restore = restore or file_restore

    try:
        result = decide(
            header,
            constant,
            zip_header,
            zip_constant,
            args.requested,
            args.catalog,
            restore,
        )
    except ReleaseError as exc:
        print(str(exc), file=sys.stderr)
        return 1

    print(result["reason"])
    if args.github_output:
        with args.github_output.open("a", encoding="utf-8") as handle:
            handle.write(f"publish={'true' if result['publish'] else 'false'}\n")
            handle.write(f"version={result['version']}\n")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ReleaseError as exc:
        print(str(exc), file=sys.stderr)
        raise SystemExit(1)
