<?php
require_once __DIR__ . "/connection.php";

$total_ops = 5000;
$start = microtime(true);

for ($i = 0; $i < $total_ops; $i++) {
    if ($i % 2 == 0) {
        // INSERT
        $type = 'INFO';
        $appname = 'web.indopaket';
        $version = '2.0.0';
        $method = 'POST /api/test';
        $user = 'user_' . rand(1, 1000);
        $keyword = 'mixed_test';
        $log = 'Simulated log entry number ' . $i;
        $datetime = date('Y-m-d H:i:s');

        $conn->query("INSERT INTO tracelog (type, appname, version, method, user, keyword, log, created_at, updated_at)
                      VALUES ('$type', '$appname', '$version', '$method', '$user', '$keyword', '$log', '$datetime', '$datetime')");
    } else {
        // READ
        $user = 'user_' . rand(1, 1000);
        $conn->query("SELECT * FROM tracelog WHERE user = '$user' LIMIT 5");
    }
}

$end = microtime(true);
$duration = $end - $start;

echo "✅ Completed $total_ops mixed (insert+read) operations in " . round($duration, 2) . " seconds\n";
