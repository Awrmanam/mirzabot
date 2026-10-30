import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("legacy", ROOT/"patch_vpnapp_legacy_receipts.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class LegacyReceiptRecovery(unittest.TestCase):
    def test_migrate_handler_only_and_backup(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            php=root/"vpnapp.php"
            original="<?php\n// custom mirza declarations\n"+module.OLD
            php.write_text(original)
            module.patch(php,root/"backups")
            self.assertIn(module.NEW,php.read_text())
            self.assertIn("// custom mirza declarations",php.read_text())
            module.patch(php,root/"backups")
            backs=list((root/"backups").iterdir())
            self.assertEqual(len(backs),1)
            self.assertEqual(backs[0].read_text(),original)

    def test_unrecognized_handler_is_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);p=root/"vpnapp.php"
            raw="<?php\n"+module.OLD.replace("function vpnappPrivateReceiptDir()", "function changedPrivateReceiptDir()")
            p.write_text(raw)
            with self.assertRaises(RuntimeError):
                module.patch(p,root/"backups")
            self.assertEqual(p.read_text(),raw)

if __name__=="__main__":
    unittest.main()
