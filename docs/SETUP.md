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
- The Hive Engine RPC endpoint can be changed if you run or trust a different contracts API.

## 3) Endpoints
Five endpoints are configurable, and they do different jobs:

| Setting | Used for |
| --- | --- |
| **Hive RPC endpoint** | Native HIVE/HBD transfer detection |
| **Hive RPC fallback endpoints** | Tried in order when the primary node fails |
| **Hive Engine RPC endpoint** | Token metadata (precision) and market prices |
| **Hive Engine history endpoint** | Detecting incoming Hive Engine token payments |
| **Hive Engine blockchain endpoint** | Hive Engine confirmation depth |

Add at least one fallback Hive RPC endpoint. With a single node, an outage
stops payment detection entirely until it recovers.

## 4) Polling & Confirmations
- The poller uses Action Scheduler when available; otherwise it falls back to WP-Cron.
- **Polling interval**: how often to fetch new account history.
- **Minimum confirmations**: number of blocks required before marking as paid. Native
  payments are measured against the Hive head block; Hive Engine payments against the
  Hive Engine sidechain head block. The two chains number their blocks independently.
- **Payment window**: how long the customer has to pay before the order is cancelled automatically.
- The thank-you page shows a live countdown for pending orders and updates automatically when the payment is confirmed or expires.

## 5) Accepted Assets
- Enable HIVE and/or HBD.
- Add Hive Engine tokens one per line as `SYMBOL|Optional Label|Optional Manual Rate|Optional Precision`.
- Example: `BEE|Hive Engine Token|0.25|8`
- Precision is fetched from Hive Engine metadata when available. The optional precision field is a fallback for checkout calculation if metadata is temporarily unavailable.
- If your store currency is already HIVE/HBD, the amount is used directly.
- Hive Engine token payments require a Hive Engine compatible wallet and are matched from
  `tokens_transfer` operations with the exact memo, read from the **Hive Engine history
  endpoint**. They cannot be read from the Hive chain: a Hive Engine transfer is a
  `custom_json` signed by the sender, so it appears only in the sender's Hive account
  history and never in the recipient's. If token payments are not being detected, check
  that endpoint before anything else.
- Native HIVE/HBD payments also expose a prefilled Hivesigner transfer link on the order screen.

## 6) Troubleshooting
- Enable **Debug logging** in settings.
- Check WooCommerce logs for `hive-payments`.
- Make sure the memo and amount are exact.
- For Hive Engine tokens, confirm the **Hive Engine history endpoint** is reachable from
  your server. Watching the Hive account history alone will never see token payments.
- Make sure the customer paid before the deadline shown on the order.
- Verify the receiving account spelling.

## 7) Security Notes
- Orders remain on-hold until a valid transfer matches.
- Token amounts are compared as decimal strings so high-precision Hive Engine underpayments are not accepted by float tolerance.
- Each confirmed blockchain payment candidate is recorded to prevent reuse across orders,
  and is claimed through a unique database key first so a cron poll and a customer-triggered
  check cannot both credit the same transfer.
- Customer-triggered payment checks are throttled per order. Filter
  `hive_payments_check_throttle_seconds` to change the window (default 10 seconds).
- Expired unpaid orders are cancelled automatically and WooCommerce stock is restored.
- Mismatches are recorded in order notes for manual review.
- A transfer whose memo matches an order that has already been cancelled is recorded on
  that order as a note, so late payments can be refunded rather than quietly kept.
