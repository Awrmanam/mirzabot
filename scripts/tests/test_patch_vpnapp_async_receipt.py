import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "async_receipt", ROOT / "patch_vpnapp_async_receipt.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def code(versions):
    return "<?php\n// Keep customized Mirza bot code untouched.\n" + "\n".join(
        new if use_new else old
        for (_, old, new), use_new in zip(mod.CHANGES, versions)
    ) + "\n"


class AsyncReceiptPatchTests(unittest.TestCase):
    def test_atomic_upgrade_partial_and_repeat_without_database_changes(self):
        for states in [(False,)*4, (True, False, True, False), (True,)*4]:
            with self.subTest(states=states), tempfile.TemporaryDirectory() as d:
                root=Path(d)
                php=root/"vpnapp.php"
                original=code(states)
                php.write_text(original)
                mod.patch(php,root/"backup")
                self.assertIn("Keep customized Mirza bot code untouched",php.read_text())
                for _,old,new in mod.CHANGES:
                    self.assertIn(new,php.read_text())
                mod.patch(php,root/"backup")
                backups=list((root/"backup").iterdir()) if (root/"backup").exists() else []
                self.assertEqual(len(backups), 0 if all(states) else 1)
                if backups:
                    self.assertEqual(backups[0].read_text(), original)

    def test_unexpected_live_function_prevents_partial_write(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            php=root/"vpnapp.php"
            old=code((False,)*4).replace("function vpnappSubmitReceipt(", "function modifiedReceipt(")
            php.write_text(old)
            with self.assertRaises(RuntimeError):
                mod.patch(php,root/"backup")
            self.assertEqual(php.read_text(),old)
            self.assertFalse((root/"backup").exists())


if __name__=="__main__":
    unittest.main()
