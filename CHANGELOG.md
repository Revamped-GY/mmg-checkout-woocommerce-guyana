# Changelog

All notable changes to MMG Checkout for WooCommerce are recorded here. The format follows Keep a Changelog and the project follows Semantic Versioning.

## [2.16.2] - 2026-09-01

### Fixed

- Restored standard hosted checkout when the optional Merchant Initiated API fields are empty.
- Accepted MMG's encrypted Checkout Response using exact session, credential mode, order snapshot and transaction uniqueness checks.
- Kept unavailable Transaction Lookup requests from blocking a documented hosted payment result.
- Preserved all stored merchant settings during plugin updates and unrelated settings saves.
- Distinguished requests rejected before creation from uncertain connection results, so definite rejections can be retried safely.
- Replaced missing walkthrough images and added the MMG OTP step to the hosted checkout guidance.

### Changed

- Renamed the optional shared API fields to Merchant Initiated API and removed the separate authorisation checkbox.
- Uses the documented Transaction Lookup fields as an additional check when they are configured.
- Imports optional Sandbox or Live Merchant Initiated Postman environments in the same field order as MMG's JSON.

### Security

- Made public QR order creation atomic and bounded each link to one active unpaid order.
- Restricted Payment Request links and emails to valid module-owned MMG orders.
- Moved subscription renewal creation to an owned POST action and invalidated replaced links.
- Expanded support-bundle redaction for API headers, tokens, passwords and private keys.

## [2.16.1] - 2026-08-31

### Fixed

- Separated shared API fields from approval-only settings.
- Prevented protected plaintext and encrypted storage values from appearing in settings forms.
- Preserved stored credentials when replacement fields are left blank and flagged unreadable values for re-entry.

## [2.16.0] - 2026-08-31

### Added

- Compact hosted-checkout guidance with responsive image lightboxes and an on or off setting.
- A disabled-by-default MMG app approval method with authenticated background reconciliation.

### Changed

- Creates a verified release after a version-bumped push reaches `main`, after PHP 7.4 to 8.3 validation.

### Fixed

- Corrected the GitHub update repository and moved SHA-256 verification before package installation.
- Added a one-time verified legacy manifest bridge so existing released installations can discover 2.16.0.
- Preserved authenticated callbacks for hosted checkouts started before the 2.16.0 update.
- Split API credentials by Sandbox and Live mode and corrected the published UAT API base path.
- Added safe retry guidance for MMG's hosted Login and QR switching race.

### Security

- Added atomic provider transaction claims across orders.
- Blocked uncertain initiation retries and kept pending or unclear payments unpaid for manual review.
- Restricts paid and unpaid order status mappings to their correct payment states.
- Required exact release asset names and verified package hashes after all update-download filters run.
- Restricted credential-bearing API requests to MMG-controlled hosts by default.

## [2.15.0] - 2026-08-30

### Security

- Requires an authenticated MMG Transaction Lookup before a browser callback can mark an order paid.
- Verifies the exact stored merchant transaction ID, provider transaction ID, immutable amount, GYD currency, credited merchant, credential mode, order total, order currency and payment state.
- Rejects provider transaction IDs already attached to another order while keeping repeat callbacks for the same verified order idempotent.
- Removes order resolution by numeric prefix. Callbacks resolve only through an exact stored merchant transaction ID.
- Generates merchant transaction IDs with 128 bits of randomness instead of an order ID and timestamp alone.
- Applies the same authenticated invariants to the administrator Verify payment tool.

### Changed

- Hides MMG from checkout until all authenticated Transaction Lookup credentials are configured.
- Stores allow-listed callback and lookup summaries instead of full provider payloads. Customer wallet party data and provider HTML are excluded.
- Keeps the immutable verification snapshot after a successful payment for audit and replay protection.

## [2.14.21] - 2026-05-03

### Changed

