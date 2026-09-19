<?php

declare(strict_types=1);

// A page on a different site (localhost, while the application is on 127.0.0.1) that tries the classic
// attacks against a visitor who is signed in to the application.

$app = (string) getenv('TRUNK_BROWSER_APP');
$page = $_GET['page'] ?? '';

header('Content-Type: text/html; charset=utf-8');

if ($page === 'logout') {
    echo '<form id="f" method="post" action="' . $app . '/web/logout-form"><input name="_csrf" value="guess"></form><script>document.getElementById("f").submit()</script>';
} elseif ($page === 'login-csrf') {
    echo '<form id="f" method="post" action="' . $app . '/web/login-form"><input name="email" value="mallory@example.com"><input name="password" value="attacker password 1"></form><script>document.getElementById("f").submit()</script>';
} elseif ($page === 'frame') {
    echo '<iframe id="victim" src="' . $app . '/web/form" width="400" height="300"></iframe>';
} else {
    echo 'attacker';
}
