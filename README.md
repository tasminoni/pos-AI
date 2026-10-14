# Schema-driven AI Database Assistant

Last updated: October 14, 2026. This is the single project guide. Read it before changing the application, then inspect the referenced implementation files. It describes the current executable project, rather than an implementation proposal.

## Quick understanding

The user supplies `sql_schema.json` containing SQL Server tables and columns and asks a question in English, Bangla or Banglish. Gemini receives schema metadata and the question and returns SQL. PHP validates that SQL and sends it to an authenticated internal API. That API independently validates it, executes a bounded parameterized SELECT against Microsoft SQL Server, and returns results for the browser to display.

There are no predefined SQL queries for particular user questions. General interpretation instructions, permitted SQL grammar and access restrictions are predefined; SQL statements are generated from the question and supplied schema. Optional business meanings for the current POS database live inside that schema file. The old report-template implementation and its separate schema catalog/business-rule files have been removed.

The AI does not receive a database connection, database credentials or query result rows. The schema describes structure, not stored records. The provider does receive the user's question, schema names/types/descriptions/notes/relationships, prior question/SQL/report metadata, and safe error categories during repair. Users can disclose data in their own questions; do not put secrets or record samples in schema annotations. The AI cannot directly call the Query API.

## Local environment and startup

- Workspace: `C:\xampp\htdocs\test_ai_project`.
- Browser entry: `http://localhost/test_ai_project/ui/index.html`. The project root redirects HTML browser requests there.
- Stack: Apache/XAMPP, PHP 8.1+ with `sqlsrv`, `curl`, JSON and working HTTPS CA configuration; compatible Microsoft ODBC driver; SQL Server; Gemini API key.
- Frontend: plain HTML, CSS and JavaScript modules with local Chart.js 4.5.1. No npm installation, build step, frontend framework, Composer package or OpenAI SDK is needed.
- Use Apache or another server with multiple workers. A single-worker PHP development server cannot serve chat while chat calls its own internal Query API.
- PHP must be able to write its session directory. The project directory must be writable for optional schema refresh/provider cache operations.

The current local connection targets SQL Server `.` and database `MGIFSM00002`. Actual credentials and API secrets remain in `.env` and must not be copied into this guide. Reporting currently does not require login (`CHAT_REQUIRE_SESSION=false`) and reuses the configured main connection (`QUERY_USE_POS_CONNECTION=true`). Current table metadata includes 33 tables and 1,194 columns; this is a snapshot, not an automatic guarantee about future database changes.

Start Apache in XAMPP, verify the SQL Server instance is available, and open the browser URL. Use `http://localhost/test_ai_project/test-db.php` for a loopback-only live database probe. A JSON `success:true` verifies a database query; it does not independently verify Gemini or every possible report.

## End-to-end request flow

1. `ui/assets/app.mjs` initializes `ChatApi` and calls `GET api/chat.php`. PHP starts a session, loads the schema and server scope, and returns a CSRF token, authorized shop selectors and bounded conversation history.
2. The browser posts the question, conversation ID and optional date/shop filters to `api/chat.php` with `Content-Type: application/json` and `X-CSRF-Token`. Schema/database identity is checked against the conversation. New questions use `action:"ask"`.
3. `GeneratedReportService` checks input, narrows any authorized shop selection, builds a cache key and calls `GeminiService::generateSql` unless a valid session query-plan cache entry exists. Follow-ups include prior question, generated SQL, title, date bounds and column/presentation metadata, never prior rows.
4. `GeminiService` sends a structured JSON prompt to `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`. The `x-goog-api-key` header authenticates the provider call. A valid response has four string fields: `status` (`ready` or `clarification`), `sql`, `title`, `clarification_question`.
5. `GeneratedSqlValidator` parses the returned SQL, checks every table/column reference, resolves canonical identifier spelling, excludes restricted fields, binds literals, bounds the row count and wraps each table source with configured row-scope predicates. Selected date filters are independently enforced on the primary source.
6. `QueryApiClient::runSql` sends the generated SQL, filters and offset to the configured internal `api/query.php`. It forwards the PHP session cookie and signs the exact JSON body with HMAC-SHA256 using `QUERY_API_TOKEN`, a timestamp and random nonce. It never exposes this token to the browser or AI.
7. `api/query.php` checks the peer IP, HMAC, timestamp and replay nonce; reloads scope/schema; validates SQL again; and calls `GeneratedSqlExecutor` with `SqlsrvQueryDriver`. Only the SQL reconstructed by the validator reaches `sqlsrv_query`, with separate bound parameters and a query timeout. The endpoint accepts SQL envelopes, not the removed legacy report-plan envelope.
8. Results return typed columns, bounded rows, scope/schema identity, pagination and a query request ID. PHP verifies scope and row counts, derives KPI/chart presentation and returns a chat response. Assistant commentary comes from PHP; the database rows are not sent back to Gemini for summarization.
9. `ReportView` renders a table, optional KPI/chart, sorting and export controls. A follow-up asking for a list after a total should select underlying rows while retaining prior filters/period. A server next-page request uses `action:"page"` and stored SQL, with no new AI call.

