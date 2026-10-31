"""Exercise the actual PHP worker against a fake in-memory DB and Telegram.
No production connection, bank callback, or real bot token is used.
"""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

WORKER = Path(__file__).resolve().parents[1] / "vpnapp_receipt_worker.php"
API_SHIM = r'''<?php
class FakeStatement {
    private $db; private $sql; private $params=[];
    function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
    function execute($params=[]){$this->params=$params;if(str_contains($this->sql,'UPDATE'))$this->update();return true;}
    function fetchAll($mode=null){
        if (str_contains($this->sql,'FROM vpnapp_orders')) {
            $items=array_values($this->db->orders);
            usort($items,fn($a,$b)=>$a['updated_at']<=>$b['updated_at']);
            return $items;
        }
        return [];
    }
    function fetch($mode=null){
        if (str_contains($this->sql,'FROM vpnapp_orders')) {
            $key=$this->params[0];$owner=$this->params[1];
            $row=$this->db->orders[$key]??null;
            return $row && $row['owner_id']===$owner ? $row : false;
        }
        return false;
    }
    function fetchColumn(){
        if (str_contains($this->sql,'FROM Payment_report')){
            $key=$this->params[0];
            return $this->db->payments[$key]??false;
        }
        if (str_contains($this->sql,'FROM vpnapp_orders')){
            $key=$this->params[0];return $this->db->orders[$key]['result_json']??false;
        }
        return false;
    }
    function update(){
        if (str_contains($this->sql,'Payment_report') || str_contains($this->sql,'invoice')
            || str_contains($this->sql,'user SET')) {
            throw new Exception("Financial write forbidden");
        }
        if (!str_contains($this->sql,'UPDATE vpnapp_orders')) throw new Exception("Unexpected update");
        [$json,$when,$key,$owner]=$this->params;
        if ($this->db->orders[$key]['owner_id']!==$owner) throw new Exception("Owner mismatch");
        $this->db->orders[$key]['result_json']=$json;
        $this->db->orders[$key]['updated_at']=$when;
    }
}
class FakeDB {
    public $orders=[];public $payments=[];public $sent=[];public $writes=0;
    private $tx=false;
    function __construct(){
        foreach(json_decode(getenv('TEST_ORDERS'),true) as $item) $this->orders[$item['external_id']]=$item;
        $this->payments=json_decode(getenv('TEST_PAYMENTS'),true);
    }
    function query($sql){return new FakeStatement($this,$sql);}
    function prepare($sql){return new FakeStatement($this,$sql);}
    function beginTransaction(){$this->tx=true;}
    function rollBack(){$this->tx=false;}
    function commit(){$this->tx=false;}
    function inTransaction(){return $this->tx;}
}
$pdo=new FakeDB();
function vpnappFindReceipt($key,$mime){return getenv('TEST_IMAGE');}
function vpnappNotify($message,$owner,$image=null,$paymentId=null){
    global $pdo;
    $pdo->sent[]=['owner'=>$owner,'payment'=>$paymentId,'image'=>($image!==null)];
    return getenv('TEST_TELEGRAM_FAIL')!=='1';
}
register_shutdown_function(function(){
    global $pdo;
    if ($pdo) file_put_contents(getenv('TEST_RESULT'),
        json_encode(['orders'=>array_values($pdo->orders),'sent'=>$pdo->sent],JSON_UNESCAPED_UNICODE));
});
'''


class WorkerBatchTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which("php"), "PHP CLI required")
    def test_bounded_paid_and_waiting_with_duplicate_suppression(self):
        rows = [self.order("order-"+str(i), i, status="fulfilled" if i==1 else "waiting")
                for i in range(1, 4)]
        self.run_case(rows, {"payment-1":"paid","payment-2":"waiting","payment-3":"waiting"},
                      expected_sent=2)

    @unittest.skipUnless(shutil.which("php"), "PHP CLI required")
    def test_already_delivered_skipped_and_failures_remain_retryable(self):
        rows = [self.order("order-"+str(i), i, sent=(i==1)) for i in range(1, 4)]
        result = self.run_case(rows, {"payment-1":"paid","payment-2":"paid","payment-3":"paid"},
                               expected_sent=2, telegram_fail=True)
        self.assertEqual(len(result["sent"]), 2)
        for row in result["orders"]:
            if row["external_id"]=="order-1":
                self.assertTrue(json.loads(row["result_json"])["receipt_notice_sent"])
            else:
                self.assertNotIn("receipt_notice_sent", json.loads(row["result_json"]))
                self.assertEqual(json.loads(row["result_json"])["receipt_send_attempts"], 1)

    @staticmethod
    def order(key, number, status="waiting", sent=False):
        return {"external_id":key,"owner_id":str(100+number),
                "payment_id":"payment-"+str(number),"status":status,
                "result_json":json.dumps({"receipt_notice_sent":sent} if sent else {}),
                "updated_at":number,"kind":"card"}

    def run_case(self, rows, payments, expected_sent, telegram_fail=False):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            (root/"api").mkdir();(root/"scripts").mkdir()
            shutil.copy2(WORKER,root/"scripts"/"vpnapp_receipt_worker.php")
            (root/"api"/"vpnapp.php").write_text(API_SHIM)
            (root/"receipt.png").write_bytes(b"fixture")
            output=root/"result.json"
            env=os.environ.copy()
            env.update({
                "TEST_ORDERS":json.dumps(rows),
                "TEST_PAYMENTS":json.dumps(payments),
                "TEST_IMAGE":str(root/"receipt.png"),
                "TEST_RESULT":str(output),
                "TEST_TELEGRAM_FAIL":"1" if telegram_fail else "0",
            })
            completed=subprocess.run(["php",str(root/"scripts"/"vpnapp_receipt_worker.php")],
                                      env=env,capture_output=True,text=True,timeout=20)
            self.assertEqual(completed.returncode,0,completed.stderr)
            result=json.loads(output.read_text())
            self.assertEqual(len(result["sent"]),expected_sent)
            if not telegram_fail:
                sent={item["external_id"]:json.loads(item["result_json"])
                      for item in result["orders"]}
                self.assertTrue(sent["order-1"].get("receipt_notice_sent"))
                self.assertTrue(sent["order-2"].get("receipt_notice_sent"))
                self.assertNotIn("receipt_notice_sent",sent["order-3"])
                self.assertEqual(result["sent"][0]["payment"],None)
                self.assertEqual(result["sent"][1]["payment"],"payment-2")
            return result


if __name__=="__main__":
    unittest.main()
