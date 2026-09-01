=== MMG Checkout for WooCommerce ===
Contributors: revampedgy
Tags: woocommerce, payment gateway, mmg, guyana, merchant checkout, blocks checkout
Requires at least: 6.0
Tested up to: 6.9.1
Tested with: 6.9.1
Requires PHP: 7.4
Stable tag: 2.16.2
License: GPLv2 or later

== Description ==

MMG Checkout for WooCommerce adds MMG Merchant Checkout as a WooCommerce payment method. It supports both Classic Checkout and WooCommerce Block Checkout, then brings the full setup and day to day tools into one place under WP Admin → MMG Checkout.

This plugin is built for Guyanese stores using MMG, with a focus on reliability, clean order updates, and fast support.

== Features ==

* Live and Sandbox modes that you can switch any time
* MMG credential Importer for hosted checkout packages and optional Merchant Initiated Postman environments
* Works with WooCommerce Block Checkout
* Optional short hosted-checkout walkthrough with responsive image previews
* Optional MMG app approval request method using the documented Merchant Initiated API
* Order notes and stored MMG transaction details for audit and support
* Strong callback handling with duplicate callback protection (idempotency)
* Clear status mapping with better customer messages
* Advanced status mapping options for stores that want different behaviour
* Verify payment tool on the order screen using optional MMG Transaction Lookup credentials
* Resend payment link tool for pending orders
* Payment Requests: create an invoice order, send a secure pay link, track paid and expired
* Subscriptions: reminder based renewals with subscriber tracking and renewal links
* Customer portal: My Account → My Invoices and My Subscriptions
* Exports: detailed CSV exports and monthly summary exports for accounting
* Currency conversion to GYD for non GYD stores, with rounding to the nearest 100 GYD
* Diagnostics page with callback URL, environment checks, logs, and quick tests
* Support Bundle generator to send a single zip for support
* Feature Manager to enable or disable modules
* Role Manager to control which roles can access each module

== Installation ==

1. Upload the plugin zip: WordPress → Plugins → Add New → Upload Plugin
2. Activate the plugin
3. Go to WP Admin → MMG Checkout → Settings
4. Enable MMG Checkout and complete your configuration

== Configuration ==

1. Go to WP Admin → MMG Checkout → Settings
2. Choose your Mode: Sandbox or Live
3. Go to WP Admin → MMG Checkout → Importer
4. Upload the zip you received from MMG, or upload setup.cfg and key files separately
5. If the package includes a Merchant Initiated Postman environment, import its optional API fields for Sandbox or Live
6. Go to WP Admin → MMG Checkout → Diagnostics and copy the Callback URL into the Response URL for the active merchant environment
7. Run a test order and confirm the order status updates and emails are sent
8. Complete the optional Merchant Initiated API fields only if you want app approval requests or manual Transaction Lookup

== Payment Requests ==

Payment Requests let you create a real WooCommerce order in the background, then send a secure pay link to the customer. The customer lands on a WooCommerce pay page, pays with MMG, then the order updates like a normal checkout.

Find it at: WP Admin → MMG Checkout → Payment Requests

== Subscriptions ==

Subscriptions in this plugin are renewal reminders, not automatic recurring billing. Each successful payment updates a subscriber record, calculates the next due date, then sends reminders on your schedule. Customers can view and manage their subscriptions from My Account.

Find it at: WP Admin → MMG Checkout → Subscriptions

== Exports ==

Exports provide a detailed CSV of MMG payments between dates with filters, plus a monthly summary for reporting and accounting.

Find it at: WP Admin → MMG Checkout → Exports

== Security ==

* Do not email your key files or share them on chat platforms
* Use the Importer for uploads, then restrict admin access and use 2FA where possible
* For production sites, consider wp-config.php constants to keep secrets out of the database
* Keep WordPress, WooCommerce, and this plugin updated

== Frequently Asked Questions ==

= My order is stuck on Pending payment =
Check the MMG dashboard for the transaction status, then open the order and use Verify payment. If it still does not update, check plugin logs and generate a Support Bundle.

= The customer paid but closed the tab =
Use Verify payment on the order. This can update the order without the customer returning.

= MMG shows paid but WooCommerce did not update =
Confirm the configured MMG Response URL matches the Callback URL shown in Diagnostics. Also confirm you imported the correct Merchant Checkout keys for the selected mode.

= I changed modes and now decryption fails =
Sandbox keys only work in Sandbox mode. Live keys only work in Live mode. Re import the correct files for the current mode.

