# Validation

A request is a small class. Its constructor parameters are the fields, their types say what is accepted, and attributes say what else must hold. Valid input becomes an object; invalid input becomes a `422` that names each wrong field and never repeats what was sent.

```bash
trunk package:install validation      # also: trunk package:install console, for make:request
trunk make:request Signup             # writes app/Requests/Signup.php
```

## The whole idea

```php
// app/Requests/Signup.php
final readonly class Signup
{
    public function __construct(
        #[Required, Length(max: 100)]            public string $name,
        #[Required, Email]                        public string $email,
        #[Required, Length(min: 12), Sensitive]   public string $password,
        #[SameAs('password'), Sensitive]          public string $passwordConfirmation,
        #[Range(13, 120)]                         public ?int $age = null,
    ) {}
}
```

```php
// app/Controllers/SignupController.php
final readonly class SignupController
{
    public function __construct(private RequestValidator $requests, private ResponseBuilder $responses) {}

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $signup = $this->requests->validate(Signup::class, $request);   // a Signup, or a 422

        return $this->responses->json(['email' => $signup->email], 201);
    }
}
```

If `validate()` returns, every rule passed: holding a `Signup` *is* the proof. If not, it throws `Trunk\Error\ValidationException` (the one exception the whole framework uses for a 422), and the error pipeline answers:

```json
{"error":{"code":"VALIDATION_FAILED","message":"The given data was invalid.","requestId":"...",
  "details":{"fields":{"email":["Must be a valid email address."],"age":["Must be at least 13."]},
             "rules":{"email":"email","age":"min"}}}}
```

Field names are paths (`email`, `address.city`, `tags.0`). Each field reports its **first** failure, and `rules` gives that failure's stable code. Messages contain the field name and rule limits, never the submitted value.

## Where the input comes from

| The request | Read from | Values |
| --- | --- | --- |
| `Content-Type: application/json` | the body, through `JsonBody` (size, depth and content type are checked: 415, 413, 400) | keep their types: `"30"` is not an `int` |
| a form post | the parsed body | text, read strictly: `"30"` becomes `30`, `"on"` becomes `true` |
| `GET` / `HEAD` | the query string | text, as for forms |

Say otherwise on the class: `#[From(Source::Query)]`. Form and query numbers are parsed strictly (`30` yes; `0x1F`, `1e2`, ` 30`, `+30`, `30.5` for an int no), and a blank form field counts as *not sent* for numbers, enums and booleans (for text it is an empty string).

Outside HTTP use `Validator`: `$validator->validate(Signup::class, $array)` (throws) or `->check(...)` (returns a `ValidationResult` with `isValid()`, `value()`, `errors` and `old`).

## Types

The declared type is the first rule.

| Declared | Accepts |
| --- | --- |
| `string` | text (valid UTF-8, no NUL bytes) |
| `int`, `float`, `bool` | numbers and booleans; `float` accepts a whole JSON number |
| a backed enum | one of its values; a wrong value is a field error, never an exception |
| `array` | a list of at most 1000 entries; add `#[ListOf(Line::class)]` for a list of request objects |
| another request class | a nested object, validated the same way (up to 8 levels deep) |
| `?T` or a default | may be left out (or `null`); the default is used |

Anything else (`mixed`, `iterable`, a union of two types, a pure enum) is refused when the class is built, with a message that names the parameter. Input keys that are not parameters are ignored, so nothing you did not declare can ever be set.

## Rules

| Attribute | Passes when |
| --- | --- |
| `Required` | the field was sent, and text is not blank and lists are not empty |
| `RequiredIf('type', 'company')` | required when another field has that value |
| `Email` | plain ASCII address, at most 254 characters, dotted domain |
| `Url` / `Url(['https', 'ftp'])` | absolute URL with a host; only the listed schemes (default `http`, `https`), so `javascript:` never passes |
| `Uuid`, `Ip`, `Ip(4)`, `Ip(6)` | a UUID, an IP address |
| `Length(min: 3)`, `Length(max: 40)`, `Length(3, 40)` | length in characters (not bytes) |
| `Range(13, 120)`, `Range(min: 0)` | a number within limits |
| `Items(max: 10)`, `Items(min: 1)` | the number of entries in a list |
| `OneOf(['draft', 'published'])` | strictly one of a fixed list (for a backed enum, just declare the enum) |
| `Pattern('/^[a-z0-9-]+$/D', 'Use a-z, 0-9 and -.')` | matches a regular expression; a broken pattern fails the build |
| `Date`, `Date('d/m/Y')` | a real date in exactly that format (`2026-02-30` fails) |
| `SameAs('password')` | equals another field |
| `Each(new Length(max: 40))` | every entry of a list passes; reports the first that fails |
| `Sensitive` | not a rule: the value is never offered back for redisplay |

