#!/usr/bin/env python3
"""Reject non-synthetic mobile numbers, without mistaking labelled SHA-256 for one."""

import argparse
import pathlib
import re
import sys


EXCLUDED = {".git", "vendor", "node_modules"}
# Existing SMS CLI examples plus the PBX test number; all are overtly synthetic.
SYNTHETIC_MOBILES = {"09120000000", "09120000001", "09123456789"}
DIGIT_MAP = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
CANDIDATE = re.compile(r"(?<!\d)(?:\+98|0098|98|0)?9\d{9}(?!\d)")

# Only complete ASCII hashes in explicitly named fields are metadata. Do not
# exempt whole documents, arbitrary hex strings, or all strings near a hash.
SHA256_FIELD = r"(?:[A-Za-z_][A-Za-z0-9_-]*)?sha256"
JSON_SHA256 = re.compile(
    rf'"{SHA256_FIELD}"\s*:\s*"(?P<digest>[A-Fa-f0-9]{{64}})"'
    r"(?=\s*(?:[,}\]]|$))",
    re.IGNORECASE | re.MULTILINE,
)
DOCUMENT_SHA256 = re.compile(
    rf"^[ \t]*(?:[-*][ \t]+)?(?:{SHA256_FIELD}|`{SHA256_FIELD}`)[ \t]*:[ \t]*"
    r"(?:`(?P<quoted>[A-Fa-f0-9]{64})`|(?P<plain>[A-Fa-f0-9]{64}))[ \t]*$",
    re.IGNORECASE | re.MULTILINE,
)


def mask_sha256_fields(content: str) -> str:
    """Mask only exact digest spans, retaining original line/character offsets."""
    spans = []
    for pattern in (JSON_SHA256, DOCUMENT_SHA256):
        for match in pattern.finditer(content):
            for name in pattern.groupindex:
                if match.group(name) is not None:
                    spans.append(match.span(name))
    if not spans:
        return content
    characters = list(content)
    for start, end in spans:
        characters[start:end] = " " * (end - start)
    return "".join(characters)


def violation_lines(content: str) -> list[int]:
    """Return violation line numbers, never phone values or matched content."""
    # Validate hash ASCII syntax before applying Persian/Arabic normalization.
    content = mask_sha256_fields(content).translate(DIGIT_MAP)
    violations = []
    for match in CANDIDATE.finditer(content):
        digits = re.sub(r"\D", "", match.group())
        if digits.startswith("0098"):
            digits = digits[4:]
        elif digits.startswith("98"):
            digits = digits[2:]
        if len(digits) == 10 and digits.startswith("9"):
            digits = "0" + digits
        if digits not in SYNTHETIC_MOBILES:
            violations.append(content.count("\n", 0, match.start()) + 1)
    return violations


def scan(root: pathlib.Path) -> list[str]:
    """Apply the original whole-tree scan and dependency exclusions."""
    violations = []
    for path in root.rglob("*"):
        relative = path.relative_to(root)
        if not path.is_file() or any(part in EXCLUDED for part in relative.parts):
            continue
        try:
            content = path.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError):
            continue
        violations.extend(f"{relative}:{line}" for line in violation_lines(content))
    return violations


def main(argv: list[str] | None = None) -> int:
    """Run from the repository root; exit 1 only when violations are found."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=pathlib.Path, default=pathlib.Path("."))
    args = parser.parse_args(argv)
    if not args.root.is_dir():
        parser.error("scan root must be an existing directory")
    violations = scan(args.root)
    if violations:
        print(
            "Non-synthetic mobile fixture found at: " + ", ".join(violations),
            file=sys.stderr,
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
