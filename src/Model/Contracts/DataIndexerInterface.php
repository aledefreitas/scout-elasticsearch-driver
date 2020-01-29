<?php
/**
 * @author      Alexandre de Freitas Caetano <alexandrefc2@hotmail.com>
 */
namespace ScoutElastic\Model\Contracts;

use Illuminate\Support\LazyCollection;

interface DataIndexerInterface
{
    public function indexAllData() : LazyCollection;
}
