"""Run the local PHP and Node suites. Requires NOVA_WP_CORE_FIXTURE pointing to WordPress wp-includes."""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import os
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
fixture = Path(os.environ.get("NOVA_WP_CORE_FIXTURE", ""))
for name in ("blocks.php", "shortcodes.php", "class-wp-block-parser.php"):
    if not (fixture / name).is_file():
        sys.exit("Set NOVA_WP_CORE_FIXTURE to a local WordPress wp-includes directory first.")
php = os.environ.get("PHP", "php")
tests = sorted(p for p in root.rglob("*-unit.*") if "tests" in p.parts and p.suffix in (".php", ".cjs"))


def run(path):
    cmd = [php, "-n", str(path)] if path.suffix == ".php" else ["node", "--test", str(path)]
    result = subprocess.run(cmd, cwd=root, capture_output=True, encoding="utf8", errors="replace", timeout=120)
    output = result.stdout + result.stderr
    passed = result.returncode == 0 and not any(term in output for term in ("SKIP:", "Warning:", "Fatal error:", "Deprecated:"))
    return path.relative_to(root), passed, output


with ThreadPoolExecutor(max_workers=4) as pool:
    results = list(pool.map(run, tests))
for path, passed, output in results:
    if not passed:
        print(path, output)
print(f"{sum(passed for _, passed, _ in results)}/{len(results)} local suites passed")
sys.exit(0 if results and all(passed for _, passed, _ in results) else 1)
