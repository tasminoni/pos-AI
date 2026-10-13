# AI MSSQL Reporting Assistant — Implementation Plan

## Current project assessment

The workspace contains exactly four files: `config.php`, `db.php`, `index.php`, and `Pos_Local_Database.txt`. There are no `api/`, `services/`, `ui/`, tests, Composer dependencies, or application authentication files. All three PHP files pass `php -l`. This local PHP 8.5 CLI has neither `sqlsrv` nor `curl`; no database connection or Gemini call was made during this review.

| Area | Present | Gap or risk |
| --- | --- | --- |
| Configuration | `config.php` loads `.env` and exposes Gemini, MSSQL, optional query API, query DB, mock, and session settings. DB credentials default to empty values. | No documented deployment validation. `DEFAULT_ZID` has a nonempty fallback, `CHAT_REQUIRE_SESSION` can be disabled, and the primary DB connection defaults to trusting the server certificate. Those defaults cannot establish authorization. |
| MSSQL | `db.php` connects with `sqlsrv_connect`, parameterizes values in `sqlsrv_query`, converts dates, and has mock fallback. | Its substring blacklist is not a read-only SQL parser: it does not require a single `SELECT`, constrain identifiers, joins, rows, time, or tenant scope. Errors include raw driver messages; `queryOne()` fetches every row; floats are rounded during retrieval. The account's SQL permissions are unknown. |
| Entry point | `index.php` returns a JSON status and redirects HTML clients to `ui/index.html`. | It advertises `api/chat.php`, `api/dashboard.php`, `api/test_tools.php`, and UI files that do not exist. Status exposes server/database identity and runtime configuration details. |
| Chat/Gemini/query API/dashboard/UI | Configuration or advertised paths only. | No callable Gemini client, authentication integration, query API, reporting pipeline, rendering, or frontend exists. No existing working feature in these areas can be assumed. |
| Schema document | Eleven named POS tables, field descriptions, suggested joins, and sync order. | It explicitly describes **local SQLite mappings derived from SQL Server scripts**. Descriptions are inferred from names; it is neither a verified MSSQL schema nor a metric specification. |

## Target architecture and trust boundaries

`Browser -> authenticated PHP chat endpoint -> scope resolver -> Gemini REST planner (question + approved catalog only) -> strict plan validator -> PHP SQL compiler -> internal Query API/service -> existing sqlsrv connection -> MSSQL read-only principal -> result validator -> PHP report presenter -> browser`

Gemini never receives DB credentials, a database connection, SQL errors, raw rows, customer/card/user fields, or secrets. It cannot call the query service. The model returns a structured **query plan**, not executable SQL. PHP owns all SQL construction, authorization predicates, execution, calculations, and narrative text. A rejected or ambiguous plan fails closed with a useful clarification; arbitrary questions are supported only where verified, approved data and metrics permit an answer.

### 1. Establish actual MSSQL schema and metric semantics (P0 gate)

- Use a read-only discovery account in the target environment to inventory `sys.schemas`, `sys.tables`, `sys.columns`, types/precision/scale/nullability, primary/foreign/unique keys, indexes, views, permissions, and row counts for the relevant tables. Confirm actual SQL Server object names and schema qualifiers; do not import SQLite `TEXT`/`REAL` types into query generation.
- Compare the eleven documented tables (`xusers`, `xroles`, `xtrn`, `xcodes`, `oppossigninlog`, `caitem`, `opdoheader`, `opdodetail`, `opposmultiplecard`, `opdef`, `zbusiness`) with MSSQL. Start the reporting allowlist with `opdoheader`, `opdodetail`, and narrowly selected `caitem` columns; add other tables only for a signed-off report need. Exclude password/old-password, card number, phone, email, free-text notes, cost, and other sensitive columns by default.
- Verify composite join keys and cardinality: `opdoheader` to `opdodetail`/`opposmultiplecard` by `zid` and `xdornum` (plus shop if required by real keys); `opdodetail` to `caitem` by `zid` and `xitem` (plus shop if required). Check whether item master or payment rows duplicate invoice lines. Never sum header amounts after a one-to-many join.
- Obtain business sign-off on report date (`xworkingdate` versus `xdate`), timezone, status exclusions, sale versus return representation, currency, net/gross/VAT/discount formulae, payment reconciliation, and whether line `xqty` or `xqtyord` means sold quantity. Test each definition against known POS reports. Until signed off, metric names are provisional.

### 2. Resolve identity and authorized scope (P0)