- Update channel switched to GitHub Releases. The plugin now reads
  `https://api.github.com/repos/revamped-gy/mmg-checkout-woocommerce/releases/latest`,
  finds the `.zip` asset attached to the latest release, optionally verifies
  a `.zip.sha256` sidecar and applies the update through the standard
  WordPress upgrader. The repo is filterable via `mmgwc_github_repo` and the
  allowed download hosts via `mmgwc_update_allowed_hosts`. An optional
  `mmgwc_github_token` filter is available for higher API rate limits.
- Pre-releases and drafts on GitHub are ignored. Tags must follow a
  semantic-version pattern (`X.Y.Z` with optional pre-release suffix).
- Plugin details modal now reads `Tested up to`, `Requires at least` and
  `Requires PHP` from the locally installed `readme.txt` so the WP UI shows
  accurate compatibility information without a separate manifest.

### Security

- Updater requires HTTPS for both the API call and the downloaded asset.
- Default download hosts are restricted to `github.com` and
  `objects.githubusercontent.com`.
- Version downgrades are still rejected.

### Fallback

- The legacy JSON manifest (`MMGWC_UPDATE_JSON_URL`) is still consulted if
  the GitHub call fails (rate limit, network error, repo unavailable). This
  keeps existing 2.14.20 sites updatable even if GitHub is briefly
  unreachable. The constant can be removed in a future release once all
  installs are on 2.16.0 or newer.

## [2.14.20] - 2026-04-15

### Changed

- The MMG checkout session is always fresh by default. Every customer attempt now generates a new MMG session URL and a new `merchantTransactionId`, so MMG cannot reject a reused id after an abandoned first attempt. The only cached case is two concurrent calls landing inside the same wall-clock second, which would otherwise generate an identical id. That narrow window is resolved by returning the in-progress URL once. Stores that need a longer reuse window for a specific integration can raise it with the `mmgwc_checkout_session_reuse_seconds` filter.

## [2.14.19] - 2026-04-15

### Added

- Bundled MMG and Revamped GY brand logos as local `.webp` files under `assets/images/` so admin and checkout pages do not make an external request for branding. The URLs remain filterable via `mmgwc_gateway_icon_url` and `mmgwc_brandbar_logo_url`.

### Fixed

- Customers who abandoned the MMG page without returning could see "We are unable to process your request right now" on the next attempt or the QR would fail to render. The plugin used to reuse the same MMG checkout session URL for 20 minutes and MMG rejects the same id twice. The reuse window is now 60 seconds, tight enough to guarantee a fresh session on real retries but wide enough to absorb theme double-init. Superseded by 2.14.20 which sets the default to 0 seconds.
- Restored the branded MMG logo on the checkout payment method label and the Revamped GY logo in the WP Admin header. Both were missing in 2.14.18.

### Security

- The "MMG redirect reused" log message no longer includes the full URL with the encrypted token.

## [2.14.18] - 2026-04-14

Major security and reliability release covering the full plugin. No changes to the MMG token shape or callback URL, so existing credentials continue to work.

### Security

