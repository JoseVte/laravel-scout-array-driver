<?php

namespace Sti3bas\ScoutArray\Engines;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use Sti3bas\ScoutArray\ArrayStore;

class ArrayEngine extends Engine
{
    public ArrayStore $store;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected mixed $softDelete;

    public function __construct($store, $softDelete = false)
    {
        $this->store = $store;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     */
    public function update($models): void
    {
        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $models->each(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            $this->store->set($model->searchableAs(), $model->getScoutKey(), array_merge(
                $searchableData,
                $model->scoutMetadata()
            ));
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     */
    public function delete($models): void
    {
        $models->each(function ($model) {
            $this->store->forget($model->searchableAs(), $model->getScoutKey());
        });
    }

    /**
     * Perform the given search on the engine.
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, [
            'perPage' => $builder->limit,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  int  $perPage
     * @param  int  $page
     */
    public function paginate(Builder $builder, $perPage, $page): mixed
    {
        return $this->performSearch($builder, [
            'perPage' => $perPage,
            'page' => $page,
        ]);
    }

    /**
     * Perform the given search on the engine.
     */
    protected function performSearch(Builder $builder, array $options = []): array
    {
        $index = $builder->index ?: $builder->model->searchableAs();

        $matches = $this->store->find($index, function ($record) use ($builder) {
            $values = new RecursiveIteratorIterator(new RecursiveArrayIterator($record));

            return $this->matchesFilters($record, $builder->wheres) &&
                $this->matchesFilters($record, $builder->whereIns) &&
                $this->matchesFilters($record, data_get($builder, 'whereNotIns', []), true) &&
                ! empty(array_filter(iterator_to_array($values, false), function ($value) use ($builder) {
                    return ! $builder->query || stripos($value, $builder->query) !== false;
                }));
        }, true);

        $matches = Collection::make($matches);

        return [
            'hits' => (isset($options['perPage']) ? $matches->slice((($options['page'] ?? 1) - 1) * $options['perPage'], $options['perPage']) : $matches)->values()->all(),
            'total' => $matches->count(),
        ];
    }

    /**
     * Determine if the given record matches given filters.
     */
    private function matchesFilters(array $record, array $filters, bool $not = false): bool
    {
        if (empty($filters)) {
            return true;
        }

        $match = function ($record, $key, $value) {
            if (is_array($value)) {
                $needle = data_get($record, $key);

                if (is_array($needle)) {
                    return ! empty(array_intersect($needle, $value));
                }

                if ($needle instanceof Collection) {
                    return $needle->contains(function ($item) use ($value) {
                        return in_array($item, $value, true);
                    });
                }

                return in_array($needle, $value, true);
            }

            return data_get($record, $key) === $value;
        };

        $match = Collection::make($filters)->every(function ($value, $key) use ($match, $record) {
            $keyExploded = explode('.', $key);
            if (count($keyExploded) > 1) {
                if (data_get($record, $keyExploded[0]) instanceof Collection) {
                    return data_get($record, $keyExploded[0])->contains(function ($subRecord) use ($match, $keyExploded, $value) {
                        return $match($subRecord, $keyExploded[1], $value);
                    });
                }

                return $match($record, $keyExploded, $value);
            }

            return $match($record, $key, $value);
        });

        return $not ? ! $match : $match;
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     */
    public function mapIds($results): Collection
    {
        return Collection::make($results['hits'])->pluck('objectID')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  Model  $model
     */
    public function map(Builder $builder, $results, $model): Collection
    {
        if (count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = Collection::make($results['hits'])->pluck('objectID')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  mixed  $results
     * @param  Model  $model
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = Collection::make($results['hits'])->pluck('objectID')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
            $builder,
            $objectIds
        )->cursor()->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     */
    public function getTotalCount($results): int
    {
        return $results['total'];
    }

    /**
     * Flush all the model's records from the engine.
     *
     * @param  Model  $model
     */
    public function flush($model): void
    {
        $this->store->flush($model->searchableAs());
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     */
    public function createIndex($name, array $options = []): void
    {
        $this->store->createIndex($name);
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     */
    public function deleteIndex($name): void
    {
        $this->store->deleteIndex($name);
    }

    /**
     * Determine if the given model uses soft deletes.
     */
    protected function usesSoftDelete(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
