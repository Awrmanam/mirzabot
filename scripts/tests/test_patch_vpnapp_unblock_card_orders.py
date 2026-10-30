import importlib.util
import tempfile
import unittest
from pathlib import Path

SRC=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("checkout", SRC/"patch_vpnapp_unblock_card_orders.py")
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class PendingCheckoutPatch(unittest.TestCase):
    def test_previous_live_checkout_upgraded_once_and_backed_up(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            live=root/"vpnapp.php"
            original="<?php\n// private bot customizations\n"+mod.OLD
            live.write_text(original)
            mod.patch(live,root/"backups")
            self.assertIn(mod.NEW,live.read_text())
            self.assertIn("// private bot customizations",live.read_text())
            mod.patch(live,root/"backups")
            saved=list((root/"backups").iterdir())
            self.assertEqual(len(saved),1)
            self.assertEqual(saved[0].read_text(),original)

    def test_unknown_customized_checkout_is_not_overwritten(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            live=root/"vpnapp.php"
            old="<?php\n"+mod.OLD.replace("function vpnappNewOrder(", "function myCustomOrder(")
            live.write_text(old)
            with self.assertRaises(RuntimeError):
                mod.patch(live,root/"backups")
            self.assertEqual(old,live.read_text())
            self.assertFalse((root/"backups").exists())

if __name__ == "__main__":
    unittest.main()
