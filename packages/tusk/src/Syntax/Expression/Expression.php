<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

/**
 * Marker for expression AST nodes. Expressions are parsed by Tusk's own grammar and never contain raw PHP.
 */
interface Expression {}
