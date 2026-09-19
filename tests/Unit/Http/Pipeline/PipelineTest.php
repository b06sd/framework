<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Pipeline;

use ArrayObject;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use Trunk\Container\ContainerBuilder;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Pipeline\Pipeline;

final class PipelineTest extends TestCase
{
    public function test_middleware_runs_in_order_around_the_final_handler(): void
    {
        // Arrange
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $pipeline = new Pipeline([$this->middleware($log, 'a'), $this->middleware($log, 'b')], new ContainerBuilder()->build(), $this->finalHandler($log));

        // Act
        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['in:a', 'in:b', 'handler', 'out:b', 'out:a'], $log->getArrayCopy());
    }

    public function test_string_entries_are_resolved_lazily_and_a_short_circuit_skips_the_rest(): void
    {
        // Arrange
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $builder = new ContainerBuilder();
        $builder->closure('stop', fn(): MiddlewareInterface => $this->middleware($log, 'stop', true));
        $builder->closure('never', static function () use ($log): never {
            $log->append('never-resolved');

            throw new LogicException('must not be resolved');
        });
        $pipeline = new Pipeline(['stop', 'never'], $builder->build(), $this->finalHandler($log));

        // Act
        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        // Assert
        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['in:stop', 'out:stop'], $log->getArrayCopy());
    }

    public function test_an_id_that_is_not_a_middleware_is_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance('oops', new stdClass());
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $pipeline = new Pipeline(['oops'], $builder->build(), $this->finalHandler($log));

        // Act & Assert
        $this->expectException(LogicException::class);
        $pipeline->handle(new ServerRequest('GET', '/'));
    }

    public function test_an_empty_pipeline_calls_the_final_handler(): void
    {
        // Arrange
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $container = new ContainerBuilder()->build();
        self::assertInstanceOf(ContainerInterface::class, $container);

        // Act
        new Pipeline([], $container, $this->finalHandler($log))->handle(new ServerRequest('GET', '/'));

        // Assert
        self::assertSame(['handler'], $log->getArrayCopy());
    }
    /**
     * @param ArrayObject<int, string> $log
     */
    private function middleware(ArrayObject $log, string $name, bool $shortCircuit = false): MiddlewareInterface
    {
        return new class ($log, $name, $shortCircuit) implements MiddlewareInterface {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(private readonly ArrayObject $log, private readonly string $name, private readonly bool $stop) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->log->append('in:' . $this->name);
                $response = $this->stop ? new Response(202) : $handler->handle($request);
                $this->log->append('out:' . $this->name);

                return $response;
            }
        };
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private function finalHandler(ArrayObject $log): RequestHandlerInterface
    {
        return new class ($log) implements RequestHandlerInterface {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(private readonly ArrayObject $log) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->log->append('handler');

                return new Response(200);
            }
        };
    }
}
