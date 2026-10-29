import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "mirza_receipts_patch", ROOT / "patch_vpnapp_telegram_receipts.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TestProductionReceiptPatch(unittest.TestCase):
    def test_changes_only_two_exact_known_handlers_with_backup(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            live = root / "vpnapp.php"
            original = "<?php\n// Customized live code must survive\n" + module.OLD + (
                "\n// Custom logic remains intact\n") + module.RECEIPT_OLD
            live.write_text(original)
            backup = module.patch(live, root / "backups")
            self.assertEqual(backup.read_text(), original)
            modified = live.read_text()
            self.assertIn("Customized live code must survive", modified)
            self.assertIn("Custom logic remains intact", modified)
            self.assertIn(module.NEW, modified)
            self.assertIn(module.RECEIPT_NEW, modified)
            self.assertNotIn(module.OLD, modified)

    def test_mismatched_handler_aborts_without_any_update(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            live = root / "vpnapp.php"
            original = "<?php\n" + module.OLD + "\nfunction customer_receipt() {}"
            live.write_text(original)
            with self.assertRaises(RuntimeError):
                module.patch(live, root / "backups")
            self.assertEqual(live.read_text(), original)
            self.assertFalse((root / "backups").exists())


if __name__ == "__main__":
    unittest.main()
