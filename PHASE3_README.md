# Phase 3: AI chat reporting

`api/chat.php` is the browser-facing controller. It authenticates the POS PHP session through `ReportScope`, requires a session CSRF token, asks Gemini for one fixed-schema intent, resolves dates in `Asia/Dhaka`, compiles the six-field report plan locally, and sends that plan through `QueryApiClient` to `api/query.php`. The Query API authenticates and validates again before SQLSRV execution. PHP checks the returned scope, dates, columns, row count and pagination, then creates the UI payload. Report numbers come only from SQL results; Gemini sees no credentials, tenant/shop values or database rows. Follow-up context stores only the last plan and bounded report history. Pagination makes no Gemini call.

## Deployment requirements

1. The host POS login must populate `$_SESSION['reporting_identity']` from its authenticated account and authorized shop grants: `authenticated` (true), `user_id` (string), `zid` (integer), `allowed_shopnos` (nonempty string array). Never populate it from a browser field, `DEFAULT_ZID`, or Gemini. Regenerate the PHP session ID at login and clear this identity on logout.
2. Set `QUERY_API_URL` to the private endpoint ending in `/api/query.php`, `QUERY_API_TOKEN` to the same random 32+ byte server secret used by the Query API, and `QUERY_API_ALLOWED_HOSTS` to the exact internal host list. HTTP is permitted only for loopback; remote internal hosts require HTTPS and a shared authenticated PHP session backend. Configure `QUERY_API_ALLOWED_IPS` on the Query API side. PHP cURL is required for Gemini and the internal HTTP call. Use a worker-capable PHP server; a single-worker development server cannot call itself while serving a chat request.
3. Verify the MSSQL catalog, business definitions, join cardinality, and dedicated reporting SQL login as described in `PHASE1_README.md` and `PHASE2_README.md`. `product_sales_amount` has a separate **unverified** rule. It must be approved before product reporting is available. The current repository remains fail closed because its catalog and business rules are unverified and local PHP lacks SQLSRV.

The endpoint preserves `index.php` and the existing Phase 1–2 APIs. There is no `ui/` directory in this workspace; the response below is ready for an existing or future UI to render. Render `assistant_text` as text, not HTML. Export a table page from `table.columns` and `table.rows`; request each `pagination.next_offset` through the `page` action to export subsequent pages. A single page is never presented as an all-results total.

## Requests

First, authenticated browser code calls `GET /api/chat.php` to receive `csrf_token`, `authorized_shopnos`, and bounded `history`. Then it sends JSON with the token in `X-CSRF-Token`:

```http
POST /api/chat.php
Content-Type: application/json
X-CSRF-Token: <token from authenticated GET>

{"action":"ask","question":"গত পাঁচ বছরের বিক্রি বছর অনুযায়ী দেখাও","conversation_id":null,"filters":{"shopno":null}}
```

For a follow-up, reuse the returned `conversation_id` and ask, for example, `"মাস অনুযায়ী দেখাও"`. An `ask` may include `filters: {"shopno":"<authorized shop>","date_start":"2026-04-01","date_end_inclusive":"2026-04-30"}`. Both date fields must be present together. PHP validates the inclusive range and converts it to an exclusive end; the Query API verifies the shop selector against the authenticated session again. For another page, send `{"action":"page","question":"","conversation_id":"<returned id>"}`. The server chooses the next offset from its session; the client cannot supply SQL, tenant authority, or pagination offsets. `GET /api/chat.php` lists at most eight conversations and twelve report turns per conversation without saving raw questions or rows.

## Example response

The numbers below are illustrative test data, **not** values from the POS database:

```json
{
  "status": "ok",
  "assistant_text": "Here are the verified report results.",
  "error": null,
  "kpis": [],
  "table": {
    "columns": [{"name":"year","type":"integer"},{"name":"sales_amount","type":"decimal"}],
    "rows": [{"year":2022,"sales_amount":"100.00"},{"year":2023,"sales_amount":"125.50"}],
    "row_count": 2,
    "export_ready": true
  },
  "chart": {
    "type": "line",
    "x": "year",
    "y": "sales_amount",
    "data": [{"year":2022,"sales_amount":"100.00"},{"year":2023,"sales_amount":"125.50"}],
    "partial_page": true
  },
  "filters": {"tenant_zid": 777, "shopnos": ["A", "B"]},
  "time_range": {"start":"2022-01-01","end_exclusive":"2026-10-14","timezone":"Asia/Dhaka","all_authorized_history":false},
  "pagination": {"page_size":3,"offset":0,"truncated":true,"next_offset":2},
  "metadata": {"report":"sales_amount","group_by":"year","query_request_id":"query-1","numeric_source":"verified_sql","model_calls":1},
  "conversation_id": "0123456789abcdef0123456789abcdef"
}
```

`status` may be `ok`, `no_data`, `clarification`, or `error`. KPI cards are emitted only for a scalar verified aggregate; grouped or paginated rows do not become invented totals. Dates are half-open (`end_exclusive`). Relative current periods end after **today in Asia/Dhaka**, so future dates are not silently included. All-history aggregation uses `null` bounds. Daily and detail reports require bounded dates; the Query API enforces its own limits.

The fixed query plan supports reports grouped by shop or product across the authenticated shop set. An authorized shop selector can narrow reports to one shop. It has no individual product selector; Gemini is instructed to clarify unsupported product filters and metrics.

An `ask` uses one Gemini planning call and one Query API call. For a complete, nonempty year/month/day sales aggregate, it may make one additional Gemini call for short narrative insights (`REPORT_INSIGHTS_ENABLED`, on by default). PHP sends only its exact first-to-last trend label, grouping, and requested language; no amount, row, shop scope, or credential leaves PHP. Generated numeric claims are rejected. The report and KPI values remain SQL-derived. `page` uses no Gemini call. The planning prompt contains at most one prior report context plus question-relevant, observed MSSQL columns and verified rule metadata.

## Verification

Run `php tests/phase1_test.php`, `php tests/phase2_test.php`, and `php tests/phase3_test.php`. Phase 3 tests cover the Gemini prompt boundary, five-year and monthly follow-up dates, product grouping, signed internal requests, pagination, no-data, error sanitization, and sensitive input rejection. They use fake HTTP and query transports. Live Gemini/MSSQL integration needs an authorized environment with verified metadata, approved business rules, PHP SQLSRV/cURL, and the dedicated read-only account.
