<?php
/**
 * @author      Alexandre de Freitas Caetano <alexandrefc2@hotmail.com>
 */
namespace ScoutElastic\Model;

use ScoutElastic\Model\Indexable;
use Illuminate\Database\Eloquent\Model;
use Vkovic\LaravelCustomCasts\HasCustomCasts;
use ScoutElastic\Model\Casters\DateTime;

abstract class AbstractElasticSearchModel extends Model
{
    use Indexable;
    use HasCustomCasts;

    /**
     * @var array
     */
    protected $dataIndexers = [];

    /**
     * @var \ScoutElastic\IndexConfigurator
     */
    protected $indexConfigurator;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    public $casts = [];

    /**
     * @param  array  $attributes
     *
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        $this->casts = array_merge($this->casts, [
            'created_at' => DateTime::class,
            'date' => DateTime::class,
        ]);

        parent::__construct($attributes);

        $this->indexConfigurator = $this->configurator();
        $this->dataIndexers = $this->dataIndexers();
    }

    /**
     * Returns the class to use as an index configurator
     *
     * @return string
     */
    abstract protected function configurator() : string;

    /**
     * Returns an array of data indexers used to fill the index with data
     *
     * @return array
     */
    protected function dataIndexers() : array
    {
        return [];
    }
}
