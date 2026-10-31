-- STAGING-ONLY MySQL migration, never run from php request or on production.
-- Requires identity migration first and a separate disposable Mirza MySQL.
-- This is a tracking journal, NOT a second wallet; canonical funds remain user.Balance.
-- Manual bank transfer uses Payment_report (existing Mirza payment journal).
CREATE TABLE IF NOT EXISTS vpnweb_topups (
  external_id VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  owner_id VARCHAR(64) NOT NULL,
  amount_toman INT NOT NULL,
  payment_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  card_number VARCHAR(16) NOT NULL,
  card_holder VARCHAR(200) NOT NULL,
  state VARCHAR(32) NOT NULL,
  receipt_mime VARCHAR(32) NULL,
  receipt_sha256 CHAR(64) NULL,
  bank_reference VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
  approved_by VARCHAR(64) NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  INDEX idx_wallet_owner (owner_id,created_at),
  INDEX idx_pending (state,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Payment_report rows for these orders MUST be:
-- Payment_Method='vpnweb_manual_wallet_topup'
-- bottype='vpnweb_manual'
-- payment_Status='vpnweb_review_pending' then 'paid' ONLY after manual verification.
-- They cannot satisfy croncard.php's condition Payment_Method IN
-- ('cart to cart','arze digital offline') AND bottype IS NULL.
-- DO NOT delete table or revert owner_id to numeric before reconciling funds.