- Integrate with the host POS's actual server-side login/session contract; no such implementation is in this workspace. Require a valid session for every chat, dashboard, and query request. Map its trusted user ID to active tenant `zid`, allowed shop numbers, reporting role, and optional date/metric limits using an authoritative source. `xusers`/`xroles` are candidate sources only after their server-side semantics and sync freshness are verified.
- Reject missing or mismatched scope; never accept `zid`, shop list, or role from the request or Gemini. Treat `DEFAULT_ZID`, `DEFAULT_SHOPNO`, `CHAT_REQUIRE_SESSION=false`, and mock mode as local-preview settings only, unavailable in production. Require CSRF protection for cookie-authenticated POSTs, secure session cookies, same-origin policy, rate limits, and per-user audit IDs.
- Insert tenant and shop predicates in every compiled query and every joined table where applicable. Verify aggregated scopes cannot leak through joins, cached results, error messages, or dashboard defaults. A multi-shop user may request only the subset granted by the server.

### 3. Create a reviewed catalog and backend-owned query plans (P0)

- Add a versioned `services/SchemaCatalog.php` containing only verified MSSQL tables/columns, relationship keys, allowed dimensions, filter operators, reportable date fields, and display labels/synonyms for Bangla, Banglish, and English. Keep credentials and sensitive fields outside the catalog sent to Gemini. Avoid sending full DDL or row samples.
- Add `services/MetricCatalog.php`: define approved metrics in PHP, e.g. `net_sales`, `order_count`, `units_sold`, `average_order_value`, `vat`, `discount`, and `payment_mix` only after their source/formula/status rules are verified. Give each metric a unit, currency rule, grain, allowed dimensions, and comparison semantics. Use DECIMAL-safe arithmetic and preserve SQL Server numeric precision through JSON; do not round source values in `db.php`.
- Candidate metric contracts for business review (not yet safe to publish as facts):

  | Metric | Candidate source/calculation | Required confirmation |
  | --- | --- | --- |
  | Net sales | Sum one `opdoheader.xnetamt` per eligible invoice | Status and return signs, discounts/tax inclusion, duplicate document keys |
  | Order count / average order value | Count eligible header documents / net sales divided by that count | What qualifies as an order, cancelled/void/return treatment |
  | Units and item sales | Sum approved `opdodetail` quantity and `xnetamt`, joined to eligible headers | `xqty` versus `xqtyord`, bonus/add-on/return rows, key cardinality |
  | VAT / discount | Sum approved header or line amounts at one consistent grain | Which fields reconcile to invoice totals; rounding policy |
  | Payment mix | Sum approved header payment fields or normalized card-payment rows | Combined payments, change, refunds, and reconciliation without double counting |

- Request a schema-constrained JSON plan from Gemini REST: `{catalog_version, metric_ids, dimensions, filters, date_range, comparison, sort, limit, presentation, language}`. The model may interpret the user's question but cannot supply scope, SQL text, table names outside the catalog, functions, or executable expressions. Validate JSON shape, enum values, combinations, dates, grouping, cardinality, cost limits, and catalog version server-side. Reject prompt-injection instructions embedded in questions or data.
- `services/SqlCompiler.php` builds one bounded parameterized T-SQL `SELECT` from reviewed templates/identifiers. Bind all values with `?`; use half-open date ranges, deterministic sorting, explicit columns, capped groups/rows, and no arbitrary joins, subqueries, procedure calls, comments, batches, or user-selected functions. Enforce a maximum date span and an execution timeout. Treat the existing blacklist as legacy defense only, never as the validator.

### 4. Execute through an internal Query API and verify results (P0)

- Implement `services/QueryService.php` as the internal Query API contract first, called in-process by chat and dashboard. Input is a validated plan plus server-derived authorization context, not SQL from HTTP or Gemini. Output is typed columns, bounded rows, aggregates, units, scope, date window, metric/catalog versions, and a request ID. If a separate process is later required, expose the same contract over private authenticated HTTP using `QUERY_API_URL`/`QUERY_API_TOKEN`; never create a public arbitrary-SQL endpoint.
- Reuse `DatabaseManager::getConnection()` and `sqlsrv_query` in `db.php`, with a distinct least-privilege reporting login from `query_db` if available. Grant `SELECT` only on approved reporting views/tables, no write/DDL/execute privileges, and no cross-database access. Enforce query timeout and response-size limits; use TLS certificate validation in production. Sanitize DB errors to clients and keep secret-free diagnostics server-side. Fail closed when sqlsrv is missing; never present mock/empty data as a real zero result.
- Validate returned column names/types, row count, finite numeric values, and totals before presentation. Reconcile totals when combining grains; flag incomplete/truncated data rather than asserting an answer. Keep audit logs of user ID, scope, plan hash, metric version, duration, count, and outcome without storing secrets or sensitive rows.

