<?php
require_once "protocol.php";

error_reporting(E_ALL);

$shortOptions = "p:"; // -p
$longOptions = [
    "port:", // --port
    "replicaof:" // --replicaof "<MASTER_HOST> <MASTER_PORT>"
];
$options = getopt($shortOptions, $longOptions);
// p or port => set port
$port = $options['p'] ?? $options['port'] ?? 6379;

$nodeRole = "master";
// replicaof => set replicaof
$replicaof = $options['replicaof'] ?? null;
if (!empty($replicaof)) {
    $replicaof = explode(" ", $replicaof);
    $replicaof = [
        "host" => $replicaof[0],
        "port" => $replicaof[1]
    ];
    $nodeRole = "slave";
}
if ($nodeRole == "master") {
    // master_replid and master_repl_offset
    $masterReplId = bin2hex(random_bytes(40));
    $masterReplOffset = 0;
}
if ($nodeRole == "slave") {
    $masterHost = $replicaof['host'];
    $masterPort = $replicaof['port'];
}

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, 'localhost', $port);
socket_listen($sock, 5);

echo "Server started. Waiting for connections...\n";


$protocol = new Protocol();

// If node is slave, try to connect master node and send PING command.
if ($nodeRole == "slave") {
    $slaveConnMasterSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($slaveConnMasterSocket, $masterHost, $masterPort)) {
        echo "Connect master node failed.\n";
        exit(1);
    }
    // send PING command to master node.
    socket_write($slaveConnMasterSocket, $protocol->RESP2Encode(["PING"], 2));
    // receive PONG command.
    $response = socket_read($slaveConnMasterSocket, 1024);
    if (strtoupper($response) != "+PONG\r\n") {
        echo "Connect master node failed.\n";
        exit(1);
    }
    // send twice REPLCONF RESP Arrays type
    // REPLCONF listening-port <PORT>
    // REPLCONF capa psync2
    socket_write($slaveConnMasterSocket, $protocol->RESP2Encode(["REPLCONF", "listening-port", $port], 2));
    // receive OK
    $response = socket_read($slaveConnMasterSocket, 1024);
    if (strtoupper($response) != "+OK\r\n") {
        echo "[REPLCONF1] response failed.\n";
        exit(1);
    }
    socket_write($slaveConnMasterSocket, $protocol->RESP2Encode(["REPLCONF", "capa", "psync2"], 2));
    $response = socket_read($slaveConnMasterSocket, 1024);
    if (strtoupper($response) != "+OK\r\n") {
        echo "[REPLCONF2] response failed.\n";
        exit(1);
    }
}

$socketPool = [$sock];
$write = array();
$expect = array();

// storage key value data.
// key => ['value', 'expireAt']
$storageDataKeyValue = [];

while (true) {
    $read = $socketPool;
    socket_select($read, $write, $except, NULL);

    foreach ($read as $socket) {
        if ($socket == $sock) {
            $socket = socket_accept($sock);
            $socketPool[] = $socket;
        } else {
            $inputStr = socket_read($socket, 1024);
            if (!empty($inputStr)) {
                $decoded = $protocol->RESP2Decode($inputStr);
                echo "Decode: " . json_encode($decoded) . "\n";
                if (!empty($decoded)) {
                    // cmd args1 args2 args3
                    $cmd = strtoupper($decoded[0]);
                    switch ($decoded[0]) {
                        case "PING":
                            socket_write($socket, "+PONG\r\n");
                            break;
                        case "ECHO":
                            $output = $protocol->RESP2Encode($decoded[1]);
                            socket_write($socket, $output);
                            break;
                        case "SET":
                            // implement set command
                            $key = $decoded[1];
                            $value = $decoded[2];
                            // px 100
                            $pxCmd = $decoded[3] ?? null;
                            $expireAtMilliseconds = 0;
                            if ("px" == $decoded[3]) {
                                $expireMilliseconds = $decoded[4] ?? 0;
                                if ($expireMilliseconds > 0) {
                                    $expireAtMilliseconds = microtime(true) * 1000 + $expireMilliseconds;
                                }
                            }

                            $d = ['value' => $value, 'expireAt' => $expireAtMilliseconds];
                            $storageDataKeyValue[$key] = $d;
                            $output = $protocol->RESP2Encode("OK", 1);
                            socket_write($socket, $output);
                            break;
                        case "GET":
                            // implement get command
                            $key = $decoded[1];
                            if (!isset($storageDataKeyValue[$key])) {
                                $value = "";
                            } else {
                                $res = $storageDataKeyValue[$key];
                                $value = $res['value'] ?? "";
                                if ($res['expireAt'] > 0 && $res['expireAt'] < microtime(true) * 1000) {
                                    // expired will return null bulk strings: $-1\r\n
                                    $value = "";
                                }
                            }
                            socket_write($socket, $protocol->RESP2Encode($value));
                            break;
                        case "INFO":
                            // implement info command
                            $arg = $decoded[1] ?? null;
                            $info = "role:$nodeRole\r\n";
                            if ($nodeRole == "master") {
                                $info .= "master_replid:$masterReplId\r\n";
                                $info .= "master_repl_offset:$masterReplOffset\r\n";
                            }
                            socket_write($socket, $protocol->RESP2Encode($info));
                            break;
                        case "REPLCONF":
                            socket_write($socket,  $protocol->RESP2Encode("OK", 1));
                            break;
                        default:
                            socket_write($socket,  $protocol->RESP2Encode("PONG", 1));
                    }
                }
            }
        }
    }
}