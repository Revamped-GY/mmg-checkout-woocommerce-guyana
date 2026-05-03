# Contributing

This repository powers a live payment plugin used by Guyanese WooCommerce stores. The bar for changes is "does this keep merchants able to take MMG payments". Anything that touches checkout, callbacks, order resolution or credential handling is treated as load-bearing.

If you are not part of Revamped GY and would like to contribute, please open an issue first to discuss the change.

## Local setup

The plugin lives at the repo root. The folder is the live source. Old per-version source snapshots are not in the repo because git tags handle that.

```
.
├── mmg-checkout-woocommerce.php   plugin bootstrap
├── readme.txt                     WP.org-style readme
├── uninstall.php                  uninstall handler
├── assets/                        bundled CSS, JS, images
├── includes/                      PHP classes
├── docs/                          local-only reference material (gitignored)
├── README.md
├── SECURITY.md
├── CHANGELOG.md
├── CONTRIBUTING.md
├── CLAUDE.md
└── .github/                       workflows and issue templates
```

To test locally drop the repo content into `wp-content/plugins/mmg-checkout-woocommerce/` of a WordPress install with WooCommerce active. PHP 7.4 or newer.

## Coding conventions

These match the existing source. Keep them consistent with anything you change.

- PHP files use hard tabs. JavaScript uses two spaces.
- Output is escaped with `esc_html`, `esc_attr`, `esc_url` or `wp_kses_post`. Never echo `$_GET` or `$_POST` raw.
- Every `admin_post_*` and `wp_ajax_*` handler verifies a nonce and a capability. AJAX handlers verify the nonce before reading any other request data.
- Settings are read through `MMGWC_Settings::get($key)` so constant overrides and at-rest decryption work. Settings are written through `MMGWC_Settings::update_partial([...])` so unrelated keys survive and protected keys get encrypted.
- Sensitive keys go in `MMGWC_Secure_Store::protected_keys()`.
- Database queries use `$wpdb->prepare( $sql, ...$params )` with the spread form. The deprecated array-as-second-arg form is not allowed.
- Large or sensitive options use `update_option( $key, $value, false )` so they do not autoload.
- State-changing customer-facing actions are POST forms with `wp_nonce_field`. Never `GET ?action=...&_wpnonce=...`.
- No new external HTTP dependencies without a strong reason. The MMG logo, the Revamped GY admin logo and QR images are all bundled locally.

## Style of writing

Documentation, release notes and admin UI strings use UK English. No em dashes. No Oxford commas. Plain English wording, the way the rest of the plugin reads.

## Branching

- `main` is always installable.
- Work happens on short-lived branches named `<topic>` or `<issue>-<topic>`.
- Pull requests target `main` and need at least one passing run of the lint workflow.

## Versioning

The plugin uses `MAJOR.MINOR.PATCH`. The version appears in three places that must always match:

1. `mmg-checkout-woocommerce.php` plugin header `Version:`
2. `mmg-checkout-woocommerce.php` `MMGWC_VERSION` constant
3. `readme.txt` `Stable tag:`

The release workflow refuses to build a tag where these three do not agree.

## Releasing

1. Bump the version in the three places above.
2. Add a section to `CHANGELOG.md` and a matching entry to the `== Changelog ==` block in `readme.txt`.
3. Commit on `main`.
4. Tag the commit with `v<version>` (for example `v2.14.21`).
5. Push the tag. The `Release` workflow builds the ZIP, computes its SHA-256 and creates a GitHub Release with the asset attached.
6. The plugin's self-hosted updater on installed sites picks up the new release on its next check.

## What not to commit

- Real MMG credentials, private keys or `setup.cfg` files
- Sample `.env` files with live values, only `.env.example`
- Database dumps, debug logs, customer data
- Generated ZIP archives (those go to GitHub Releases)
- Local IDE files, OS files, swap files

If you are not sure, run `git status` before pushing and check the diff. The `.gitignore` covers most cases but secrets sometimes sneak in through copy-paste.
