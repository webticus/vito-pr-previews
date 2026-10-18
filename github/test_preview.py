import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("preview", Path(__file__).with_name("preview.py"))
preview = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preview)


class FakeVito:
    def __init__(self, status, log):
        self.status = status
        self.log = log

    def request(self, path, method="GET", payload=None, raw=False):
        if method == "POST":
            return {"id": 10}
        if path.endswith("/log"):
            return self.log
        return {"status": self.status}


class PreviewTests(unittest.TestCase):
    def test_rejects_forks_before_sending_code_to_server(self):
        with self.assertRaisesRegex(ValueError, "restricted"):
            preview.validate_pull_request({"head": {"repo": {"full_name": "other/burzsait"}, "sha": "a" * 40}}, "webticus/burzsait")

    def test_rejects_shell_input_as_pull_request_number(self):
        for number in ["1;id", "../1", "0", "-1", "1\n2"]:
            with self.subTest(number=number), self.assertRaises(ValueError):
                preview.pull_request_number({}, number)

    def test_vito_completed_with_action_failure_is_a_failed_deployment(self):
        vito = FakeVito("completed", "Workflow action failed: build failed")
        with self.assertRaisesRegex(RuntimeError, "failed"):
            preview.run_vito(vito, 2, 1, "deploy", 12, "a" * 40)

    def test_missing_success_marker_is_a_failed_deployment(self):
        with self.assertRaisesRegex(RuntimeError, "failed"):
            preview.run_vito(FakeVito("completed", "Done"), 2, 1, "deploy", 12, "a" * 40)

    def test_accepts_only_the_current_request_success_marker(self):
        request_id = "b" * 32
        marker = f"PREVIEW_OK:{request_id}:deploy:12:{'a' * 40}"
        with patch.object(preview.uuid, "uuid4") as generated:
            generated.return_value.hex = request_id
            self.assertEqual(preview.run_vito(FakeVito("completed", marker), 2, 1, "deploy", 12, "a" * 40), 10)
            with self.assertRaises(RuntimeError):
                preview.run_vito(FakeVito("completed", marker.replace(request_id, "c" * 32)), 2, 1, "deploy", 12, "a" * 40)

    def test_access_secret_survives_updates_and_is_distinct_per_pr(self):
        secret = preview.maintenance_secret("api-token", "example/app", 12)
        self.assertEqual(secret, preview.maintenance_secret("api-token", "example/app", 12))
        self.assertNotEqual(secret, preview.maintenance_secret("api-token", "example/app", 13))
        self.assertNotEqual(secret, preview.maintenance_secret("other-token", "example/app", 12))

    def test_updates_bot_comment_without_overwriting_human_comments(self):
        from unittest.mock import Mock
        github = Mock()
        github.request.return_value = [
            {"id": 1, "user": {"login": "human"}, "body": "<!-- vito-pr-preview --> human note"},
            {"id": 2, "user": {"login": "github-actions[bot]"}, "body": "<!-- vito-pr-preview --> old preview"},
        ]
        preview.preview_comment(github, "example/app", 12, "https://preview.example.com", "secret", "a" * 40)
        self.assertEqual(github.request.call_args.args[:2], ("/repos/example/app/issues/comments/2", "PATCH"))
        self.assertIn("https://preview.example.com/secret", github.request.call_args.args[2]["body"])
        preview.preview_comment(github, "example/app", 12)
        self.assertNotIn("https://", github.request.call_args.args[2]["body"])

    def test_timeout_does_not_report_success(self):
        with self.assertRaisesRegex(RuntimeError, "timed out"):
            preview.run_vito(FakeVito("running", ""), 2, 1, "deploy", 12, "a" * 40, timeout=0)


if __name__ == "__main__":
    unittest.main()
