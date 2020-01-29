<?php
/**
 * @author      Alexandre de Freitas Caetano <alexandrefc2@hotmail.com>
 */

namespace ScoutElastic\Model\Casters;

use ScoutElastic\Model\Casters\AbstractCaster;

class DateTime extends AbstractCaster
{
    /**
     * Casts the attribute
     *
     * @param  mixed  $value
     *
     * @return null|int
     */
    public function castAttribute($value)
    {
        if (isset($value)) {
            return $value->getTimestamp();
        }

        return null;
    }
}