- Encrypts private keys, secret keys and API credentials at rest using AES-256-GCM keyed on the site's `AUTH_KEY`. Existing plaintext values continue to work transparently.
- Large settings values are stored with autoload disabled so PEM blobs no longer load on every request.
- QR code images are generated locally as inline SVG. Payment-link tokens are never sent to third-party services. Previously these were sent to api.qrserver.com.
- Removed the unauthenticated QR template AJAX endpoint. Only logged-in users with `manage_woocommerce` can create QR templates. Tokens are now 32 random bytes.
- Role Manager refuses to grant Importer, Logs, Features Manager or Role Manager capabilities to non-administrator roles.
- Feature Manager save now drops unknown slugs so the database only stores registered flags.
- Admin order-screen notices are delivered via short-lived per-user transients, blocking crafted URLs from showing fake admin notices.
- Verify Payment AJAX verifies the nonce before reading any request data.
- Payment-link and payment-request emails strip CR and LF characters from headers and body fields and refuse to send if the URL contains newlines.
- Credential Importer enforces size, ZIP entry count, uncompressed size and PEM validation, rejects traversal paths and returns a short generic error message to the admin while logging full detail internally.
- Support Bundle temp file is written to the system temp directory with a random suffix, not the publicly served uploads folder. Settings redaction now uses an allow-list so any future secret key is redacted by default.
- Self-hosted updater rejects non-HTTPS URLs, requires the download host to match the JSON host, refuses version downgrades, caches correctly and optionally verifies a package SHA-256 declared in the update JSON.
- RSA-OAEP decryption now returns a single generic error to avoid oracle leakage.
- Logger scrubs nested arrays, bearer tokens, PEM blocks and more key names.
- Callback handler returns accurate HTTP status codes (400, 404, 503) instead of HTTP 200 on error.
- Rewrite regexes tightened. QR tokens must be alphanumeric and at least 10 characters.
- My Account subscription actions are POST-only so nonces do not leak through referer or history.
- Admin Subscriptions pause, resume, cancel and lock actions are POST-only with inline form buttons.

### Added

- Uninstall handler that drops plugin options, transients, capabilities and the subscriptions table on plugin delete.

### Changed

- Analytics page paginates in 500-row chunks and caps at 5000 orders to prevent out-of-memory on large shops.
- Exports query by `payment_method` at the database level, add a UTF-8 BOM and escape CSV cells that could trigger formula injection in Excel or LibreOffice.
- Subscriptions cron is guarded by a short lock so overlapping WP-Cron and system cron runs cannot double-send emails.
- Renewal-order creation uses a per-subscription lock to prevent duplicate renewal orders under concurrent admin or cron activity.
- FX rates are validated against a plausibility window so a bad feed cannot silently bill a near-zero or absurdly large amount.
- FX refresh accepts only ISO-4217 currency codes.
- Payment Requests and QR Payments now only accept published products.
- Subscription `update_status` enforces a status enum so callers cannot persist arbitrary strings.
- External dependencies for the admin logo and gateway icon are bundled locally.
- Internal log rotation is symlink-safe. The logs view has a hard memory cap.
- Deactivation no longer strips custom role caps. Cap cleanup happens only on uninstall.
- WooCommerce 9.4 compatibility retained. Classic Checkout and Blocks Checkout flows unchanged at the merchant-facing level.

### Fixed

- `MMGWC_Settings::get_config` was returning constant names instead of actual values for status mapping and currency settings.
- A duplicate `MMGWC_Checkout_Compat::init()` call was double-registering frontend hooks.
- QR handler call sites now explicitly return after rendering a public message, protecting against future refactor regressions.
- Gateway settings save now merges into the existing option rather than replacing it, preserving unrelated keys such as `qr_checkout_fallback`.

## [2.14.17] - 2026-03-08

- Improved retry behaviour after failed MMG attempts by clearing the cached checkout session and merchant transaction id on failed, cancelled and timed out responses so customers can return to the same checkout page and try again immediately.
- Preserved protection against duplicate theme initiations by still reusing the active MMG checkout URL while the customer is in progress.
- Reduced cases where the MMG page reopens an expired or invalid session, which can also affect QR display and repeated login attempts.

## [2.14.16]

- Improved stability for custom checkout themes by reusing the last generated MMG checkout URL for 20 minutes, preventing OTP interruptions caused by duplicate initiation events.
- Improved callback compatibility by supporting token return formats like `?wc-api=mmg-checkout/<TOKEN>` and preventing canonical redirects from rewriting callback requests.
- Improved order resolution by extracting the WooCommerce order id from `merchantTransactionId` format `<order_id>-<timestamp>`.

## [2.14.15]

