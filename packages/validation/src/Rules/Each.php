<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * Applies rules to every entry of a list: `#[Each(new Length(max: 40))]`. Reports the first entry that fails.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Each implements Rule
{
    /** @var list<Rule> */
    public array $rules;

    public function __construct(Rule ...$rules)
    {
        $this->rules = array_values($rules);
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        foreach (\is_array($value) ? array_values($value) : [] as $index => $item) {
            foreach ($this->rules as $rule) {
                $violation = $rule->check($item, $context);

                if ($violation !== null) {
                    return new Violation($violation->rule, \sprintf('Item %d: %s', $index + 1, $violation->message));
                }
            }
        }

        return null;
    }
}
