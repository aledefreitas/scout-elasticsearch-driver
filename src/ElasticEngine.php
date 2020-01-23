<?php

namespace ScoutElastic;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use ScoutElastic\Builders\SearchBuilder;
use ScoutElastic\Facades\ElasticClient;
use ScoutElastic\Indexers\IndexerInterface;
use ScoutElastic\Payloads\TypePayload;
use stdClass;

class ElasticEngine extends Engine
{
    /**
     * The indexer interface.
     *
     * @var \ScoutElastic\Indexers\IndexerInterface
     */
    protected $indexer;

    /**
     * Should the mapping be updated.
     *
     * @var bool
     */
    protected $updateMapping;

    /**
     * The updated mappings.
     *
     * @var array
     */
    protected static $updatedMappings = [];

    /**
     * ElasticEngine constructor.
     *
     * @param \ScoutElastic\Indexers\IndexerInterface $indexer
     * @param bool $updateMapping
     * @return void
     */
    public function __construct(IndexerInterface $indexer, $updateMapping)
    {
        $this->indexer = $indexer;

        $this->updateMapping = $updateMapping;
    }

    /**
     * {@inheritdoc}
     */
    public function update($models)
    {
        if ($this->updateMapping) {
            $self = $this;

            $models->each(function ($model) use ($self) {
                $modelClass = get_class($model);

                if (in_array($modelClass, $self::$updatedMappings)) {
                    return true;
                }

                Artisan::call(
                    'elastic:update-mapping',
                    ['model' => $modelClass]
                );

                $self::$updatedMappings[] = $modelClass;
            });
        }

        $this
            ->indexer
            ->update($models);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($models)
    {
        $this->indexer->delete($models);
    }

    /**
     * Performs a delete by query request
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     *
     * @return array|mixed
     */
    protected function performDeleteByQuery(Builder $builder, array $options = [])
    {
        $results = [];

        $this->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function ($payload) use (&$results, $options) {
                $results = ElasticClient::deleteByQuery(array_merge($payload, $options));

                $results['_payload'] = $payload;
            });

        return $results;
    }

    /**
     * Delete by query
     *
     * @param \Laravel\Scout\Builder $builder
     *
     * @return array|mixed
     */
    public function deleteByQuery(Builder $builder, array $options = [])
    {
        return $this->performDeleteByQuery($builder, $options);
    }

    /**
     * Build the payload collection.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     * @return \Illuminate\Support\Collection
     */
    public function buildSearchQueryPayloadCollection(Builder $builder, array $options = [])
    {
        $payloadCollection = collect();

        if ($builder instanceof SearchBuilder) {
            $searchRules = $builder->rules ?: $builder->model->getSearchRules();

            foreach ($searchRules as $rule) {
                $payload = new TypePayload($builder->model);

                if (is_callable($rule)) {
                    $payload->setIfNotEmpty('body.query', call_user_func($rule, $builder));
                } else {
                    /** @var SearchRule $ruleEntity */
                    $ruleEntity = new $rule($builder);

                    if ($ruleEntity->isApplicable()) {
                        $payload->setIfNotEmpty('body.query', $ruleEntity->buildQueryPayload());

                        if ($options['highlight'] ?? true) {
                            $payload->setIfNotEmpty('body.highlight', $ruleEntity->buildHighlightPayload());
                        }
                    } else {
                        continue;
                    }
                }

                $payloadCollection->push($payload);
            }
        } else {
            $payload = (new TypePayload($builder->model))
                ->setIfNotEmpty('body.query.bool.must.match_all', new stdClass());

            $payloadCollection->push($payload);
        }

        return $payloadCollection->map(function (TypePayload $payload) use ($builder, $options) {
            $payload
                ->setIfNotEmpty('body._source', $builder->select)
                ->setIfNotEmpty('body.collapse.field', $builder->collapse)
                ->setIfNotEmpty('body.sort', $builder->orders)
                ->setIfNotEmpty('body.explain', $options['explain'] ?? null)
                ->setIfNotEmpty('body.profile', $options['profile'] ?? null)
                ->setIfNotNull('body.from', $builder->offset)
                ->setIfNotNull('body.size', $builder->limit);

            foreach ($builder->wheres as $clause => $filters) {
                $clauseKey = 'body.query.bool.filter.bool.'.$clause;

                $clauseValue = array_merge(
                    $payload->get($clauseKey, []),
                    $filters
                );

                $payload->setIfNotEmpty($clauseKey, $clauseValue);
            }

            return $payload->get();
        });
    }

    /**
     * Perform the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     * @return array|mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                ElasticClient::getFacadeRoot(),
                $builder->query,
                $options
            );
        }

        $results = [];

        $this
            ->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function ($payload) use (&$results, $options) {
                $results = ElasticClient::search(array_merge($payload, $options));

                $results['_payload'] = $payload;

                if ($this->getTotalCount($results) > 0) {
                    return false;
                }
            });

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function search(Builder $builder, array $options = [])
    {
        return $this->performSearch($builder);
    }

    /**
     * {@inheritdoc}
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $builder
            ->from(($page - 1) * $perPage)
            ->take($perPage);

        return $this->performSearch($builder);
    }

    /**
     * Explain the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return array|mixed
     */
    public function explain(Builder $builder, array $options = [])
    {
        return $this->performSearch($builder, array_merge($options, [
            'explain' => true,
        ]));
    }

    /**
     * Profile the search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return array|mixed
     */
    public function profile(Builder $builder, array $options = [])
    {
        return $this->performSearch($builder, array_merge($options, [
            'profile' => true,
        ]));
    }

    /**
     * Return the number of documents found.
     *
     * @param \Laravel\Scout\Builder $builder
     * @return int
     */
    public function count(Builder $builder, array $options = [])
    {
        $count = 0;

        $this
            ->buildSearchQueryPayloadCollection($builder, array_merge($options, ['highlight' => false]))
            ->each(function ($payload) use (&$count, $options) {
                $result = ElasticClient::count(array_merge($payload, $options));

                $count = $result['count'];

                if ($count > 0) {
                    return false;
                }
            });

        return $count;
    }

    /**
     * Make a raw search.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array $query
     * @param  array  $options
     * @return mixed
     */
    public function searchRaw(Model $model, $query, array $options = [])
    {
        $payload = (new TypePayload($model))
            ->setIfNotEmpty('body', $query)
            ->get();

        return ElasticClient::search(array_merge($payload, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id');
    }

    /**
     * {@inheritdoc}
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($this->getTotalCount($results) === 0) {
            return Collection::make();
        }

        $scoutKeyName = $model->getScoutKeyName();

        $columns = Arr::get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $scoutKeyName;
        }

        return Collection::make($results['hits']['hits'])
            ->map(function ($hit) {
                $model = $this->model->newInstance($hit);

                if (isset($hit['highlight'])) {
                    $model->highlight = new Highlight($hit['highlight']);
                }

                return $model;
            })
            ->filter()
            ->values();
    }

    /**
     * {@inheritdoc}
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total']['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function flush($model)
    {
        $query = $model::usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $query
            ->orderBy($model->getScoutKeyName())
            ->unsearchable();
    }
}
