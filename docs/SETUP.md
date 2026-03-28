# Setup Guide

This guide provides deeper configuration details for Hive Payments for WooCommerce, including custom Hive Engine token support.

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
- Native assets use CoinGecko live pricing.
- Hive Engine tokens use Hive Engine market prices in `SWAP.HIVE`, then convert through the HIVE rate.
- If live pricing fails, the plugin falls back to manual rates.

## 3) Polling & Confirmations
- The poller uses Action Scheduler when available; otherwise it falls back to WP-Cron.
- **Polling interval**: how often to fetch new account history.
- **Minimum confirmations**: number of blocks required before marking as paid.
- **Payment window**: how long the customer has to pay before the order is cancelled automatically.
- The thank-you page shows a live countdown for pending orders and updates automatically when the payment is confirmed or expires.

## 4) Accepted Assets
- Enable HIVE and/or HBD.
- Add Hive Engine tokens one per line as `SYMBOL|Optional Label|Optional Manual Rate`.
- Example: `BEE|Hive Engine Token|0.25`
- If your store currency is already HIVE/HBD, the amount is used directly.
- Hive Engine token payments require a Hive Engine compatible wallet and are matched from `tokens.transfer` custom JSON operations with the exact memo.
- Native HIVE/HBD payments also expose a prefilled Hivesigner transfer link on the order screen.

## 5) Troubleshooting
- Enable **Debug logging** in settings.
- Check WooCommerce logs for `hive-payments`.
- Make sure the memo and amount are exact.
- Make sure the customer paid before the deadline shown on the order.
- Verify the receiving account spelling.

## 6) Security Notes
- Orders remain on-hold until a valid transfer matches.
- Expired unpaid orders are cancelled automatically and WooCommerce stock is restored.
- Mismatches are recorded in order notes for manual review.
