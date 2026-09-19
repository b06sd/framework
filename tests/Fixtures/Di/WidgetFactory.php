<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final class WidgetFactory
{
    public function make(string $label): Widget
    {
        return new Widget(strtoupper($label));
    }

    public static function staticMake(): Widget
    {
        return new Widget('static');
    }

    protected function hidden(): Widget
    {
        return new Widget('hidden');
    }
}
