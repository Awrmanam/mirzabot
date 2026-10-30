import importlib.util
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("collation_patch",
    SCRIPTS / "patch_vpnapp_snapshot_collations.py")
patcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)


def snapshot(fixed_first=False, fixed_second=False):
    body = "<?php\\n// preserve unrelated custom logic\\n" + patcher.START + '\\n  $sql = "SELECT 1\\n'
    for i, (old, new) in enumerate(patcher.FIXES):
        body += (new if (fixed_first, fixed_second)[i] else old) + "\\n"
    return body + '";\\n}\\n' + patcher.END + " && MIRZA_VPNAPP_LIBRARY_ONLY) return;\\n"

class SnapshotCollationPatchTests(unittest.TestCase):
    def test_old_partial_and_already_fixed(self):
        for first, second in [(False, False), (True, False), (True, True)]:
            with self.subTest(first=first, second=second), tempfile.TemporaryDirectory() as d:
                root = Path(d)
                path = root / "vpnapp.php"
                original = snapshot(first, second)
                path.write_text(original)
                self.assertEqual(patcher.patch(path, root / "backups"), not (first and second))
                for _, new in patcher.FIXES:
                    self.assertIn(new, path.read_text())
                self.assertIn("preserve unrelated custom logic", path.read_text())
                self.assertFalse(patcher.patch(path, root / "backups"))
                backups = list((root / "backups").iterdir()) if (root / "backups").exists() else []
                self.assertEqual(len(backups), 0 if first and second else 1)
                if backups:
                    self.assertEqual(backups[0].read_text(), original)

    def test_unexpected_join_aborts_without_touching_code(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            file = root / "vpnapp.php"
            original = snapshot().replace("i.id_invoice=o.invoice_id", "i.id_invoice=o.random_id")
            file.write_text(original)
            with self.assertRaises(RuntimeError):
                patcher.patch(file, root / "backups")
            self.assertEqual(file.read_text(), original)
            self.assertFalse((root / "backups").exists())


if __name__ == "__main__":
    unittest.main()