A rule configured wrongly (`Length()` with nothing, `Range(5, 1)`) fails as soon as the class is read, so it fails the build, not a request.

## Forms: showing the errors again

For an HTML page, catch the exception and render the form with the messages and the safe input:

```php
public function store(ServerRequestInterface $request): ResponseInterface
{
    try {
        $signup = $this->requests->validate(Signup::class, $request);
    } catch (ValidationException $e) {
        return $this->responder->view('signup', ['errors' => $e->firstMessages(), 'old' => $e->old(), 'csrf' => $this->csrf->token()], 422);
    }
    // ...
}
```

```html
<input name="email" value="{{ old.email }}">
<if test="errors.email"><p class="error">{{ errors.email }}</p></if>
```

`$e->old()` holds the submitted scalars (each at most 1000 bytes) **except** fields marked `#[Sensitive]`. Tusk escapes both.

## Rules only your code can check

A taken email needs the database, so check it where the database is and throw the same exception (`use Trunk\Error\ValidationException;`):

```php
if ($this->users->emailTaken($signup->email)) {
    throw ValidationException::field('email', 'That email is already registered.');
}
```

## Writing a rule

A rule is an attribute class implementing `Rule`. Its constructor parameters must be public and hold plain values (scalars, arrays, enums, other rules), because the build writes each rule back out as a `new` expression.

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Even implements Rule
{
    public function __construct(public int $above = 0) {}

    public function check(mixed $value, Context $context): ?Violation
    {
        return is_int($value) && $value % 2 === 0 && $value > $this->above
            ? null
            : new Violation('even', sprintf('Must be an even number above %d.', $this->above));
    }
}
```

`$value` has already been checked against the parameter's type; `$context->sibling('other')` reads another submitted field. Never put the value in the message. A rule that must react to a *missing* field implements `PresenceRule::whenMissing()` (that is how `Required` and `RequiredIf` work).

## Build and configuration

`trunk build` reads every class in `app/Requests`, reports mistakes as build errors (with the class and parameter), and writes `build/validation.php`; production loads only that file. Development reads the classes directly, so an edit is live at once. Both run the same engine and give the same answers (a test compares them on random input).

`config/validation.php`: `mode` (`development` or `compiled`), `requests` (discovered from `app/Requests/*.php`; add classes kept elsewhere by hand), `build`.

Measured on the development machine: validating a realistic registration takes about 7 µs, and a failing one about 5 µs, in both modes. What the build gives you is not speed but certainty: a broken request class fails `trunk build` and never reaches production.

## Limits and safety

* Every string must be valid UTF-8 without NUL bytes; other input is a field error.
* Nesting stops at 8 levels, a list at 1000 entries, and at most 100 failures are reported, so hostile input costs a bounded amount of work.
* Patterns in `Pattern` are yours: end them with `D` and avoid nested quantifiers on untrusted input. The built-in rules avoid backtracking and reject oversized input up front.
* A value of the wrong type (an integer for an enum, a list for a string, `NAN`) is a field error, never a `TypeError`. The security test feeds random hostile input in both modes to prove it.

## Errors you may see

| Message | Cause | Fix |
| --- | --- | --- |
| `App\Requests\X does not exist` (build) | a class listed in `config/validation.php` is missing | fix the name or the file |
| `X::$field needs one declared type ...` | untyped, `mixed`, or a union of two types | declare one type, optionally nullable |
| `X::$field: #[Length] is not valid: ...` | a rule was configured wrongly | fix its arguments |
| `X needs a public constructor` | the class has no constructor to read fields from | add one with promoted parameters |
| `X is not a request class. List it in config/validation.php` | production build lacks the class | put it in `app/Requests`, run `trunk build` |
| `... cannot be compiled: constructor parameter $p must be a public property` | a custom rule keeps a value private | use promoted `public` parameters |

Related: [HTTP](http.md) (errors and limits), [Views](views.md), [Auth](auth.md) (CSRF on forms), [API](API.md).
