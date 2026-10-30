<?php

declare(strict_types=1);

namespace Trunk\Tests\Install;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\Cli;
use Trunk\Tests\Support\JwtKit;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * What a new user gets: `trunk new`, a real `composer install` (no borrowed autoloader, no dev
 * dependencies), then the project's own vendor/bin/trunk and public/index.php. This is the only
 * test that can prove a project's Composer requirements are complete, so it must stay real.
 */
final class RealInstallTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function setUp(): void
    {
        [$code] = new Cli()->run(['composer', '--version'], sys_get_temp_dir());

        if ($code !== 0) {
            self::markTestSkipped('composer is not available.');
        }
    }

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function profiles(): iterable
    {
        yield 'api' => ['api', '/api/customers/2', '"name":"Grace Hopper"'];
        yield 'web' => ['web', '/', '<h1>'];
        yield 'self-contained' => ['self-contained', '/api/customers/1', '"id":1'];
        yield 'cli' => ['cli', '', ''];
        yield 'worker' => ['worker', '', ''];
    }

    #[DataProvider('profiles')]
    public function test_a_freshly_installed_project_passes_doctor_builds_and_runs_in_production(string $type, string $uri, string $expected): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('fresh-' . $type, $type, realInstall: true);

        if ($type === 'worker') {
            // The worker profile enables queue by default; doctor now checks that its table exists,
            // exactly the migration step a real deploy must not skip.
            $project->trunk(['queue:table']);
            $project->trunk(['migrate']);
        }

        // Act
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [$listCode, $listOut] = $project->trunk(['list'], null, ['APP_ENV' => 'production']);
        [, $body, $err] = $uri === '' ? [0, '', ''] : $project->request('GET', $uri, 'production');

        // Assert
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertStringContainsString('✓ Capability dependencies', $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame(0, $listCode, $listOut);
        self::assertStringNotContainsString('not found', $err . $body, 'a missing class means the project lacks a Composer requirement');
        self::assertStringContainsString($expected, $body, $err);
    }

    public function test_auth_installs_migrates_issues_a_token_and_guards_a_route_in_a_real_project(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('authed', 'api', realInstall: true);
        $dir = $project->directory;

        // Act
        $project->trunk(['package:install', 'console']);
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'auth']);
        [$tableCode] = $project->trunk(['auth:table', '--users']);
        [$migrateCode, $migrateOut, $migrateErr] = $project->trunk(['migrate']);
        self::assertSame([0, 0, 0], [$installCode, $tableCode, $migrateCode], $installOut . $installErr . $migrateOut . $migrateErr);
        $pdo = new PDO('sqlite:' . $dir . '/storage/database.sqlite');
        $pdo->prepare('INSERT INTO users (name, email, password, session_version) VALUES (?, ?, ?, ?)')->execute(['Ada', 'ada@example.com', password_hash('correct horse battery', \PASSWORD_ARGON2ID), '1']);
        [$tokenCode, $tokenOut] = $project->trunk(['auth:token', '1', 'ci', '--abilities=read']);
        preg_match('/trk_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}/', $tokenOut, $m);
        $token = $m[0] ?? '';
        file_put_contents($dir . '/app/Controllers/MeController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Auth\\Auth;\nuse Trunk\\Http\\Response\\ResponseBuilder;\n\nfinal readonly class MeController\n{\n    public function __construct(private ResponseBuilder \$responses, private Auth \$auth) {}\n\n    public function show(): ResponseInterface\n    {\n        return \$this->responses->json(['id' => \$this->auth->user()?->authId(), 'read' => \$this->auth->tokenCan('read'), 'write' => \$this->auth->tokenCan('write')]);\n    }\n}\n");
        $routes = (string) file_get_contents($dir . '/routes/api.php');
        file_put_contents($dir . '/routes/api.php', str_replace("    });\n};", "        \$api->get('/me', [\\App\\Controllers\\MeController::class, 'show'], middleware: [\\Trunk\\Auth\\Http\\RequireToken::class]);\n    });\n};", $routes));
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [, $ok] = $project->request('GET', '/api/me', 'production', authorization: 'Bearer ' . $token);
        [, $missing] = $project->request('GET', '/api/me', 'production');
        [, $forged] = $project->request('GET', '/api/me', 'production', authorization: 'Bearer ' . substr($token, 0, -1) . 'x');
        [$pruneCode, $pruneOut] = $project->trunk(['auth:prune']);

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertSame([0, 0, 0], [$tableCode, $migrateCode, $tokenCode], $migrateOut . $tokenOut);
        self::assertNotSame('', $token, $tokenOut);
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame('{"id":"1","read":true,"write":false}', $ok);
        self::assertStringContainsString('Unauthorized', $missing);
        self::assertStringContainsString('Unauthorized', $forged);
        self::assertSame(0, $pruneCode, $pruneOut);
    }

    public function test_mail_installs_symfony_mailer_and_sends_over_real_smtp_from_the_production_build(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('mailed', 'api', realInstall: true);
        $transcript = $project->base . '/smtp.log';

        // Act: a real `composer require symfony/mailer`, then a development send and a production send
        $project->trunk(['package:install', 'console']);
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'mail']);
        [$localCode, $localOut, $localErr] = $project->trunk(['mail:test', 'ada@example.com'], environment: ['APP_ENV' => 'local']);
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        $sink = proc_open([\PHP_BINARY, \dirname(__DIR__) . '/Support/smtp_sink.php', $transcript, 'accept'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($sink);
        $port = (int) fgets($pipes[1]);
        [$sendCode, $sendOut, $sendErr] = $project->trunk(['mail:test', 'ada@example.com'], environment: ['APP_ENV' => 'production', 'MAIL_DSN' => 'smtp://shop:pw@127.0.0.1:' . $port]);
        proc_close($sink);

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertDirectoryExists($project->directory . '/vendor/symfony/mailer', 'package:install ran a real composer require');
        self::assertSame(0, $localCode, $localOut . $localErr);
        self::assertCount(1, glob($project->directory . '/storage/mail/*.eml') ?: []);
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame(0, $sendCode, $sendOut . $sendErr);
        self::assertStringContainsString('RCPT TO:<ada@example.com>', (string) file_get_contents($transcript));
        self::assertStringContainsString('Subject: Trunk mail test', (string) file_get_contents($transcript));
    }

    public function test_storage_installs_flysystem_and_serves_a_stored_file_from_the_production_build(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('stored', 'api', realInstall: true);
        file_put_contents($project->directory . '/app/Controllers/FileController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Storage\\Storage;\n\nfinal readonly class FileController\n{\n    public function __construct(private Storage \$storage, private ResponseBuilder \$responses) {}\n\n    public function show(): ResponseInterface\n    {\n        \$this->storage->disk()->write('notes/hello.txt', 'stored by flysystem');\n\n        return \$this->responses->download(\$this->storage->disk()->readStream('notes/hello.txt'), 'hello.txt', 'text/plain');\n    }\n}\n");
        file_put_contents($project->directory . '/routes/api.php', "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Controllers\\FileController;\nuse Trunk\\Router\\Definition\\RouteCollector;\n\nreturn static function (RouteCollector \$routes): void {\n    \$routes->get('/file', [FileController::class, 'show']);\n};\n");

        // Act
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'storage']);
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [, $body] = $project->request('GET', '/file', 'production');

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertDirectoryExists($project->directory . '/vendor/league/flysystem', 'package:install ran a real composer require');
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame('stored by flysystem', $body);
        self::assertSame('0640', substr(\sprintf('%o', fileperms($project->directory . '/storage/app/notes/hello.txt')), -4));
    }

    public function test_pdf_installs_dompdf_and_serves_an_invoice_rendered_from_a_template(): void
    {
        // Arrange: a web project (Tusk views), an invoice template and a controller that renders it to PDF
        $project = $this->project = new ScaffoldedProject('billing', 'web', realInstall: true);
        @mkdir($project->directory . '/resources/views/invoices', 0o755, true);
        file_put_contents($project->directory . '/resources/views/invoices/show.tusk.php', "<html><body><h1>Invoice {{ number }}</h1><p>Total: {{ total }}</p></body></html>\n");
        file_put_contents($project->directory . '/app/Controllers/InvoiceController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Pdf\\Pdf;\nuse Trunk\\Tusk\\Renderer;\n\nfinal readonly class InvoiceController\n{\n    public function __construct(private Pdf \$pdf, private Renderer \$views, private ResponseBuilder \$responses) {}\n\n    public function show(): ResponseInterface\n    {\n        \$bytes = \$this->pdf->render(\$this->views->render('invoices/show', ['number' => 'INV-7', 'total' => '19.99']));\n\n        return \$this->responses->download(\$bytes, 'invoice-INV-7.pdf', 'application/pdf');\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/web.php');
        file_put_contents($project->directory . '/routes/web.php', preg_replace('/\};\s*$/', "    \$routes->get('/invoice', [\\App\\Controllers\\InvoiceController::class, 'show']);\n};\n", $routes, 1));

        // Act
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'pdf']);
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [, $body, $error] = $project->request('GET', '/invoice', 'production');

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertDirectoryExists($project->directory . '/vendor/dompdf/dompdf', 'package:install ran a real composer require');
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertStringStartsWith('%PDF-', $body, $error);
        self::assertStringContainsString('%%EOF', $body);
    }

    public function test_a_fresh_web_project_serves_its_page_and_its_static_files_over_a_real_http_server(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('served', 'web', realInstall: true);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $port = (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $server = proc_open([\PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $project->directory . '/public', $project->directory . '/public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $project->directory, ['APP_ENV' => 'local', 'APP_DEBUG' => '0', 'PATH' => (string) getenv('PATH')]);
        self::assertIsResource($server);

        try {
            $get = static function (string $path) use ($port): array {
                for ($attempt = 0; $attempt < 60; ++$attempt) {
                    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Accept: text/html\r\n"]]));

                    if ($body !== false) {
                        $headers = http_get_last_response_headers() ?? [];

                        return [(int) substr($headers[0] ?? 'HTTP/1.1 0', 9, 3), implode("\n", $headers), $body];
                    }

                    usleep(50_000);
                }

                return [0, '', ''];
            };

            // Act
            [$pageStatus, , $page] = $get('/');
            [$cssStatus, $cssHeaders, $css] = $get('/styles.css');
            [$jsStatus, , $js] = $get('/script.js');
            [$sourceStatus] = $get('/index.php');
            [$outsideStatus] = $get('/composer.json');
            [$missingStatus, , $missing] = $get('/nothing-here');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        // Assert
        self::assertSame(200, $pageStatus);
        self::assertStringContainsString('<h1>Served</h1>', $page);
        self::assertSame(200, $cssStatus);
        self::assertStringContainsString('text/css', $cssHeaders);
        self::assertStringContainsString('--accent', $css);
        self::assertSame(200, $jsStatus);
        self::assertStringContainsString('clock', $js);
        self::assertSame([404, 404], [$sourceStatus, $outsideStatus], 'only real static files under public/ are served; never the entry script or anything outside it');
        self::assertSame(404, $missingStatus);
        self::assertStringContainsString('Page not found', $missing, 'the project\'s own styled error page');
    }

    public function test_validation_installs_generates_a_request_class_and_answers_422_in_development_and_from_the_build(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('forms', 'api', realInstall: true);
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'validation']);
        $project->trunk(['package:install', 'console']);
        [$makeCode, $makeOut] = $project->trunk(['make:request', 'Signup']);
        [$again] = $project->trunk(['make:request', 'Signup']);
        [$bad] = $project->trunk(['make:request', '../Evil']);
        $dir = $project->directory;
        file_put_contents($dir . '/app/Controllers/SignupController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse App\\Requests\\Signup;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Psr\\Http\\Message\\ServerRequestInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Validation\\Http\\RequestValidator;\n\nfinal readonly class SignupController\n{\n    public function __construct(private RequestValidator \$requests, private ResponseBuilder \$responses) {}\n\n    public function store(ServerRequestInterface \$request): ResponseInterface\n    {\n        \$signup = \$this->requests->validate(Signup::class, \$request);\n\n        return \$this->responses->json(['name' => \$signup->name, 'email' => \$signup->email], 201);\n    }\n}\n");
        $routes = (string) file_get_contents($dir . '/routes/api.php');
        file_put_contents($dir . '/routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->post('/signup', [\\App\\Controllers\\SignupController::class, 'store']);\n        \$api->get('/customers', ", $routes));
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);

        // Act
        $development = $this->serveAndPost($project, 'local');
        [$buildCode, $buildOut] = $project->trunk(['build']);
        $production = $this->serveAndPost($project, 'production');

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertSame([0, 1, 1], [$makeCode, $again, $bad], $makeOut);
        self::assertFileExists($dir . '/app/Requests/Signup.php');
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertFileExists($dir . '/build/validation.php');

        foreach (['development' => $development, 'production (compiled)' => $production] as $mode => [$ok, $invalid]) {
            self::assertSame(201, $ok[0], $mode);
            self::assertSame('{"name":"Ada","email":"ada@example.com"}', $ok[1], $mode);
            self::assertSame(422, $invalid[0], $mode);
            self::assertStringContainsString('"code":"VALIDATION_FAILED"', $invalid[1], $mode);
            self::assertStringContainsString('"fields":{"email":["Must be a valid email address."]}', $invalid[1], $mode);
            self::assertStringContainsString('"rules":{"email":"email"}', $invalid[1], $mode);
            self::assertStringNotContainsString('not-an-email', $invalid[1], $mode . ': the submitted value must never be echoed');
        }
    }

    public function test_openapi_installs_and_documents_a_typed_body_route_in_development_and_from_the_build(): void
    {
        // Arrange: an API with a #[Body] handler described by #[Returns]
        $project = $this->project = new ScaffoldedProject('catalog', 'api', realInstall: true);
        $dir = $project->directory;
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'validation']);
        $project->trunk(['package:install', 'console']);
        [$openApiCode, $openApiOut, $openApiErr] = $project->trunk(['package:install', 'openapi']);
        file_put_contents($dir . '/app/Requests/AddProduct.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Requests;\n\nuse Trunk\\Validation\\Rules\\Length;\nuse Trunk\\Validation\\Rules\\Required;\n\nfinal readonly class AddProduct\n{\n    public function __construct(#[Required, Length(max: 40)] public string \$name) {}\n}\n");
        file_put_contents($dir . '/app/Controllers/ProductController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse App\\Requests\\AddProduct;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\OpenApi\\Attribute\\Returns;\nuse Trunk\\Router\\Attribute\\Body;\n\nfinal readonly class ProductController\n{\n    public function __construct(private ResponseBuilder \$responses) {}\n\n    /**\n     * Add a product.\n     */\n    #[Returns(201, AddProduct::class)]\n    public function store(#[Body] AddProduct \$product): ResponseInterface\n    {\n        return \$this->responses->json(['name' => \$product->name], 201);\n    }\n}\n");
        $routes = (string) file_get_contents($dir . '/routes/api.php');
        file_put_contents($dir . '/routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->post('/products', [\\App\\Controllers\\ProductController::class, 'store'], 'products.store');\n        \$api->get('/customers', ", $routes));
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);

        // Act
        [$writeCode, $writeOut, $writeErr] = $project->trunk(['openapi', 'docs/api.json']);
        [, $localSpec, $localErr] = $project->request('GET', '/openapi.json', 'local');
        [, $localDocs] = $project->request('GET', '/docs', 'local');
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [, $productionSpec] = $project->request('GET', '/openapi.json', 'production', accept: 'application/json');

        // Assert
        self::assertSame([0, 0], [$installCode, $openApiCode], $installOut . $installErr . $openApiOut . $openApiErr);
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $writeCode, $writeOut . $writeErr);
        $written = json_decode((string) file_get_contents($dir . '/docs/api.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($written);
        self::assertSame('Add a product', self::dig($written, 'paths', '/api/products', 'post', 'summary'));
        self::assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 40], self::dig($written, 'components', 'schemas', 'AddProduct', 'properties', 'name'));
        $served = json_decode($localSpec, true);
        self::assertIsArray($served, $localSpec . $localErr);
        self::assertSame(['$ref' => '#/components/schemas/AddProductResponse'], self::dig($served, 'paths', '/api/products', 'post', 'responses', '201', 'content', 'application/json', 'schema'));
        self::assertStringContainsString('swagger-ui-bundle.js', $localDocs);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertFileExists($dir . '/build/openapi.json');
        self::assertStringContainsString('"code":"NOT_FOUND"', $productionSpec, 'production does not serve the document unless openapi.serve is on');
    }

    public function test_jwt_installs_php_jwt_and_accepts_a_providers_token_in_the_production_build(): void
    {
        // Arrange: a stand-in identity provider publishing its key set on this machine
        $jwks = sys_get_temp_dir() . '/trunk-e2e-jwks-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($jwks, JwtKit::jwks(['k1' => JwtKit::shared()->rsa]));
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $provider = proc_open([\PHP_BINARY, '-S', $address, \dirname(__DIR__) . '/Support/jwks_server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['TRUNK_JWKS_FILE' => $jwks]);
        self::assertIsResource($provider);
        $issuer = 'http://' . $address . '/';

        $project = $this->project = new ScaffoldedProject('partners', 'api', realInstall: true);
        $dir = $project->directory;
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'jwt']);
        file_put_contents($dir . '/.env', preg_replace(['/^JWT_ISSUER=.*$/m', '/^JWT_AUDIENCE=.*$/m'], ['JWT_ISSUER=' . $issuer, 'JWT_AUDIENCE=partners-api'], (string) file_get_contents($dir . '/.env')) ?? '');
        file_put_contents($dir . '/config/jwt.php', str_replace("'match' => 'id'", "'match' => 'none'", (string) file_get_contents($dir . '/config/jwt.php')));
        file_put_contents($dir . '/app/Controllers/PartnerController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Auth\\Auth;\nuse Trunk\\Http\\Response\\ResponseBuilder;\n\nfinal readonly class PartnerController\n{\n    public function __construct(private Auth \$auth, private ResponseBuilder \$responses) {}\n\n    public function me(): ResponseInterface\n    {\n        return \$this->responses->json(['partner' => \$this->auth->claims()?->subject, 'canExport' => \$this->auth->tokenCan('export')]);\n    }\n}\n");
        $routes = (string) file_get_contents($dir . '/routes/api.php');
        file_put_contents($dir . '/routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->get('/partner', [\\App\\Controllers\\PartnerController::class, 'me'], middleware: [\\Trunk\\Auth\\Http\\RequireJwt::class]);\n        \$api->get('/customers', ", $routes));
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['iss' => $issuer, 'aud' => 'partners-api', 'sub' => 'acme', 'scope' => 'export'], time());

        try {
            // Act
            [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
            [, $accepted, $acceptedErr] = $project->request('GET', '/api/partner', 'production', authorization: 'Bearer ' . $token);
            [, $refused] = $project->request('GET', '/api/partner', 'production', accept: 'application/json', authorization: 'Bearer ' . JwtKit::sign(JwtKit::shared()->otherRsa, 'RS256', 'k1', ['iss' => $issuer, 'aud' => 'partners-api'], time()));
        } finally {
            proc_terminate($provider);
            proc_close($provider);
            @unlink($jwks);
        }

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertDirectoryExists($dir . '/vendor/firebase/php-jwt', 'package:install ran a real composer require');
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame('{"partner":"acme","canExport":true}', $accepted, $acceptedErr);
        self::assertStringContainsString('"code":"AUTHENTICATION_REQUIRED"', $refused);
    }

    public function test_the_api_token_page_installs_into_a_web_project_and_its_view_is_the_projects_own(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('portal', 'web', realInstall: true);
        $dir = $project->directory;

        // Act
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'api-tokens']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [, $body, $error] = $project->request('GET', '/account/tokens', 'production', accept: 'application/json');

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertStringContainsString('resources/views/account/tokens.tusk.php', $installOut);
        self::assertFileExists($dir . '/resources/views/account/tokens.tusk.php');
        self::assertFileExists($dir . '/config/api_tokens.php');
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertStringContainsString('"code":"AUTHENTICATION_REQUIRED"', $body, $error);
        self::assertStringNotContainsString('Your tokens', $body, 'a visitor who is not signed in never sees the page');
    }

    public function test_enabling_a_capability_installs_what_it_needs(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('grows', 'cli', realInstall: true);

        // Act
        [$code, $out, $err] = $project->trunk(['package:install', 'cache']);
        $composer = json_decode((string) file_get_contents($project->directory . '/composer.json'), true, 16, \JSON_THROW_ON_ERROR);
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);

        // Assert
        self::assertSame(0, $code, $out . $err);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        self::assertArrayHasKey('psr/simple-cache', $composer['require']);
        self::assertFileExists($project->directory . '/vendor/psr/simple-cache');
        self::assertSame(0, $doctorCode, $doctorOut);
    }

    /**
     * Starts the project on PHP's built-in server and posts one valid and one invalid signup as JSON.
     *
     * @return array{array{int, string}, array{int, string}}
     */
    private function serveAndPost(ScaffoldedProject $project, string $environment): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $name = (string) stream_socket_get_name($socket, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);
        $server = proc_open([\PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $project->directory . '/public', $project->directory . '/public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $project->directory, ['APP_ENV' => $environment, 'APP_DEBUG' => '0', 'PATH' => (string) getenv('PATH')]);
        self::assertIsResource($server);

        $post = static function (array $data) use ($port): array {
            for ($attempt = 0; $attempt < 60; ++$attempt) {
                $body = @file_get_contents('http://127.0.0.1:' . $port . '/api/signup', false, stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => json_encode($data, \JSON_THROW_ON_ERROR)]]));

                if ($body !== false) {
                    return [(int) substr((http_get_last_response_headers() ?? [])[0] ?? 'HTTP/1.1 0', 9, 3), $body];
                }

                usleep(50_000);
            }

            return [0, ''];
        };

        try {
            return [$post(['name' => 'Ada', 'email' => 'ada@example.com']), $post(['name' => 'Ada', 'email' => 'not-an-email'])];
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    private static function dig(mixed $data, string ...$path): mixed
    {
        foreach ($path as $key) {
            $data = \is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $data;
    }
}
