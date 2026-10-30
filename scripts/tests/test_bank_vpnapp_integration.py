"""Static integration guards for legacy bank, manual Telegram and VPN app card checkout.

Deliberately catches accidental mixing of notification, payment approval,
wallet side effects, and order bookkeeping across the four entrypoints.
"""
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]

def read(path):
    return (ROOT/path).read_text(encoding='utf-8')

class BankVpnAppIntegration(unittest.TestCase):
    def test_app_card_checkout_uses_legacy_purchase_marker(self):
        app=read('api/vpnapp.php')
        self.assertIn("'getconfigafterpay|'.$name",app)
        self.assertIn("'cart to cart'",app)
        self.assertIn("SET payment_Status='waiting'",app)
        self.assertIn("new DateTimeZone('Asia/Tehran')",app)

    def test_telegram_receipt_worker_cannot_approve_payment(self):
        worker=read('scripts/vpnapp_receipt_worker.php')
        self.assertNotIn("DirectPayment(",worker)
        self.assertNotIn("SET payment_Status='paid'",worker)
        self.assertNotIn("UPDATE user SET Balance",worker)
        self.assertIn("vpnappNotify(",worker)

    def test_original_cron_and_manual_confirmation_remain_separate(self):
        cron=read('cronbot/croncard.php')
        admin=read('admin.php')
        bank=read('payment/card.php')
        self.assertIn("if($PaySetting == \"onautoconfirm\")return;",cron)
        self.assertIn("if($paymentverify == \"offauto\")return;",cron)
        self.assertIn("Confirm_pay_",admin)
        self.assertIn("DirectPayment($order_id)",admin)
        self.assertIn("mirzaFindUniqueBankCardPayment",bank)
        self.assertIn("SELECT GET_LOCK",bank)
        self.assertNotIn("substr($amountInteger, -3)",bank)

    def test_verified_bank_is_authenticated_and_guarded_against_duplicates(self):
        bank=read('payment/card.php')
        self.assertIn("base64_decode($name_post[0])",bank)
        self.assertIn("if($secret_key == 0)return;",bank)
        self.assertIn("SET payment_Status='paid'",bank)
        self.assertIn("WHERE id_order=? AND payment_Status IN ('waiting','Unpaid')",bank)

if __name__=="__main__":
    unittest.main()
