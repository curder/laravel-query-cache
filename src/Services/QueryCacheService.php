<?php

namespace Neurony\QueryCache\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Neurony\QueryCache\Contracts\QueryCacheServiceContract;
use Neurony\QueryCache\Helpers\RelationHelper;
use Neurony\QueryCache\Traits\IsCacheable;

class QueryCacheService implements QueryCacheServiceContract
{
    /**
     * The model the cache should run on.
     * The model should use the IsCacheable trait for the whole process to work.
     *
     * @var Model
     */
    protected Model $model;

    /**
     * Flag whether or not to cache queries forever.
     *
     * @var bool
     */
    protected bool $cacheAllQueries = true;

    /**
     * Flag whether or not to cache only duplicate queries for the current request.
     *
     * @var bool
     */
    protected bool $cacheDuplicateQueries = true;

    /**
     * The query cache types available.
     *
     * @const
     */
    const TYPE_CACHE_ALL_QUERIES_FOREVER = 1;
    const TYPE_CACHE_ONLY_DUPLICATE_QUERIES_ONCE = 2;

    /**
     * Get the cache store to be used when caching queries forever.
     *
     * @return string
     */
    public function getAllQueryCacheStore(): string
    {
        return config('query-cache.all.store', 'array');
    }

    /**
     * Get the cache store to be used when caching only duplicate queries.
     *
     * @return string
     */
    public function getDuplicateQueryCacheStore(): string
    {
        return config('query-cache.duplicate.store', 'array');
    }

    /**
     * Get the cache prefix to be appended to the specific cache tag for the model instance.
     * Used when caching queries forever.
     *
     * @return string
     */
    public function getAllQueryCachePrefix(): string
    {
        return config('query-cache.all.prefix', 'cache.all_query');
    }

    /**
     * Get the cache prefix to be appended to the specific cache tag for the model instance.
     * Used when caching only duplicate queries.
     *
     * @return string
     */
    public function getDuplicateQueryCachePrefix(): string
    {
        return config('query-cache.duplicate.prefix', 'cache.duplicate_query');
    }

    /**
     * Verify if forever query caching should run.
     *
     * @return bool
     */
    public function shouldCacheAllQueries(): bool
    {
        return $this->cacheAllQueries && config('query-cache.all.enabled', false) === true;
    }

    /**
     * Verify if caching of duplicate queries should run.
     *
     * @return bool
     */
    public function shouldCacheDuplicateQueries(): bool
    {
        return $this->cacheDuplicateQueries && config('query-cache.duplicate.enabled', false) === true;
    }

    /**
     * Get the "cache all queries forever" caching type.
     *
     * @return int
     */
    public function cacheAllQueriesForeverType(): int
    {
        return static::TYPE_CACHE_ALL_QUERIES_FOREVER;
    }

    /**
     * Get the "cache only duplicate queries once" caching type.
     *
     * @return int
     */
    public function cacheOnlyDuplicateQueriesOnceType(): int
    {
        return static::TYPE_CACHE_ONLY_DUPLICATE_QUERIES_ONCE;
    }

    /**
     * Enable caching of database queries for the current request.
     * This is generally useful when working with rolled back database migrations.
     *
     * @return void
     */
    public function enableQueryCache(): void
    {
        $this->cacheAllQueries = $this->cacheDuplicateQueries = true;
    }

    /**
     * Disable caching of database queries for the current request.
     * This is generally useful when working with rolled back database migrations.
     *
     * @return void
     */
    public function disableQueryCache(): void
    {
        $this->cacheAllQueries = $this->cacheDuplicateQueries = false;
    }

    /**
     * Verify if either forever query caching or duplicate query caching are enabled.
     *
     * @return bool
     */
    public function canCacheQueries(): bool
    {
        return $this->shouldCacheAllQueries() || $this->shouldCacheDuplicateQueries();
    }

    /**
     * Flush all the query cache for the specified store.
     * Please note that this does not happen only for one caching type, but for all.
     *
     * @throws Exception
     */
    public function flushQueryCache(): void
    {
        if (! self::canCacheQueries()) {
            return;
        }

        if (self::shouldCacheAllQueries()) {
            cache()->store(self::getAllQueryCacheStore())->flush();
        }

        if (self::shouldCacheDuplicateQueries()) {
            cache()->store(self::getDuplicateQueryCacheStore())->flush();
        }
    }

    /**
     * Flush the query cache from the store only for the tag corresponding to the model instance.
     * If something fails, flush all existing cache for the specified store.
     * This way, it's guaranteed that nothing will be left out of sync at the database level.
     *
     * @param Model $model
     * @return void
     * @throws Exception
     */
    public function clearQueryCache(Model $model): void
    {
        if (! ((self::shouldCacheAllQueries() || self::shouldCacheDuplicateQueries()) && self::canCacheQueries())) {
            return;
        }

        try {
            $this->model = $model;

            $stores = $this->getCacheStores();

            foreach ($stores as $store) {
                cache()->store($store['store'])->tags($store['tagResolver']($this->model))->flush();
            }

            foreach (RelationHelper::getModelRelations($this->model) as $relation => $attributes) {
                if (
                    ($related = $attributes['model'] ?? null) && $related instanceof Model &&
                    array_key_exists(IsCacheable::class, class_uses($related))
                ) {
                    foreach ($stores as $store) {
                        cache()->store($store['store'])->tags($store['tagResolver']($related))->flush();
                    }
                }
            }
        } catch (Exception $e) {
            self::flushQueryCache();
        }
    }

    /**
     * Build the list of cache stores and tag resolvers based on enabled caching modes.
     *
     * @return array<int, array<string, callable>>
     */
    protected function getCacheStores(): array
    {
        $stores = [];

        if (self::shouldCacheAllQueries()) {
            $stores[] = [
                'store' => self::getAllQueryCacheStore(),
                'tagResolver' => static fn (Model $model): string => method_exists($model, 'getQueryCacheTag')
                    ? $model->getQueryCacheTag()
                    : $model->getTable(),
            ];
        }

        if (self::shouldCacheDuplicateQueries()) {
            $stores[] = [
                'store' => self::getDuplicateQueryCacheStore(),
                'tagResolver' => static fn (Model $model): string => method_exists($model, 'getDuplicateQueryCacheTag')
                    ? $model->getDuplicateQueryCacheTag()
                    : (method_exists($model, 'getQueryCacheTag') ? $model->getQueryCacheTag() : $model->getTable()),
            ];
        }

        return $stores;
    }
}
