# WP Apify Connector

Lightweight WordPress plugin to embed live **Apify** Actor/Task datasets via shortcode `[apify_dataset]` with built-in Transient caching, `AES-256-GCM` encrypted token storage, and optional non-blocking Actor triggers when publishing new WordPress posts.

Built in alignment with the Apify Store **WordPress Integration** community request (`https://apify.com/ideas`), pairing natively with:
- [`brasil-api-lcm/apify-wordpress-publisher`](https://apify.com/brasil-api-lcm/apify-wordpress-publisher)
- [`brasil-api-lcm/rag-markdown-extractor`](https://apify.com/brasil-api-lcm/rag-markdown-extractor)
- [`brasil-api-lcm/html-table-to-json-mcp`](https://apify.com/brasil-api-lcm/html-table-to-json-mcp)
- [`brasil-api-lcm/apify-brazil-cvm`](https://apify.com/brasil-api-lcm/apify-brazil-cvm)

## Features

- **Zero PHP dependencies** — uses WordPress HTTP API (`wp_remote_get` / `wp_remote_post`), Settings API, and Transients API.
- **Shortcode `[apify_dataset]`** supporting `dataset`, `task`, and `actor` last-run endpoints, `table` or `cards` layout, custom `fields`, and automatic URL linkification with strict `esc_html` / `esc_url` output escaping.
- **Encrypted API tokens** — `AES-256-GCM` encryption keyed off `AUTH_KEY` + `SECURE_AUTH_SALT`.
- **Non-blocking publish hook** — trigger downstream Apify workflows (e.g., RAG ingestion or syndication) when a `post` transitions to `publish`.

## Shortcode Examples

```text
[apify_dataset id="brasil-api-lcm~brazil-fii-reits-dividends-equity" type="task" limit="10" fields="name,categoryOrSector,metricValue,sourceUrl" title="Brazilian FIIs (CVM)"]
```

```text
[apify_dataset id="DATASET_ID" type="dataset" limit="12" format="cards" cache="3600"]
```

## Verification

```bash
php -l wp-apify-connector.php uninstall.php tests/test_smoke.php
php tests/test_smoke.php
```

## License

GPL-2.0-or-later