== Changelog ==

= 2.16.2 =

* Restored normal hosted checkout when optional Merchant Initiated API fields are empty.
* Accepted the encrypted MMG Checkout Response using exact session, mode, order and transaction checks.
* Renamed the shared optional fields to Merchant Initiated API and removed the extra authorisation checkbox.
* Kept Transaction Lookup as an additional check when its documented fields are configured.
* Preserved stored merchant settings during the update.
* Imported optional Sandbox and Live Merchant Initiated Postman environments in MMG's JSON field order.
* Made definitely rejected approval requests retryable while keeping uncertain requests protected from duplicates.
* Replaced both hosted walkthrough images and added the OTP step.
* Prevented repeated public QR requests from creating unbounded or duplicate payable orders.
* Restricted payment-request links and renewal links to the correct object and customer.
* Expanded support-bundle redaction for API headers, tokens, passwords and private keys.

= 2.16.1 =

* Separated shared API fields from approval-only settings.
* Prevented protected plaintext and encrypted storage values from appearing in settings forms.
* Preserved stored credentials when replacement fields are left blank and flagged unreadable values for re-entry.

= 2.16.0 =

* Added a compact Login and QR walkthrough with responsive lightbox previews and an on or off setting.
* Added a disabled-by-default MMG app approval method with authenticated polling and fail-closed payment verification.
* Split API credentials by Sandbox and Live mode and updated the public UAT API base path.
* Fixed the GitHub repository and moved package verification before installation.
* Added a one-time verified legacy manifest bridge for existing released installations.
* Preserved authenticated callbacks for hosted checkouts started before the 2.16.0 update.
* Added automatic versioned releases for main-branch version updates.
* Added guidance for the confirmed Login and QR switching race on MMG's hosted page.
* Required exact release asset names and verified package hashes after all update-download filters run.

= 2.15.0 =

* Security: a browser callback can no longer mark an order paid by itself. Successful callbacks now require an authenticated MMG Transaction Lookup result that matches the exact stored merchant transaction ID, provider transaction ID, immutable amount, GYD currency, merchant account, checkout mode and current order state.
* Security: merchant transaction IDs now include 128 bits of randomness and order resolution uses only an exact stored metadata match. Predictable order ID parsing was removed.
* Security: provider transaction IDs cannot be reused across orders. Duplicate callbacks for the same verified order remain idempotent.
* Security: the admin Verify payment tool applies the same amount, currency, merchant, uniqueness and order-state checks as the public callback.
* Privacy: callback and lookup metadata now retain only an allow-listed payment summary. Customer wallet party data and provider HTML are not stored.
* Changed: MMG is hidden from checkout until the authenticated Transaction Lookup credentials are complete. Existing checkout credentials alone are not sufficient to prove settlement.

= 2.14.21 =

* Switched the plugin update channel to GitHub Releases. The plugin now reads the latest release from the project's GitHub repository, finds the attached ZIP and optional SHA-256 sidecar and installs it through the standard WordPress upgrader. The repo is filterable via `mmgwc_github_repo` and the allowed download hosts via `mmgwc_update_allowed_hosts`. An optional `mmgwc_github_token` filter is available for higher API rate limits.
* Tightened updater safety: HTTPS required for both the API call and the package download, hosts restricted to github.com and objects.githubusercontent.com by default, pre-releases and drafts ignored, version downgrades rejected.
* Plugin details modal now sources Tested up to, Requires at least and Requires PHP from the local readme.txt so the WP UI always shows accurate compatibility info.
* The legacy self-hosted JSON manifest is still consulted as a fallback if the GitHub call fails. Removable in a future release once all sites are on 2.16.0 or newer.

= 2.14.20 =

* Made the MMG checkout session always fresh by default. Every customer attempt now generates a new MMG session URL and a new merchantTransactionId, so there is no chance of MMG rejecting a reused id after an abandoned first attempt. The only cached case is two concurrent calls landing inside the same wall-clock second, which would otherwise generate an identical id. That narrow window is resolved by returning the in-progress URL once. Stores that need a longer reuse window for a specific integration can raise it with the `mmgwc_checkout_session_reuse_seconds` filter.

= 2.14.19 =

Hotfix for two issues reported against 2.14.18.

