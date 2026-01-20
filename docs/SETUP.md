# Setup Guide

This guide provides deeper configuration details for Hive Payments for WooCommerce.

## 1) Hive Account & Memo
- Set **Receiving Hive account** to the wallet that will receive funds.
- Each order generates a memo: `{PREFIX}:{ORDER_ID}:{RANDOM}`.
- **Strict memo matching** means the memo must match **exactly**.
- Increase **Memo random length** if you want extra collision protection.

## 2) Live Pricing
- **Rate source**: Live pricing uses CoinGecko’s `simple/price` endpoint.
- **No-key default**: The gateway works without a key.
- Optional: Add a Demo/Pro API key for higher limits.
- **Cache minutes**: Default 5. Increase if you expect high traffic.
- If live pricing fails, the plugin falls back to manual rates.

## 3) Polling & Confirmations
- The poller uses Action Scheduler when available; otherwise it falls back to WP-Cron.
- **Polling interval**: how often to fetch new account history.
- **Minimum confirmations**: number of blocks required before marking as paid.

## 4) Accepted Assets
- Enable HIVE and/or HBD.
- If your store currency is already HIVE/HBD, the amount is used directly.

## 5) Troubleshooting
- Enable **Debug logging** in settings.
- Check WooCommerce logs for `hive-payments`.
- Make sure the memo and amount are exact.
- Verify the receiving account spelling.

## 6) Security Notes
- Orders remain on-hold until a valid transfer matches.
- Mismatches are recorded in order notes for manual review.
