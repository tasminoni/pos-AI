<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../services/SchemaService.php';

$root = dirname(__DIR__);
$catalogPath = $root . '/schema_catalog.json';
$action = $argv[1] ?? '';

try {
    if ($action === 'build') {
        $source = file_get_contents($root . '/Pos_Local_Database.txt');
        if ($source === false) {
            throw new RuntimeException('Schema reference could not be read.');
        }
        $catalog = SchemaService::fromReference($source);
    } elseif ($action === 'verify') {
        require_once $root . '/db.php';
        $service = new SchemaService($catalogPath);
        $catalog = $service->verifyAgainstMssql(DatabaseManager::getConnection());
    } else {
        fwrite(STDERR, "Usage: php bin/schema_catalog.php build|verify\n");
        exit(2);
    }

    $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($catalogPath, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write schema catalog.');
    }
    $verification = $catalog['verification'];
    printf("Catalog %s: %d tables, %d relationships, %d mismatches.\n",
        $verification['status'], count($catalog['tables']), count($catalog['relationships']), count($verification['mismatches']));
} catch (Throwable $error) {
    // Do not print sqlsrv error details or credentials in a CLI diagnostic.
    fwrite(STDERR, "Schema catalog operation failed: " . (
        str_contains($error->getMessage(), 'SQLSRV') ? $error->getMessage() : 'review runtime and source availability'
    ) . "\n");
    exit(1);
}
