# Hive Payments for WooCommerce

Accept HIVE or HBD payments using the Hive blockchain. Payments are verified by polling the blockchain for a transfer that matches a **strict memo** generated per order.

## Features
- HIVE and HBD support
- Strict memo matching (unique long token per order)
- Live pricing via CoinGecko (default), with optional API key
- Manual rate fallback
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
- **Default asset**: Default choice at checkout.
- **Rate source**: Live (CoinGecko) or Manual.
- **CoinGecko API plan**: Default is **No API key**. Select Demo/Pro if you have a key.
- **CoinGecko API key**: Optional.
- **Rate cache**: Cache pricing for N minutes (default 5).
- **Manual rates**: Used if live pricing fails or if you select Manual.
- **Polling interval**: How often to check the blockchain.
- **Minimum confirmations**: Blocks to wait before completing the order.
- **Debug logging**: Log poller activity to WooCommerce logs.

## Payment Flow
1. Customer places order and selects Hive Payments.
2. Order status moves to **on-hold**.
3. A memo is generated like: `WC:1234:AbcDefGh...`.
4. Customer sends the exact amount to the configured account with **that memo**.
5. Poller detects a matching transfer and marks the order paid.

## Strict Memo Matching
- Only transfers with an **exact memo match** are accepted.
- This prevents memo collisions and accidental cross-order attribution.

## Live Pricing (CoinGecko)
- Default behavior uses CoinGecko’s public API without a key.
- You can supply a Demo or Pro API key if you want higher limits.
- Rates are cached (default 5 minutes) to avoid excessive requests.
- If rates can’t be fetched, the gateway falls back to manual rates.

## Logs & Troubleshooting
- Enable **Debug logging** in the gateway settings.
- View logs at **WooCommerce → Status → Logs**, look for `hive-payments`.
- If payments aren’t detected:
  - Verify the receiving Hive account name.
  - Confirm the customer used the exact memo.
  - Ensure polling is running (Action Scheduler or WP-Cron).
  - Check CoinGecko settings and cache interval.

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
- Amount and asset mismatches are recorded in order notes.

## Roadmap Ideas
- Hive Keychain deep link support
- QR display for transfers
- Additional rate providers
