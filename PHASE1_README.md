# Phase 1: schema catalog and Gemini report planning

This phase adds planning services only. It does not add a public API endpoint or execute generated SQL. `index.php` and `db.php` retain their existing behavior.

The added `.htaccess` denies direct access to the catalog, rules, source TXT, documentation, and `.env` under Apache. For Nginx/IIS or other servers, configure equivalent deny rules and keep these files outside public static serving before deployment.

## Files and commands

- `services/SchemaService.php` parses the SQLite field dictionary, persists only an explicit safe subset, retrieves question-relevant metadata, and compares it with read-only MSSQL catalog results.
- `schema_catalog.json` contains the parsed catalog. `mssql_type: null` and `metadata_status: unverified` mean SQL Server has not been checked. Descriptions remain unverified even after structural metadata checks.
- `business_rules.json` records candidate sales, invoice status, returns, working date, and invoice grain definitions. Every rule is currently `unverified`; the TXT does not establish eligible status/type values or a signed-off formula.
- `services/GeminiService.php` calls Gemini REST with structured JSON output and rejects model fields outside the fixed schema. `services/ReportPlanCompiler.php` can compile a parameterized T-SQL `SELECT` after all catalog and business-rule gates are verified. It never executes it.
- `services/ReportScope.php` accepts tenant and shop values only from `$_SESSION['reporting_identity']`, which the host POS login must populate server-side with `authenticated`, `user_id`, integer `zid`, and `allowed_shopnos`. No route is exposed until that integration exists.
- `samples/five_year_sales_gemini_response.json` is a model intent example for five completed years, 2021–2025. `samples/five_year_sales_phase1_result.json` shows the current safe result with SQL blocked.

Run `php bin/schema_catalog.php build` to regenerate the catalog from the TXT. This resets structural verification to `not_checked`. Run `php bin/schema_catalog.php verify` **in an authorized environment with SQLSRV and a read-only DB account** to query `sys.tables`, `sys.columns`, `sys.types`, and declared foreign keys. The command writes observed types/nullability and explicit missing/type/nullability mismatches to the catalog; descriptions and logical joins without a declared FK remain unverified. It does not read business rows or modify MSSQL. The current local PHP CLI has no SQLSRV extension, so live verification cannot run here.

Run `php tests/phase1_test.php` for offline unit tests. They mock Gemini HTTP and use fixture-only verified statuses/types to exercise SQL compilation; the fixture values are **not** production business definitions. The local CLI also lacks cURL, so a live Gemini request requires cURL in the deployment PHP runtime.

## Integration and approval gate

`config.php` retains the existing Gemini model/key settings and now provides `GEMINI_API_URL` (fixed Google host), `GEMINI_TIMEOUT_SECONDS`, and `GEMINI_MAX_RESPONSE_BYTES`. The API key is sent only in the `x-goog-api-key` header. Gemini receives the user question, a small approved catalog subset, and metric verification status; it receives no DB credentials, authenticated scope values, or database rows.

After verifying MSSQL types and the actual invoice key/cardinality, the POS owner must approve `business_rules.json`: the sales amount field/formula, eligible invoice status values, sale-versus-return type values, authoritative working date/timezone, and one-header-per-invoice grain. Mark a definition `verified` only after those checks and update its actual values. The compiler accepts only the currently coded header-based sales definition; any other formula needs an explicit reviewed template. Until these conditions are met, `planReport()` returns `needs_clarification` and `sql: null`.

For PHP integration, load the services server-side, call `ReportScope::fromSession()` after authenticated POS session setup, then construct `GeminiService` with `$config['gemini']`, `new SchemaService(__DIR__ . '/schema_catalog.json')`, and decoded `business_rules.json`. Call `planReport($question, $scope)` and return its structured result. Never pass request JSON directly as a scope object or run the returned SQL in Phase 1.

Gemini structured JSON generation for the existing `gemini-2.5-flash` default uses `generationConfig.responseMimeType` and `responseSchema`: https://ai.google.dev/gemini-api/docs/migrate-to-interactions . Read-only metadata queries use the existing `sqlsrv` connection and per-query timeouts: https://learn.microsoft.com/en-us/sql/connect/php/sqlsrv-query .
