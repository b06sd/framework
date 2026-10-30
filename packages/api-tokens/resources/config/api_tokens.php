<?php

declare(strict_types=1);

// The API token page at /account/tokens (resources/views/account/tokens.tusk.php is yours to change).
return [
    // What a user may allow a new token to do, e.g. ['read', 'write:orders']. Your code checks them with
    // $auth->tokenCan('read') or the Gate. Empty: every token has full access ("*").
    'abilities' => [],
    // At most this many active tokens per user.
    'max_active' => 20,
];
