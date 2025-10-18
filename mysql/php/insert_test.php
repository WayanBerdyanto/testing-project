<?php
require_once __DIR__ . '/connection.php';
mysqli_set_charset($conn, 'utf8mb4');

function getTableStats(mysqli $conn, string $table): array
{
    $sql = "SELECT 
        ROUND(data_length/1024/1024, 2) AS data_mb,
        ROUND(index_length/1024/1024, 2) AS index_mb,
        table_rows
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: ['data_mb' => 0, 'index_mb' => 0, 'table_rows' => 0];
    $stmt->close();

    $countRes = $conn->query("SELECT COUNT(*) AS total FROM `" . $conn->real_escape_string($table) . "`");
    $countRow = $countRes->fetch_assoc();
    $rowCount = (int)($countRow['total'] ?? $row['table_rows']);

    return [
        'data_mb' => (float)$row['data_mb'],
        'index_mb' => (float)$row['index_mb'],
        'rows' => $rowCount,
    ];
}

$total = 100000; // ubah sesuai kebutuhan
$table = 'tracelog';

echo "🚀 Starting accurate insert test for $total logs...\n";

$pre = getTableStats($conn, $table);

$types = str_repeat('s', 9);
$stmt = $conn->prepare("INSERT INTO `" . $conn->real_escape_string($table) . "` (type, appname, version, method, user, keyword, log, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$conn->query("ANALYZE TABLE `$table`");

$success = 0;
$payloadBytes = 0;

$conn->begin_transaction();
$start = hrtime(true);
for ($i = 1; $i <= $total; $i++) {
    $type = 'INFO';
    $appname = 'web.indopaket';
    $version = '2.0.0';
    $method = 'jobRetryFailedExpireAWB';
    $user = 'cronjob';
    $keyword = 'batch_' . $i;
    $log = 'Job executed successfully #' . $i;
    $ts = date('Y-m-d H:i:s');

    $payloadBytes += mb_strlen($type, '8bit')
        + mb_strlen($appname, '8bit')
        + mb_strlen($version, '8bit')
        + mb_strlen($method, '8bit')
        + mb_strlen($user, '8bit')
        + mb_strlen($keyword, '8bit')
        + mb_strlen($log, '8bit')
        + mb_strlen($ts, '8bit')
        + mb_strlen($ts, '8bit');

    $stmt->bind_param($types, $type, $appname, $version, $method, $user, $keyword, $log, $ts, $ts);
    $stmt->execute();
    $success++;
}
$conn->commit();
$end = hrtime(true);

$duration = ($end - $start) / 1e9; // s
$perInsert = $duration / $total;
$payloadMB = $payloadBytes / 1024 / 1024;
$throughput = $payloadMB / $duration; // MB/s

$post = getTableStats($conn, $table);
$deltaDataMB = max($post['data_mb'] - $pre['data_mb'], 0);
$deltaIndexMB = max($post['index_mb'] - $pre['index_mb'], 0);
$avgRowKB = $success > 0 ? ($deltaDataMB * 1024) / $success : 0;

echo "📦 Total payload size sent: " . number_format($payloadBytes, 0) . " bytes (" .
    number_format($payloadBytes / 1024, 2) . " KB / " . number_format($payloadMB, 2) . " MB)\n";

echo "✅ Inserted " . number_format($success) . " logs in " . number_format($duration, 2) . " s (" .
    number_format($perInsert, 6) . " s per insert)\n";

echo "⚡ Throughput (payload): " . number_format($throughput, 2) . " MB/s\n";

echo "\n📊 Table stats (before → after):\n";
echo "📦 Data size: " . number_format($pre['data_mb'], 2) . " MB → " . number_format($post['data_mb'], 2) . " MB (Δ " . number_format($deltaDataMB, 2) . " MB)\n";

echo "🗃️ Index size: " . number_format($pre['index_mb'], 2) . " MB → " . number_format($post['index_mb'], 2) . " MB (Δ " . number_format($deltaIndexMB, 2) . " MB)\n";

echo "📄 Row count: " . number_format($pre['rows']) . " → " . number_format($post['rows']) . "\n";

echo "🔎 Avg row footprint (data only): " . number_format($avgRowKB, 3) . " KB/row\n";

$stmt->close();
$conn->close();

echo "🔌 MySQL connection closed.\n";