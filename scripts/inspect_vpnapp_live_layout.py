#!/usr/bin/env python3
"""Read-only classification of live Mirza bridge receipt support.

Never prints PHP source, credentials, tokens, paths to private receipt images,
user identifiers, or bank details. Intended after exact-match patches abort.
"""
from pathlib import Path
import sys

def classify(raw: str) -> dict[str, str]:
    have=lambda name: ('function '+name+'(') in raw
    out = {
        'receipt_storage': 'gallery-present' if have('vpnappPrivateReceiptDir') else 'gallery-not-installed',
        'private_receipt_file': 'present' if have('vpnappReceiptFile') else 'missing',
        'private_receipt_writer': 'present' if have('vpnappPersistReceipt') else 'missing',
        'admin_receipt_view': 'present' if have('vpnappAdminReceipt') else 'missing',
        'receipt_lookup_legacy': 'present' if have('vpnappFindReceipt') else 'missing',
        'receipt_upload_handler': 'present' if have('vpnappSubmitReceipt') else 'missing',
        'checkout_handler': 'present' if have('vpnappNewOrder') else 'missing',
    }
    if "hash_hmac('sha256',(string)$key" in raw:
        out['private_filename'] = 'legacy-hmac'
    elif "hash('sha256',(string)$key)" in raw:
        out['private_filename'] = 'stable-sha256'
    else:
        out['private_filename'] = 'missing-or-custom'
    if "'notification_queued'=>true" in raw:
        out['receipt_delivery'] = 'queued-async'
    elif have('vpnappSubmitReceipt'):
        out['receipt_delivery'] = 'legacy-synchronous-or-custom'
    else:
        out['receipt_delivery'] = 'handler-missing'
    if "Too many card orders pending; contact support to reconcile" in raw:
        out['pending_card_limit'] = 'new-recovery'
    elif "Too many receipts awaiting review today" in raw:
        out['pending_card_limit'] = 'old-3-receipt-limit'
    else:
        out['pending_card_limit'] = 'unknown-custom'
    return out

def main() -> int:
    target=Path(sys.argv[1]) if len(sys.argv)==2 else Path('/var/www/mirzaprobotconfig/api/vpnapp.php')
    if not target.is_file() or target.is_symlink():
        print("ERROR: Live Mirza bridge not found")
        return 2
    data=target.read_text(encoding='utf-8')
    for k,v in classify(data).items():
        print(k+'='+v)
    return 0

if __name__ == '__main__':
    sys.exit(main())
