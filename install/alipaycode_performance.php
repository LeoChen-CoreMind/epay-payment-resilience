<?php
if (PHP_SAPI !== 'cli') {
    exit("This migration can only be run in CLI mode\n");
}

require __DIR__.'/../config.php';
require_once __DIR__.'/AlipayCodeIndexPlanner.php';

if (!preg_match('/^[A-Za-z0-9_]+$/D', (string)$dbconfig['dbqz'])) {
    fwrite(STDERR, "Invalid database table prefix\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$dbconfig['host']};dbname={$dbconfig['dbname']};port={$dbconfig['port']};charset=utf8mb4",
        $dbconfig['user'],
        $dbconfig['pwd'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Database connection failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

$table = $dbconfig['dbqz'].'_order';

try {
    $rows = $pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll();
    $missing = AlipayCodeIndexPlanner::missing($rows);
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to inspect order indexes: '.$e->getMessage().PHP_EOL);
    exit(1);
}

if (empty($missing)) {
    echo "All Alipay code performance indexes already exist\n";
    exit(0);
}

$clauses = [];
foreach ($missing as $name => $columns) {
    $quotedColumns = array_map(function ($column) {
        return '`'.$column.'`';
    }, $columns);
    $clauses[] = 'ADD INDEX `'.$name.'` ('.implode(',', $quotedColumns).')';
}

try {
    $pdo->exec("ALTER TABLE `{$table}` ".implode(', ', $clauses));
} catch (Throwable $e) {
    fwrite(STDERR, 'Failed to add order indexes: '.$e->getMessage().PHP_EOL);
    exit(1);
}

foreach (array_keys($missing) as $name) {
    echo "Added index {$name}\n";
}

try {
    $verifiedRows = $pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll();
    if (!empty(AlipayCodeIndexPlanner::missing($verifiedRows))) {
        throw new RuntimeException('Indexes are still missing after ALTER TABLE');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Index verification failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}

echo "Alipay code performance migration complete\n";