Browser request example:

```json
{"action":"ask","question":"List April 2026 sales with net amount below 100","conversation_id":null,"filters":{"date_start":"2026-04-01","date_end_inclusive":"2026-04-30"}}
```

Both date-filter fields must be present together. `shopno` is optional and must be an authorized shop. For a follow-up reuse the returned `conversation_id`. For another server page send `{"action":"page","question":"","conversation_id":"<returned ID>"}` without new filters. CSRF is required even in no-login mode.

Chat responses include `status`, `assistant_text`, `error`, `kpis`, `table`, `chart`, `filters`, `time_range`, `pagination`, `metadata` and `conversation_id`. Relevant statuses are `ok`, `no_data`, `clarification`, `error`. Metadata includes generated title, query request ID, duration, generation-call count, cache use and whether SQL was repaired. `no_data` means no matching rows or an all-null aggregate; it is not fabricated zero revenue.

## Files retained and their responsibilities

| File/directory | Responsibility |
| --- | --- |
| `README.md` | This single maintenance and setup guide. |
| `.env`, `config.php` | Secrets and environment settings; configuration loader. Existing process environment values take precedence over `.env`. |
| `.htaccess`, directory `.htaccess` files | Apache access restrictions for private configuration, source, tests, samples and documentation. |
| `.gitignore` | Excludes `.env` and generated provider-cache metadata from version control. |
| `index.php` | Browser redirect and JSON application/database health information. |
| `db.php` | SQLSRV main/dedicated connections and parameterized metadata/diagnostic helpers. Its keyword check is not the AI SQL security boundary. |
| `test-db.php` | Loopback/CLI live connection probe. |
| `sql_schema.json` | Sole user-maintained database-definition input. |
| `ai_schema_cache.json` | Optional generated provider-cache resource/fingerprint/expiry or retry cooldown; recreated automatically, not a schema you maintain. |
| `api/chat.php` | Browser request, CSRF, conversations and report orchestration. |
| `api/query.php` | Signed internal execution endpoint. |
| `services/SqlSchema.php` | Schema normalization, metadata restrictions, identifier lookup and date-column selection. |
| `services/ReportScope.php` | Trusted schema/session scope; authorized shop narrowing and schema/database identity. |
| `services/GeminiService.php` | SQL prompt, JSON generation, retry and provider-context cache. |
| `services/GeneratedReportService.php` | Plan caching, validation, bounded repair, API result checking, report presentation and follow-up context. |
| `services/GeneratedSqlValidator.php` | SELECT parser, allowlisting, literal binding, source scoping and paging bounds. |
| `services/QueryValidationException.php` | Safe SQL-validation failure reason. |
| `services/InternalQueryAuth.php` | Peer allowlist, HMAC/timestamp verification and replay prevention. |
| `services/QueryApiClient.php` | Server-only signed HTTP transport. |
| `services/QueryDriver.php` | SQLSRV driver/cursor interfaces, execution and safe error categories. |
| `services/GeneratedSqlExecutor.php` | Validated query execution, cursor cleanup, output typing and row/byte limits; secret-free audit logs. |
| `bin/refresh_sql_schema.php` | Optional live metadata refresh, preserving existing schema annotations. |
| `samples/dynamic_schema.example.json` | Minimal unrelated database schema example. |
| `tests/` | Generated-SQL, Bangla follow-up, dynamic schema, internal authentication and UI regression tests. |
| `ui/index.html`, `ui/assets/styles.css` | Responsive application markup/layout, drawers, scrolling and print styles. |
| `ui/assets/app.mjs`, `api.mjs` | Chat state, filters, history, API session/CSRF handling and request timeouts. |
| `ui/assets/report-view.mjs`, `report-utils.mjs`, `charts.mjs` | Result rendering, sorting, decimal comparison, CSV safety and chart configuration. |
| `ui/assets/vendor/chart.umd.min.js`, `LICENSE.chartjs.txt` | Only the Chart.js runtime bundle and required MIT license notice. |

