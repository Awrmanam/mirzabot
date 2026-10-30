import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
spec = importlib.util.spec_from_file_location("live_safe", ROOT / "patch_vpnapp_live_safe.py")
safe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(safe)


def make_php(states):
    blocks = ["<?php\n// Leave all custom code as is\n"]
    for (name, old, new), installed in zip(safe.SECTIONS, states):
        blocks.append(new if installed else old)
    blocks.append("// Custom postlude\n")
    return "\n".join(blocks)


class TestSafeLiveUpdate(unittest.TestCase):
    def test_all_old_mixed_and_repeated(self):
        for state in [(False, False, False, False),
                      (True, False, True, False),
                      (True, True, True, True)]:
            with self.subTest(state=state), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                live = root / "vpnapp.php"
                original = make_php(state)
                live.write_text(original)
                outcome = safe.patch(live, root / "backups")
                self.assertEqual(len(outcome), 4)
                fixed = live.read_text()
                self.assertIn("Leave all custom code as is", fixed)
                self.assertIn("Custom postlude", fixed)
                for _, _, new in safe.SECTIONS:
                    self.assertIn(new, fixed)
                self.assertEqual(safe.patch(live, root / "backups"),
                                 {name: "already installed" for name, _, _ in safe.SECTIONS})
                if any(not installed for installed in state):
                    backups = list((root / "backups").glob("*"))
                    self.assertEqual(len(backups), 1)
                    self.assertEqual(backups[0].read_text(), original)

    def test_unknown_variant_aborts_without_backup_or_change(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            live = root / "vpnapp.php"
            original = make_php((False,) * 4).replace("function vpnappNotify(", "function modifiedNotify(")
            live.write_text(original)
            with self.assertRaises(RuntimeError):
                safe.patch(live, root / "backups")
            self.assertEqual(live.read_text(), original)
            self.assertFalse((root / "backups").exists())


if __name__ == "__main__":
    unittest.main()
