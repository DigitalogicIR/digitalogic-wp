"""No-network regressions for the repository's phone-fixture CI guard."""

import contextlib
import importlib.util
import io
import json
import pathlib
import tempfile
import unittest


SCRIPT = pathlib.Path(__file__).resolve().parents[1] / ".github/scripts/check_synthetic_mobile_fixtures.py"
SPEC = importlib.util.spec_from_file_location("mobile_fixture_guard", SCRIPT)
GUARD = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GUARD)
# Split literals deliberately: these synthetic rejection inputs must not themselves
# become prohibited committed phone fixtures under the whole-tree guard.
PHONE = "0912" + "3456780"
DIGEST = "b9d90148" + "23129c4623af94aa943ba6480f5ab481aa079b29d6b596b951a3402f"


class SyntheticMobileFixtureGuardTest(unittest.TestCase):
    def test_exact_deployed_sha256_regression_is_ignored(self):
        self.assertEqual(64, len(DIGEST))
        for key in ("sha256", "deployed_sha256", "source_sha256", "APK_SHA256"):
            for value in (DIGEST, DIGEST.upper()):
                with self.subTest(key=key, value=value):
                    self.assertEqual([], GUARD.violation_lines(json.dumps({key: value})))

    def test_document_fields_are_explicit_and_complete(self):
        for content in (
            f"deployed_sha256: {DIGEST}",
            f"- source_sha256: `{DIGEST}`",
            f"* `source_sha256`: `{DIGEST}`",
        ):
            with self.subTest(content=content):
                self.assertEqual([], GUARD.violation_lines(content))

    def test_unlabelled_or_differently_labelled_hash_stays_scanned(self):
        for content in (
            DIGEST,
            json.dumps({"token": DIGEST}),
            json.dumps({"sha256_note": DIGEST}),
            f"description: {DIGEST}",
            f"source_sha256 = {DIGEST}",
        ):
            with self.subTest(content=content):
                self.assertEqual([1], GUARD.violation_lines(content))

    def test_malformed_digests_never_receive_an_exemption(self):
        for value in (DIGEST[:-1], DIGEST + "a", DIGEST[:-1] + "g", PHONE, DIGEST.replace("9", "۹")):
            with self.subTest(value=value):
                self.assertEqual([1], GUARD.violation_lines(json.dumps({"deployed_sha256": value}, ensure_ascii=False)))

    def test_neighbor_phone_and_line_numbers_are_preserved(self):
        content = json.dumps({"deployed_sha256": DIGEST, "phone": PHONE}, indent=2)
        self.assertEqual([3], GUARD.violation_lines(content))
        content = f"- source_sha256: `{DIGEST}`\nphone: {PHONE}\n"
        self.assertEqual([2], GUARD.violation_lines(content))
        content = json.dumps({"deployed_sha256": DIGEST + " " + PHONE})
        self.assertEqual([1, 1], GUARD.violation_lines(content))

    def test_phone_normalization_and_original_synthetic_allowlist_are_preserved(self):
        for prefix in ("0", "98", "+98", "0098", ""):
            number = prefix + PHONE[1:]
            for digits in ("0123456789", "۰۱۲۳۴۵۶۷۸۹", "٠١٢٣٤٥٦٧٨٩"):
                translated = number.translate(str.maketrans("0123456789", digits))
                with self.subTest(prefix=prefix, digits=digits):
                    self.assertEqual([1], GUARD.violation_lines(translated))
        for number in GUARD.SYNTHETIC_MOBILES:
            self.assertEqual([], GUARD.violation_lines(number))
            self.assertEqual([], GUARD.violation_lines("+98" + number[1:]))
        self.assertEqual([], GUARD.violation_lines("1" + PHONE + "1"))

    def test_scan_does_not_exempt_docs_and_retains_dependency_exclusions(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            for directory in ("docs", ".git", "vendor", "node_modules"):
                (root / directory).mkdir()
                (root / directory / "fixture.txt").write_text(PHONE, encoding="utf-8")
            (root / "docs/checkpoint.json").write_text(json.dumps({"deployed_sha256": DIGEST}), encoding="utf-8")
            (root / "binary.bin").write_bytes(b"\xff\xfe")
            self.assertEqual([str(pathlib.Path("docs/fixture.txt")) + ":1"], GUARD.scan(root))

    def test_cli_reports_only_paths_and_never_phone_values(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = pathlib.Path(temporary)
            self.assertEqual(0, GUARD.main(["--root", temporary]))
            (root / "fixture.txt").write_text(PHONE, encoding="utf-8")
            error = io.StringIO()
            with contextlib.redirect_stderr(error):
                self.assertEqual(1, GUARD.main(["--root", temporary]))
            self.assertIn("fixture.txt:1", error.getvalue())
            self.assertNotIn(PHONE, error.getvalue())


if __name__ == "__main__":
    unittest.main()
