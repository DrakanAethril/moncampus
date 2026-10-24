#!/usr/bin/env python3
"""Tests for the release files check.

On a release commit this check is all CI runs, so what it lets through reaches production.

Run: python3 -m unittest discover -s .github/scripts -p 'test_*.py'
"""

import datetime
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

import release_files  # noqa: E402


def changelog(*releases):
    return {"releases": list(releases)}


def release(version="2026.10.22", date="2026-10-22", summary="Une phrase.", entries=None):
    return {
        "version": version,
        "date": date,
        "summary": summary,
        "entries": entries if entries is not None else [{"type": "fix", "title": "Une ligne"}],
    }


class ChangelogTest(unittest.TestCase):
    def test_a_well_formed_release_passes(self):
        self.assertEqual([], release_files.changelog_problems(changelog(release(), release("2026.10.21.2", "2026-10-21"))))

    def test_an_unquoted_date_read_as_a_date_passes(self):
        self.assertEqual([], release_files.changelog_problems(changelog(release(date=datetime.date(2026, 10, 22)))))

    def test_a_version_that_is_not_calver_is_refused(self):
        self.assertTrue(release_files.changelog_problems(changelog(release(version="v1.2"))))

    def test_a_version_yaml_read_as_a_number_is_refused(self):
        """An unquoted 2026.10 is a float: the page would then show 2026.1."""
        self.assertTrue(release_files.changelog_problems(changelog(release(version=2026.10))))

    def test_the_same_version_twice_is_refused(self):
        problems = release_files.changelog_problems(changelog(release(), release()))

        self.assertEqual(1, len(problems))
        self.assertIn("twice", problems[0])

    def test_an_impossible_date_is_refused(self):
        self.assertTrue(release_files.changelog_problems(changelog(release(date="2026-02-30"))))

    def test_a_release_without_summary_or_entries_is_refused(self):
        self.assertEqual(2, len(release_files.changelog_problems(changelog(release(summary=" ", entries=[])))))

    def test_an_unknown_type_is_refused(self):
        """The page would quietly file it under « autre »: a gate must not."""
        problems = release_files.changelog_problems(changelog(release(entries=[{"type": "feature", "title": "x"}])))

        self.assertEqual(1, len(problems))
        self.assertIn("feature", problems[0])

    def test_an_entry_without_title_is_refused(self):
        self.assertTrue(release_files.changelog_problems(changelog(release(entries=[{"type": "fix"}]))))

    def test_an_empty_file_is_refused(self):
        self.assertTrue(release_files.changelog_problems(None))
        self.assertTrue(release_files.changelog_problems({"releases": []}))


class TechProfileTest(unittest.TestCase):
    def test_a_well_formed_profile_passes(self):
        self.assertEqual([], release_files.tech_profile_problems({"measured_at": "2026-10-22", "commits": 2939, "tests": 4489}))

    def test_counts_must_be_positive_integers(self):
        problems = release_files.tech_profile_problems({"measured_at": "2026-10-22", "commits": "2939", "tests": 0})

        self.assertEqual(2, len(problems))

    def test_a_missing_date_is_refused(self):
        self.assertTrue(release_files.tech_profile_problems({"commits": 1, "tests": 1}))


class RepositoryFilesTest(unittest.TestCase):
    def test_the_files_in_the_repository_pass(self):
        """The whole history back to 2026-07-05 has to pass the rule, not only the next release."""
        import yaml

        root = Path(__file__).resolve().parents[2]
        with open(root / release_files.CHANGELOG, encoding="utf-8") as handle:
            self.assertEqual([], release_files.changelog_problems(yaml.safe_load(handle)))
        with open(root / release_files.TECH_PROFILE, encoding="utf-8") as handle:
            self.assertEqual([], release_files.tech_profile_problems(yaml.safe_load(handle)))


if __name__ == "__main__":
    unittest.main()
