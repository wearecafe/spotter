#!/usr/bin/env python3
"""Checks the project's living docs. Python 3.9+, standard library only.

    python3 scripts/docs_check.py                      # all checks
    python3 scripts/docs_check.py --require-docs main  # also: code changed since main => docs/claude changed

Fails (exit 1) when:
- CLAUDE.md is over its size budget, links a docs/claude file that doesn't
  exist, or leaves a docs/claude/modules/*.md file out of its table;
- a decision file in docs/claude/decisions/ has a bad name or is missing its
  title, Status, Date, Area, Decided by or **Decision:** lines;
- a UAT row has a bare number, a repeated ID, or a code that isn't in the
  registry (docs/claude/demo-and-uat.md -> "UAT row IDs");
- with --require-docs BASE: files outside docs/ changed since BASE but
  nothing under docs/claude/ did.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOCS = ROOT / "docs" / "claude"
CLAUDE_MD = ROOT / "CLAUDE.md"
MAX_BYTES = 16 * 1024
STATUSES = ("proposed", "confirmed", "reversed", "superseded")
NOT_DECISIONS = {"README.md", "TEMPLATE.md", "pending.md"}
UAT_ID = re.compile(r"^([A-Z]{1,4})-[A-Z0-9]+[a-z]?(?: \(was [A-Z0-9-]+\))?$")
UAT_HEADER = re.compile(r"^\|\s*(#|ID|Row)\s*\|")

problems: list[str] = []


def fail(message: str) -> None:
    problems.append(message)


def check_claude_md() -> None:
    if not CLAUDE_MD.exists():
        fail("CLAUDE.md is missing.")
        return
    text = CLAUDE_MD.read_text(encoding="utf-8")
    size = len(text.encode("utf-8"))
    if size > MAX_BYTES:
        fail(f"CLAUDE.md is {size} bytes (budget {MAX_BYTES}). Move module detail into docs/claude/modules/.")
    for path in sorted(set(re.findall(r"docs/claude/[A-Za-z0-9_./-]+\.md", text))):
        if not (ROOT / path).exists():
            fail(f"CLAUDE.md links {path}, which doesn't exist.")
    for notes in sorted((DOCS / "modules").glob("*.md")):
        if notes.name == "README.md":
            continue
        rel = f"docs/claude/modules/{notes.name}"
        if rel not in text:
            fail(f"{rel} isn't linked from CLAUDE.md's Module notes table.")


def check_decisions() -> None:
    folder = DOCS / "decisions"
    if not folder.exists():
        fail("docs/claude/decisions/ is missing.")
        return
    for file in sorted(folder.glob("*.md")):
        if file.name in NOT_DECISIONS:
            continue
        text = file.read_text(encoding="utf-8")
        name = f"decisions/{file.name}"
        if not re.fullmatch(r"\d{4}-\d{2}-\d{2}-[a-z0-9-]+\.md", file.name):
            fail(f"{name} should be named YYYY-MM-DD-short-slug.md.")
        if not re.match(r"# \S", text):
            fail(f"{name} should start with a # title.")
        if not re.search(rf"^Status: ({'|'.join(STATUSES)})$", text, re.M):
            fail(f"{name} needs a Status line: {', '.join(STATUSES)}.")
        if not re.search(r"^Date: \d{4}-\d{2}-\d{2}$", text, re.M):
            fail(f"{name} needs a Date line (YYYY-MM-DD).")
        if not re.search(r"^Area: \S", text, re.M):
            fail(f"{name} needs an Area line.")
        if not re.search(r"^Decided by: \S", text, re.M):
            fail(f"{name} needs a Decided by line.")
        if "**Decision:**" not in text:
            fail(f"{name} needs a **Decision:** section.")


def registered_codes() -> set[str]:
    registry = DOCS / "demo-and-uat.md"
    if not registry.exists():
        return set()
    text = registry.read_text(encoding="utf-8")
    section = text.split("## UAT row IDs", 1)[-1].split("\n## ", 1)[0]
    codes: set[str] = set()
    for line in section.splitlines():
        cells = [c.strip() for c in line.split("|")]
        if len(cells) > 2 and cells[1] and cells[1] != "Code" and not cells[1].startswith("-"):
            codes.update(c.strip() for c in cells[1].split(","))
    return codes


def check_uat() -> None:
    codes = registered_codes()
    seen: dict[str, str] = {}
    for file in sorted(DOCS.glob("*.md")):
        header = None
        for line in file.read_text(encoding="utf-8").splitlines():
            if not line.startswith("|"):
                header = None
                continue
            if header is None:
                header = line
                continue
            if re.match(r"^\|\s*:?-", line):
                continue
            if not (UAT_HEADER.match(header) and ("Result" in header or "check" in header.lower())):
                continue
            cell = line.split("|")[1].strip()
            if not cell or cell.startswith("(") or " to " in cell:
                continue  # pointer rows like "(P-2)" or "HM-18 to HM-22"
            match = UAT_ID.match(cell)
            if not match:
                fail(f'UAT row "{cell}" in {file.name} needs a feature ID like SU-12.')
                continue
            uat_id = cell.split(" ")[0]
            if codes and match.group(1) not in codes:
                fail(f"UAT {uat_id} in {file.name} uses code {match.group(1)}, which isn't in demo-and-uat.md -> UAT row IDs.")
            if uat_id in seen:
                fail(f"UAT {uat_id} is in both {seen[uat_id]} and {file.name}.")
            seen[uat_id] = file.name


def check_docs_changed(base: str) -> None:
    try:
        changed = subprocess.run(
            ["git", "diff", "--name-only", f"{base}...HEAD"],
            cwd=ROOT, check=True, capture_output=True, text=True,
        ).stdout.split()
    except subprocess.CalledProcessError as error:
        fail(f"Couldn't diff against {base}: {error.stderr.strip()}")
        return
    code = [p for p in changed if not p.startswith("docs/") and not p.endswith(".md")]
    docs = [p for p in changed if p.startswith("docs/claude/")]
    if code and not docs:
        fail("Code changed but nothing under docs/claude/ did. Add the decision files, phase doc, UAT rows or module notes this change needs.")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--require-docs", metavar="BASE", help="fail if code changed since BASE without a docs/claude change")
    args = parser.parse_args()

    check_claude_md()
    check_decisions()
    check_uat()
    if args.require_docs:
        check_docs_changed(args.require_docs)

    for problem in problems:
        print(f"✗ {problem}")
    if problems:
        print(f"\n{len(problems)} problem(s). See docs/claude/README.md for how the docs are laid out.")
        return 1
    print("✓ Docs check passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
