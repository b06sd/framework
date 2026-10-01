<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Controllers;

use Psr\Http\Message\ResponseInterface;
use Trunk\Database\Connection\Connection;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Response\ResponseBuilder;

/**
 * Begins a transaction and forgets to commit it, the bug the kernel must not report as a success.
 */
final readonly class LeakyTransactionController
{
    public function __construct(private Connection $connection, private ResponseBuilder $responses) {}

    public function created(): ResponseInterface
    {
        $this->write();

        return $this->responses->json(['saved' => true], 201);
    }

    public function refused(): ResponseInterface
    {
        $this->write();

        throw new HttpException(422, 'Refused after starting to write.');
    }

    public function committed(): ResponseInterface
    {
        $this->connection->execute('CREATE TABLE IF NOT EXISTS notes (body TEXT)');
        $this->connection->transaction(fn() => $this->connection->table('notes')->insert(['body' => 'kept']));

        return $this->responses->json(['saved' => true], 201);
    }

    public function count(): ResponseInterface
    {
        $this->connection->execute('CREATE TABLE IF NOT EXISTS notes (body TEXT)');

        return $this->responses->json(['notes' => array_column($this->connection->select('SELECT body FROM notes ORDER BY rowid'), 'body'), 'open' => $this->connection->transactionDepth()]);
    }

    private function write(): void
    {
        $this->connection->execute('CREATE TABLE IF NOT EXISTS notes (body TEXT)');
        $this->connection->beginTransaction();
        $this->connection->table('notes')->insert(['body' => 'lost']);
    }
}
