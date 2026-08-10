#!/usr/bin/env python3
"""Tests for the release announcer.

This script is the only piece of logic in the repository that runs nowhere but in CI, and on
2026-08-10 it announced 2026.08.10 while the application showed 2026.08.11 - two releases shared a
date and nothing broke the tie. What App\\Tests\\Service\\ChangelogTest pins on the PHP side is pinned
here too: the page and the Discord message read the same file and must name the same release.

Run: python3 -m unittest discover -s .github/scripts -p 'test_*.py'
"""

import json
import os
import sys
import unittest
import urllib.error
import urllib.request
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

import announce_release  # noqa: E402


def release(version, date):
    return {"version": version, "date": date}


class LatestReleaseTest(unittest.TestCase):
    def test_the_version_breaks_a_tie_on_the_date(self):
        """Two deploys in one day is ordinary - the CalVer rank is monthly, not daily."""
        latest = announce_release.latest_release([
            release("2026.08.9", "2026-08-10"),
            release("2026.08.11", "2026-08-10"),
            release("2026.08.10", "2026-08-10"),
        ])

        self.assertEqual("2026.08.11", latest["version"])

    def test_versions_are_compared_as_numbers_not_as_strings(self):
        """As plain strings "2026.08.9" sorts above "2026.08.11" - which is how the bug read."""
        latest = announce_release.latest_release([
            release("2026.08.11", "2026-08-10"),
            release("2026.08.9", "2026-08-10"),
        ])

        self.assertEqual("2026.08.11", latest["version"])

    def test_a_later_date_wins_over_a_higher_version(self):
        latest = announce_release.latest_release([
            release("2026.09.1", "2026-09-01"),
            release("2026.08.42", "2026-08-31"),
        ])

        self.assertEqual("2026.09.1", latest["version"])

    def test_no_release_at_all_answers_none(self):
        self.assertIsNone(announce_release.latest_release([]))


class MessageTest(unittest.TestCase):
    RELEASE = {
        "version": "2026.08.11",
        "date": "2026-08-10",
        "summary": "Un résumé.",
        "entries": [
            {"type": "nouveaute", "title": "Une nouveauté"},
            {"type": "interne", "title": "PHPStan niveau 5"},
            {"type": "fix", "title": "Une correction"},
        ],
    }

    def test_the_success_message_leaves_internal_entries_out(self):
        """They are the code's own business and say nothing to a teacher reading the channel."""
        body = "\n".join(announce_release.message_success("2026.08.11", self.RELEASE))

        self.assertIn("Une nouveauté", body)
        self.assertIn("Une correction", body)
        self.assertNotIn("PHPStan", body)

    def test_the_success_message_carries_the_version_and_the_summary(self):
        body = "\n".join(announce_release.message_success("2026.08.11", self.RELEASE))

        self.assertIn("2026.08.11", body)
        self.assertIn("Un résumé.", body)

    def test_the_failure_message_does_not_claim_the_previous_version_is_still_running(self):
        """deploy-prod.sh pulls then restarts: a failure can leave production part-way."""
        body = "\n".join(announce_release.message_failure("2026.08.11", self.RELEASE))

        self.assertIn("échoué", body)
        self.assertNotIn("précédente", body)


class SoftFailureTest(unittest.TestCase):
    """Nothing this script does may turn a successful deploy red."""

    def setUp(self):
        self.environment = dict(os.environ)
        self.urlopen = urllib.request.urlopen

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self.environment)
        urllib.request.urlopen = self.urlopen

    def test_no_webhook_exits_zero(self):
        os.environ.pop("DISCORD_WEBHOOK", None)
        sys.argv = ["announce_release.py", "--phase", "success"]

        with self.assertRaises(SystemExit) as exit_code:
            announce_release.main()

        self.assertEqual(0, exit_code.exception.code)

    def test_an_unreachable_discord_exits_zero(self):
        os.environ["DISCORD_WEBHOOK"] = "https://discord.invalid/hook"
        sys.argv = ["announce_release.py", "--phase", "success"]
        urllib.request.urlopen = self.raise_url_error

        with self.assertRaises(SystemExit) as exit_code:
            announce_release.main()

        self.assertEqual(0, exit_code.exception.code)

    @staticmethod
    def raise_url_error(request, timeout=0):
        raise urllib.error.URLError("unreachable")


class RealChangelogTest(unittest.TestCase):
    """Run against the file the deploy will actually read - a malformed release fails here first."""

    def setUp(self):
        self.environment = dict(os.environ)
        self.urlopen = urllib.request.urlopen
        self.cwd = os.getcwd()
        os.chdir(Path(__file__).resolve().parents[2])

    def tearDown(self):
        os.chdir(self.cwd)
        os.environ.clear()
        os.environ.update(self.environment)
        urllib.request.urlopen = self.urlopen

    def test_it_announces_the_release_the_changelog_calls_current(self):
        import yaml

        with open("config/changelog.yaml", encoding="utf-8") as handle:
            expected = announce_release.latest_release(yaml.safe_load(handle)["releases"])

        os.environ["DISCORD_WEBHOOK"] = "https://discord.test/hook"
        os.environ.pop("APP_URL", None)
        sys.argv = ["announce_release.py", "--phase", "success"]

        captured = {}
        urllib.request.urlopen = self.capture(captured)

        # main() returns normally on the happy path; only fail_soft() exits.
        announce_release.main()

        self.assertIn(expected["version"], captured["body"])

    @staticmethod
    def capture(captured):
        class Response:
            status = 204

            def __enter__(self):
                return self

            def __exit__(self, *args):
                return False

        def urlopen(request, timeout=0):
            captured["body"] = json.loads(request.data.decode())["content"]

            return Response()

        return urlopen


if __name__ == "__main__":
    unittest.main()
