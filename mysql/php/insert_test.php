<?php
require_once __DIR__ . "/connection.php";

$total = 100000;
$logs = [];

for ($i = 1; $i <= $total; $i++) {
    $logs[] = [
        'type' => 'INFO',
        'appname' => 'web.indopaket',
        'version' => '2.0.0',
        'method' => 'jobRetryFailedExpireAWB',
        'user' => 'cronjob',
        'keyword' => 'batch_' . $i,
        'log' => 'Job executed successfully #' . $i,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

$jsonData = json_encode($logs);
$totalBytes = strlen($jsonData);
$totalKB = $totalBytes / 1024;
$totalMB = $totalKB / 1024;

echo "🚀 Starting insert test for $total logs...\n";
echo "📦 Total data size to insert: " . number_format($totalBytes, 0) . " bytes (" .
    number_format($totalKB, 2) . " KB / " . number_format($totalMB, 2) . " MB)\n";

$startInsert = microtime(true);

$types = str_repeat('s', 9);
$stmt = $conn->prepare("INSERT INTO tracelog (type, appname, version, method, user, keyword, log, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$successCount = 0;
foreach ($logs as $log) {
    $stmt->bind_param($types,
        $log['type'],
        $log['appname'],
        $log['version'],
        $log['method'],
        $log['user'],
        $log['keyword'],
        $log['log'],
        $log['created_at'],
        $log['updated_at']
    );

    if ($stmt->execute()) {
        $successCount++;
    } else {
        echo "❌ Insert error: " . $stmt->error . "\n";
        break;
    }
}

$endInsert = microtime(true);
$insertDuration = $endInsert - $startInsert;
$perInsert = $insertDuration / $total;
$throughput = $totalMB / $insertDuration;

echo "✅ Inserted " . number_format($successCount) . " logs in " . number_format($insertDuration, 2) . " seconds (" .
     number_format($perInsert, 6) . " s per insert)\n";
echo "⚡ Avg per insert: " . number_format($perInsert, 6) . " s\n";
echo "📊 Throughput: " . number_format($throughput, 2) . " MB/s\n";

// Ambil statistik storage
$result = $conn->query("
    SELECT 
        table_name,
        ROUND((data_length) / 1024 / 1024, 2) AS data_size_mb,
        ROUND((index_length) / 1024 / 1024, 2) AS index_size_mb,
        table_rows
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
    AND table_name = 'tracelog'
");

$rowCount = null;
if ($result && $row = $result->fetch_assoc()) {
    $dataSize = $row['data_size_mb'] ?? 0;
    $indexSize = $row['index_size_mb'] ?? 0;
    $rowCount = $row['table_rows'] ?? null;

    // Kalau table_rows tidak valid, pakai COUNT(*)
    if ($rowCount === null || $rowCount == 0) {
        $countResult = $conn->query("SELECT COUNT(*) AS total FROM tracelog");
        $countRow = $countResult->fetch_assoc();
        $rowCount = $countRow['total'];
    }

    echo "\n📊 Table stats:\n";
    echo "📦 Storage size: {$dataSize} MB\n";
    echo "🗃️ Total index size: {$indexSize} MB\n";
    echo "📄 Row count: " . number_format($rowCount) . "\n";
}

$stmt->close();
$conn->close();
echo "🔌 MySQL connection closed.\n";
?>
