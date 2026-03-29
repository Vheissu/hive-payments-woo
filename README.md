# Hive Payments for WooCommerce

Accept HIVE, HBD, or custom Hive Engine token payments using Hive. Payments are verified by polling for an exact matching payment memo generated per order.

## Features
- HIVE and HBD support
- Custom Hive Engine token support
- Strict memo matching (unique long token per order)
- Live pricing via CoinGecko for native assets and Hive Engine market data for tokens
- Manual rate fallback
- Structured payment instructions with copy actions
- One-click `Pay now with Keychain` flow for native and Hive Engine payments
- Native HIVE/HBD launch link for Hivesigner
- Automatic payment window expiry with WooCommerce order cancellation and stock restoration
- Action Scheduler polling with WP-Cron fallback
- WooCommerce Blocks checkout support
- Admin-configurable confirmations, polling interval, and logging

## Requirements
- PHP 8.5+ (latest PHP 8.x as of Jan 2026)
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
- **Hive Engine tokens**: Add one token per line as `SYMBOL|Optional Label|Optional Manual Rate`.
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
10. Native HIVE/HBD payments are matched from Hive `transfer` operations.
11. Hive Engine token payments are matched from `ssc-mainnet-hive` `tokens.transfer` custom JSON operations.
12. Poller detects the matching payment and marks the order paid.

## Strict Memo Matching
- Only payments with an **exact memo match** are accepted.
- This prevents memo collisions and accidental cross-order attribution.

## Live Pricing (CoinGecko)
- Default behavior uses CoinGecko’s public API without a key.
- You can supply a Demo or Pro API key if you want higher limits.
- Native asset rates are cached (default 5 minutes) to avoid excessive requests.
- Hive Engine tokens use Hive Engine market prices in `SWAP.HIVE` and convert through the current HIVE rate.
- If live rates can’t be fetched, the gateway falls back to configured manual rates.

## Logs & Troubleshooting
- Enable **Debug logging** in the gateway settings.
- View logs at **WooCommerce → Status → Logs**, look for `hive-payments`.
- If payments aren’t detected:
  - Verify the receiving Hive account name.
  - Confirm the customer used the exact memo.
  - Confirm the payment arrived before the configured payment deadline.
  - For Hive Engine tokens, confirm the customer used a Hive Engine compatible wallet and sent a `tokens.transfer`.
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
- Unpaid Hive orders are automatically cancelled after the configured payment window.
- Amount and asset mismatches are recorded in order notes.

## Roadmap Ideas
- QR display for transfers
- Additional rate providers
