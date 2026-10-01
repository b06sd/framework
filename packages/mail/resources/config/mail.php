<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Settings come from the environment and .env. MAIL_DSN says where email goes (a Symfony Mailer DSN):
//   smtp://user:password@smtp.example.com:587      any SMTP server
//   ses+smtp://KEY:SECRET@default?region=eu-west-1  a provider: install its bridge first, e.g.
//   mailgun+api://KEY:DOMAIN@default                 composer require symfony/amazon-mailer, symfony/mailgun-mailer,
//   postmark+api://TOKEN@default                     symfony/postmark-mailer or symfony/sendgrid-mailer
//   file://default                                   development: keep each email as storage/mail/*.eml (refused in production)
//   null://null                                      discard everything
// MAIL_DSN holds credentials, so it is read from the environment when the process starts and never
// written into the build.
return static fn(Runtime $runtime): array => [
    'dsn' => $runtime->secret('MAIL_DSN', 'file://default'),
    // The sender of every message that does not set its own From.
    'from' => [
        'address' => $runtime->variable('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => $runtime->variable('MAIL_FROM_NAME', $runtime->variable('APP_NAME', 'App')),
    ],
];