- Improved MMG checkout initiation so retry attempts generate a fresh merchant transaction id after a short double-click window.
- Adjusted `requestInitiationTime` formatting to be sent as a string for stricter gateway validation.

## [2.14.14]

- Fixed a checkout "-1" blank page failure caused by themes or checkout customisations omitting the WooCommerce process checkout nonce field.
- Added a lightweight checkout compatibility script that injects the missing nonce when needed.

## [2.14.13]

- Fixed Live (Production) checkout URL to use the correct MMG payment host (mmgpg.mymmg.gy).
- Added an automatic migration that replaces the old mmgpg.mmg.gy URL in saved settings to prevent "This site can't be reached" errors after switching to Live.

## [2.14.12]

- Added the MMG logo icon next to the MMG Checkout payment method label, aligned neatly like other gateways.
- Works on both Classic checkout and WooCommerce Blocks checkout.

## [2.14.11]

- Fixed QR checkout link mode so QR links open a real WooCommerce order pay page that shows payment options and the Pay button.
- In checkout link mode, QR orders created by admins are stored as guest orders so customers can pay without being forced to log in.
- In checkout link mode, the plugin no longer forces MMG as the order payment method so the pay screen can load gateways normally.

## [2.14.10]

- Changed QR checkout link mode so newly generated QR links become standard WooCommerce `/checkout/order-pay/...` links (no custom `mmgwc_qr` query token).
- In checkout link mode, the plugin creates the order at QR generation time, similar to Payment Requests, then customers land on checkout first and choose a payment method.

## [2.14.9]

- Added a QR Payments alternative redirect mode that sends customers to the WooCommerce pay screen first. Useful if the direct MMG redirect method is blocked by a security plugin, caching or hosting security rules.
- Added a checkbox setting under MMG Checkout → QR Payments to enable the alternative checkout flow.

## [2.14.8]

- Fixed QR public token handling so tokens are safely normalised even on hardened setups where query parameters may arrive in unexpected formats.
- Improved fail-fast behaviour for invalid links and added no-cache headers for QR token requests.

## [2.14.6]

- Fixed an admin UI glitch where a stray "QR Payments" link could appear in the top-left during page loads, especially noticeable on mobile view.
- Cleaned up admin menu rendering to prevent unintended output during menu registration.

## [2.14.5]

- Fixed QR payments redirect behaviour so QR links now send customers straight to the MMG payment page reliably.
- Adjusted redirect handling to avoid WordPress safe redirect restrictions that could push users back to the site homepage.

## [2.14.4]

- Improved QR link compatibility by defaulting to a query-string QR link format for better reliability across security plugins and hosting rules.
- Reduced edge cases where pretty links could trigger forced login redirects.

## [2.14.3]

- Fixed a fatal TypeError in the QR payment handler caused by passing the wrong variable type into the order creation function.
- Hardened token parsing to prevent invalid data from reaching the QR order builder.

## [2.14.2]

- Added QR routing improvements to reduce unwanted homepage redirects caused by canonical redirects or query stripping.
- Improved public handler detection to accept multiple QR URL formats cleanly.
- Improved rewrite and handler checks to make QR links more resilient.

## [2.14.1]

- Fixed a critical error after release by ensuring QR module classes are loaded before initialising the feature.
- Updated internal module bootstrapping for safer feature loading.

## [2.14.0]

- Added QR MMG Payments module with admin generator and frontend shortcode support.
- Added expiry handling and duplicate payment protection for QR payment links.
- Added QR content and navigation updates in Overview and Help pages.

## [2.13.1]

- Added self-hosted update support using a JSON metadata endpoint and hosted ZIP downloads so users can update from wp-admin.
- Updated readme changelog and release notes for the new updater flow.

## [2.13.0]

- Refreshed the plugin admin UI to match Revamped GY branding with cleaner layout, improved spacing and nicer controls.
- Improved consistency of admin components across all MMG Checkout pages.

## [2.12.4]

