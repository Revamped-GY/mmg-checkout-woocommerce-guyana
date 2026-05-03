# Security Policy

MMG Checkout for WooCommerce handles live payment data. Please treat any potential issue accordingly.

## Reporting a vulnerability

If you find a security issue, do not open a public GitHub issue. Send the details privately to **support@revamped.gy** with the subject line `MMG Checkout security`.

A useful report contains:

- The plugin version (see WP Admin → Plugins, or `MMGWC_VERSION` in `mmg-checkout-woocommerce.php`)
- WordPress and WooCommerce versions
- PHP version
- A short description of the impact and the steps to reproduce
- Any logs, requests or order ids that help verify the issue (with secrets redacted)

You will get an acknowledgement within five working days. If the issue is confirmed, we will agree a disclosure timeline before any public details are shared.

## In scope

- The MMG payment gateway and its callback handler
- The credential importer, settings storage and at-rest encryption
- The self-hosted updater and any package integrity checks
- The QR Payments, Payment Requests, Subscriptions and Support Bundle modules
- The custom REST or AJAX endpoints exposed by the plugin

## Out of scope

- Third-party plugins, themes or hosting issues that are not caused by this plugin
- Vulnerabilities in WordPress core or WooCommerce core (please report those upstream)
- Misconfigured credentials supplied by the merchant (for example a leaked private key the merchant pasted into a public document)
- Issues that require a malicious administrator with `manage_options` to be useful, since that role can already modify any plugin file

## Hardening guidance for stores

- Keep `MMGWC_LIVE_PRIVATE_KEY` and the other live credentials out of the database where possible by defining them as constants in `wp-config.php`. The Diagnostics page lists every supported constant.
- Run WordPress and WooCommerce on the latest stable releases. Keep PHP on a supported branch.
- Limit which roles can access the Importer, Logs, Features Manager and Role Manager pages. The plugin already restricts these to administrators by default.
- Review the access tokens, webhook secrets and API credentials any other plugin holds. Compromise of a different plugin can still expose payment data on the same site.

## What the plugin already does

- Encrypts protected settings (private keys, secret keys, API credentials) at rest using AES-256-GCM derived from `AUTH_KEY` and `SECURE_AUTH_SALT`. Existing plaintext values from older versions are still readable.
- Never logs raw tokens, secret keys or PEM blocks. The logger redacts known sensitive keys, bearer headers and PEM blocks recursively.
- Verifies the host of the update package and refuses non-HTTPS download URLs.
- Optionally verifies the SHA-256 of the update package before installing it.
- Returns a single generic error for any decryption failure to avoid oracle leakage.
- Uses POST plus nonces for state-changing customer and admin actions so nonces do not leak in referer or browser history.
- Treats all customer and webhook input as untrusted.

## Disclosures

There are no known public advisories for this plugin at the time of this file. Confirmed disclosures will be linked in this section once they are resolved and a fix is released.