* Restored the branded MMG logo on the checkout payment method and the Revamped GY logo in the WP Admin header. Both are now shipped as bundled .webp files under assets/images/ so no external request is needed. The URLs remain filterable via `mmgwc_gateway_icon_url` and `mmgwc_brandbar_logo_url`.
* Fixed an issue where a customer who abandoned the MMG page without returning (for example by closing the tab or losing signal before the OTP step) could see "We are unable to process your request right now" on their next attempt, or the MMG QR would fail to render. The plugin used to reuse the same MMG checkout session URL (and the same merchantTransactionId) for 20 minutes; MMG rejects that id on the next attempt because it has already seen it. The reuse window is now 60 seconds, which still absorbs theme double-init but guarantees a fresh MMG session whenever the customer actually retries. Also exposed the window via a new `mmgwc_checkout_session_reuse_seconds` filter so the default can be tuned per site.
* Reduced log context on the "MMG redirect reused" message so the full URL (including token) is no longer written to the log file.

= 2.14.18 =

Major security and reliability release covering the full plugin.

Security
* Encrypts private keys, secret keys and API credentials at rest using AES-256-GCM keyed on the site's AUTH_KEY. Existing values continue to work transparently.
* Large settings values are now stored with autoload disabled, so PEM blobs no longer load on every request.
* QR code images are now generated locally as inline SVG. Payment-link tokens are never sent to third-party services (previously api.qrserver.com).
* Removed the unauthenticated QR template AJAX endpoint. Only logged-in users with manage_woocommerce can create QR templates. Tokens are now 32 random bytes.
* The Role Manager will no longer grant Importer, Logs, Features Manager or Role Manager caps to non-administrator roles.
* Feature Manager now persists only known feature slugs; unknown slugs are dropped.
* Admin "order notice" messages are now delivered via short-lived transients, blocking crafted URLs from showing fake admin notices.
* Verify Payment AJAX now verifies the nonce before reading any request data.
* Payment-link emails strip CR / LF characters and validate the URL before sending.
* Credential Importer now enforces size, ZIP entry count, uncompressed size and PEM validation, and blocks paths with traversal segments.
* Support Bundle temp file is now written to the system temp directory with a random suffix, not to the publicly-served uploads folder. Settings redaction now uses an allow-list so future keys do not accidentally leak.
* Self-hosted updater rejects non-HTTPS URLs, requires the download host to match the JSON host (filterable), refuses version downgrades, caches correctly, and optionally verifies a package SHA-256 declared in the update JSON.
* RSA-OAEP decryption now returns a single generic error to avoid oracle leakage.
* Logger now scrubs nested arrays, bearer tokens and PEM blocks and covers more key names.
* Callback handler returns accurate HTTP status codes (400/404/503) instead of HTTP 200 on error.
* Rewrite regexes tightened: QR tokens must be alphanumeric and at least 10 characters.
* My Account subscription actions are now POST-only, so nonces do not leak through referer or history.

Reliability
* Fixed MMGWC_Settings::get_config returning constant names instead of actual values for status mapping and currency settings.
* Removed a duplicate MMGWC_Checkout_Compat::init() call that double-registered frontend hooks.
* Analytics page now paginates and caps at 5000 orders to prevent out-of-memory on large shops.
* Exports query by payment_method at the database level, add a UTF-8 BOM, and escape CSV cells that could trigger formula injection in Excel or LibreOffice.
* Subscriptions cron is now guarded by a short lock so overlapping WP-Cron and system cron runs cannot double-send emails or create duplicate renewal orders.
* FX rates are validated against a plausibility window so a bad feed cannot silently bill a near-zero amount.
* FX refresh accepts only ISO-4217 currency codes.
* QR render_public_message call sites now return after rendering, so future refactors cannot fall through.
* Payment Requests and QR Payments now only accept published products.
* Subscription update_status enforces a status enum.
* Settings save merges into the existing option rather than replacing it, preserving unrelated keys like qr_checkout_fallback.
* External dependencies for the admin logo and gateway icon are now bundled locally.
* Uninstall handler added: drops plugin options, transients, capabilities and the subscriptions table on plugin delete. Deactivation no longer strips custom role caps.
* Internal log rotation is now symlink-safe; the logs view has a hard memory cap.
* Added the checkout URL to the Callback URL helper text with proper escaping.

Compatibility
* WooCommerce 9.4 compatibility retained.
* Classic Checkout and Blocks Checkout flows unchanged at the merchant-facing level.
* No changes to the MMG token payload or callback URL shape, so existing credentials and URLs remain valid.

