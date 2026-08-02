<?php

namespace Illuminate\Database\Query\Processors;

class SQLiteProcessor extends \Illuminate\Database\Query\Processors\Processor
{
    /**
     * Process the results of a column listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processColumnListing($results)
    {
        return \array_map(function ($result) {
            return ((object) $result)->name;
        }, $results);
    }
}
