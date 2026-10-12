<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Tests;

use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\DatabaseManager\Exceptions\DatabaseException;
use EchoFusion\DatabaseManager\Exceptions\QueryException;
use EchoFusion\DatabaseManager\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Behaviour every ConnectionInterface implementation must have.
 * The same tests run against PDO and Doctrine: that is what makes them replaceable.
 */
abstract class ConnectionContractTestCase extends TestCase
{
    protected ConnectionInterface $db;

    abstract protected function createConnection(): ConnectionInterface;

    protected function setUp(): void
    {
        $this->db = $this->createConnection();
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER, active INTEGER NOT NULL DEFAULT 1, note TEXT)');
        $this->db->execute('INSERT INTO users (name, age, active) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)', ['Ann', 30, 1, 'Bob', 17, 1, 'Cy', 45, 0]);
    }

    public function testFetchAllReturnsAssociativeRows(): void
    {
        $rows = $this->db->fetchAll('SELECT name, age FROM users ORDER BY id');

        $this->assertSame([['name' => 'Ann', 'age' => 30], ['name' => 'Bob', 'age' => 17], ['name' => 'Cy', 'age' => 45]], $rows);
        $this->assertSame([], $this->db->fetchAll('SELECT * FROM users WHERE id > ?', [99]));
    }

    public function testFetchOneAndFetchValue(): void
    {
        $this->assertSame(['name' => 'Ann'], $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [1]));
        $this->assertNull($this->db->fetchOne('SELECT name FROM users WHERE id = ?', [99]));
        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertNull($this->db->fetchValue('SELECT name FROM users WHERE id = ?', [99]));
    }

    public function testNamedAndPositionalParameters(): void
    {
        $this->assertSame('Bob', $this->db->fetchValue('SELECT name FROM users WHERE age < :max', ['max' => 18]));
        $this->assertSame('Bob', $this->db->fetchValue('SELECT name FROM users WHERE age < :max', [':max' => 18]));
        $this->assertSame('Cy', $this->db->fetchValue('SELECT name FROM users WHERE age > ? AND active = ?', [40, 0]));
    }

    public function testBooleanAndNullParameters(): void
    {
        $this->db->execute('INSERT INTO users (name, active, note) VALUES (?, ?, ?)', ['Di', true, null]);

        $this->assertSame(['active' => 1, 'note' => null], $this->db->fetchOne('SELECT active, note FROM users WHERE name = ?', ['Di']));
    }

    public function testArrayParameterExpandsForIn(): void
    {
        $names = $this->db->fetchAll('SELECT name FROM users WHERE id IN (:ids) ORDER BY id', ['ids' => [1, 3]]);

        $this->assertSame([['name' => 'Ann'], ['name' => 'Cy']], $names);
        $this->assertSame([], $this->db->fetchAll('SELECT name FROM users WHERE id IN (:ids)', ['ids' => []]));
        $this->assertSame(1, $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE id IN (:ids) AND age > :age', ['ids' => [1, 2], 'age' => 20]));
    }

    public function testExecuteReturnsAffectedRowsAndLastInsertId(): void
    {
        $this->assertSame(2, $this->db->execute('UPDATE users SET active = 0 WHERE age < ?', [40]));
        $this->assertSame(1, $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Eve']));
        $this->assertSame('4', $this->db->lastInsertId());
    }

    public function testErrorsAreWrappedAndKeepTheSqlButNotTheValues(): void
    {
        try {
            $this->db->execute('INSERT INTO users (name) VALUES (?)', [null]);
            $this->fail('Expected a QueryException');
        } catch (QueryException $e) {
            $this->assertSame('INSERT INTO users (name) VALUES (?)', $e->getSql());
            $this->assertStringContainsString('SQL: INSERT INTO users', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function testSyntaxErrorsAreWrappedOnEveryReadMethod(): void
    {
        foreach (['fetchAll', 'fetchOne', 'fetchValue', 'execute'] as $method) {
            try {
                $this->db->$method('SELEC nonsense');
                $this->fail($method . ' should throw');
            } catch (QueryException $e) {
                $this->assertSame('SELEC nonsense', $e->getSql());
            }
        }
    }

    public function testTransactionalCommitsAndReturnsTheCallbackResult(): void
    {
        $result = $this->db->transactional(function (ConnectionInterface $db): string {
            $this->assertTrue($db->inTransaction());
            $db->execute('INSERT INTO users (name) VALUES (?)', ['Fay']);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(4, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testTransactionalRollsBackAndRethrows(): void
    {
        try {
            $this->db->transactional(function (ConnectionInterface $db): void {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Gus']);

                throw new RuntimeException('abort');
            });
            $this->fail('Expected the exception to be re-thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('abort', $e->getMessage());
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testNestedTransactionsRollBackOnlyTheInnerPart(): void
    {
        $this->db->transactional(function (ConnectionInterface $db): void {
            $db->execute('INSERT INTO users (name) VALUES (?)', ['outer']);

            try {
                $db->transactional(function (ConnectionInterface $db): void {
                    $db->execute('INSERT INTO users (name) VALUES (?)', ['inner']);

                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
                // the outer transaction carries on
            }

            $this->assertTrue($db->inTransaction());
        });

        $this->assertSame(['outer'], array_column($this->db->fetchAll("SELECT name FROM users WHERE name IN ('outer', 'inner')"), 'name'));
    }

    public function testManualTransactionControl(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['temp']);
        $this->db->rollBack();

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));

        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['kept']);
        $this->db->commit();

        $this->assertSame(4, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function testQueryBuilderWorksOnAnyConnection(): void
    {
        $names = QueryBuilder::table($this->db, 'users')
            ->select('name')
            ->where('active', 1)
            ->where('age', '>=', 18)
            ->orderBy('name', 'desc')
            ->get();

        $this->assertSame([['name' => 'Ann']], $names);

        $id = QueryBuilder::table($this->db, 'users')->insertGetId(['name' => 'Hal', 'age' => 50]);
        $this->assertSame(1, QueryBuilder::table($this->db, 'users')->where('id', (int) $id)->update(['age' => 51]));
        $this->assertSame(51, QueryBuilder::table($this->db, 'users')->where('name', 'Hal')->value('age'));
        $this->assertSame(1, QueryBuilder::table($this->db, 'users')->where('name', 'Hal')->delete());
        $this->assertSame(3, QueryBuilder::table($this->db, 'users')->count());
    }

    public function testRolledBackNestedLevelCannotBeCommittedTwice(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->commit();
    }
}
