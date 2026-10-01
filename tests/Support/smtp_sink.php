<?php

declare(strict_types=1);

// Run by the mail tests as a separate process: a minimal SMTP server on a free local port. It prints
// the port, takes one connection, accepts AUTH PLAIN, and appends every command and message to
// the file given as the first argument. With "reject" as the second argument it refuses recipients.
$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$log = is_string($arguments[1] ?? null) ? $arguments[1] : '';
$mode = is_string($arguments[2] ?? null) ? $arguments[2] : 'accept';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

if ($server === false) {
    fwrite(STDERR, (string) $error);

    exit(1);
}

echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
fflush(STDOUT);
$client = stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}

$say = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};
$say('220 sink ready');
$inData = false;

while (($line = fgets($client)) !== false) {
    file_put_contents($log, $line, FILE_APPEND);

    if ($inData) {
        if ($line === ".\r\n") {
            $inData = false;
            $say('250 2.0.0 queued');
        }

        continue;
    }

    $command = strtoupper(substr(trim($line), 0, 4));

    if ($command === 'EHLO') {
        $say('250-sink');
        $say('250-AUTH PLAIN');
        $say('250 8BITMIME');
    } elseif ($command === 'AUTH') {
        $say('235 2.7.0 authenticated');
    } elseif ($command === 'RCPT' && $mode === 'reject') {
        $say('550 5.1.1 mailbox unavailable');
    } elseif ($command === 'DATA') {
        $inData = true;
        $say('354 go ahead');
    } elseif ($command === 'QUIT') {
        $say('221 bye');
        fclose($client);

        exit(0);
    } else {
        $say('250 OK');
    }
}
