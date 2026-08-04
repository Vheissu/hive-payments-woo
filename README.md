# Hive Payments for WooCommerce

Accept HIVE, HBD, or custom Hive Engine token payments using Hive. Payments are verified by polling for an exact matching payment memo generated per order.

## Features
- HIVE and HBD support
- Custom Hive Engine token support with optional manual rates and precision fallback
- Strict memo matching (unique long token per order)
- Live pricing via CoinGecko for native assets and Hive Engine market data for tokens
- Manual rate fallback
- Hive RPC endpoint failover
- Structured payment instructions with copy actions
- One-click `Pay now with Keychain` flow for native and Hive Engine payments
- Native HIVE/HBD launch link for Hivesigner
- Automatic payment window expiry with WooCommerce order cancellation and stock restoration
- Action Scheduler polling with WP-Cron fallback
- WooCommerce Blocks checkout support
- Admin-configurable confirmations, polling interval, and logging

## Requirements
- PHP 8.2+
- WooCommerce 10.4+ (tested up to 10.4.3)
- WordPress 6.x

## Installation
1. Copy this plugin folder into `wp-content/plugins/hive-payments-woo`.
2. Activate **Hive Payments for WooCommerce** in WordPress.
3. Go to **WooCommerce → Settings → Payments → Hive Payments** and configure.

## Configuration
Key settings (WooCommerce → Settings → Payments → Hive Payments):
- **Enable**: Turn on the payment gateway.
- **Receiving Hive account**: Hive account (without `@`) to receive payments.
- **Memo prefix**: Prefix for memos (e.g., `WC`).
- **Memo random length**: Length of the random token (default 24). Longer reduces clashes.
- **Accepted assets**: Choose HIVE and/or HBD.
- **Hive Engine tokens**: Add one token per line as `SYMBOL|Optional Label|Optional Manual Rate|Optional Precision`.
- **Hive RPC endpoint**: Node used to read the receiving account's Hive history.
- **Hive RPC fallback endpoints**: Optional, one per line, tried in order when the primary node fails.
- **Hive Engine RPC endpoint**: Contracts endpoint used for token metadata and market prices.
- **Hive Engine history endpoint**: Account history API used to detect incoming token payments.
- **Hive Engine blockchain endpoint**: Sidechain node used to measure token confirmation depth.
- **Default asset**: Default choice at checkout.
- **Rate source**: Live or Manual.
- **CoinGecko API plan**: Default is **No API key**. Select Demo/Pro if you have a key.
- **CoinGecko API key**: Optional.
- **Live rate cache**: Cache pricing for N minutes (default 5).
- **Manual rates**: Used if live pricing fails or if you select Manual. Hive Engine token manual rates are configured per token line.
- **Polling interval**: How often to check the blockchain.
- **Minimum confirmations**: Blocks to wait before completing the order.
- **Payment window**: How long the customer has to pay before the order is automatically cancelled.
- **Debug logging**: Log poller activity to WooCommerce logs.

## Payment Flow
1. Customer places order and selects Hive Payments.
2. Order status moves to **on-hold**.
3. A memo is generated like: `WC:1234:AbcDefGh...`.
4. Customer sees a payment card with the exact amount, destination account, memo, copy actions, and the payment deadline.
5. The order page offers `Pay now with Keychain`, which opens a prefilled transaction for native HIVE/HBD transfers or Hive Engine `tokens.transfer` custom JSON operations.
6. For native HIVE/HBD payments, the customer can also launch a prefilled Hivesigner transfer.
7. Manual copy/send remains available for any compatible wallet.
8. Customer sends the exact amount to the configured account with **that memo**.
9. If no matching payment arrives before the deadline, the order is automatically cancelled and stock is restored.
10. Native HIVE/HBD payments are matched from Hive `transfer` operations in the receiving account's Hive history.
11. Hive Engine token payments are matched from `tokens_transfer` operations in the Hive Engine history API.
12. Poller detects the matching payment and marks the order paid.

### Why two sources
A Hive Engine transfer is a `custom_json` operation signed by the **sender**, so it only
ever appears in the sender's Hive account history. Watching the receiving account's Hive
history will never surface an incoming token payment, which is why token detection reads
Hive Engine's own history API instead.

## Strict Memo Matching
- Only payments with an **exact memo match** are accepted.
- This prevents memo collisions and accidental cross-order attribution.

## Live Pricing (CoinGecko)
- Default behavior uses CoinGecko’s public API without a key.
- You can supply a Demo or Pro API key if you want higher limits.
- Native asset rates are cached (default 5 minutes) to avoid excessive requests.
- Hive Engine tokens use Hive Engine market prices in `SWAP.HIVE` and convert through the current HIVE rate.
- Hive Engine token precision is read from token metadata. If the contracts API is unavailable, the optional configured precision is used as a fallback.
- If live rates can’t be fetched, the gateway falls back to configured manual rates.

## Logs & Troubleshooting
- Enable **Debug logging** in the gateway settings.
- View logs at **WooCommerce → Status → Logs**, look for `hive-payments`.
- If payments aren’t detected:
  - Verify the receiving Hive account name.
  - Confirm the customer used the exact memo.
  - Confirm the payment arrived before the configured payment deadline.
  - For Hive Engine tokens, confirm the customer used a Hive Engine compatible wallet and sent a `tokens_transfer`, and that the Hive Engine history endpoint is reachable from your server.
  - Ensure polling is running (Action Scheduler or WP-Cron).
  - Check live pricing settings and cache interval.

## Developer Notes
- Unit tests use Pest + Brain Monkey.
- Run tests:
  ```bash
  composer install
  composer test
  ```

## Security & Safety
- Uses the WordPress HTTP API and sanitizes settings.
- Orders remain **on-hold** until a matching transfer is confirmed.
- High-precision token amounts are compared as decimal strings, not loose floats.
- Confirmed payment candidates are stored so the same blockchain operation cannot be reused for another order, and are claimed through a unique database key so concurrent checks cannot double-credit one transfer.
- Customer-triggered payment checks are throttled per order to keep the endpoint from being used to hammer the store's RPC node.
- Unpaid Hive orders are automatically cancelled after the configured payment window.
- Amount and asset mismatches are recorded in order notes.
- Transfers arriving after an order has been cancelled are flagged on that order for refund rather than silently kept.

## Roadmap Ideas
- QR display for transfers
- Additional rate providers
- Estimated payment amount shown at checkout before the order is placed
