#!/usr/bin/env python3
"""Checks for the connector catalog version decision."""

from __future__ import annotations

import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts"))

import resolve_connector_release as release  # noqa: E402


def expect(name: str, ok: bool) -> None:
    if ok:
        print(f"ok  {name}")
        return
    print(f"FAIL {name}", file=sys.stderr)
    raise SystemExit(1)


def decide(**kwargs):
    try:
        return release.decide(**kwargs), None
    except release.ReleaseError as exc:
        return None, str(exc)


def main() -> None:
    higher, error = decide(header="0.3.2", constant="0.3.2", catalog="0.3.1")
    expect(
        "a higher connector header is the published version",
        error is None and higher["publish"] and higher["version"] == "0.3.2",
    )

    same, error = decide(header="0.3.1", constant="0.3.1", catalog="0.3.1")
    expect(
        "the same catalog version is not published again",
        error is None and not same["publish"] and same["version"] == "0.3.1",
    )

    portal_tag, error = decide(
        header="0.3.1",
        constant="0.3.1",
        catalog="0.5.10",
        requested="0.5.10",
    )
    expect(
        "a portal tag that disagrees with the connector header is refused",
        portal_tag is None and error is not None and "disagree" in error,
    )

    skipped, error = decide(header="0.3.1", constant="0.3.1", catalog="0.5.10")
    expect(
        "a portal tag is ignored and a lower header is not published",
        error is None and not skipped["publish"] and skipped["version"] == "0.3.1",
    )

    mismatch, error = decide(
        header="0.3.1",
        constant="0.3.1",
        zip_header="0.5.10",
        catalog="0.3.0",
    )
    expect(
        "a zip header that disagrees with the plugin header is refused",
        mismatch is None and error is not None and "zip header" in error,
    )

    constant, error = decide(header="0.3.1", constant="0.5.10", catalog="0.3.0")
    expect(
        "a version constant that disagrees with the plugin header is refused",
        constant is None and error is not None and "RW_CONNECTOR_VERSION" in error,
    )

    restored, error = decide(
        header="0.3.1",
        constant="0.3.1",
        zip_header="0.3.1",
        zip_constant="0.3.1",
        catalog="0.5.10",
        restore="0.3.1",
    )
    expect(
        "an explicit restore may republish the plugin header",
        error is None and restored["publish"] and restored["version"] == "0.3.1",
    )

    bad_restore, error = decide(
        header="0.3.1",
        constant="0.3.1",
        catalog="0.5.10",
        restore="0.5.10",
    )
    expect(
        "a restore version that is not the plugin header is refused",
        bad_restore is None and error is not None and "disagree" in error,
    )

    expect(
        "0.5.10 sorts above 0.3.1",
        release.is_higher("0.5.10", "0.3.1") and not release.is_higher("0.3.1", "0.5.10"),
    )

    plugin = ROOT / "reactwoo-support-connector" / "reactwoo-support-connector.php"
    text = plugin.read_text(encoding="utf-8")
    header, constant = release.read_plugin_versions(text)
    expect(
        "the connector header and constant are both 0.3.1",
        header == "0.3.1" and constant == "0.3.1",
    )

    with tempfile.TemporaryDirectory() as tmp:
        zip_path = Path(tmp) / "reactwoo-support-connector.zip"
        with zipfile.ZipFile(zip_path, "w") as archive:
            archive.writestr("reactwoo-support-connector/reactwoo-support-connector.php", text)
        output = Path(tmp) / "github-output.txt"
        completed = subprocess.run(
            [
                sys.executable,
                str(ROOT / "scripts" / "resolve_connector_release.py"),
                "--main",
                str(plugin),
                "--zip",
                str(zip_path),
                "--catalog",
                "0.3.0",
                "--github-output",
                str(output),
            ],
            check=False,
            capture_output=True,
            text=True,
        )
        written = output.read_text(encoding="utf-8") if output.exists() else ""
        expect(
            "the command publishes 0.3.1 when the catalog is older",
            completed.returncode == 0
            and "publish=true" in written
            and "version=0.3.1" in written,
        )

        refused = subprocess.run(
            [
                sys.executable,
                str(ROOT / "scripts" / "resolve_connector_release.py"),
                "--main",
                str(plugin),
                "--requested",
                "0.5.10",
                "--catalog",
                "0.3.1",
            ],
            check=False,
            capture_output=True,
            text=True,
        )
        expect(
            "the command fails when the requested version disagrees",
            refused.returncode == 1 and "disagree" in refused.stderr,
        )

    workflow = (ROOT / ".github" / "workflows" / "publish-update.yml").read_text(encoding="utf-8")
    expect(
        "the publish workflow does not take the catalog version from the git tag",
        "GITHUB_REF_NAME#v" not in workflow
        and "resolve_connector_release.py" in workflow
        and "steps.meta.outputs.version" in workflow,
    )


if __name__ == "__main__":
    main()
