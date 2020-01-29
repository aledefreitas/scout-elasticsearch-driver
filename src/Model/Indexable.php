<?php
/**
 * @author      Alexandre de Freitas Caetano <alexandrefc2@hotmail.com>
 */
namespace App\Model\ElasticSearch;

use ScoutElastic\Searchable;
use Illuminate\Support\Facades\App;
use ScoutElastic\Model\Contracts\DataIndexerInterface;
use Illuminate\Support\LazyCollection;
use \Exception;

trait Indexable
{
    use Searchable;

    /**
     * Runs all data indexers related to this model and sends it to elasticsearch
     *
     * @return void
     */
    public static function makeAllSearchable()
    {
        $self = new static;

        if (isset($self->dataIndexers)) {
            foreach ($self->dataIndexers as $indexer) {
                $dataIndexer = App::make($indexer);

                if (!$dataIndexer instanceof DataIndexerInterface) {
                    throw new Exception(sprintf(
                        'The data indexer \'%1\' must be of type %2',
                        get_class($dataIndexer),
                        DataIndexerInterface::class
                    ));
                }

                $data = $dataIndexer->indexAllData();

                $data->chunk(config('scout.chunk.searchable', 100))
                    ->each(function (LazyCollection $data) {
                        static::hydrate($data->toArray())
                            ->filter
                            ->shouldBeSearchable()
                            ->searchable();
                    });
            }
        }
    }
}
