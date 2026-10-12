<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Tests;

use EchoFusion\DatabaseManager\Connections\PdoConnection;
use EchoFusion\DatabaseManager\Exceptions\QueryBuilderException;
use EchoFusion\DatabaseManager\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private PdoConnection $db;

    protected function setUp(): void
    {
        $this->db = new PdoConnection(['driver' => 'sqlite']);
        $this->db->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, views INTEGER, author_id INTEGER, deleted_at TEXT)');
        $this->db->execute('INSERT INTO posts (title, views, author_id, deleted_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)', [
            'A', 10, 1, null,
            'B', 20, 1, '2024-01-01',
            'C', 30, 2, null,
            'D', 40, 3, null,
        ]);
    }

    private function posts(): QueryBuilder
    {
        return QueryBuilder::table($this->db, 'posts');
    }

    public function testGeneratedSqlAndBindings(): void
    {
        $query = $this->posts()
            ->select('id', 'title as name')
            ->where('views', '>', 5)
            ->orWhere('author_id', 2)
            ->whereIn('id', [1, 2])
            ->whereNotIn('id', [3])
            ->whereNull('deleted_at')
            ->whereNotNull('title')
            ->whereRaw('views % ? = 0', [10])
            ->orderBy('views', 'DESC')
            ->limit(5)
            ->offset(10);

        $this->assertSame(
            'SELECT "id", "title" AS "name" FROM "posts" WHERE "views" > ? OR "author_id" = ? AND "id" IN (?, ?) AND "id" NOT IN (?) AND "deleted_at" IS NULL AND "title" IS NOT NULL AND (views % ? = 0) ORDER BY "views" DESC LIMIT 5 OFFSET 10',
            $query->toSql(),
        );
        $this->assertSame([5, 2, 1, 2, 3, 10], $query->getBindings());
    }

    public function testMysqlQuotesWithBackticksAndPlainConnectionsDoNotQuote(): void
    {
        $mysql = QueryBuilder::table(new PdoConnection(['driver' => 'mysql']), 'users');
        $plain = new QueryBuilder($this->db, 'users');

        $this->assertSame('SELECT `users`.`id`, `users`.* FROM `users`', $mysql->select('users.id', 'users.*')->toSql());
        $this->assertSame('SELECT * FROM users', $plain->toSql());
    }

    public function testReads(): void
    {
        $this->assertCount(4, $this->posts()->get());
        $this->assertSame('A', $this->posts()->orderBy('id')->first()['title']);
        $this->assertNull($this->posts()->where('id', 99)->first());
        $this->assertSame('D', $this->posts()->orderBy('views', 'desc')->value('title'));
        $this->assertNull($this->posts()->where('id', 99)->value('title'));
        $this->assertSame(4, $this->posts()->count());
        $this->assertSame(1, $this->posts()->where('views', '>', 30)->count());
        $this->assertSame(2, $this->posts()->orderBy('id')->limit(2)->offset(1)->count() === 0 ? 0 : 2);
        $this->assertTrue($this->posts()->where('title', 'A')->exists());
        $this->assertFalse($this->posts()->where('title', 'Z')->exists());
        $this->assertSame([['total' => 4]], $this->posts()->selectRaw('COUNT(*) AS total')->get());
    }

    public function testLimitAndOffsetWorkTogetherAndAlone(): void
    {
        $this->assertSame([['id' => 2], ['id' => 3]], $this->posts()->select('id')->orderBy('id')->limit(2)->offset(1)->get());
        $this->assertSame([['id' => 3], ['id' => 4]], $this->posts()->select('id')->orderBy('id')->offset(2)->get());
        $this->assertSame([], $this->posts()->limit(0)->get());
    }

    public function testNullHandling(): void
    {
        $this->assertSame(3, $this->posts()->where('deleted_at', null)->count());
        $this->assertSame(1, $this->posts()->where('deleted_at', '!=', null)->count());
        $this->assertSame(1, $this->posts()->where('deleted_at', '<>', null)->count());
    }

    public function testOperatorsAreCaseInsensitiveAndLikeWorks(): void
    {
        $this->assertSame(1, $this->posts()->where('title', 'LIKE', 'A%')->count());
        $this->assertSame(3, $this->posts()->where('title', 'not like', 'A%')->count());
    }

    public function testEmptyInListsMatchNothingOrEverything(): void
    {
        $this->assertSame(0, $this->posts()->whereIn('id', [])->count());
        $this->assertSame(4, $this->posts()->whereNotIn('id', [])->count());
    }

    public function testInsertUpdateDelete(): void
    {
        $this->assertSame(1, $this->posts()->insert(['title' => 'E', 'views' => 50]));
        $id = $this->posts()->insertGetId(['title' => 'F']);
        $this->assertSame('6', $id);

        $this->assertSame(2, $this->posts()->where('author_id', 1)->update(['views' => 0, 'title' => 'same']));
        $this->assertSame(2, $this->posts()->where('title', 'same')->count());

        $this->assertSame(1, $this->posts()->where('id', (int) $id)->delete());
        $this->assertSame(5, $this->posts()->count());
    }

    public function testValuesNeverBecomePartOfTheSql(): void
    {
        $evil = "x'; DROP TABLE posts; --";
        $query = $this->posts()->where('title', $evil);

        $this->assertStringNotContainsString('DROP', $query->toSql());
        $this->assertSame(0, $query->count());
        $this->assertSame(4, $this->posts()->count());
    }

    public function testUpdateAndDeleteRefuseToRunWithoutWhere(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('Refusing to update without a WHERE clause');

        $this->posts()->update(['views' => 0]);
    }

    public function testDeleteRefusesToRunWithoutWhere(): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage('Refusing to delete');

        $this->posts()->delete();
    }

    public function testWhereRawIsTheExplicitWayToTouchEveryRow(): void
    {
        $this->assertSame(4, $this->posts()->whereRaw('1 = 1')->update(['views' => 1]));
        $this->assertSame(4, $this->posts()->whereRaw('1 = 1')->delete());
    }

    /**
     * @dataProvider invalidUsage
     */
    public function testInvalidUsage(callable $call, string $message): void
    {
        $this->expectException(QueryBuilderException::class);
        $this->expectExceptionMessage($message);

        $call($this->posts());
    }

    /**
     * @return array<string, array{callable, string}>
     */
    public function invalidUsage(): array
    {
        return [
            'bad column' => [static fn (QueryBuilder $q) => $q->where('title; DROP TABLE x', 1), 'not a valid table or column name'],
            'bad select' => [static fn (QueryBuilder $q) => $q->select('a b'), 'not a valid table or column name'],
            'bad alias' => [static fn (QueryBuilder $q) => $q->select('a as b c'), 'not a valid table or column name'],
            'bad order column' => [static fn (QueryBuilder $q) => $q->orderBy('1; --'), 'not a valid'],
            'bad direction' => [static fn (QueryBuilder $q) => $q->orderBy('id', 'sideways'), 'direction must be'],
            'bad operator' => [static fn (QueryBuilder $q) => $q->where('id', 'OR 1=1 --', 5), 'Unsupported operator'],
            'null with <' => [static fn (QueryBuilder $q) => $q->where('id', '<', null), 'NULL can only be compared'],
            'negative limit' => [static fn (QueryBuilder $q) => $q->limit(-1), 'cannot be negative'],
            'negative offset' => [static fn (QueryBuilder $q) => $q->offset(-1), 'cannot be negative'],
            'empty insert' => [static fn (QueryBuilder $q) => $q->insert([]), 'nothing to insert'],
            'empty update' => [static fn (QueryBuilder $q) => $q->where('id', 1)->update([]), 'nothing to update'],
            'bad insert column' => [static fn (QueryBuilder $q) => $q->insert(['a)--' => 1]), 'not a valid'],
            'bad update column' => [static fn (QueryBuilder $q) => $q->where('id', 1)->update(['a=1,b' => 1]), 'not a valid'],
        ];
    }

    public function testBadTableNameIsRejectedOnConstruction(): void
    {
        $this->expectException(QueryBuilderException::class);

        QueryBuilder::table($this->db, 'posts; DROP TABLE posts');
    }

    public function testQualifiedNamesAreAllowed(): void
    {
        $this->assertSame('SELECT "posts"."id" FROM "main"."posts"', QueryBuilder::table($this->db, 'main.posts')->select('posts.id')->toSql());
    }
}
