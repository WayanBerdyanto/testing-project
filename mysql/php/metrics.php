<?php
require_once __DIR__ . '/connection.php';
mysqli_set_charset($conn, 'utf8mb4');
set_time_limit(0);

function parseArgs(array $argv): array {
    // Default table set to 'tracelog'; can be overridden via --table=...
    $args = ['table' => 'tracelog', 'interval' => 1.0, 'analyze' => false];
    foreach ($argv as $arg) {
        if (preg_match('/^--table=(.+)$/', $arg, $m)) { $args['table'] = $m[1]; }
        elseif (preg_match('/^--interval=(\d+(?:\.\d+)?)$/', $arg, $m)) { $args['interval'] = (float)$m[1]; }
        elseif ($arg === '--analyze') { $args['analyze'] = true; }
    }
    return $args;
}
function showStatus(mysqli $c): array {
    $res = $c->query("SHOW GLOBAL STATUS");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[$row['Variable_name']] = $row['Value']; }
    return $out;
}
function showVars(mysqli $c): array {
    $res = $c->query("SHOW VARIABLES");
    $out = [];
    while ($row = $res->fetch_assoc()) { $out[$row['Variable_name']] = $row['Value']; }
    return $out;
}
function tableStatus(mysqli $c, string $t): ?array {
    $t = $c->real_escape_string($t);
    $res = $c->query("SHOW TABLE STATUS LIKE '$t'");
    return $res ? $res->fetch_assoc() : null;
}
function dbTotals(mysqli $c): array {
    $res = $c->query("SELECT SUM(data_length) AS dl, SUM(index_length) AS il, SUM(table_rows) AS rows_count FROM information_schema.tables WHERE table_schema = DATABASE()");
    $row = $res->fetch_assoc();
    return [
        'data_mb' => ((float)($row['dl'] ?? 0)) / 1024 / 1024,
        'index_mb' => ((float)($row['il'] ?? 0)) / 1024 / 1024,
        'rows' => (int)($row['rows_count'] ?? 0),
    ];
}
function delta(array $b, array $a, string $key): float { return (float)($b[$key] ?? 0) - (float)($a[$key] ?? 0); }

$args = parseArgs($argv);
$interval = max(0.5, (float)$args['interval']);

if ($args['analyze'] && $args['table']) {
    $conn->query("ANALYZE TABLE `" . $conn->real_escape_string($args['table']) . "`");
}

$vars = showVars($conn);
$statusA = showStatus($conn);
usleep((int)($interval * 1e6));
$statusB = showStatus($conn);

$qps = delta($statusB, $statusA, 'Queries') / $interval;
$tps = (delta($statusB, $statusA, 'Com_insert') + delta($statusB, $statusA, 'Com_update') + delta($statusB, $statusA, 'Com_delete')) / $interval;
$bytesSentMBps = delta($statusB, $statusA, 'Bytes_sent') / 1024 / 1024 / $interval;
$bytesRecvMBps = delta($statusB, $statusA, 'Bytes_received') / 1024 / 1024 / $interval;
$reads = delta($statusB, $statusA, 'Innodb_buffer_pool_reads');
$reqs  = delta($statusB, $statusA, 'Innodb_buffer_pool_read_requests');
$hit   = $reqs > 0 ? max(0, min(1, 1 - ($reads / $reqs))) : null;

$threadsRunning = (int)($statusB['Threads_running'] ?? 0);
$threadsConn    = (int)($statusB['Threads_connected'] ?? 0);

$db = dbTotals($conn);

$tableLine = '';
if (!empty($args['table'])) {
    $ts = tableStatus($conn, $args['table']);
    $tDataMB = ((float)($ts['Data_length'] ?? 0)) / 1024 / 1024;
    $tIndexMB = ((float)($ts['Index_length'] ?? 0)) / 1024 / 1024;
    $tRows = (int)($ts['Rows'] ?? 0);
    $avgRowKB = $tRows > 0 ? ((float)($ts['Avg_row_length'] ?? 0)) / 1024 : 0;
    $tableLine = "• Table `{$args['table']}`: data " . number_format($tDataMB, 2) . " MB | index " . number_format($tIndexMB, 2) . " MB | rows " . number_format($tRows) . " | avg row " . number_format($avgRowKB, 3) . " KB\n";
}

echo "📡 MySQL Metrics Snapshot (interval {$interval}s)\n";
echo "• Version: " . ($vars['version'] ?? 'n/a') . " | InnoDB buffer pool: " . number_format(((float)($vars['innodb_buffer_pool_size'] ?? 0)) / 1024 / 1024, 0) . " MB\n";
echo "• Connections: running " . number_format($threadsRunning) . " / connected " . number_format($threadsConn) . "\n";
echo "• QPS: " . number_format($qps, 2) . " | TPS: " . number_format($tps, 2) . "\n";
echo "• Throughput: send " . number_format($bytesSentMBps, 2) . " MB/s | recv " . number_format($bytesRecvMBps, 2) . " MB/s\n";
if ($hit !== null) { echo "• Buffer pool hit ratio: " . number_format($hit * 100, 2) . "%\n"; } else { echo "• Buffer pool hit ratio: n/a\n"; }
echo "• Database totals: data " . number_format($db['data_mb'], 2) . " MB | index " . number_format($db['index_mb'], 2) . " MB | rows " . number_format($db['rows']) . "\n";
echo $tableLine;

echo "\n💡 Hints:\n";
echo "- If hit ratio < 99%, consider more RAM or horizontal scaling.\n";
echo "- If TPS/QPS saturate and latency rises, add CPU/I/O (vertical) or shard.\n";

$conn->close();
echo "🔌 MySQL connection closed.\n";