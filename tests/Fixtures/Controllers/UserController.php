<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

final class UserController
{
    public function index(): void {}

    public function show(): void {}

    public static function staticAction(): void {}

    protected function hidden(): void {}
}