## Supplying a different database schema

Replace `sql_schema.json` with your tables/columns, change the database connection settings in `.env`, and refresh the browser. No other schema, catalog, business-rules or mapping file is required. The current driver supports Microsoft SQL Server; PostgreSQL/MySQL need a different driver and SQL grammar.

Minimal accepted format:

```json
{"tables":{"dbo.Books":["BookId","Title","Price","PublishedDate"],"dbo.Loans":["LoanId","BookId","LoanDate","Fee"]}}
```

Typed format and optional annotations:

```json
{
  "timezone":"Asia/Dhaka",
  "tables":{
    "dbo.Books":{
      "columns":{"BookId":"int","Title":"nvarchar","Price":"decimal","PublishedDate":"date"},
      "primary_key":["BookId"],
      "description":"One record per book",
      "date_column":"PublishedDate",
      "column_meta":{"Price":{"description":"Stored unit price"}}
    }
  },
  "notes":[],
  "relationships":[]
}
```

The loader also accepts a direct table map or a list of table descriptors with `name`/`table_name`, optional `schema`, and `columns`. Column lists may contain strings or descriptors with `name`/`column_name` and `type`/`data_type`; column maps may contain type strings or descriptors. Unqualified table names default to `dbo` (override with `default_schema`). Omitted types become `unknown`. Names are resolved case-insensitively to their original spelling for execution.

Optional table `queryable:false` excludes a table. Column `sensitive:true` or `queryable:false` excludes a field. Credential/contact-like field names are excluded automatically, and credential/auth-like table names are not queryable by default. Not every declared field is therefore necessarily queryable. These heuristics are not a complete classification of confidential data: annotate additional private fields and use database permissions.

`date_column` disambiguates UI date filters. Otherwise the loader looks for one date-typed or obvious date/time-named field, using the query's WHERE references to resolve alternatives. Ambiguous date filtering is rejected rather than silently selecting a field. For joined reports, UI dates constrain the first source, so that source must represent the intended date grain. Prompt dates may differ from display bounds inferred from SQL literals; inspect SQL logic when diagnosing complex ranges.

Names alone cannot explain cryptic columns, undocumented joins, currency, sales/return signs or accounting formulas. The model uses obvious names and conversation context; optional descriptions/relationships/notes help, and ambiguity should produce a clarification. Relationship annotations inform the AI; the PHP validator does not certify join cardinality or business accuracy.

Optional server row scope belongs inside this same JSON file:

```json
{"access":{"row_scope":{"tenant":{"column":"OrganizationId","value":123,"required":true},"shop":{"column":"BranchCode","values":["B1"],"required":false}}}}
```

Every source with an applicable scope column is independently constrained. A required missing scope column makes the table unavailable; an optional missing column is skipped. The `tenant` and `shop` roles use the trusted scope; additional role names use the file's static rule values. Integer tenant values and shop lists are the supported named-role convention. The AI sees scope column names, not configured values.

A names-only replacement without `access.row_scope` has no automatic tenant/shop restriction: it can read declared permitted tables within the configured SQL account's permissions. Existing `.env` `DEFAULT_ZID`/`DEFAULT_SHOPNO` settings no longer control scope. Schema/database identity prevents old conversations from being reused after replacement; query-plan keys also include schema, date, question, filters, scope and prior context.

## Current POS annotations and known reference results

The current schema preserves tenant/shop scope through `zid` and `xshopno`. It declares the preferred header date as `opdoheader.xworkingdate`. Optional notes define sales as `SUM(opdoheader.xnetamt)` for `xstatusord='Confirmed'`, exclude Dismissed invoices and identify `xdornum` as the invoice identifier. Header/detail joins use `zid`, `xshopno`, `xdornum`; do not duplicate header amounts across detail rows. This is file-specific information, not fixed SQL-generation PHP logic.

Observed `xtype` values were NULL, so no separately verified return formula exists. Do not infer dates or business meanings from digits inside opaque document identifiers. Known historical checks in the configured scope: document `DO2101FSM-000022026041300001` has net amount `100.00`; April 2026 has two Confirmed invoices with net amount below 100. These are test references, not cached or hardcoded answers, and live data can change.

## Configuration reference

