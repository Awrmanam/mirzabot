import importlib.util
import tempfile
import unittest
from pathlib import Path

PATH = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("clock_patch", PATH/"fix_auto_confirm_clock.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def sample():
    return ("<?php\n// keep custom Mirza code\n"
        "function vpnappSubmitReceipt() {\n"
        "    $update = new stdClass();\n"
        "    "+mod.OLD+"\n"
        "}\nfunction anotherFunction() { return 7; }\n")

class TimezoneAutoConfirmSafety(unittest.TestCase):
    def test_patch_once_preserves_other_code_and_backup(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); f=root/"vpnapp.php"
            f.write_text(sample())
            mod.patch(f,root/"backups")
            self.assertIn(mod.NEW,f.read_text())
            self.assertIn("keep custom Mirza code",f.read_text())
            self.assertIn("anotherFunction",f.read_text())
            mod.patch(f,root/"backups")
            files=list((root/"backups").iterdir())
            self.assertEqual(len(files),1)
            self.assertEqual(files[0].read_text(),sample())

    def test_unexpected_custom_timestamp_aborts_without_write(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); f=root/"vpnapp.php"
            original=sample().replace("date('Y/m/d H:i:s')","myCustomTimestamp()")
            f.write_text(original)
            with self.assertRaises(RuntimeError):
                mod.patch(f,root/"backups")
            self.assertEqual(f.read_text(),original)
            self.assertFalse((root/"backups").exists())

if __name__=="__main__":
    unittest.main()
