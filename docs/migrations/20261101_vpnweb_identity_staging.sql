-- STAGING ONLY. Do not run automatically or against production without approval.
-- Preflight: exact schema diff, encrypted DB backup, maintenance/reconciliation
-- of in-flight vpnapp orders, test rollback and documented operator approval.
-- DDL in MySQL performs implicit commits. Backup / controlled restore required.
--
-- 1. Verify current column metadata and no invalid owner IDs:
-- SELECT DATA_TYPE,COLUMN_TYPE FROM information_schema.columns
-- WHERE table_schema=DATABASE() AND table_name='vpnapp_orders'
-- AND column_name='owner_id';
-- SELECT COUNT(*) FROM vpnapp_orders WHERE owner_id IS NULL;
--
-- 2. Preserve all existing numeric Telegram ID owners as text, without rekeying.
ALTER TABLE vpnapp_orders
  MODIFY COLUMN owner_id VARCHAR(64) NOT NULL;
--
-- 3. A non-Telegram app account maps uniquely to ONE canonical Mirza user.id.
CREATE TABLE IF NOT EXISTS vpnweb_identities (
  app_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  commercial_id VARCHAR(64) NOT NULL UNIQUE,
  created_at BIGINT NOT NULL,
  CONSTRAINT fk_vpnweb_mirza_user
    FOREIGN KEY (commercial_id) REFERENCES user(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
-- This migration does not touch Balance, invoice, Payment_report, DirectPayment
-- or cronbot/croncard.php.
-- Rollback: do not revert owner_id to BIGINT while web_ records still exist.
-- First disable WEB signup/checkout, reconcile records and restore from
-- a verified backup; never blindly DELETE web_ user rows.
