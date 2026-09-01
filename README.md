# MMG Checkout for WooCommerce

A WooCommerce payment gateway and merchant operations toolkit for MMG (Mobile Money Guyana). Built and maintained by Revamped GY.

The plugin adds MMG as a payment method on classic and Block checkout, handles encrypted callbacks and bundles a set of admin tools that most Guyanese stores end up needing once they go live: credential importer, diagnostics, exports, payment requests, QR payments, reminder-based subscriptions, support bundle, role manager and a self-hosted updater.

## Features

- MMG payment method on classic checkout and WooCommerce Blocks checkout
- Compact hosted-checkout guidance with responsive image previews
- Optional MMG app approval requests using the documented Merchant Initiated API
- Sandbox (UAT) and Live mode with one-click switching
- Credential importer that reads hosted checkout packages and optional Merchant Initiated Postman environments
- Resilient callback handling with three URL shapes accepted, idempotent processing and clear order notes
- Currency conversion to GYD for non-GYD stores with rounding to the nearest 100
- Verify Payment and Resend Payment Link tools on the order screen
- Payment Requests using the standard WooCommerce pay-for-order flow
- QR Payments with locally generated SVG codes (no third-party QR services)
- Reminder-based subscription renewals with a custom subscriber table
- Customer portal endpoints under My Account: My Invoices, My Subscriptions
- Detailed CSV exports and a monthly summary export, both Excel-safe
- Lightweight Analytics dashboard for weekly and monthly totals
- Diagnostics page with environment checks, callback URLs and rate refresh
- Support Bundle that ships a redacted ZIP for faster troubleshooting
- Feature Manager and Role Manager for module-level access control
- Self-hosted updater that reads from GitHub Releases

## Tech stack

- WordPress plugin (PHP 7.4+, WordPress 6.0+)
- WooCommerce 7.0 or later, tested up to 9.4
- RSA-OAEP (SHA-256) crypto using OpenSSL with a hand-rolled OAEP wrapper to match MMG's reference implementation
- AES-256-GCM at-rest encryption for secrets and private keys, keyed on the site's `AUTH_KEY`
- WooCommerce Blocks Cart and Checkout integration

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer (PHP 8.x supported)
- WooCommerce 7.0 or newer
- OpenSSL extension (default on every supported PHP build)
- A live or sandbox MMG Merchant Checkout credential package from MMG Merchant Services

## Installation

1. Download the latest plugin ZIP from the [Releases](../../releases) page.
2. In WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate the plugin.
4. Go to **MMG Checkout → Importer** and upload your MMG credential package (Sandbox first).
5. Test the complete Sandbox checkout and callback flow.
6. Import the Live credential package and switch the mode when the store is ready.

## Configuration

Most settings live under **WP Admin → MMG Checkout → Settings**. Sensitive values (private keys, secret keys, optional API credentials) are encrypted at rest. You can also override any setting from `wp-config.php` using a constant. The Diagnostics page lists every supported constant with example syntax.

The callback URL is shown on the Diagnostics page. MMG's [Merchant Checkout documentation](https://mmg.gy/developer/Merchant%20Checkout.html) calls this the Response URL. Copy the Diagnostics value into the merchant configuration used for the active environment.

## Usage

After installation customers see MMG on the checkout page next to the other payment methods. They click **Place Order**, get redirected to the MMG hosted page, complete OTP authorisation and return to the order received page.

For invoice-style billing use **MMG Checkout → Payment Requests** to create a pay-for-order link and send it to the customer. For repeat billing use **MMG Checkout → Subscriptions** to track subscribers and schedule reminder emails. For in-person or shareable payment use **MMG Checkout → QR Payments** to generate a QR or short link.

## Updates

Version 2.16.0 corrected the GitHub update channel and requires a matching `.zip.sha256` sidecar before WordPress can install the ZIP. Versions 2.13.1 through 2.14.21 use the legacy manifest to receive the one-time 2.16.0 bridge, then use this repository for later releases. Update metadata is checked during normal WordPress update checks and successful responses are cached for six hours. A version-bumped push to `main` creates the matching release and installable assets after PHP 7.4 to 8.3 validation. Later pushes at the same version do not publish another update.

Version 2.16.2 follows MMG's two published flows. Standard hosted checkout requires only the Checkout URL, Merchant ID, Client ID, Merchant Name, Secret Key and PEM keys documented for [Merchant Checkout](https://mmg.gy/developer/Merchant%20Checkout.html). Its encrypted Checkout Response can complete the matching WooCommerce order. The separate [Merchant Initiated API](https://mmg.gy/developer/Merchant%20Initiated.html) credentials are optional for hosted checkout and are used for approval requests plus an additional Transaction Lookup when configured. The importer can read these optional values from a Postman environment inside the MMG ZIP or from a separate JSON file for Sandbox or Live.

The optional **Approve in the MMG app** method sends a payment request to the customer's registered phone number. Configure `x-api-key`, `x-wss-mid`, API password, `x-wss-msecret`, `x-wss-mkey` and the API base URL for the active mode. The merchant `creditParty.accountid` uses `x-wss-mid` unless a separate account is entered. The UAT base URL is provided as the Sandbox default. Public documentation examples are not credentials for every merchant.

Credential-bearing API requests are restricted to MMG-controlled domains by default. A store using another configured production domain can add only that domain through the `mmgwc_allowed_api_hosts` filter.

If you need to point the updater somewhere else, the GitHub repository can be filtered:

```php
add_filter( 'mmgwc_github_repo', function() {
    return 'your-org/your-fork';
} );
```

## Security

Please report security issues privately. See [SECURITY.md](SECURITY.md) for details. Do not open a public issue for anything that could be used to compromise a live store.

The plugin handles encrypted MMG payment tokens, never logs raw secrets or PEM blocks, encrypts protected settings at rest and refuses GitHub update packages without a matching SHA-256 checksum.

## Troubleshooting

- The Diagnostics page surfaces most setup problems (missing callback URL, missing credentials, currency conversion off, OpenSSL missing).
- The Logs page shows recent plugin log entries with secrets redacted. Enable Debug logging in Settings before reproducing an issue.
- The Support Bundle on the Support page produces a single ZIP with diagnostics and redacted logs. Send that to support instead of screenshots.
- "Unable to process your request" on the MMG page after an abandoned attempt means MMG saw a duplicate `merchantTransactionId`. Plugin versions 2.14.20 and later regenerate this on every retry, so an upgrade resolves it.
- MMG's hosted page can create overlapping QR requests when a customer switches quickly between Pay with QR and Login. Return to checkout and start a fresh session. This race is inside MMG's page, so the plugin cannot cancel those requests directly.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes per version.

## Licence

Released under the GPLv2 or later, the same licence WordPress and WooCommerce use. The full text is available at [https://www.gnu.org/licenses/old-licenses/gpl-2.0.html](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html). The licence is also declared in the plugin header at the top of `mmg-checkout-woocommerce.php`.

## Author

Built by [Revamped GY](https://revamped.gy). Plugin landing page: [revamped.gy/mmg-woocommerce-plugin-guyana](https://revamped.gy/mmg-woocommerce-plugin-guyana).