- Updated documentation flow across the plugin to reflect the correct callback process: copy callback URL from Diagnostics and send it to MMG when requesting credentials.
- Updated wording across Overview, Help and relevant settings hints.

## [2.12.3]

- Fixed a fatal admin error caused by an invalid footer hook that could break the entire WordPress admin menu.
- Removed the global footer injection and kept support branding only where intended.

## [2.12.2]

- Corrected the setup flow in Overview and Help: request UAT package, import, test, send test video to MMG, receive Live package, import, then switch to Live.
- Updated plugin row links (View Details and Visit Plugin Site) to the new plugin landing page.

## [2.12.1]

- Fixed My Account endpoint handling that could cause critical errors when loading customer account pages.
- Updated Help and Overview to match the latest feature set and navigation.
- Expanded readme and changelog structure.

## [2.12.0]

- Added Features Manager to enable or disable major plugin modules and hide their menus when disabled.
- Added Role Manager so site admins can grant access to specific MMG features per user role.
- Locked core management pages (Feature Manager, Role Manager) so they cannot be accidentally disabled.

## [2.11.1]

- Fixed Analytics menu registration so the Analytics page reliably appears under MMG Checkout.

## [2.11.0]

- Added improved subscription controls: grace period logic with overdue tagging, pause and resume, stop reminders after missed cycles.
- Added per-subscriber renewal price locking, not just per-product locking.
- Added lightweight Analytics dashboard with weekly and monthly totals, success, failed, cancelled and pending counts and top products.

## [2.10.0]

- Added My Account portal sections: My Invoices and My Subscriptions.
- Improved exports with more filters by status, product or customer plus a monthly summary export option.

## [2.9.1]

- Added Support Bundle tool to generate a redacted zip of diagnostics, logs and optional order details for faster troubleshooting.

## [2.9.0]

- Added full Subscription reminders module with subscriber tracking table, reminder scheduling and email templates.
- Added product-level "MMG Subscription" settings so subscription rules can be set per product.
- Added renewal link generation using real WooCommerce renewal orders for clean audit history.

## [2.8.0]

- Added Payment Requests module using WooCommerce "pay for order" flow.
- Created Payment Requests list and Create Request page, supporting both products and custom invoice-only items.
- Added request expiry support and safeguards to prevent paying expired invoices.

## [2.7.0]

- Added currency conversion support for stores selling in non-GYD currencies, converting totals to GYD for MMG.
- Added Diagnostics support for currency details, rate source visibility, caching and a manual refresh button.
- Added order metadata so conversion rates and rounding differences are visible in WooCommerce.

## [2.6.0]

- Added resend payment link tool and improved exports and admin reporting.
- Added advanced order status mapping options for different fulfilment workflows.
- Added log rotation to prevent huge debug files.

## [2.5.1]

- Fixed WooCommerce Blocks Checkout compatibility issues and improved checkout reliability.

## [2.5.0]

- Moved all settings and tools into a dedicated WP Admin menu: MMG Checkout.
- Added credential Importer supporting MMG zip package uploads plus manual cfg and key uploads.
- Improved user guidance on where to get files and how to go live.

## [2.0.0]

- Major rewrite focusing on stability, logging, diagnostics and better WooCommerce status handling.
- Added Verify Payment button in the order screen and stronger duplicate callback protections.
- Improved plugin metadata, settings descriptions and plugin row links.

## [1.0.3]

- Fixed return handling so orders update correctly after MMG payment, removing the "-1" return issue.
- Improved callback handling to prevent pending orders after successful payment.

## [1.0.2]

- Improved MMG request flow and fixed "Unable to Process" errors caused by request formatting issues.
- Strengthened redirect and response validation.

## [1.0.1]

- Improved gateway stability and added better error handling for redirects and order creation.

## [1.0.0]

- Initial release. MMG Checkout payment gateway for WooCommerce with core redirect flow and order creation.
