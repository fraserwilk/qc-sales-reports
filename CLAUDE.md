# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single-file WordPress admin plugin (`qc-sales-reports.php`) for Quality Components. It renders a
"Sales Reports" admin page that aggregates completed WooCommerce orders into sales-by-rep and
sales-by-customer tables with commission figures, plus CSV export. Version tracked in the plugin
header only; the sole commit is the V1.1 baseline.

There is no build step, dependency manifest, linter config, or test suite. Edit the PHP file directly.

## Environment / commands

- Local stack is MAMP PRO (PHP 8.4). `php` and `composer` are MAMP-aliased in this shell.
- WP-CLI is available as `wp` (`/Users/fraser/bin/wp`), run from the WordPress root
  (`/Users/fraser/dev-websites/qualitycomp`).
- Toggle the plugin: `wp plugin activate qc-sales-reports` / `wp plugin deactivate qc-sales-reports`.
- Lint a change: `php -l wp-content/plugins/qc-sales-reports/qc-sales-reports.php`.
- Inspect data the report reads: `wp option get options_qc_sales_reps`, and
  `wp user meta get <id> qc_sales_rep`.

## Hard dependencies (no graceful degradation)

- **WooCommerce HPOS.** Queries hit `{$wpdb->prefix}wc_orders` and `wc_order_operational_data`
  directly. Legacy `wp_posts`-based order storage is not supported.
- **Advanced Custom Fields (ACF Pro).** Provides the "Sales Reps" options page
  (`acf/init` → `acf_add_options_page`, slug `sales-reps` under Settings) and the repeater field
  `qc_sales_reps` with sub-fields `rep_name`, `rep_percentage`, `rep_active`.

## Architecture / data flow

**Rep model.** A "sales rep" is just a string name defined as a row in the ACF `qc_sales_reps`
repeater. Customers are linked to a rep via the user meta key `qc_sales_rep` on the WP user.
That meta is an ACF select field whose choices are populated dynamically from active repeater rows
(`acf/load_field` filter, `qc_acf_load_sales_rep_choices`). The users list table gets a sortable
"Sales Rep" column via `manage_users_*` filters.

**Aggregation (`qc_get_sales_stats`)** is the core. It:
1. Pulls completed `shop_order` rows (`status = 'wc-completed'`) in the date window, LEFT JOINing
   operational data for `shipping_total_amount` and `discount_total_amount`.
2. Divides every monetary amount by **1.1 to strip 10% GST** — WooCommerce stores GST-inclusive
   figures, all report/CSV columns are ex-GST. `net = gross - coupons - shipping`.
3. Buckets totals per customer and per rep (customers with no `qc_sales_rep` fall into the
   literal rep bucket `"Unassigned"`).
4. Resolves customer IDs to `user_email` / `display_name` in one `IN (...)` query.

**Commission** is computed at render/export time, not in the aggregate: `pct` comes from
`qc_get_rep_percentages()`, which reads ACF's raw `options_qc_sales_reps_{i}_*` option rows
directly (not the `have_rows()` API used elsewhere) and only includes rows where `rep_active` is
truthy. Commission = `(net / 1.1) * (pct / 100)` — note `net` is already ex-GST, so this applies a
second `/1.1`; preserve that behavior unless explicitly asked to change it.

**Page rendering (`qc_sales_reports_page`).** Admin menu `qc-sales-reports`, capability
`manage_options`. Query args: `from` / `to` (date range, default = current calendar month),
and `qc_rep` for drill-down. With `qc_rep` set, the page filters to that rep's customers (via a
`qc_sales_rep` usermeta subquery in `qc_get_sales_stats`) and hides the rep table.
`qc_render_table()` is a generic renderer used by the rep table; the customer table is rendered
by its own function. Both sort by `gross` descending.

**CSV export** is intercepted on `admin_init` (`qc_handle_csv_export`) *before* any admin HTML is
sent, so it can emit `Content-Disposition` headers and `exit`. Triggered by `qc_export=rep` or
`qc_export=customers` on the report page URL; re-runs `qc_get_sales_stats` with the same
from/to/qc_rep params. Both entry points re-check `manage_options`.

## Conventions

- All functions are prefixed `qc_` and live in the global namespace in one file.
- Follows WordPress core style (tabs, Yoda conditions, `esc_*` on output).
- Inline `style="..."` attributes for the admin UI — there is no enqueued CSS/JS.
- `qc_money()` formats display currency; CSV writes raw `round(..., 2)` numbers.
- SQL: date bounds and the drill-down value are the only interpolated inputs — dates via
  `$wpdb->prepare`, the rep value via `esc_sql`. Keep new query inputs parameterised.
