<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax;

enum TokenKind
{
    case Text;
    case Output;
    case Open;
    case Close;
}
