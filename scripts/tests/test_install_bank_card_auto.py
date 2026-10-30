import importlib.util
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('bank_patch',ROOT/'install_bank_card_auto.py')
patcher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)

class BankAutoRestoreTests(unittest.TestCase):
    def legacy(self):
        return ("<?php\n"
                "// preserve all customized bank SMS parsers here\n"
                +patcher.ANCHOR+"if (true) {\n"
                +patcher.OLD_TAIL).encode()

    def test_round_amount_guard_removed_without_bypassing_bank_sms(self):
        original=self.legacy()
        updated,changed=patcher.planned(original)
        self.assertTrue(changed)
        self.assertIn(b'card_auto_match.php',updated)
        self.assertNotIn(b"substr($amountInteger, -3)",updated)
        self.assertIn(b"mirzaFindUniqueBankCardPayment",updated)
        self.assertIn(b"payment_Status='paid'",updated)
        self.assertIn(b"payment_Status IN ('waiting','Unpaid')",updated)
        self.assertIn(b"// preserve all customized bank SMS parsers here",updated)
        self.assertEqual(patcher.planned(updated),(updated,False))

    def test_unknown_or_custom_bank_match_aborts(self):
        original=self.legacy().replace(b"if (is_numeric($amountInteger)",
            b"if (customizedBankVerifier($amountInteger)")
        with self.assertRaises(RuntimeError):
            patcher.planned(original)
        with self.assertRaises(RuntimeError):
            patcher.planned(original.replace(b"keyboard.php",b"custom.php"))

if __name__=="__main__":
    unittest.main()
