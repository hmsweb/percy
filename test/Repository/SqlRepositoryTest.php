<?php

namespace Percy\Test\Repository;

use Aura\Sql\ExtendedPdoInterface;
use InvalidArgumentException;
use Percy\Entity\Collection;
use Percy\Test\Asset\EntityStub;
use Percy\Test\Asset\SqlRepositoryIncompleteMapStub;
use Percy\Test\Asset\SqlRepositoryNoMapStub;
use Percy\Test\Asset\SqlRepositoryPartialMapStub;
use Percy\Test\Asset\SqlRepositoryStub;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class SqlRepositoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var array
     */
    protected $queryToQuery = [
        [
            'query_string' => 'sort=some_table.some_field|desc&filter[]=some_field|=|value1&filter[]=another_field|<>|value2&limit=50&offset=0',
            'data_query'   => 'SELECT * FROM some_table WHERE some_field = :some_field_0 AND another_field <> :another_field_1 ORDER BY some_table.some_field DESC LIMIT 0,50',
            'count_query'  => 'SELECT *, COUNT(*) as total FROM some_table WHERE some_field = :some_field_0 AND another_field <> :another_field_1',
            'binds'        => ['some_field_0' => 'value1', 'another_field_1' => 'value2']
        ],
        [
            'query_string' => 'sort=some_table.some_field|desc&filter[]=some_field|=|null&filter[]=another_field|!=|null&limit=50&offset=0',
            'data_query'   => 'SELECT * FROM some_table WHERE some_field IS null AND another_field IS NOT null ORDER BY some_table.some_field DESC LIMIT 0,50',
            'count_query'  => 'SELECT *, COUNT(*) as total FROM some_table WHERE some_field IS null AND another_field IS NOT null',
            'binds'        => ['some_field_0' => 'null', 'another_field_1' => 'null']
        ],
        [
            'query_string' => 'search=some_field|term&minscore=1.0',
            'data_query'   => 'SELECT * FROM some_table WHERE MATCH (some_field) AGAINST (:match_bind IN BOOLEAN MODE) HAVING MATCH (some_field) AGAINST (:match_bind) > :score_bind ORDER BY MATCH (some_field) AGAINST (:match_bind) > :score_bind',
            'count_query'  => 'SELECT *, COUNT(*) as total FROM some_table WHERE MATCH (some_field) AGAINST (:match_bind IN BOOLEAN MODE) HAVING MATCH (some_field) AGAINST (:match_bind) > :score_bind',
            'binds'        => ['match_bind' => 'term', 'score_bind' => 1.0]
        ],
        [
            'query_string' => 'sort=rand',
            'data_query'   => 'SELECT * FROM some_table ORDER BY RAND()',
            'count_query'  => 'SELECT *, COUNT(*) as total FROM some_table',
            'binds'        => []
        ],
        [
            'query_string' => 'has=some_relationship',
            'data_query'   => 'SELECT * FROM some_table WHERE (SELECT COUNT(some_table.some_uuid) FROM some_table WHERE some_table.some_uuid = some_table.uuid) > 0',
            'count_query'  => 'SELECT *, COUNT(*) as total FROM some_table WHERE (SELECT COUNT(some_table.some_uuid) FROM some_table WHERE some_table.some_uuid = some_table.uuid) > 0',
            'binds'        => []
        ],
        [
            'query_string' => '',
            'data_query'   => 'SELECT * FROM some_table',
            'count_query'  => 'SELECT *, COUNT(*) as total FROM some_table',
            'binds'        => []
        ]
    ];

    /**
     * Asserts that the repository can build and execute a query by field.
     */
    public function testSqlRepoBuildsQueriesFromRequestAndReturnsCollection()
    {
        foreach ($this->queryToQuery as $query) {
            $this->getByRequestAssertions($query['query_string'], $query['data_query'], $query['count_query'], $query['binds']);
        }
    }

    /**
     * Checks assertions on a get by request.
     *
     * @param string $queryString
     * @param string $dataQuery
     * @param string $countQuery
     * @param array  $binds
     *
     * @return void
     */
    protected function getByRequestAssertions($queryString, $dataQuery, $countQuery, array $binds)
    {
        $uri     = $this->createMock(UriInterface::class);
        $request = $this->createMock(ServerRequestInterface::class);
        $dbal    = $this->createMock(ExtendedPdoInterface::class);

        $uri->expects($this->exactly(2))->method('getQuery')->will($this->returnValue($queryString));

        $request->expects($this->exactly(2))->method('getUri')->will($this->returnValue($uri));

        $dbal->expects($this->at(0))->method('fetchAll')->with($this->equalTo($dataQuery), $this->equalTo($binds))->will($this->returnValue([[], []]));

        $dbal->expects($this->at(1))->method('fetchOne')->with($this->equalTo($countQuery), $this->equalTo($binds))->will($this->returnValue(['total' => 10]));

        $collection = (new SqlRepositoryStub($dbal))->getFromRequest($request);

        $this->assertInstanceOf('Percy\Entity\Collection', $collection);
        $this->assertCount(2, $collection);
        $this->assertSame(10, $collection->getTotal());
    }

    /**
     * Asserts that the repository can build and execute a query by field.
     */
    public function testSqlRepoBuildsQueryFromFieldAndReturnsCollection()
    {
        $dbal    = $this->createMock(ExtendedPdoInterface::class);
        $request = $this->createMock(ServerRequestInterface::class);
        $uri     = $this->createMock(UriInterface::class);

        $dbal->expects($this->at(0))->method('quote')->with($this->equalTo('value1,value2'))->will($this->returnValue("'value1,value2'"));

        $dbal->expects($this->at(1))->method('fetchAll')->with(
            $this->equalTo("SELECT * FROM some_table WHERE some_table.field IN (:field) ORDER BY FIND_IN_SET(some_table.field, 'value1,value2')"),
            $this->equalTo(['field' => ['value1', 'value2']])
        )->will(
            $this->returnValue([[], []])
        );

        $dbal->expects($this->at(2))->method('fetchOne')->with(
            $this->equalTo('SELECT COUNT(*) as total FROM some_table WHERE some_table.field IN (:field)'),
            $this->equalTo(['field' => ['value1', 'value2']])
        )->will(
            $this->returnValue(['total' => 10])
        );

        $uri->expects($this->once())->method('getQuery')->will($this->returnValue(''));
        $request->expects($this->once())->method('getUri')->will($this->returnValue($uri));

        $collection = (new SqlRepositoryStub($dbal))->getByField('field', ['value1', 'value2'], $request);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame(10, $collection->getTotal());
    }

    /**
     * Asserts that the repository can build and execute a query by field with sort.
     */
    public function testSqlRepoBuildsQueryFromFieldWithSortAndReturnsCollection()
    {
        $dbal    = $this->createMock(ExtendedPdoInterface::class);
        $request = $this->createMock(ServerRequestInterface::class);
        $uri     = $this->createMock(UriInterface::class);

        $dbal->expects($this->at(0))->method('fetchAll')->with(
            $this->equalTo("SELECT * FROM some_table WHERE some_table.some_field IN (:some_field) ORDER BY some_table.some_field ASC"),
            $this->equalTo(['some_field' => ['value1', 'value2']])
        )->will(
            $this->returnValue([[], []])
        );

        $dbal->expects($this->at(1))->method('fetchOne')->with(
            $this->equalTo('SELECT COUNT(*) as total FROM some_table WHERE some_table.some_field IN (:some_field)'),
            $this->equalTo(['some_field' => ['value1', 'value2']])
        )->will(
            $this->returnValue(['total' => 10])
        );

        $uri->expects($this->once())->method('getQuery')->will($this->returnValue('sort=some_table.some_field|asc'));
        $request->expects($this->once())->method('getUri')->will($this->returnValue($uri));

        $collection = (new SqlRepositoryStub($dbal))->getByField('some_field', ['value1', 'value2'], $request);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertCount(2, $collection);
        $this->assertSame(10, $collection->getTotal());
    }

    /**
     * Asserts that an exception is thrown when trying to filter on non white listed fields.
     *
     * The idea here is that it covers all user input, values are not covered by this as they
     * are parameter bound.
     */
    public function testSqlRepositoryThrowsExceptionWithInvalidFields()
    {
        $exceptions = [];

        $queries = [
            'search=a_field|term&minscore=1.0',
            'filter=some_field|;drop table users;|something',
            'filter=a_field;drop table something;|=|something',
            'sort=some_table.;drop table something;',
            'sort=what.what,;drop table something;',
            'sort=;drop table something;',
            'sort=what.what'
        ];

        foreach ($queries as $query) {
            try {
                $uri     = $this->createMock(UriInterface::class);
                $request = $this->createMock(ServerRequestInterface::class);
                $dbal    = $this->createMock(ExtendedPdoInterface::class);

                $uri->expects($this->once())->method('getQuery')->will($this->returnValue($query));
                $request->expects($this->once())->method('getUri')->will($this->returnValue($uri));

                (new SqlRepositoryStub($dbal))->getFromRequest($request);
            } catch (InvalidArgumentException $e) {
                $exceptions[] = $e;
            }
        }

        // ensure an exception was thrown for every test case
        $this->assertCount(count($queries), $exceptions);

        // ensure correct instance exception
        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        }
    }

    /**
     * Asserts that nothing is done when attached relationships is invoked with no includes.
     */
    public function testAttachRelationshipsIsSkippedWhenNoIncludesRequested()
    {
        $collection = $this->createMock(Collection::class);
        $dbal       = $this->createMock(ExtendedPdoInterface::class);
        $repository = new SqlRepositoryStub($dbal);

        $this->assertNull($repository->attachRelationships($collection));
    }

    /**
     * Asserts that the attach relationships method builds correct query.
     */
    public function testAttachRelationshipsBuildsCorrectQuery()
    {
        $collection = $this->getMockBuilder(Collection::class)->setMethods(['current', 'getIterator', 'count'])->getMock();
        $dbal       = $this->createMock(ExtendedPdoInterface::class);
        $entity     = new EntityStub;
        $request    = $this->createMock(ServerRequestInterface::class);
        $uri        = $this->createMock(UriInterface::class);

        $entity['uuid'] = 'blah';

        $uri->expects($this->once())->method('getQuery')->will($this->returnValue('include=some_relationship;uuid|=|blah,another&sort=some_table.uuid|asc,another_table.uuid|asc'));
        $request->expects($this->once())->method('getUri')->will($this->returnValue($uri));

        $collection->expects($this->at(0))->method('count')->will($this->returnValue(1));
        $collection->expects($this->at(1))->method('getIterator')->will($this->returnSelf());
        $collection->expects($this->at(2))->method('current')->will($this->returnValue($entity));
        $collection->expects($this->at(3))->method('getIterator')->will($this->returnValue([$entity]));
        $collection->expects($this->at(4))->method('getIterator')->will($this->returnValue([$entity]));

        $dbal->expects($this->once())->method('fetchAll')->with(
            $this->equalTo("SELECT * FROM some_table LEFT JOIN another_table ON another_table.uuid = some_table.another_uuid WHERE some_table.some_uuid IN (:relationships) AND another_table.uuid = :uuid_0 ORDER BY another_table.uuid ASC"),
            $this->equalTo(['relationships' => ['blah'], 'uuid_0' => 'blah'])
        )->will(
            $this->returnValue([])
        );

        $repository = new SqlRepositoryStub($dbal);

        $repository->attachRelationships($collection, null, $request);
    }
}
