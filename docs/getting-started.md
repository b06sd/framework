# Getting started

## Requirements

PHP 8.4 or newer with `mbstring`, `json`, `ctype`, `tokenizer` (and `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql` for a database, `pcntl` for queue timeouts), and Composer 2.

## Get the framework

Trunk is on Packagist as [`trunkphp/framework`](https://packagist.org/packages/trunkphp/framework) (the PHP namespace is `Trunk\`). Install it once, globally, so the `trunk` command exists:

```bash
composer global require trunkphp/framework
export PATH="$(composer global config bin-dir --absolute):$PATH"     # add this line to your shell profile

trunk new blog --type=web
cd blog
composer install
php vendor/bin/trunk serve               # http://127.0.0.1:8006
```

Inside a project use `php vendor/bin/trunk ...` (the examples below write `trunk`): it runs the framework version the project locked. `trunk new` writes `"trunkphp/framework": "^0.1"` into the project's `composer.json`.

**Working on the framework itself?** Clone https://github.com/b06sd/framework, run `composer install`, and create projects against your checkout: `php ~/framework/bin/trunk new blog --type=web --repository=~/framework` (a Composer path repository with `dev` stability).

Project types are only starting points (`api`, `web`, `self-contained`, `cli`, `worker`); everything is a capability you can add later with `trunk package:install`.

Open <http://127.0.0.1:8006>: a web project starts with a styled home page (light and dark, responsive) that shows the application name and environment. Its look lives in three files you own: `resources/views/layouts/app.tusk.php` (header, footer, and `<slot />` for each page's content), `public/styles.css` and `public/script.js`. The 404 and error pages (`resources/views/errors/`) use the same layout. `trunk serve` serves files from `public/` itself; in production your web server does. `trunk doctor` reports whether the project is healthy.

## What you got

```text
trunk.php              the module list = this application's capabilities, in load order
.env                   settings (git-ignored); real environment variables override it
app/AppModule.php      your module: register services, load routes, add commands
app/Controllers/       controllers
config/*.php           one file per capability (see the configuration reference)
resources/views/       Tusk templates
routes/web.php         routes
public/index.php       the web entry point; public/styles.css and script.js are the default page's assets
storage/               logs, caches, sqlite database
build/                 written by `trunk build` (production only)
```

## A small real application

The rest of this page builds a "posts" feature with a page, a JSON API, a database, an ORM entity and a background job. First add the capabilities:

```bash
trunk package:install console     # commands (make:*, migrate, ...)
trunk package:install orm         # also enables the database capability
trunk package:install queue
trunk package:install auth        # used in the last step
```

`package:install` edits `trunk.php`, publishes `config/<name>.php`, adds `.env` settings and creates directories, and it installs any Composer packages a capability needs before it touches `trunk.php`.

### 1. A table and an entity

```bash
trunk make:migration create_posts_table     # then edit the file it prints
```

```php
// database/migrations/<timestamp>_create_posts_table.php
return new Migration(
    up: function (Schema $schema): void {
        $schema->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
        });
    },
    down: function (Schema $schema): void {
        $schema->dropIfExists('posts');
    },
);
```

```php
// app/Orm/Post.php  (a plain PHP class; no base class)
final class Post
{
    public function __construct(
        public private(set) ?int $id = null,
        public string $title = '',
        public string $body = '',
    ) {}
}
```

```php
// app/Orm/PostMap.php  (the mapping is explicit, in a separate class)
final class PostMap implements EntityMap
{
    public function entity(): string { return Post::class; }

    public function define(MapBuilder $map): void
    {
        $map->table('posts');
        $map->id();
        $map->string('title')->filterable()->sortable();
        $map->string('body');
    }
}
```

```bash
trunk migrate
trunk orm:validate      # checks every map without building
```

`make:entity Post` generates both classes for you.

### 2. A job

```bash
trunk make:job SendReceipt
trunk queue:table && trunk migrate
```

```php
// app/Jobs/SendReceipt.php
final readonly class SendReceipt implements Job
{
    public function __construct(public int $postId) {}     // the constructor is the payload

    public function handle(LoggerInterface $logger): void   // services are injected here
    {
        $logger->info('Receipt sent', ['postId' => $this->postId]);
    }

    public static function options(): JobOptions
    {
        return new JobOptions(tries: 3, backoff: [10, 60, 300], timeout: 60, queue: 'default');
    }
}
```

### 3. A controller

```php
// app/Controllers/PostController.php
final readonly class PostController
{
    public function __construct(
        private Responder $responder,
        private EntityManager $posts,
        private Queue $queue,
        private JsonBody $json,
    ) {}

    public function index(): ResponseInterface
    {
        $posts = $this->posts->repository(Post::class)->query()->readOnly()->sortBy('title')->get();

        return $this->responder->view('posts/index', ['posts' => $posts]);
    }

    public function show(int $id): ResponseInterface
    {
        $post = $this->posts->repository(Post::class)->find($id) ?? throw HttpException::notFound();

        return $this->responder->view('posts/show', ['post' => $post]);
    }

    public function apiIndex(ServerRequestInterface $request): ResponseInterface
    {
        $page = $this->posts->repository(Post::class)->query()->readOnly()
            ->filter($request->getQueryParams())      // only filterable() properties are accepted
            ->sortBy('title')->paginate(1, 20);

        return $this->responder->json(['data' => array_map($this->posts->toArray(...), $page->items), 'total' => $page->total]);
    }

    public function apiCreate(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->json->decode($request);        // 415 if not JSON, 413 if too big, 400 if malformed
        $title = \is_string($data['title'] ?? null) ? trim($data['title']) : '';

        if ($title === '') {
            throw new HttpException(422, 'A post needs a title.');
        }

        $post = new Post(title: $title, body: \is_string($data['body'] ?? null) ? $data['body'] : '');
        $this->posts->persist($post);
        $this->posts->flush();
        $this->queue->dispatch(new SendReceipt((int) $post->id));

        return $this->responder->json($this->posts->toArray($post), 201);
    }
}
```

Nothing is registered: Trunk wires every class a controller needs from its constructor types.

### 4. Routes and views

```php
// routes/web.php
return static function (RouteCollector $routes): void {
    $routes->get('/', [HomeController::class, 'index'], 'home');
    $routes->get('/posts', [PostController::class, 'index'], 'posts.index');
    $routes->get('/posts/{id:int}', [PostController::class, 'show'], 'posts.show');

    $routes->group('/api', static function (RouteCollector $api): void {
        $api->get('/posts', [PostController::class, 'apiIndex']);
        $api->post('/posts', [PostController::class, 'apiCreate']);
    });
};
```

```html
<!-- resources/views/posts/index.tusk.php -->
<layout name="app">
    <fill slot="title">Posts</fill>
    <h1>Posts</h1>
    <for each="posts" as="post">
        <article><h2><a href="/posts/{{ post.id }}">{{ post.title }}</a></h2></article>
    <else>
        <p>No posts yet.</p>
    </for>
</layout>
```

```html
<!-- resources/views/posts/show.tusk.php -->
<layout name="app">
    <fill slot="title">{{ post.title }}</fill>
    <h1>{{ post.title }}</h1>
    <p>{{ post.body }}</p>
</layout>
```

`trunk route:list` shows every route with how each argument is bound.

### 5. Try it

```bash
trunk serve &
curl -i -X POST localhost:8006/api/posts -H 'Content-Type: application/json' \
     -d '{"title":"Hello Trunk","body":"First <b>post</b>"}'      # 201, the body is stored as data
curl 'localhost:8006/api/posts?title=Hello%20Trunk'                # {"data":[...],"total":1}
curl localhost:8006/posts                                          # rendered page
curl -o /dev/null -w '%{http_code}\n' localhost:8006/posts/99      # 404
curl -X POST localhost:8006/api/posts -H 'Content-Type: application/json' -d '{"title":""}'   # 422
curl -X POST localhost:8006/api/posts -d 'x=1'                     # 415: Expected a JSON body.
trunk queue:work --stop-when-empty                                 # runs SendReceipt for each post
```

Open a post page and look at the source: `First <b>post</b>` is escaped (`&lt;b&gt;`), because Tusk escapes by default. In `storage/logs/` the job's log line carries `originRequestId`, the request that queued it.

### 6. Sign-in (optional)

```bash
trunk auth:table --users && trunk migrate
```

Add a command to create a user (`trunk make:command CreateUser`, register it in `AppModule::commands()`), a controller and two views as shown in the [auth guide](auth.md), and routes:

```php
$web = [SessionMiddleware::class, CsrfMiddleware::class];
$routes->get('/login', [AccountController::class, 'loginForm'], middleware: $web);
$routes->post('/login', [AccountController::class, 'login'], middleware: $web);
$routes->post('/logout', [AccountController::class, 'logout'], middleware: $web);
$routes->get('/account', [AccountController::class, 'show'], middleware: [...$web, RequireLogin::class]);
```

A signed-out browser hitting `/account` is redirected to `/login`; a wrong password is a 401; a post without the CSRF token is a 403.

## Go to production

```bash
trunk build                       # writes build/ (validates everything first)
APP_ENV=production php public/index.php     # or your web server; unset APP_ENV means production
```

Production refuses to start without a build. See [Deployment](deployment.md).

## Next

[Concepts](concepts.md) explains what just happened. The [testing guide](testing.md) explains how to run the framework's own suites and how to test your app.
