<?php
declare(strict_types=1);

/**
 * MSSQL Database Connection and Legacy Query Wrapper for /ai-chat
 * 
 * Reuses the existing POS connection conventions while enforcing:
 * 1. Parameterized queries only.
 * 2. A legacy keyword check; this is not an AI SQL security boundary.
 * 3. Graceful fallback when the sqlsrv PHP extension is not loaded in local dev.
 */

class DatabaseManager
{
    private static $connection = null;
    private static ?array $config = null;
    private static $reportingConnection = null;

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Obtains active MSSQL connection resource
     * 
     * @return resource|null
     * @throws RuntimeException
     */
    public static function getConnection()
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        if (self::$config === null) {
            $appConfig = require __DIR__ . '/config.php';
            self::$config = $appConfig['db'];
        }

        if (!function_exists('sqlsrv_connect')) {
            // Check if mock mode is allowed
            $appConfig = require __DIR__ . '/config.php';
            if (!empty($appConfig['mock_mode'])) {
                return null;
            }
            throw new RuntimeException(
                "PHP sqlsrv extension is not loaded in this environment. Please enable php_sqlsrv extension in php.ini, or set MOCK_DB_MODE=true in .env for local preview."
            );
        }

        $server = self::$config['server'];
        foreach (['server', 'database', 'uid', 'pwd'] as $name) {
            if (!is_string(self::$config[$name] ?? null) || self::$config[$name] === '') {
                throw new RuntimeException('Database connection settings are incomplete. Check DB_* in .env.');
            }
        }
        $connectionInfo = [
            "Database" => self::$config['database'],
            "UID" => self::$config['uid'],
            "PWD" => self::$config['pwd'],
            "CharacterSet" => self::$config['charset'] ?? "UTF-8",
            "TrustServerCertificate" => self::$config['trust_cert'] ?? "Yes",
            "LoginTimeout" => max(1, min(30, (int)(self::$config['login_timeout_seconds'] ?? 5))),
        ];

        sqlsrv_configure('WarningsReturnAsErrors', 0);
        $conn = sqlsrv_connect($server, $connectionInfo);

        if (!$conn) {
            $errors = sqlsrv_errors();
            $msg = "MSSQL connection failed: ";
            if (is_array($errors) && isset($errors[0]['message'])) {
                $msg .= $errors[0]['message'];
            }
            throw new RuntimeException($msg);
        }