| Setting | Purpose/default |
| --- | --- |
| `GEMINI_API_KEY` | Required Gemini secret. |
| `GEMINI_MODEL` | Default `gemini-2.5-flash`; current local installation configures `gemini-3.1-flash-lite`. |
| `GEMINI_API_URL` | Approved Google `https://generativelanguage.googleapis.com/v1beta/models` endpoint; `/v1/models` also accepted. |
| `GEMINI_SQL_THINKING_LEVEL` | Gemini 3 SQL reasoning: `low` by default; `minimal` optional. Gemini 2.5 Flash uses thinking budget 0. |
| `GEMINI_TIMEOUT_SECONDS` | Default 25 seconds per provider request, clamped 5–60; cache creation uses 5 seconds. |
| `GEMINI_MAX_RESPONSE_BYTES` | Default 65,536; clamped 1,024–262,144 bytes. |
| `DB_SERVER`, `DB_NAME`, `DB_USER`, `DB_PASS` | Main SQL Server connection. UTF-8 and TrustServerCertificate=Yes are currently configured in PHP. |
| `DB_LOGIN_TIMEOUT_SECONDS` | Default 5, clamped 1–30 seconds. |
| `QUERY_USE_POS_CONNECTION` | `true` uses main `DB_*`; default `false` requires dedicated `QUERY_DB_*`. Name retained for existing deployment compatibility. |
| `QUERY_DB_SERVER`, `QUERY_DB_NAME`, `QUERY_DB_USER`, `QUERY_DB_PASS` | Dedicated reporting account settings. Point schema and connection at the same database. |
| `QUERY_DB_TRUST_CERT`, `QUERY_DB_LOGIN_TIMEOUT_SECONDS` | Dedicated certificate trust defaults false; login timeout defaults 5. |
| `QUERY_API_URL` | Required internal URL ending `/api/query.php`; current local URL uses `http://127.0.0.1/test_ai_project/api/query.php`. |
| `QUERY_API_TOKEN` | Shared server-only secret of at least 32 characters. |
| `QUERY_API_ALLOWED_HOSTS`, `QUERY_API_ALLOWED_IPS` | Defaults `127.0.0.1,localhost,::1` hosts and `127.0.0.1,::1` peers. HTTP only on loopback; other approved hosts require HTTPS and shared sessions. |
| `QUERY_API_SIGNATURE_WINDOW_SECONDS` | Default 60, clamped 1–300; nonce cache holds at most 128 recent requests per session. |
| `QUERY_API_MAX_BODY_BYTES` | Default 8,192, clamped 512–65,536. Large generated SQL may need a higher envelope allowance. |
| `QUERY_TIMEOUT_SECONDS` | Default 20, execution clamped 1–30 seconds. |
| `QUERY_MAX_ROWS` | Default 100 per API page, clamped 1–500. |
| `QUERY_MAX_RESPONSE_BYTES` | Default 1,048,576 measured row bytes, capped 2,097,152; internal HTTP client separately caps total response at 2 MiB. |
| `CHAT_REQUIRE_SESSION` | Default true; local false allows reports without host login, while still using sessions and CSRF. |
| `MOCK_DB_MODE` | Default false; missing-driver diagnostic helper can return no rows when true. Generated report execution does not provide mock report data. |

The only AI provider implemented is Gemini. Removed settings for OpenAI, legacy report selection, narrative insights, legacy preview, default tenant/shop and old detail-day/offset limits no longer select behavior. The SQL-generation path is always active after cleanup; `AI_GENERATED_SQL=false` cannot restore the deleted legacy path.

For host login integration set `CHAT_REQUIRE_SESSION=true` and populate trusted `$_SESSION['reporting_identity']` with `authenticated:true`, nonempty `user_id`, positive integer `zid` and nonempty `allowed_shopnos` string array (maximum 50). Regenerate the session ID on login and clear identity on logout. This adapter still uses the POS identity convention; a different authenticated host needs an adapter change. Use a SELECT-only database account for deployment; the current reused local administrative connection is not a least-privilege reporting account. There is no application rate limiter, deployed authentication UI or role-management implementation.

## SQL, token, latency and paging limits

