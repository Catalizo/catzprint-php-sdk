<?php

declare(strict_types=1);

/*
 * A unicast stand-in for an mDNS responder (needs ext-sockets):
 *   php fake_mdns.php <dir>
 * Listens on UDP 127.0.0.1:<port> (written to <dir>/port), and answers every
 * query whose question is _catzprint._tcp.local. with the bytes in
 * <dir>/answer. Counts queries in <dir>/queries.
 */

$dir = $argv[1];
$s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_bind($s, '127.0.0.1', 0);
socket_getsockname($s, $addr, $port);
file_put_contents("$dir/port.tmp", (string) $port);
rename("$dir/port.tmp", "$dir/port");
socket_set_option($s, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 500_000]);
$started = time();
$n = 0;
while (time() - $started < 120 && is_dir($dir)) {
    $buf = '';
    $from = '';
    $fromPort = 0;
    $len = @socket_recvfrom($s, $buf, 2048, 0, $from, $fromPort);
    if ($len === false || $len < 12) {
        continue;
    }
    if (!str_contains($buf, "\x0a_catzprint\x04_tcp\x05local\x00")) {
        continue;
    }
    file_put_contents("$dir/queries", (string) ++$n);
    $answer = (string) @file_get_contents("$dir/answer");
    if ($answer !== '') {
        socket_sendto($s, $answer, strlen($answer), 0, $from, $fromPort);
    }
}