### 5. Present verified reports (P1)

- `api/chat.php`: `POST {message, conversation_id?}` with authenticated session. Return `{request_id, answer, scope_label, report: {title, columns, rows, kpis, chart?, totals, units, period, warnings}, assumptions, error?}`. Gemini sees the current question and approved catalog subset only. Generate the natural-language answer from verified values using PHP templates in Bangla/English (Banglish phrasing can be supported), so no result rows are sent back to Gemini. Ask for clarification when metric, date, or scope is ambiguous.
- Contract errors: `401` for no session, `403` for denied scope, `400` for invalid input, `422` for unsupported/ambiguous report, `429` for rate limit, and `503` for Gemini/MSSQL unavailable. Return a stable error code and request ID, never SQL or driver details. Cap message length and strip/reject sensitive identifiers before sending a question to Gemini.
- `api/dashboard.php`: authenticated `GET` with allowed date filters; use the same metric/query service for fixed KPI and trend plans. `api/test_tools.php` should not be publicly exposed; use a protected local diagnostic or CLI health command instead. Harden `index.php` to advertise only real routes and avoid publishing DB server/name or configuration state.
- Create `ui/index.html` and minimal assets for chat, loading/error/clarification states, accessible table, KPI cards, and charts selected from returned typed data. Escape all user/model/data text before HTML insertion; never render SQL or model HTML. Add chart labels/units, a table fallback, and a clear period and shop scope. Preserve the existing `index.php` HTML redirect behavior once the target exists.

## Planned file changes

| Priority | Files | Change |
| --- | --- | --- |
| P0 | `config.php`, `db.php` | Validate required environment, preserve sqlsrv connection shape, add reporting account/TLS/timeouts/typed results, suppress raw errors, remove unsafe production fallbacks. |
| P0 | `services/AuthScope.php`, `services/SchemaCatalog.php`, `services/MetricCatalog.php` | Authoritative user/tenant/shop permissions and reviewed catalog/metric definitions. |
| P0 | `services/GeminiClient.php`, `services/PlanValidator.php`, `services/SqlCompiler.php`, `services/QueryService.php` | REST planning, server validation, bounded T-SQL generation, and internal execution contract. |
| P1 | `api/chat.php`, `api/dashboard.php`, `services/ReportPresenter.php` | Authenticated API responses and verified explanations/visualization data. |
| P1 | `ui/index.html`, `ui/` assets, `index.php` | Functional report UI and accurate entry point. |
| P1 | `tests/` and deployment documentation | Plan/compiler/authorization regression cases, fixture metrics, runtime and secret configuration instructions. |

## Verification and release gates

1. **Environment:** PHP web runtime has compatible `sqlsrv`, `curl`, JSON, TLS CA configuration, Gemini key, and a reporting SQL login; secrets reside outside served files. Confirm actual production PHP/driver versions separately from this CLI.
2. **Schema and metrics:** Run read-only catalog discovery; approve actual columns, keys, shop scope and formulas with the POS owner; compare sample outputs to known invoices and reports, including returns, cancelled orders, multiple cards, multi-shop users, zero data, and Bangla dates.
3. **Security:** Prove unauthenticated and cross-tenant requests fail; fuzz malformed/prompt-injected plans, forbidden fields, unbounded dates/joins, multi-statement SQL, and HTML injection. Verify the SQL login cannot mutate data even if PHP validation fails.
4. **Functional:** Test Bangla, Banglish and English questions, follow-up clarification, KPI/table/chart consistency, date/currency formatting, empty results, Gemini failure, MSSQL failure, timeout and rate limits. Check that mock results cannot be mistaken for production results.
5. **Operational:** Review query plans/index use on representative data; confirm row/latency budgets, redacted audit logs, monitoring, and a feature-flagged rollout. No deployment or database mutation is part of this plan.

## External API and driver references

- Gemini REST generation and API-key header: https://ai.google.dev/api
- Gemini structured JSON output (current `responseFormat` guidance; verify model compatibility at implementation): https://ai.google.dev/gemini-api/docs/generate-content/structured-output
- SQLSRV parameterized query/options: https://learn.microsoft.com/en-us/sql/connect/php/sqlsrv-query
- SQLSRV query timeout: https://learn.microsoft.com/en-us/sql/connect/php/performance-tuning-php-sql-driver
- SQL Server object-level `SELECT` grants: https://learn.microsoft.com/en-us/sql/t-sql/statements/grant-object-permissions-transact-sql
