=== WP Apify Connector ===
Contributors: lucasmaia
Tags: apify, web scraping, dataset, automation, ai
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed live Apify Actor & Task datasets in WordPress with built-in Transient caching, encrypted API tokens, and post-publish webhook triggers.

== Description ==

**WP Apify Connector** connects your WordPress site directly to any public or private **Apify Store Actor**, **Saved Task**, or **Dataset** without external middleware like Zapier or Make.

Key features:

* **Shortcode `[apify_dataset]`**: Render clean, responsive HTML tables or card grids directly inside posts, pages, or widgets.
* **Built-in Transient Caching**: Avoid API rate limits and extra compute usage by caching Apify responses (`60s` to `24h`).
* **Encrypted API Token Storage**: Optional Apify API tokens are encrypted at rest with `AES-256-GCM` using your WordPress site auth keys.
* **On-Publish Actor Trigger**: Automatically start a configured Apify Actor run whenever a new post is published (non-blocking).
* **Zero External PHP Dependencies**: Pure WordPress HTTP API (`wp_remote_get` / `wp_remote_post`) and Transients API.

== Installation ==

1. Upload the `wp-apify-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings -> Apify Connector** to optionally configure an API token, default dataset/task ID, cache TTL, or on-publish Actor trigger.

== Shortcode Usage ==

Display the latest 20 items from a public Apify Dataset:
`[apify_dataset id="YOUR_DATASET_ID" type="dataset" limit="20" cache="3600"]`

Display structured financial data from a published Apify Task in card layout:
`[apify_dataset id="brasil-api-lcm~brazil-fii-reits-dividends-equity" type="task" limit="10" format="cards" fields="name,categoryOrSector,metricLabel,metricValue"]`

== Frequently Asked Questions ==

= Do I need an Apify API token for public datasets? =
No. Public Apify datasets can be embedded directly without a token. An API token is only required for private datasets or triggering Actor runs on post publish.

= How does caching work? =
Each unique shortcode configuration caches its JSON payload using WordPress Transients (`cache` attribute in seconds). Administrators can clear cached datasets anytime from **Settings -> Apify Connector**.

== Changelog ==

= 1.0.0 =
* Initial release with `[apify_dataset]` shortcode, AES-256-GCM token storage, Transient caching, and non-blocking post-publish Actor trigger.