- Gemini generation: temperature 0, maximum 2,048 output tokens and structured JSON. There is no configurable input-token truncator; the full schema is sent, or referenced through a provider context cache. A much larger schema can exceed provider/model input limits or cause cost/latency increases.
- Chat question: maximum 1,200 UTF-8 bytes (Bangla characters use multiple bytes). API request maximum 4,096 bytes; question must be at least two bytes for the report service.
- SQL: maximum 12,000 bytes, 2,000 lexical tokens, expression recursion depth 32, eight table sources, ten branches per CASE, at most 40 returned columns. Some limits are hardcoded, not `.env` settings.
- Grammar: SELECT/DISTINCT/TOP, schema-qualified aliased FROM, INNER/LEFT JOIN, WHERE/GROUP BY/HAVING/ORDER BY; searched CASE; IN/NOT IN, LIKE/NOT LIKE, BETWEEN/NOT BETWEEN, ordinary comparisons/arithmetic. Explicit column references and result aliases are required by the prompt. Bracketed aliases can contain spaces/Bangla and escaped closing brackets.
- Functions: SUM, AVG, COUNT, COUNT_BIG, MIN, MAX, YEAR, MONTH, DAY, DATEPART, COALESCE, ISNULL, NULLIF, ROUND, ABS, CAST, LEN, LOWER, UPPER, LTRIM, RTRIM, CEILING, FLOOR, SUBSTRING. CAST supports the parser's limited date/datetime/int/bigint/decimal/numeric/varchar types. For other grammar, inspect the validator before extending the prompt.
- Rejected: SELECT *, CTEs, subqueries, UNION, window functions, system/cross-database queries, comments, multiple statements, semicolons, variables, stored procedures, write/DDL operations and undeclared/restricted fields. Arbitrary database questions requiring those features cannot currently execute.
- Query-plan cache: 16 session entries, 600-second expiry. Same validated plan can avoid generation, but rows are always queried fresh. Pagination never regenerates SQL.
- Provider-context cache: schema-only static context, requested TTL 3,600 seconds; local file stores resource ID/fingerprint/expiry or a 300-second cooldown after failure. It is optional and falls back to a full-schema request. Remove the local cache file if stale; it recreates automatically.
- Provider transport retries one timeout or one transient 500/502/503/504 response. Authentication/quota errors are not retried. A single request can therefore take longer than the normal 25-second timeout.
- SQL repair: at most one generation repair for parser rejection or allowlisted execution-error categories. No raw database messages/rows go to Gemini. Repair is skipped after 45 seconds elapsed; this check is not a hard overall cancellation deadline. Repair calls can each use their own transport retry.
- Internal HTTP timeout: 70 seconds (connect 3); browser request timeout: 130 seconds. Normal requests need one AI generation and one query API call, but latency is provider/database dependent.
- Server paging: stable ORDER BY permits next pages up to offset 10,000. Explicit TOP suppresses next-page loading. The bounded SELECT reads up to offset + page size + one probe row; offset pages become more expensive and can shift when live rows change.
- Conversations: eight per session, twelve compact history turns per conversation plus latest query context. Old history does not store complete report rows for restoration.

## UI and exports

The desktop layout has sidebar, conversation and filter panel; smaller screens use drawers. The page uses viewport height with internal conversation scrolling. Blank overflow/responsiveness fixes are in `styles.css`. No live rendered-browser screenshot was obtained in this environment; test actual mobile/tablet/desktop browsers when changing layout.

Tables can sort and paginate rows already loaded in the browser. **Load next data page** requests additional server rows; it is different from the table's local page controls. CSV exports only loaded rows and guards spreadsheet formula text. **Print / Save PDF** opens browser printing; there is no server PDF generator. Charts are derived from returned columns and have a table fallback if Chart.js fails. A one-row, one-numeric-column result becomes a KPI; a suitable two-column multirow result becomes a chart. These are presentation heuristics, not certified accounting definitions. Numeric strings preserve SQL Server precision in results; floating-point chart conversion is for visualization.

All user/AI/result strings render through text nodes, not arbitrary HTML. The page applies same-origin script/API content security policy. User-visible suggestions are examples, not executable query templates.

## Metadata refresh

Normally manually replacing the schema is sufficient. Optional database introspection:

```powershell
& C:\xampp\php\php.exe bin/refresh_sql_schema.php
```

If CLI SQL Server connectivity fails but Apache works:

```powershell
Invoke-RestMethod http://localhost/test_ai_project/bin/refresh_sql_schema.php -Method Post -Headers @{'X-Schema-Refresh'='1'}
```

The script allows CLI or loopback POST with that header; `bin/.htaccess` also restricts Apache access to local clients. It queries `sys.tables`/schemas/columns/types and writes `sql_schema.json`, preserving annotations for tables still present. It does not discover relationships, business semantics, row samples or full keys/indexes. Refresh imports actual current table columns, overwriting the manually supplied column list; do not run it against the wrong target database.

