# Phase 2: internal MSSQL query API

`api/query.php` is a POST-only internal endpoint. It accepts a signed JSON envelope with one `plan` object. It requires an allowed source IP, a valid HMAC signature with `QUERY_API_TOKEN` (at least 32 bytes), a fresh nonce/timestamp, and the host POS's authenticated PHP session. The session must contain `reporting_identity` as documented in `PHASE1_README.md`; tenant `zid` and shop numbers are taken from that session, never the request or Gemini.

The server-side chat controller must serialize the exact JSON body, sign it with `InternalQueryAuth::headersFor($body, $config['query_api']['token'])`, and send the returned headers plus its authenticated session cookie over a private HTTPS or loopback connection. Release the controller's PHP session lock with `session_write_close()` before making an internal HTTP call. `QUERY_API_ALLOWED_IPS` defaults to loopback; configure exact trusted peer IPs if the controller is on another host. Do not put the token in browser code, a URL, or a client response.

## Plan contract

These six fields are required; an optional `shop_filter` may narrow the authenticated shop set after the Query API checks that it belongs to the session. SQL, table, schema, tenant, free-text filter, join, nested query, and other keys are rejected:

```json
{
  "report": "sales_amount",
  "group_by": "year",
  "date_start": null,
  "date_end_exclusive": null,
  "page_size": 100,
  "offset": 0
}
```

An authorized single-shop request may add `"shop_filter":"<one authorized shop number>"`. The selector is never treated as an authority: the Query API verifies it against `ReportScope::fromSession()` and applies the narrowed scope to every underlying table. Omit it, or use `null`, to report across all authorized shops.

| Report | Allowed grouping | Date scope | Underlying tables |
| --- | --- | --- | --- |
| `sales_amount` | `none`, `year`, `month`, `day`, `shop` | ISO half-open range up to six years, or `null`/`null` for all authorized history; all-history `day` is rejected | Verified `opdoheader` only |
| `product_sales_amount` | `product` | ISO half-open range up to six years, or `null`/`null` for all authorized history | Verified `opdoheader` and `opdodetail`, plus separately verified product metric and three-key join |
| `invoice_list` | `none` | Required, capped by `QUERY_MAX_DETAIL_DAYS` | Verified `opdoheader` only |
| `invoice_lines` | `none` | Required, capped by `QUERY_MAX_DETAIL_DAYS` | Verified `opdoheader` and `opdodetail` with a separately verified three-key join |

`SqlValidator` constructs only fixed `SELECT` templates. It inserts scope predicates on every table in a join and binds date, tenant, shop, status, type, offset, and fetch values with `sqlsrv_query`. Grouping and pagination operate **after** the aggregate over the full authorized source set. The result has `request_id`, `status`, typed `columns`, `rows`, `row_count`, `scope`, `date_scope`, `truncated`, `next_offset`, and a safe `error` object. `row_count` is the returned page count, not an unbounded total count. `truncated: true` means another page may exist; `next_offset: null` means the configured safe offset ceiling has been reached.

## Database and runtime gates

Set `QUERY_DB_SERVER`, `QUERY_DB_NAME`, `QUERY_DB_USER`, and `QUERY_DB_PASS` for a dedicated SQL login distinct from the POS login. Provision it outside this code with only the needed **column-level SELECT** permissions on the approved reporting objects; do not grant database-wide reader, writer, owner, DDL, execute, or access to user/card tables. The connection enables encryption and certificate validation, declares read-only intent, and rejects broad database roles and database-level permissions before use. `ApplicationIntent=ReadOnly` is routing intent, so SQL grants remain the actual read-only control. Have a DBA verify effective object/column grants and invoice join cardinality.

Set `QUERY_API_TOKEN` (random 32+ bytes), `QUERY_API_ALLOWED_IPS`, and optionally `QUERY_TIMEOUT_SECONDS`, `QUERY_MAX_ROWS`, `QUERY_MAX_OFFSET`, `QUERY_MAX_RESPONSE_BYTES`, `QUERY_MAX_DETAIL_DAYS`, `QUERY_DB_LOGIN_TIMEOUT_SECONDS`, `QUERY_API_SIGNATURE_WINDOW_SECONDS`, and `QUERY_API_MAX_BODY_BYTES`. The code clamps limits to safe ceilings. Query audit records contain only a request ID, allowlisted report name, SQL template hash, elapsed time, returned row count, and outcome; they contain no SQL parameters, raw rows, tenant/shop values, or driver errors. Apache access to `services/`, `tests/`, `bin/`, and `samples/` is denied by directory `.htaccess` files; configure equivalent rules on other web servers.

The current catalog and business definitions are **unverified**, and this local PHP lacks `sqlsrv`; production queries therefore remain blocked. Run `php bin/schema_catalog.php verify` in an authorized MSSQL environment, confirm the reporting account permissions, and obtain POS owner approval for sales status/returns/date/grain and the header-detail join before changing rule statuses to `verified`. No database mutations or live SQL execution were performed here.

Run `php tests/phase1_test.php` and `php tests/phase2_test.php`. Phase 2 tests use verified fixture metadata and a fake streaming driver to exercise SELECTs, aggregates, joins, date filters, injection rejection, tenant/shop binding, internal HMAC authentication, replay rejection, pagination, oversized results, and timeout handling. They do not substitute for an integration test against the real read-only SQL login.

SQLSRV connection options and query timeouts follow Microsoft's driver references: https://learn.microsoft.com/en-us/sql/connect/php/connection-options and https://learn.microsoft.com/en-us/sql/connect/php/performance-tuning-php-sql-driver .
