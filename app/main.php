<?php
error_reporting(E_ALL);

$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($sock, 'localhost', 6379);
socket_listen($sock, 5);
$socket = socket_accept($sock); // Wait for first client

while (true) {
    $data = socket_read($socket, 1024);
    // No more data.
    if ($data === false) break;
    // Empty data.
    if ($data === '') break;
    // \n explode
    $cmdArr = explode("\n", $data);
    for ($i = 0; $i < count($cmdArr); $i++) {
        if ($cmdArr[$i] === 'PING') {
            socket_write($socket, "+PONG\r\n");
        } else if ($cmdArr[$i] === 'QUIT') {
            socket_write($socket, "+OK\r\n");
            break;
        } else {
            socket_write($socket, "-ERR unknown command '$cmdArr[$i]'\r\n");
        }
    }
}
socket_write($socket, "+PONG\r\n");

socket_close($sock);
?>