## Testing and troubleshooting

Run from the project directory:

```powershell
& C:\xampp\php\php.exe tests/generated_sql_test.php
& C:\xampp\php\php.exe tests/bangla_followup_test.php
& C:\xampp\php\php.exe tests/dynamic_schema_test.php
& C:\xampp\php\php.exe tests/internal_auth_test.php
node tests/ui_test.mjs
```

Tests use fake AI transports/database cursors and mock DOM/API data, so they need no real provider secrets or database writes. They cover SQL injection/restricted grammar/source scope, bindings, escaped/Bangla aliases, repair/cache behavior, Bangla total-to-list follow-ups, pagination, a completely unrelated names-only schema, signed internal authentication, CSV safety and UI rendering data flow. Tests do not prove arbitrary join/business accuracy or real browser layout.

Live checks after cleanup should include GET chat bootstrap, the local DB probe and one question through the actual chat API. Known references above help catch accidental scoping/formula changes. Audit messages use `ai_generated_sql_audit` with request ID, SQL hash, duration and outcome, not query rows or credentials. Check Apache/PHP error logs when a client only sees a sanitized failure.

Validation on October 14, 2026 after cleanup: all retained PHP files passed syntax checks; all five test suites passed; the Apache database probe and chat bootstrap succeeded; a fresh live AI/API invoice query returned `100.00` in approximately 3.6 seconds. HTTP checks confirmed private schema/environment/documentation/source/test paths return 403, refresh rejects GET with 404, and the retained Chart.js bundle returns 200. Only `README.md` remains as a Markdown file. The duplicate OpenSSL CLI warning remains in external PHP configuration; browser layout has not been visually verified.

| Symptom | Checks |
| --- | --- |
| Reporting API unreachable | Open the HTTP localhost URL, check Apache/port, same-origin API path and `.mjs` serving; do not open HTML using `file://`. |
| Sign-in/session/CSRF errors | Check `CHAT_REQUIRE_SESSION`, identity adapter, cookies, PHP writable session directory; refresh to obtain a new CSRF token. |
| Configuration/schema error | Validate readable JSON, nonempty tables/columns, identifier names, annotations and scope; verify schema and DB target match. |
| Internal query rejected/unavailable | Check URL suffix, token length/signature, allowlisted host/IP, shared session storage, time synchronization and worker availability. |
| AI timeout/quota/access failure | Check provider connection, key/project quota/model and schema size. There is no promise that every question will be answered. |
| SQL rejected or asks clarification | Inspect safe validation category and grammar/schema; add meaningful metadata for ambiguous dates/joins/metrics rather than question-specific shortcuts. |
| Unexpected sum instead of list | Check prior question/SQL/context reaches generation; preserve filters/period and select underlying rows. Regression test covers Bangla follow-up. |
| No matching rows | Verify real period/status/source columns, schema row scope and selected filters; distinguish NULL from zero. |
| CLI differs from Apache | Compare loaded php.ini/extensions/ODBC settings and certificate/environment differences. Apache DB probe is the live web-runtime check. |
| `Module "openssl" is already loaded` | Duplicate OpenSSL extension declarations in loaded PHP configuration. Use `php --ini`, enable the extension once and restart Apache. This warning is outside the project source; it remained during CLI checks. |

## Maintenance rules and cleanup history

Keep this guide synchronized with executable behavior. Start future work by tracing `api/chat.php` -> `GeneratedReportService` -> `GeminiService`/`GeneratedSqlValidator` -> `QueryApiClient` -> `api/query.php` -> `GeneratedSqlExecutor`/`QueryDriver`. When modifying SQL grammar, change parser/prompt together, preserve parameter binding and source scope, and run the tests. When changing prompt behavior, bump `SQL_PROMPT_VERSION` so cached queries cannot retain the old interpretation. Never bypass the internal validator to make one question succeed. Keep result data out of provider prompts and secrets out of logs/documentation.

Cleanup removed the legacy fixed-report services/catalog/business rules and their historical phase tests, old implementation/phase Markdown guides, duplicate connection/schema reference text, old generated report samples, redundant schema export/catalog scripts, npm cache/archive/unpacked Chart.js sources. The active runtime bundle, its complete license notice (renamed `.txt` so this is the only Markdown file), operational metadata refresh, diagnostics, current tests and schema example remain. There is no legacy-mode switch after cleanup.
