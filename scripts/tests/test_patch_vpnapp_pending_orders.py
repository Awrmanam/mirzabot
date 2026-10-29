import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("live_checkout_patch", ROOT / "patch_vpnapp_pending_orders.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PendingCheckoutPatchTests(unittest.TestCase):
    def test_only_guard_changes_and_original_is_backed_up(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            php = root / "vpnapp.php"
            original = "<?php\n// Original custom app bridge\nfunction keepThis() {}\n" + module.OLD + "\n"
            php.write_text(original)
            backup = module.patch(php, root / "backups")
            self.assertEqual(backup.read_text(), original)
            changed = php.read_text()
            self.assertIn("function keepThis() {}", changed)
            self.assertIn(module.NEW, changed)
            self.assertNotIn(module.OLD, changed)

    def test_unknown_guard_rejects_without_modifying_target(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            php = root / "vpnapp.php"
            old = "<?php\n// custom conflicting checkout guard\n"
            php.write_text(old)
            with self.assertRaises(RuntimeError):
                module.patch(php, root / "backups")
            self.assertEqual(php.read_text(), old)
            self.assertFalse((root / "backups").exists())


if __name__ == "__main__":
    unittest.main()
