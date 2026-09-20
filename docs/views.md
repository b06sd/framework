# Views (Tusk) and the Responder

Templates are `resources/views/<name>.tusk.php`. Tusk is deliberately small: HTML with a few elements and `{{ }}` output. It **escapes everything by default**, compiles to plain PHP (never `eval`), and cannot run PHP from a template.

```php
return $this->responder->view('users/show', ['user' => $user]);   // renders resources/views/users/show.tusk.php
```

## Syntax

| | |
| --- | --- |
| `{{ user.name }}` | Escaped output (HTML, quotes included). Dots read array keys or public properties. Invalid UTF-8 is substituted. |
| `{{ name\|upper }}` | Filters: `upper lower trim length default(x) join(sep) json js url`. `js` is safe inside a script, `url` for a URL component. |
| `<if test="total > 1">…<elseif test="total == 1">…<else>…</if>` | Conditions |
| `<for each="users" as="user" key="i">…<else>empty</for>` | Loops (`<else>` shows when the list is empty) |
| `<set name="x" value="expression" />` | Set a variable (self-closing) |
| `<raw value="expression" />` | Output **without** escaping. Only for HTML you have already made safe (a `SafeHtml` value or your own sanitiser). |
| `<include name="partials/row" item="user" />` | Include a template; attributes become variables |
| `<layout name="app"><fill slot="title">…</fill>…</layout>` | Use `layouts/app.tusk.php`; content outside `<fill>` goes into the default `<slot />` |
| `<slot name="title">default</slot>` | Where a layout receives content |

```html
<!-- resources/views/layouts/app.tusk.php -->
<!doctype html>
<title><slot name="title">Trunk</slot></title>
<main><slot /></main>

<!-- resources/views/users/index.tusk.php -->
<layout name="app">
    <fill slot="title">{{ title }}</fill>
    <for each="users" as="user"><article>{{ user.name }}</article><else><p>No users</p></for>
</layout>
```

Template names are validated (no `..`, no absolute paths, no NUL), so a name built from user input cannot read another file. A template that fails reports `users/index.tusk.php:14`, and the development error page shows the lines around it; production shows nothing beyond the generic error.

**Escaping is for HTML text and quoted attributes.** A value placed in a URL attribute (`href="{{ link }}"`) is HTML-escaped but is not checked for a `javascript:` scheme; validate URLs you accept from users.

## The default layout

A new web project's `layouts/app.tusk.php` is a complete page: `<slot name="title">`, a header with the application name and a `<slot name="tools">` for page-specific controls, the page content in the default `<slot />`, and a footer. A view fills only what it needs:

```html
<layout name="app">
    <fill slot="title">Orders</fill>
    <fill slot="tools"><span class="chip">3 open</span></fill>
    <section class="card"><h1>Orders</h1>…</section>
</layout>
```

Styles are in `public/styles.css` (CSS variables for colours, automatic dark mode from the visitor's system setting) and the small script in `public/script.js`. The layout loads the Inter font from Google Fonts; remove those three `<link>` lines from the layout if you do not want a request to a third party.

## Development and production

Development compiles templates on demand into `storage/views`; `trunk build` precompiles them into `build/views` and production loads only those. `config/views.php` chooses the mode.

## The Responder

`view`, `html` (already-safe HTML), `text`, `json`, `noContent`, `redirect` (local paths only). Inject it; there is no global helper.
