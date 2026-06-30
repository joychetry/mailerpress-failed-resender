# MailerPress Failed Resender

A WordPress add-on for [MailerPress](https://mailerpress.com) that lists every campaign with failed emails or stuck chunks, and gives you a one-click way to retry them. Built and maintained by [Classic Monks](https://classicmonks.com/).

## Features

- Adds a **Retry Failed** submenu under MailerPress in the WordPress admin.
- Lists every campaign that has at least one failed email or a chunk stuck in `failed` / `retry` / `processing`.
- **Retry Failed Emails**: resends every failed recipient through your active ESP. Honors the same rate-limit setting MailerPress uses.
- **Hard Reset Batch**: resets all chunks of a batch (including completed ones) and zeroes the sent/error counters. Useful when a batch is stuck after a partial failure.
- Live progress bar with sent / failed / skipped counters and ETA while a resend runs.
- Cancel an in-progress resend from the UI.
- An extra "Retry Failed" action injected into the per-row actions menu of the MailerPress campaigns list.
- WP-CLI command for running the same flows from the shell.

## Requirements

- WordPress 6.0 or higher
- PHP 7.4 or higher
- [MailerPress](https://mailerpress.com) (free or pro) installed and activated
- [Action Scheduler](https://actionscheduler.org/) (bundled with WooCommerce, usually already present)

## Installation

1. Upload the `mailerpress-failed-resender` folder to `/wp-content/plugins/`, or install the zip via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** screen.
3. Confirm MailerPress is active. A dependency notice will appear on the add-on's page if it isn't.

## Usage

### Admin page

Go to **MailerPress → Retry Failed**. The page lists every campaign that has errors. Use the buttons in the Actions column to retry a campaign or hard-reset its batch.

### REST API

The plugin registers its own routes under the `mpfr/v1` namespace, so it doesn't rely on MailerPress's REST authentication quirks.

| Method | Route | Purpose |
|---|---|---|
| `GET`  | `/wp-json/mpfr/v1/failed-campaigns` | List campaigns with failed emails |
| `POST` | `/wp-json/mpfr/v1/batch/{batch_id}/retry` | Reset failed/retry/processing chunks of a batch |
| `POST` | `/wp-json/mpfr/v1/batch/{batch_id}/reset` | Hard-reset a batch (zeros counters, all chunks pending) |
| `GET`  | `/wp-json/mpfr/v1/campaign/{campaign_id}/failed-count` | Count failed email logs for a campaign |
| `POST` | `/wp-json/mpfr/v1/campaign/{campaign_id}/resend-failed` | Start a background resend with live progress |
| `GET`  | `/wp-json/mpfr/v1/campaign/{campaign_id}/resend-progress` | Poll progress for an in-flight resend |
| `POST` | `/wp-json/mpfr/v1/campaign/{campaign_id}/resend-cancel` | Cancel an in-flight resend |

All routes require the `manage_options` capability and a valid `X-WP-Nonce` header.

### WP-CLI

```bash
# List campaigns with errors
wp mpfr retry-failed --list

# Retry one batch
wp mpfr retry-failed --batch=123

# Hard-reset one batch
wp mpfr retry-failed --batch=123 --reset

# Retry every batch that has errors
wp mpfr retry-failed --all
```

## How it works

The add-on does not modify MailerPress source files. It reads and writes the same database tables MailerPress uses:

- `{prefix}mailerpress_campaigns`
- `{prefix}mailerpress_email_batches`
- `{prefix}mailerpress_email_chunks`
- `{prefix}mailerpress_email_logs`

For chunk-level failures, the plugin resets the affected chunk rows to `pending` with a 60-second `scheduled_at` delay, so the MailerPress worker picks them up on its next tick. This mirrors `src/Api/Recovery.php::retryBatch()` in MailerPress itself.

For per-recipient failures (a chunk that completed, but a few recipients errored in `email_logs.status='error'`), the plugin rebuilds the merge-tag variable map (UNSUB_LINK, MANAGE_SUB_LINK, TRACK_OPEN, CONTACT_ID, CAMPAIGN_ID, custom fields) and re-sends through the active ESP. The rate limit is read from the same option MailerPress uses, so resends do not exceed the configured frequency.

## Frequently asked questions

**Does this modify MailerPress files?**
No. The plugin is fully self-contained.

**Does it work with MailerPress Pro?**
Yes. It detects both the free and pro versions.

**What happens to contacts that unsubscribed or bounced?**
They are skipped automatically, and their error logs are marked as `skipped` with the reason recorded in `error_message`.

**Can I cancel a resend while it's running?**
Yes. Use the Cancel button on the admin page, or `POST /wp-json/mpfr/v1/campaign/{id}/resend-cancel`. The batch that is currently executing finishes, but no further batches are scheduled.

**Is the add-on safe to remove?**
Yes. Removing the plugin does not affect MailerPress tables, scheduled actions, or in-flight campaigns.

**Who maintains this plugin?**
[Classic Monks](https://classicmonks.com/). We build and maintain a small set of WordPress add-ons for newsletters, transactional email, and ESP automation.

## Changelog

### 1.0.0
- First public release.

## License

GPL-2.0-or-later. See the `License` header in `mailerpress-failed-resender.php`.

## About Classic Monks

[Classic Monks](https://classicmonks.com/) is a WordPress enhancement toolkit. It replaces 20–30 separate plugins with one lightweight toolkit that boosts performance, improves WooCommerce, adds Bricks Builder features, strengthens security, and simplifies everyday tasks.

This add-on (MailerPress Failed Resender) is part of a small collection of plugins we maintain alongside the main [Classic Monks](https://classicmonks.com/) toolkit. If you run a MailerPress installation and need a custom integration, have a look at the [Classic Monks website](https://classicmonks.com/) for the full toolkit, documentation, and support options.

### The Classic Monks toolkit includes

- **Bricks Builder Integration** — extra elements and controls for the Bricks theme.
- **WooCommerce Enhancements** — performance and UX improvements for online stores.
- **Performance Optimization** — page-level caching tweaks, asset trimming, and smart script loading.
- **Security Features** — hardening against common WordPress attack vectors.
- **White Label Options** — rebrand the wp-admin for client sites.
- **Media Optimization** — clean up and optimize the media library.
- **Email Configuration** — SMTP and other email-related settings (pairs well with MailerPress).

Learn more on the [Classic Monks website](https://classicmonks.com/).

---

Author: [Classic Monks](https://classicmonks.com/)
Plugin URI: https://classicmonks.com/
