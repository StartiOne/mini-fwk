<?php

use Mini\Entity\Query;
use Mini\Entity\RawValue;

class QueryTest extends PHPUnit_Framework_TestCase
{
    private $connectionManager;

    public function setUp()
    {
        require_once __TEST_DIRECTORY__ . '/FakeConnectionManager.php';
        require_once __TEST_DIRECTORY__ . '/stubs/EntityStub.php';
        require_once __TEST_DIRECTORY__ . '/stubs/SimpleEntityStub.php';
        require_once __TEST_DIRECTORY__ . '/stubs/RelationEntityStub.php';

        $this->connectionManager = new FakeConnectionManager;

        app()->register('Mini\Entity\ConnectionManager', function () {
            return $this->connectionManager;
        });
    }

    public function testIsMakingSimpleSql()
    {
        $this->assertEquals(
            'SELECT * FROM users',
            (new Query)
                ->table('users')
                ->makeSql()
        );
    }

    public function testIsMakingSelectSql()
    {
        $this->assertEquals(
            'SELECT name, age, deleted_at FROM users',
            (new Query)
                ->table('users')
                ->select(
                    ['name', 'age']
                )
                ->addSelect(
                    ['deleted_at']
                )
                ->makeSql()
        );
    }

    public function testIsMakingLeftJoinSql()
    {
        $this->assertEquals(
            'SELECT name FROM users LEFT JOIN user_types ON (user_types.id = users.id)',
            (new Query)
                ->table('users')
                ->select(['name'])
                ->leftJoin('user_types', 'user_types.id', '=', 'users.id')
                ->makeSql()
        );
    }

    public function testIsMakingInnerJoinSql()
    {
        $this->assertEquals(
            'SELECT name FROM users INNER JOIN user_types ON (user_types.id = users.id)',
            (new Query)
                ->table('users')
                ->select(['name'])
                ->innerJoin('user_types', 'user_types.id', '=', 'users.id')
                ->makeSql()
        );
    }

    public function testIsMakingWhereSql()
    {
        $this->assertEquals(
            'SELECT * FROM users WHERE name = :p0',
            (new Query)
                ->table('users')
                ->where('name', '=', 'Lala')
                ->makeSql()
        );
    }

    public function testIsMakingWhereWithOperatorSql()
    {
        $this->assertEquals(
            'SELECT * FROM users WHERE name = :p0 OR deleted_at < NOW()',
            (new Query)
                ->table('users')
                ->where('name', '=', 'Lala')
                ->where('deleted_at', '<', new RawValue('NOW()'), 'OR')
                ->makeSql()
        );
    }

    public function testIsMakingOrderBySql()
    {
        $this->assertEquals(
            'SELECT * FROM users ORDER BY name ASC',
            (new Query)
                ->table('users')
                ->orderBy('name', 'ASC')
                ->makeSql()
        );
    }

    public function testIsMakingLimitSql()
    {
        $this->assertEquals(
            'SELECT * FROM users LIMIT 0, 1000',
            (new Query)
                ->table('users')
                ->limit(0, 1000)
                ->makeSql()
        );
    }

    public function testIsMakingRequiredIncludeRelationSql()
    {
        $this->assertEquals(
            'SELECT posts.id, posts.name, posts.owner_id, owner.name as owner_name FROM posts INNER JOIN users owner ON (posts.owner_id = owner.id)',
            RelationEntityStub::query()
                ->include('owner')
                ->makeSql()
        );
    }

    public function testIsMakingNotRequiredIncludeRelationSql()
    {
        $this->assertEquals(
            'SELECT posts.id, posts.name, posts.owner_id, owner.name as owner_name FROM posts LEFT JOIN users owner ON (posts.owner_id = owner.id)',
            RelationEntityStub::query()
                ->include('owner', false)
                ->makeSql()
        );
    }

    public function testIsMakingFullSql()
    {
        $this->assertEquals(
            'SELECT name, age FROM users INNER JOIN a ON (a.id = users.id) LEFT JOIN b ON (b.id = users.id) WHERE x = :p0 AND y = NOW() OR z <= :p1 ORDER BY age ASC, name ASC LIMIT 0, 1000',
            (new Query)
                ->table('users')
                ->select(['name'])
                ->addSelect(['age'])
                ->innerJoin('a', 'a.id', '=', 'users.id')
                ->leftJoin('b', 'b.id', '=', 'users.id')
                ->where('x', '=', 'A')
                ->where('y', '=', new RawValue('NOW()'))
                ->where('z', '<=', 3, 'OR')
                ->limit(0, 1000)
                ->orderBy('age')
                ->orderBy('name', 'ASC')
                ->makeSql()
        );
    }

    public function testIsGettingObject()
    {
        $result = (new Query)
            ->connection('default')
            ->className(EntityStub::class)
            ->table('users')
            ->getObject();

        $this->assertEquals('hi', $result->lala);
        $this->assertTrue($result instanceof EntityStub);
    }

    public function testIsGettingArray()
    {
        $result = (new Query)
            ->connection('default')
            ->className(EntityStub::class)
            ->table('users')
            ->getArray();

        $this->assertEquals(['lala' => 'hi'], $result);
    }

    public function testIsListingObject()
    {
        $results = (new Query)
            ->connection('default')
            ->className(EntityStub::class)
            ->table('users')
            ->listObject();

        $this->assertEquals('hi', $results[0]->lala);
        $this->assertTrue($results[0] instanceof EntityStub);
    }

    public function testIsListingArray()
    {
        $results = (new Query)
            ->connection('default')
            ->className(EntityStub::class)
            ->table('users')
            ->listArray();

        $this->assertEquals(['lala' => 'hi'], $results[0]);
    }
}