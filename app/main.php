<?php
error_reporting(E_ALL);

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
// Since the tester restarts your program quite often, setting SO_REUSEADDR
// ensures that we don't run into 'Address already in use' errors
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, 'localhost', 6379);
socket_listen($socket, 5);
socket_accept($socket); // Wait for first client

socket_write($socket, "+PONG\r\n", strlen("+PONG\r\n"));

socket_close($socket);
?>
