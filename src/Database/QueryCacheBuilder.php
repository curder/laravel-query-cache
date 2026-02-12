<?php

namespace Neurony\QueryCache\Database;

use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Str;

class QueryCacheBuilder extends QueryBuilder
{
    /**
     * The cache tag value.
     * The value comes from the Neurony\QueryCache\Traits\IsCacheable.
     *
     * @var string
     */
    protected $cacheTag;

    /**
     * The cache type value.
     * Can have one of the values present in the QueryCache class -> TYPE_CACHE constants.
     * The value comes from the Neurony\QueryCache\IsCacheable.
     *
     * @var string
     */
    protected $cacheType;

    /**
     * Create a new query builder instance.
     *
     * @param ConnectionInterface $connection
     * @param Grammar|null $grammar
     * @param Processor|null $processor
     * @param string|null $cacheTag
     * @param int|null $cacheType
     */
    public function __construct(
        ConnectionInterface $connection,
        ?Grammar $grammar = null,
        ?Processor $processor = null,
        ?string $cacheTag = null,
        ?int $cacheType = null
    ) {
        parent::__construct($connection, $grammar, $processor);

        $this->cacheType = $cacheType;
        $this->cacheTag = $cacheTag;
    }

    /**
     * Returns a unique string that can identify this query.
     *
     * @return string
     */
    public function getQueryCacheKey(): string
    {
        return json_encode([
            $this->toSql() => $this->getBindings(),
        ]);
    }

    /**
     * Build a versioned cache key for a specific store/prefix.
     */
    protected function getQueryCacheKeyForStore(string $store, string $prefix): string
    {
        return json_encode([
            'version' => $this->getCacheVersion($store, $prefix),
            $this->toSql() => $this->getBindings(),
        ]);
    }

    /**
     * Resolve the cache version key for the current table.
     */
    protected function getCacheVersionKey(string $prefix): string
    {
        $table = $this->cacheTag ? Str::afterLast($this->cacheTag, '.') : '';

        return 'query_cache_version:'.$prefix.':'.$table;
    }

    /**
     * Get the current cache version for a store/prefix pair.
     */
    protected function getCacheVersion(string $store, string $prefix): int
    {
        return (int) cache()->store($store)->get($this->getCacheVersionKey($prefix), 1);
    }

    /**
     * Bump the cache version to invalidate all existing keys for the table.
     */
    protected function bumpCacheVersion(string $store, string $prefix): void
    {
        $key = $this->getCacheVersionKey($prefix);

        try {
            cache()->store($store)->increment($key);
        } catch (Exception $e) {
            cache()->store($store)->put($key, time(), 0);
        }
    }

    /**
     * Flush the query cache based on the model's cache tag.
     *
     * @return void
     * @throws Exception
     */
    public function flushQueryCache(): void
    {
        $service = app('cache.query');

        $stores = [];

        if ($service->shouldCacheAllQueries()) {
            $stores[] = [
                'store' => $service->getAllQueryCacheStore(),
                'prefix' => $service->getAllQueryCachePrefix(),
            ];
        }

        if ($service->shouldCacheDuplicateQueries()) {
            $stores[] = [
                'store' => $service->getDuplicateQueryCacheStore(),
                'prefix' => $service->getDuplicateQueryCachePrefix(),
            ];
        }

        $stores = array_unique($stores, SORT_REGULAR);

        $table = $this->cacheTag ? Str::afterLast($this->cacheTag, '.') : '';
        $tags = [];

        if ($service->shouldCacheAllQueries()) {
            $tags[] = $service->getAllQueryCachePrefix().'.'.$table;
        }

        if ($service->shouldCacheDuplicateQueries()) {
            $tags[] = $service->getDuplicateQueryCachePrefix().'.'.$table;
        }

        $tags = array_unique(array_filter($tags));

        foreach ($stores as $storeConfig) {
            foreach ($tags as $tag) {
                cache()->store($storeConfig['store'])->tags($tag)->flush();
            }

            $this->bumpCacheVersion($storeConfig['store'], $storeConfig['prefix']);
        }
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $values
     * @return bool
     * @throws Exception
     */
    public function insert(array $values): bool
    {
        $this->flushQueryCache();

        return parent::insert($values);
    }

    /**
     * Update a record in the database.
     *
     * @param array $values
     * @return int
     * @throws Exception
     */
    public function update(array $values): int
    {
        $this->flushQueryCache();

        return parent::update($values);
    }

    /**
     * Delete a record from the database.
     *
     * @param int|null $id
     * @return int|null
     * @throws Exception
     */
    public function delete($id = null): ?int
    {
        $this->flushQueryCache();

        return parent::delete($id);
    }

    /**
     * Run a truncate statement on the table.
     *
     * @return void
     * @throws Exception
     */
    public function truncate(): void
    {
        $this->flushQueryCache();

        parent::truncate();
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     * @throws Exception
     */
    protected function runSelect(): array
    {
        switch ($this->cacheType) {
            case app('cache.query')->cacheAllQueriesForeverType():
                return $this->runSelectWithAllQueriesCached();
                break;
            case app('cache.query')->cacheOnlyDuplicateQueriesOnceType():
                return $this->runSelectWithDuplicateQueriesCached();
                break;
            default:
                return parent::runSelect();
                break;
        }
    }

    /**
     * Run the query as a "select" statement against the connection.
     * Also while fetching the results, cache all queries.
     *
     * @return mixed
     * @throws Exception
     */
    protected function runSelectWithAllQueriesCached()
    {
        $store = app('cache.query')->getAllQueryCacheStore();
        $prefix = app('cache.query')->getAllQueryCachePrefix();

        return cache()->store($store)
            ->tags($this->cacheTag)
            ->rememberForever($this->getQueryCacheKeyForStore($store, $prefix), function () {
                return parent::runSelect();
            });
    }

    /**
     * Run the query as a "select" statement against the connection.
     * Also while fetching the results, cache only duplicate queries for the current request.
     *
     * @return mixed
     * @throws Exception
     */
    protected function runSelectWithDuplicateQueriesCached()
    {
        $store = app('cache.query')->getDuplicateQueryCacheStore();
        $prefix = app('cache.query')->getDuplicateQueryCachePrefix();

        return cache()->store($store)
            ->tags($this->cacheTag)
            ->remember($this->getQueryCacheKeyForStore($store, $prefix), 1, function () {
                return parent::runSelect();
            });
    }
}
