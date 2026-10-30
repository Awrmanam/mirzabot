import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("gallery_patch", SCRIPTS / "patch_vpnapp_photo_gallery.py")
gallery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gallery)


class GalleryPatchTests(unittest.TestCase):
    def test_partial_and_full_upgrades_preserve_custom_code(self):
        for initial in ((False,False,False),(True,False,True),(True,True,True)):
            with self.subTest(initial=initial), tempfile.TemporaryDirectory() as d:
                base=Path(d)
                php=base/"vpnapp.php"
                current="<?php\n// LIVE CUSTOM CODE\n"
                for (label, old, new), installed in zip(gallery.CHANGES,initial):
                    current += new if installed else old
                php.write_text(current)
                gallery.patch(php,base/"backups")
                updated=php.read_text()
                self.assertIn("LIVE CUSTOM CODE",updated)
                for _,old,new in gallery.CHANGES:
                    self.assertIn(new,updated)
                gallery.patch(php,base/"backups")

    def test_unrecognized_live_receipt_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d)
            p=root/"vpnapp.php"
            original="<?php\n"
            for label,old,new in gallery.CHANGES: original+=old
            original=original.replace("function vpnappSubmitReceipt(", "function customReceipt(",1)
            p.write_text(original)
            with self.assertRaises(RuntimeError):
                gallery.patch(p,root/"backup")
            self.assertEqual(p.read_text(),original)
            self.assertFalse((root/"backup").exists())


if __name__=="__main__":
    unittest.main()
