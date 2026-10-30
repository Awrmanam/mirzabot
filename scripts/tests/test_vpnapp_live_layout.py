import importlib.util
import unittest
from pathlib import Path

scripts = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("live_layout", scripts / "inspect_vpnapp_live_layout.py")
layout = importlib.util.module_from_spec(spec)
spec.loader.exec_module(layout)

class LayoutTests(unittest.TestCase):
    def test_no_gallery_is_detected(self):
        state=layout.classify("function vpnappSubmitReceipt(\nfunction vpnappNewOrder(\n")
        self.assertEqual(state["receipt_storage"],"gallery-not-installed")
        self.assertEqual(state["receipt_delivery"],"legacy-synchronous-or-custom")

    def test_legacy_hmac_gallery_detected_without_revealing_secret(self):
        state=layout.classify("function vpnappReceiptFile(\n"
             "function vpnappPrivateReceiptDir(\n"
             "hash_hmac('sha256',(string)$key,SECRET)\n")
        self.assertEqual(state["private_filename"],"legacy-hmac")
        self.assertEqual(state["receipt_storage"],"gallery-present")
        self.assertNotIn("SECRET",str(state))

    def test_stable_queue_and_pending_recovery(self):
        state=layout.classify("function vpnappReceiptFile(\n"
             "hash('sha256',(string)$key)\n"
             "'notification_queued'=>true\n"
             "Too many card orders pending; contact support to reconcile")
        self.assertEqual(state["private_filename"],"stable-sha256")
        self.assertEqual(state["receipt_delivery"],"queued-async")
        self.assertEqual(state["pending_card_limit"],"new-recovery")

if __name__ == "__main__":
    unittest.main()
