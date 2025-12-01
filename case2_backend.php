<?php
// case2_backend.php  (verbose, ID default 50001, can override with ?id=)
header('Content-Type: application/json');
set_time_limit(180); // allow longer execution for polling
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$log = [];

try {
    // --- config ---
    $nodes = [
        'master' => ['host'=>'10.2.14.129','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
        'node1'  => ['host'=>'10.2.14.130','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
        'node2'  => ['host'=>'10.2.14.131','user'=>'G9_1','pass'=>'pass1234','db'=>'faker'],
    ];

    function logMsg(&$log, $msg) {
        $log[] = date('H:i:s') . " - $msg";
    }

    function getConnection($node, &$log) {
        $conn = new mysqli($node['host'], $node['user'], $node['pass'], $node['db']);
        if ($conn->connect_error) {
            logMsg($log, "Connection failed to {$node['host']}: {$conn->connect_error}");
            return ['conn'=>null,'error'=>$conn->connect_error];
        }
        logMsg($log, "Connected to {$node['host']}");
        return ['conn'=>$conn,'error'=>null];
    }

    function readUser($conn, $id, &$log) {
        $stmt = $conn->prepare("SELECT id, firstName FROM Users WHERE id=?");
        if (!$stmt) {
            $err = "Prepare failed: " . $conn->error;
            logMsg($log, $err);
            return ['error'=>$err];
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        logMsg($log, "Read user $id: " . json_encode($data));
        return $data ?: null;
    }

    function masterUpdateUser($conn, $id, $newName, &$log) {
        $conn->begin_transaction();
        logMsg($log, "Master started transaction for user $id");
        $stmt = $conn->prepare("UPDATE Users SET firstName=? WHERE id=?");
        if (!$stmt) {
            throw new Exception("Prepare failed on update: " . $conn->error);
        }
        $stmt->bind_param("si", $newName, $id);
        $stmt->execute();
        $stmt->close();
        logMsg($log, "Master updated user $id to '$newName' (not yet committed)");
    }

    function pollSlaveWithTiming($conn, $id, &$log, $attempts=5, $delaySec=1) {
        $reads = [];
        for ($i=0; $i<$attempts; $i++) {
            $reads[] = [
                'attempt' => $i+1,
                'time' => date('H:i:s'),
                'data' => readUser($conn, $id, $log)
            ];
            sleep($delaySec);
        }
        return $reads;
    }

    // --- choose ID: default 50001 (Case #2 starts at 50,001). Can override via ?id=XXXXX
    $defaultId = 50001;
    $userId = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : $defaultId;
    logMsg($log, "Using userId = $userId (default $defaultId).");

    $isolationLevels = ['READ UNCOMMITTED','READ COMMITTED','REPEATABLE READ','SERIALIZABLE'];
    $results = [];

    foreach ($isolationLevels as $level) {
        logMsg($log, "=== Isolation Level: $level ===");

        // connect
        $master = getConnection($nodes['master'], $log);
        $node1  = getConnection($nodes['node1'], $log);
        $node2  = getConnection($nodes['node2'], $log);

        // connection summary
        $results[$level]['connections'] = [
            'master'=>$master['error'] ?? 'ok',
            'node1'=>$node1['error'] ?? 'ok',
            'node2'=>$node2['error'] ?? 'ok'
        ];

        if ($master['error'] || $node1['error'] || $node2['error']) {
            logMsg($log, "Skipping level $level due to connection errors.");
            continue;
        }

        // set isolation level on master BEFORE transaction
        $master['conn']->query("SET TRANSACTION ISOLATION LEVEL $level");
        logMsg($log, "Set master isolation level to $level");

        // master update (not committed yet)
        $newName = "Phase2Timing_{$level}";
        masterUpdateUser($master['conn'], $userId, $newName, $log);
        $results[$level]['master_update_time'] = date('H:i:s');
        $results[$level]['master_update_value'] = $newName;

        // master dirty read
        $masterDirtyRead = ['time'=>date('H:i:s'),'data'=>readUser($master['conn'],$userId,$log)];
        $results[$level]['master_dirty_read'] = $masterDirtyRead;

        // slaves poll while master transaction uncommitted
        $results[$level]['slave1_poll_reads'] = pollSlaveWithTiming($node1['conn'], $userId, $log);
        $results[$level]['slave2_poll_reads'] = pollSlaveWithTiming($node2['conn'], $userId, $log);

        // commit on master
        $master['conn']->commit();
        logMsg($log, "Master committed transaction for user $userId at " . date('H:i:s'));
        $results[$level]['master_commit_time'] = date('H:i:s');

        // final reads after commit
        $results[$level]['final_reads'] = [
            'master'=>['time'=>date('H:i:s'),'data'=>readUser($master['conn'],$userId,$log)],
            'slave1'=>['time'=>date('H:i:s'),'data'=>readUser($node1['conn'],$userId,$log)],
            'slave2'=>['time'=>date('H:i:s'),'data'=>readUser($node2['conn'],$userId,$log)],
        ];

        // close connections
        $master['conn']->close();
        $node1['conn']->close();
        $node2['conn']->close();
    }

    echo json_encode(['log'=>$log,'results'=>$results], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    // always return JSON with any exception info
    $log[] = date('H:i:s') . " - Exception: " . $e->getMessage();
    echo json_encode([
        'log'=>$log,
        'error'=>'Exception caught',
        'message'=>$e->getMessage(),
        'trace'=>$e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
exit;
