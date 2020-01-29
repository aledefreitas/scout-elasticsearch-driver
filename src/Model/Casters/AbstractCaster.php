<?php
/**
 * @author      Alexandre de Freitas Caetano <alexandrefc2@hotmail.com>
 */

namespace ScoutElastic\Model\Casters;

use Vkovic\LaravelCustomCasts\CustomCastBase;

abstract class AbstractCaster extends CustomCastBase
{
    /**
     * Sets the attribute
     *
     * @param  mixed  $value
     *
     * @return mixed
     */
    public function setAttribute($value)
    {
        return $value;
    }
}
