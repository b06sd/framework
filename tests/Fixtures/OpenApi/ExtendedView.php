<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

final class ExtendedView extends BaseView
{
    public self $next;
    public ?parent $parent = null;
}