= 2.14.17 =

Improved retry behaviour after failed MMG attempts by clearing the cached checkout session and merchant transaction id on failed, cancelled, and timed out responses so customers can return to the same checkout page and try again immediately.

Preserved protection against duplicate theme initiations by still reusing the active MMG checkout URL while the customer is in progress, which helps custom checkout flows such as Elessi.

Reduced cases where the MMG page reopens an expired or invalid session, which can also affect QR display and repeated login attempts.

= 2.14.16 =

Improved stability for custom checkout themes such as Elessi by reusing the last generated MMG checkout URL for 20 minutes, preventing OTP interruptions caused by duplicate initiation events.

Improved callback compatibility by supporting token return formats like ?wc-api=mmg-checkout/<TOKEN> and preventing canonical redirects from rewriting callback requests.

Improved order resolution by extracting the WooCommerce order id from merchantTransactionId format <order_id>-<timestamp>.


= 2.14.15 =

Improved MMG checkout initiation so retry attempts generate a fresh merchant transaction id after a short double click window.

This reduces cases where MMG rejects a reused transaction id and the customer sees “Unable to process your request” during the OTP step.

Adjusted requestInitiationTime formatting to be sent as a string for stricter gateway validation.


= 2.14.14 =

Fixed a checkout “-1” blank page failure caused by themes or checkout customisations omitting the WooCommerce process checkout nonce field.

Added a lightweight checkout compatibility script that injects the missing nonce into the checkout form when needed, improving compatibility with custom checkout UIs.


= 2.14.13 =

Fixed Live (Production) checkout URL to use the correct MMG payment host (mmgpg.mymmg.gy).

Added an automatic migration that replaces the old mmgpg.mmg.gy URL in saved settings to prevent “This site can’t be reached” errors after switching to Live.


= 2.14.12 =

Added the MMG logo icon next to the MMG Checkout payment method label, aligned neatly like other gateways.

Works on both Classic checkout and WooCommerce Blocks checkout.

= 2.14.11 =

Fixed QR checkout link mode so QR links open a real WooCommerce order pay page that shows payment options and the Pay button.

In checkout link mode, QR orders created by admins are stored as guest orders so customers can pay without being forced to log in.

In checkout link mode, the plugin no longer forces MMG as the order payment method so the pay screen can load gateways normally.

= 2.14.10 =

Changed QR checkout link mode so newly generated QR links become standard WooCommerce /checkout/order-pay/... links (no custom mmgwc_qr query token).

In checkout link mode, the plugin creates the order at QR generation time, similar to Payment Requests, then customers land on checkout first and choose a payment method.

= 2.14.9 =

Added a QR Payments alternative redirect mode that sends customers to the WooCommerce pay screen first. This is useful if the direct MMG redirect method is blocked by a security plugin, caching, or hosting security rules.

Added a checkbox setting under MMG Checkout → QR Payments to enable the alternative checkout flow.

= 2.14.8 =

Fixed QR public token handling so tokens are safely normalised even on hardened setups where query parameters may arrive in unexpected formats.

Improved fail fast behaviour for invalid links and added no cache headers for QR token requests.


= 2.14.6 =

Fixed an admin UI glitch where a stray “QR Payments” link could appear in the top-left during page loads, especially noticeable on mobile view.

Cleaned up admin menu rendering to prevent unintended output during menu registration.

= 2.14.5 =

Fixed QR payments redirect behaviour so QR links now send customers straight to the MMG payment page reliably.

Adjusted redirect handling to avoid WordPress safe redirect restrictions that could push users back to the site homepage.

= 2.14.4 =

Improved QR link compatibility by defaulting to a query-string QR link format for better reliability across security plugins and hosting rules.

Reduced edge cases where pretty links could trigger forced login redirects.

= 2.14.3 =

Fixed a fatal TypeError in the QR payment handler caused by passing the wrong variable type into the order creation function.

Hardened token parsing to prevent invalid data from reaching the QR order builder.

= 2.14.2 =

Added QR routing improvements to reduce unwanted homepage redirects caused by canonical redirects or query stripping.

Improved public handler detection to accept multiple QR URL formats cleanly.

Improved rewrite and handler checks to make QR links more resilient.

= 2.14.1 =

Fixed a critical error after release by ensuring QR module classes are loaded before initialising the feature.

Updated internal module bootstrapping for safer feature loading.

= 2.14.0 =

