<?php
require_once "protocol.php";

error_reporting(E_ALL);

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, 'localhost', 6379);
socket_listen($sock, 5);

echo "Server started. Waiting for connections...\n";
$socketPool = [$sock];
$write = array();
$expect = array();

$protocol = new Protocol();

// storage key value data.
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
                            $storageDataKeyValue[$key] = $value;
                            $output = $protocol->RESP2Encode("OK", 1);
                            socket_write($socket, $output);
                            break;
                        case "GET":
                            // implement get command
                            $key = $decoded[1];
                            if (!isset($storageDataKeyValue[$key])) {
                                $res = "";
                            } else {
                                $res = $storageDataKeyValue[$key];
                            }
                            socket_write($socket, $protocol->RESP2Encode($res));
                            break;
                        default:
                            socket_write($socket, "+PONG\r\n");
                    }
                }
            }
        }
    }
}