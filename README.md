# MMG Checkout for WooCommerce (Guyana)
MMG Checkout for WooCommerce is a full MMG payment gateway and business toolkit built by Revamped GY for WooCommerce stores in Guyana.

This GitHub repository is for:
- Bug reports
- Feature requests
- Suggestions and improvements

Official plugin downloads are available only from Revamped GY.

Plugin page: https://revamped.gy/mmg-woocommerce-plugin-guyana  
Company: https://revamped.gy  
Self hosted updates JSON endpoint: https://revamped.gy/updates/mmg-checkout.json

## What this plugin helps you do
If you run WooCommerce in Guyana and you need Mobile Money Guyana payments (MMG), this plugin focuses on reliability, clean setup, accurate order history, and merchant tools.

Highlights:
- MMG payment gateway with Live and Sandbox modes
- Works with WooCommerce Blocks Checkout
- Stable return and callback handling with protection against duplicates
- Stores MMG transaction details on the WooCommerce order
- Admin tools under one menu: MMG Checkout
- Feature Manager to enable or disable modules
- Role Manager to control staff access
- Diagnostics, logging, support bundle
- Reporting and exports
- Payment Requests (invoice style pay links)
- QR Payments module with a fallback flow for hardened security setups

## Official downloads only
This repository does not host the plugin ZIP or source code.

If you need the plugin:
- Download it from the official plugin page: https://revamped.gy/mmg-woocommerce-plugin-guyana

If you downloaded the plugin from anywhere else, it is not an official Revamped GY distribution.

## Setup flow for merchants (important)
MMG credentials are issued by MMG Merchant Services.

The normal flow is:
1. Request MMG API credentials from MMG Merchant Services
2. Provide the Callback URL during the credential request (copy it from the plugin Diagnostics page)
3. Receive the Sandbox credential package zip (UAT package)
4. Import the zip into the plugin Importer
5. Test end to end
6. Record a test video and send it to MMG Merchant Services
7. Receive the Live credential package zip
8. Import Live zip, switch the plugin to Live, test, then go live

## Before you open an issue
Please confirm:
- WordPress version
- WooCommerce version
- Plugin version
- Whether you are using Blocks checkout
- Whether you use security plugins, caching, or hosting WAF rules
- Steps to reproduce

If your issue involves payments or callbacks, include:
- A screenshot of MMG Checkout → Diagnostics (redact secrets)
- Any plugin logs or a Support Bundle if available

## Security reports
Do not post security vulnerabilities in public issues.

Use the Security template guidance and contact Revamped GY privately.
Official site: https://revamped.gy

## Keywords
MMG Checkout, Mobile Money Guyana, WooCommerce MMG, WordPress payment gateway, Guyana e-commerce, WooCommerce plugin Guyana, MMG payment gateway WooCommerce.
