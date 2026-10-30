import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("checkout",ROOT/"patch_vpnapp_legacy_checkout_sql.py")
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

LEGACY_20261029 = "function vpnappNewOrder(PDO $db, $u, $kind, $key) {\n    if (!is_string($key) || !preg_match('/^[0-9a-f-]{36}$/D',$key))\n        vpnappError(400,'Invalid order key');\n    // Serialize checkouts from the same Mirza user, even with different\n    // client-generated UUIDs, so two requests cannot race the pending limit.\n    $lock=$db->prepare(\"SELECT GET_LOCK(?,10)\");\n    $lock->execute(['vpnapp_account_'.$u['id']]);\n    if ((int)$lock->fetchColumn()!==1) vpnappError(409,'Checkout currently busy');\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?\n        AND (status='unknown' OR\n             (status IN ('processing','awaiting_receipt','waiting') AND created_at>?))\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>0) vpnappError(409,'A previous order is still pending');\n    $stmt=$db->prepare(\"INSERT INTO vpnapp_orders(external_id,owner_id,kind,status,created_at,updated_at) VALUES(?,?,?,?,?,?)\");\n    $stmt->execute([$key,$u['id'],$kind,'processing',time(),time()]);\n}\n"
LEGACY_20261030 = "function vpnappNewOrder(PDO $db, $u, $kind, $key) {\n    if (!is_string($key) || !preg_match('/^[0-9a-f-]{36}$/D',$key))\n        vpnappError(400,'Invalid order key');\n    // Serialize checkouts from the same Mirza user, even with different\n    // client-generated UUIDs, so two requests cannot race the pending limit.\n    $lock=$db->prepare(\"SELECT GET_LOCK(?,10)\");\n    $lock->execute(['vpnapp_account_'.$u['id']]);\n    if ((int)$lock->fetchColumn()!==1) vpnappError(409,'Checkout currently busy');\n    // Uncertain wallet/panel operations must be reconciled before another\n    // checkout, so a timeout never accidentally charges the user twice.\n    // A card receipt already submitted for manual review is different:\n    // keeping its review open must not lock all unrelated new purchases.\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id=?\n        AND (status='unknown' OR\n             (status IN ('processing','awaiting_receipt') AND created_at>?))\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>0)\n        vpnappError(409,'A previous order needs reconciliation before checkout');\n    // Limit unreviewed card receipts and rapid repeat purchases; never\n    // approve, delete or mutate any of those existing payment records.\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders\n        WHERE owner_id=? AND status='waiting' AND created_at>?\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>=3)\n        vpnappError(429,'Too many receipts awaiting review today');\n    $stmt=$db->prepare(\"SELECT COUNT(*) FROM vpnapp_orders\n        WHERE owner_id=? AND created_at>?\");\n    $stmt->execute([$u['id'],time()-86400]);\n    if ((int)$stmt->fetchColumn()>=10)\n        vpnappError(429,'Daily checkout limit reached');\n    $stmt=$db->prepare(\"INSERT INTO vpnapp_orders(external_id,owner_id,kind,status,created_at,updated_at) VALUES(?,?,?,?,?,?)\");\n    $stmt->execute([$key,$u['id'],$kind,'processing',time(),time()]);\n}\n"

class CheckoutSqlCompatibility(unittest.TestCase):
    def test_prior_legacy_versions_unlock_without_changing_invoices(self):
        for body in (LEGACY_20261029,LEGACY_20261030):
            with self.subTest(source=body[:40]):
                original=("<?php\n// unrelated customized code\n"+body+
                    "\nfunction customOtherModule() { return 18; }\n").encode()
                result,msg=mod.patched(original)
                self.assertIn(b"kind NOT IN ('card','renewal_card')",result)
                self.assertNotIn(b"AND (status='unknown' OR",result)
                self.assertIn(b"function customOtherModule()",result)
                self.assertIn(b"INSERT INTO vpnapp_orders",result)
                self.assertEqual(mod.patched(result)[0],result)

    def test_unrecognized_custom_sql_fails_closed(self):
        original=("<?php\n"+LEGACY_20261030.replace(
             "status='unknown'","status='my-custom-state'")).encode()
        with self.assertRaises(RuntimeError):
            mod.patched(original)

    def test_backup_is_original_and_no_double_change(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);live=root/"vpnapp.php"
            old="<?php\n"+LEGACY_20261030+"\nfunction nextHandler() { return 1; }\n"
            live.write_text(old)
            mod.patch(live,root/"backups")
            self.assertNotEqual(live.read_text(),old)
            mod.patch(live,root/"backups")
            b=list((root/"backups").iterdir())
            self.assertEqual(len(b),1)
            self.assertEqual(b[0].read_text(),old)


if __name__=="__main__":
    unittest.main()
