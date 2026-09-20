<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

use Trunk\Validation\Attribute\ListOf;
use Trunk\Validation\Attribute\Sensitive;
use Trunk\Validation\Rules\Each;
use Trunk\Validation\Rules\Email;
use Trunk\Validation\Rules\Items;
use Trunk\Validation\Rules\Length;
use Trunk\Validation\Rules\Range;
use Trunk\Validation\Rules\Required;
use Trunk\Validation\Rules\RequiredIf;
use Trunk\Validation\Rules\SameAs;

final readonly class RegisterRequest
{
    /**
     * @param list<string>  $tags
     * @param list<Address> $shipTo
     */
    public function __construct(
        #[Required, Email]
        public string $email,
        #[Required, Length(min: 12), Sensitive]
        public string $password,
        #[SameAs('password'), Sensitive]
        public string $passwordConfirmation,
        #[Range(13, 120)]
        public ?int $age = null,
        #[Each(new Length(1, 10)), Items(max: 3)]
        public array $tags = [],
        public ?Address $address = null,
        #[ListOf(Address::class), Items(max: 2)]
        public array $shipTo = [],
        public Plan $plan = Plan::Free,
        public bool $newsletter = false,
        #[RequiredIf('plan', 'pro'), Length(max: 30)]
        public ?string $company = null,
    ) {}
}