        self::$connection = $conn;
        return self::$connection;
    }

    /**
     * Legacy parameterized query helper. AI reporting uses SqlValidator and
     * QueryExecutor instead; do not pass generated SQL to this method.
     * 
     * @param string $sql The SQL query with ? placeholders
     * @param array $params Parameter values
     * @return array Array of associative rows
     * @throws RuntimeException
     */
    public static function query(string $sql, array $params = []): array
    {
        // Enforce Read-Only constraint
        $cleaned = trim(preg_replace('/\s+/', ' ', $sql));
        $forbidden = ['INSERT ', 'UPDATE ', 'DELETE ', 'DROP ', 'ALTER ', 'TRUNCATE ', 'EXEC ', 'EXECUTE ', 'CREATE '];
        foreach ($forbidden as $word) {
            if (stripos($cleaned, $word) !== false) {
                throw new RuntimeException("Security violation: Only SELECT queries are permitted in the AI query layer.");
            }
        }

        $conn = self::getConnection();
        if ($conn === null) {
            // Mock mode or offline
            return [];
        }

        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $errText = is_array($errors) && isset($errors[0]['message']) ? $errors[0]['message'] : 'Unknown database error';
            throw new RuntimeException("Database query failed: " . $errText);
        }

        $rows = [];
        try {
        while (($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) !== null) {
            if ($row === false) {
                throw new RuntimeException('Database result could not be fetched.');
            }
            // Clean up any DateTime objects or byte strings for JSON compatibility
            foreach ($row as $col => $val) {
                if ($val instanceof DateTimeInterface) {
                    $row[$col] = $val->format('Y-m-d H:i:s');
                } elseif (is_float($val)) {
                    $row[$col] = round($val, 2);
                }
            }
            $rows[] = $row;
        }

        } finally {
            sqlsrv_free_stmt($stmt);
        }
        return $rows;
    }

    /**
     * Fetches a single row.
     */
    public static function queryOne(string $sql, array $params = []): ?array
    {
        $rows = self::query($sql, $params);
        return count($rows) > 0 ? $rows[0] : null;
    }

    /**
     * Separate connection for the reporting API. Never falls back to the POS
     * connection or to mock mode. SQL Server permissions remain the final gate.
     */
    public static function getReportingConnection(array $reportingConfig, array $posConfig)
    {
        if (self::$reportingConnection !== null) {
            return self::$reportingConnection;
        }
        foreach (['server', 'database', 'uid', 'pwd'] as $name) {
            if (!is_string($reportingConfig[$name] ?? null) || $reportingConfig[$name] === '') {
                throw new RuntimeException('Dedicated reporting database connection is not configured.');
            }
        }
        if (strcasecmp($reportingConfig['uid'], (string)($posConfig['uid'] ?? '')) === 0) {
            throw new RuntimeException('Reporting and POS database accounts must be distinct.');
        }
        if (($reportingConfig['trust_cert'] ?? 'No') !== 'No') {
            throw new RuntimeException('Reporting connection must validate the server certificate.');
        }
        if (!function_exists('sqlsrv_connect')) {
            throw new RuntimeException('SQLSRV is unavailable for reporting.');
        }
        $loginTimeout = max(1, min(30, (int)($reportingConfig['login_timeout_seconds'] ?? 5)));
        $conn = sqlsrv_connect($reportingConfig['server'], [
            'Database' => $reportingConfig['database'],
            'UID' => $reportingConfig['uid'],
            'PWD' => $reportingConfig['pwd'],
            'CharacterSet' => 'UTF-8',
            'Encrypt' => true,
            'TrustServerCertificate' => false,
            'ApplicationIntent' => 'ReadOnly',
            'LoginTimeout' => $loginTimeout,
        ]);
        if ($conn === false) {
            throw new RuntimeException('Reporting database connection failed.');
        }
        try {
            self::assertReportingPrincipal($conn);
        } catch (Throwable $error) {
            sqlsrv_close($conn);
            throw new RuntimeException('Reporting database account is not approved for read-only use.');
        }
        self::$reportingConnection = $conn;
        return $conn;
    }

    /** Reject broad/elevated roles and database-level permissions before use. */
    private static function assertReportingPrincipal($connection): void
    {
        $rolesSql = "SELECT USER_NAME() AS principal, IS_MEMBER('db_owner') AS db_owner, "
            . "IS_MEMBER('db_datareader') AS db_datareader, IS_MEMBER('db_datawriter') AS db_datawriter, "
            . "IS_MEMBER('db_ddladmin') AS db_ddladmin, IS_MEMBER('db_securityadmin') AS db_securityadmin, "
            . "IS_MEMBER('db_accessadmin') AS db_accessadmin, IS_MEMBER('db_backupoperator') AS db_backupoperator, "
            . "IS_SRVROLEMEMBER('sysadmin') AS sysadmin";
        $rolesStatement = sqlsrv_query($connection, $rolesSql, [], ['QueryTimeout' => 5]);
        if ($rolesStatement === false) {
            throw new RuntimeException('Reporting permission check failed.');
        }
        try {
            $roles = sqlsrv_fetch_array($rolesStatement, SQLSRV_FETCH_ASSOC);
        } finally {
            sqlsrv_free_stmt($rolesStatement);
        }
        $permissionStatement = sqlsrv_query($connection,
            "SELECT permission_name FROM fn_my_permissions(NULL, 'DATABASE')", [], ['QueryTimeout' => 5]);
        if ($permissionStatement === false) {
            throw new RuntimeException('Reporting permission check failed.');
        }
        $permissions = [];
        try {
            while (true) {
                $row = sqlsrv_fetch_array($permissionStatement, SQLSRV_FETCH_ASSOC);
                if ($row === null) { break; }
                if ($row === false) { throw new RuntimeException('Reporting permission check failed.'); }
                $permissions[] = (string)($row['permission_name'] ?? '');
            }
        } finally {
            sqlsrv_free_stmt($permissionStatement);
        }
        if (!is_array($roles) || !self::isLeastPrivilegeProfile($roles, $permissions)) {
            throw new RuntimeException('Reporting account has elevated permissions.');
        }
    }

    /** Public for offline policy tests; does not replace a DBA grant audit. */
    public static function isLeastPrivilegeProfile(array $roles, array $permissions): bool
    {
        if (!is_string($roles['principal'] ?? null) || strcasecmp($roles['principal'], 'dbo') === 0) {
            return false;
        }
        foreach (['db_owner', 'db_datareader', 'db_datawriter', 'db_ddladmin',
                  'db_securityadmin', 'db_accessadmin', 'db_backupoperator', 'sysadmin'] as $role) {
            if (($roles[$role] ?? null) !== 0) {
                return false;
            }
        }
        foreach ($permissions as $permission) {
            if (!in_array($permission, ['CONNECT', 'VIEW ANY COLUMN ENCRYPTION KEY DEFINITION',
                'VIEW ANY COLUMN MASTER KEY DEFINITION'], true)) {
                return false;
            }
        }
        return in_array('CONNECT', $permissions, true);
    }
}
