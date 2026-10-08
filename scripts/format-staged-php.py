#!/usr/bin/env python3
"""Format staged PHP blobs without adding unstaged changes to the index."""

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent


def git(*args, input_bytes=None):
    return subprocess.run(
        ["git", *args], cwd=ROOT, input=input_bytes, capture_output=True, check=True
    ).stdout


def staged_php_paths():
    paths = git("diff", "--cached", "--name-only", "-z", "--diff-filter=ACMR", "--", "*.php")
    config = json.loads((ROOT / "pint.json").read_text())
    excluded_paths = set(config.get("notPath", []))
    excluded_dirs = tuple(path.rstrip("/") + "/" for path in config.get("exclude", []))

    return [
        os.fsdecode(path)
        for path in paths.split(b"\0")
        if path
        and os.fsdecode(path) not in excluded_paths
        and not os.fsdecode(path).startswith(excluded_dirs)
    ]


def stage_mode(path):
    entries = git("ls-files", "--stage", "-z", "--", path).split(b"\0")
    entries = [entry for entry in entries if entry]
    if len(entries) != 1:
        raise RuntimeError(f"Cannot format conflicted index entry: {path}")
    mode, _, stage = entries[0].split(b"\t", 1)[0].split()
    if stage != b"0" or mode not in (b"100644", b"100755"):
        raise RuntimeError(f"Cannot format non-regular staged file: {path}")
    return mode.decode()


def main():
    paths = staged_php_paths()
    if not paths:
        print("No staged PHP files to format.")
        return

    with tempfile.TemporaryDirectory(prefix="export-pint-") as directory:
        temporary_root = Path(directory)
        original = {}
        for path in paths:
            content = git("show", f":{path}")
            original[path] = content
            temporary_path = temporary_root / path
            temporary_path.parent.mkdir(parents=True, exist_ok=True)
            temporary_path.write_bytes(content)

        result = subprocess.run(
            ["composer", "format", "--", f"--config={ROOT / 'pint.json'}", *[str(temporary_root / path) for path in paths]],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        if result.returncode:
            raise RuntimeError("Pint failed:\n" + (result.stdout + result.stderr).strip())

        changed = 0
        partial = 0
        for path in paths:
            formatted = (temporary_root / path).read_bytes()
            if formatted == original[path]:
                continue
            if git("show", f":{path}") != original[path]:
                raise RuntimeError(f"Index changed while formatting: {path}")

            worktree_path = ROOT / path
            if not worktree_path.is_symlink() and worktree_path.is_file() and worktree_path.read_bytes() == original[path]:
                worktree_path.write_bytes(formatted)
                git("add", "--", path)
            else:
                blob = git("hash-object", "-w", "--stdin", input_bytes=formatted).strip().decode()
                git("update-index", "--cacheinfo", stage_mode(path), blob, path)
                partial += 1
            changed += 1

        print(f"Pint formatted {changed} staged PHP file(s).")
        if partial:
            print(f"Preserved unstaged edits in {partial} partially staged file(s); formatting is in the index.")


if __name__ == "__main__":
    try:
        main()
    except (OSError, subprocess.CalledProcessError, ValueError, RuntimeError) as error:
        print(f"Pre-commit formatting failed: {error}", file=sys.stderr)
        sys.exit(1)
