import importlib.util
import tempfile
import unittest
from pathlib import Path

SCRIPTS=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('no_gallery',SCRIPTS/'patch_vpnapp_no_gallery.py')
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

def fixture():
    return ("<?php\n// Customized Mirza code must remain\n"
            +mod.OLD_INFO_JOIN+"\n"
            +mod.OLD_INFO_STATUS+"\n"
            +mod.SNAP_OLD+"\n"
            +mod.OLD_RECEIPT+"\n"
            +mod.OLD_GATE+"\n"
            +"function otherMirzaHandler() { return 7; }\n").encode()

class LegacyGalleryMigration(unittest.TestCase):
    def test_exact_legacy_migration_retains_rest(self):
        original=fixture()
        result=mod.build(original)
        self.assertIn(mod.STORAGE_HELPERS.encode(),result)
        self.assertIn(mod.NEW_RECEIPT.encode(),result)
        self.assertIn(mod.NEW_GATE.encode(),result)
        self.assertIn(mod.SNAP_NEW.encode(),result)
        self.assertIn(mod.NEW_INFO_JOIN.encode(),result)
        self.assertIn(b"Customized Mirza code must remain",result)
        self.assertIn(b"function otherMirzaHandler",result)

    def test_customized_upload_rejected_without_any_file_change(self):
        original=fixture().replace(b"function vpnappSubmitReceipt(", b"function customSubmitReceipt(")
        with self.assertRaises(RuntimeError):
            mod.build(original)

    def test_backup_and_php_lint(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);live=root/"vpnapp.php"
            # This is a syntax fixture; the real file defines helpers that
            # are called by submit and dashboard on the live bot.
            body=fixture().decode().replace(
              mod.OLD_INFO_JOIN+"\n"+mod.OLD_INFO_STATUS+"\n"+mod.SNAP_OLD+"\n",
              "")
            body=body.replace(mod.OLD_GATE+"\n", "")
            live.write_text(body)
            with self.assertRaises(RuntimeError):
                mod.patch(live,root/"backup")
            self.assertEqual(live.read_text(),body)
            self.assertFalse((root/"backup").exists())

if __name__=="__main__":
    unittest.main()
