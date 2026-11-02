"""Build a deterministic installable ZIP from the runtime files in this checkout."""
import argparse
import hashlib
from pathlib import Path
import re
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


def build(root, output):
    slug = "nova-wordpress-plugin"
    entry = (root / (slug + ".php")).read_text(encoding="utf-8")
    version = re.search(r"^ \* Version: (\d+\.\d+\.\d+)$", entry, re.MULTILINE).group(1)
    runtime = [root / name for name in (slug + ".php", "readme.txt", "LICENSE.txt", "wpml-config.xml")]
    for directory in ("includes", "modules"):
        runtime.extend(p for p in (root / directory).rglob("*") if p.is_file() and "tests" not in p.relative_to(root).parts)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f"{slug}-{version}.zip"
    with ZipFile(archive, "w") as writer:
        for path in sorted(runtime, key=lambda p: p.relative_to(root).as_posix()):
            relative = path.relative_to(root)
            if path.is_symlink() or any(part.startswith(".") for part in relative.parts) or path.suffix.lower() in {".zip", ".phar", ".pem", ".key", ".sql", ".tar"}:
                raise ValueError(f"Unexpected runtime file: {relative}")
            info = ZipInfo(f"{slug}/{relative.as_posix()}", (2026, 11, 2, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = ZIP_DEFLATED
            writer.writestr(info, path.read_bytes())
    with ZipFile(archive) as check:
        if check.testzip() is not None:
            raise ValueError("Damaged release archive")
    return archive


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("output_directory", type=Path)
    archive = build(Path(__file__).resolve().parents[1], parser.parse_args().output_directory)
    print(f"{hashlib.sha256(archive.read_bytes()).hexdigest()}  {archive}")
