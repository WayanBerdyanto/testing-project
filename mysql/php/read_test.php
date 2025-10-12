<?php
require_once __DIR__ . "/connection.php";

// ========================
// MySQL Benchmark Formatter
// ========================

echo "Successfully connected to MySQL.\n";
echo "🚀 Starting read benchmark...\n";

// Ambil info storage dari INFORMATION_SCHEMA
$statsQuery = "
    SELECT 
        table_name AS 'Table',
        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Total_MB',
        ROUND((data_length / 1024 / 1024), 2) AS 'Data_MB',
        ROUND((index_length / 1024 / 1024), 2) AS 'Index_MB'
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
    AND table_name = 'tracelog';
";

$statsResult = $conn->query($statsQuery);
if ($statsResult && $statsResult->num_rows > 0) {
    $stats = $statsResult->fetch_assoc();
    echo "📊 Table stats:\n";
    echo "📦 Storage size: " . $stats['Data_MB'] . " MB\n";
    echo "🗃️ Total index size: " . $stats['Index_MB'] . " MB\n";
} else {
    echo "⚠️ Could not retrieve table stats.\n";
}

// Hitung total rows
$countResult = $conn->query("SELECT COUNT(*) as count FROM tracelog");
$count = 0;
if ($countResult) {
    $countData = $countResult->fetch_assoc();
    $count = (int) $countData['count'];
    echo "📄 Row count: " . number_format($count) . "\n\n";
}

// ========================
// Benchmark Read Speed
// ========================
$start = microtime(true);

$sql = "SELECT * FROM tracelog";
$result = $conn->query($sql);

if ($result === false) {
    echo "❌ Query error: " . $conn->error . "\n";
    exit(1);
}

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$end = microtime(true);
$duration = $end - $start;

// ========================
// Calculate data size
// ========================
$jsonData = json_encode($rows);
$totalBytes = strlen($jsonData);
$totalKB = $totalBytes / 1024;
$totalMB = $totalKB / 1024;
$perDoc = $count > 0 ? $duration / $count : 0;

// ========================
// Output result (match Mongo style)
// ========================
echo "✅ Read " . number_format($count) . " logs in " . number_format($duration, 2) . " seconds (" . number_format($perDoc, 6) . " s per document)\n";
echo "📦 Total JSON data size: " . number_format($totalBytes, 0, '.', ',') . " bytes (" . number_format($totalKB, 2) . " KB / " . number_format($totalMB, 2) . " MB)\n";

$conn->close();
echo "🔌 MySQL connection closed.\n";