Added QR MMG Payments module with admin generator and frontend shortcode support.

Added expiry handling and duplicate payment protection for QR payment links.

Added QR content and navigation updates in Overview and Help pages.

= 2.13.1 =

Added self hosted update support using a JSON metadata endpoint and hosted ZIP downloads so users can update from wp-admin.

Updated readme changelog and release notes for the new updater flow.

= 2.13.0 =

Refreshed the plugin admin UI to match Revamped GY branding with cleaner layout, improved spacing, and nicer controls.

Improved consistency of admin components across all MMG Checkout pages.

= 2.12.4 =

Updated documentation flow across the plugin to reflect the correct callback process: copy callback URL from Diagnostics and send it to MMG when requesting credentials.

Updated wording across Overview, Help, and relevant settings hints.

= 2.12.3 =

Fixed a fatal admin error caused by an invalid footer hook that could break the entire WordPress admin menu.

Removed the global footer injection and kept support branding only where intended.

= 2.12.2 =

Corrected the setup flow in Overview and Help: request UAT package, import, test, send test video to MMG, receive Live package, import, then switch to Live.

Updated plugin row links (View Details and Visit Plugin Site) to the new plugin landing page.

= 2.12.1 =

Fixed My Account endpoint handling that could cause critical errors when loading customer account pages.

Updated Help and Overview to match the latest feature set and navigation.

Expanded readme and changelog structure.

= 2.12.0 =

Added Features Manager to enable or disable major plugin modules and hide their menus when disabled.

Added Role Manager so site admins can grant access to specific MMG features per user role.

Locked core management pages (Feature Manager, Role Manager) so they cannot be accidentally disabled.

= 2.11.1 =

Fixed Analytics menu registration so the Analytics page reliably appears under MMG Checkout.

= 2.11.0 =

Added improved subscription controls: grace period logic with overdue tagging, pause and resume, and stop reminders after missed cycles.

Added per-subscriber renewal price locking, not just per-product locking.

Added lightweight Analytics dashboard: weekly and monthly totals, success, failed, cancelled, pending counts, and top products.

= 2.10.0 =

Added My Account portal sections: My Invoices and My Subscriptions.

Improved exports with more filters: by status, product, customer, and a monthly summary export option.

= 2.9.1 =

Added Support Bundle tool to generate a redacted zip of diagnostics, logs, and optional order details for faster troubleshooting.

= 2.9.0 =

Added full Subscription reminders module with subscriber tracking table, reminder scheduling, and email templates.

Added product-level “MMG Subscription” settings so subscription rules can be set per product.

Added renewal link generation using real WooCommerce renewal orders for clean audit history.

= 2.8.0 =

Added Payment Requests module using WooCommerce “pay for order” flow.

Created Payment Requests list and Create Request page, supporting both products and custom invoice-only items.

Added request expiry support and safeguards to prevent paying expired invoices.

= 2.7.0 =

Added currency conversion support for stores selling in non-GYD currencies, converting totals to GYD for MMG.

Added Diagnostics support for currency details, rate source visibility, caching, and a manual refresh button.

Added order metadata so conversion rates and rounding differences are visible in WooCommerce.

= 2.6.0 =

Added resend payment link tool and improved exports and admin reporting.

Added advanced order status mapping options for different fulfilment workflows.

Added log rotation to prevent huge debug files.

= 2.5.1 =

Fixed WooCommerce Blocks Checkout compatibility issues and improved checkout reliability.

= 2.5.0 =

Moved all settings and tools into a dedicated WP Admin menu: MMG Checkout.

Added credential Importer supporting MMG zip package uploads plus manual cfg and key uploads.

Improved user guidance on where to get files and how to go live.

= 2.0.0 =

Major rewrite focusing on stability, logging, diagnostics, and better WooCommerce status handling.

Added Verify Payment button in the order screen and stronger duplicate callback protections.

Improved plugin metadata, settings descriptions, and plugin row links.

= 1.0.3 =

Fixed return handling so orders update correctly after MMG payment, removing the “-1” return issue.

Improved callback handling to prevent pending orders after successful payment.

= 1.0.2 =

Improved MMG request flow and fixed “Unable to Process” errors caused by request formatting issues.

Strengthened redirect and response validation.

= 1.0.1 =

Improved gateway stability and added better error handling for redirects and order creation.

= 1.0.0 =

Initial release: MMG Checkout payment gateway for WooCommerce with core redirect flow and order creation.
