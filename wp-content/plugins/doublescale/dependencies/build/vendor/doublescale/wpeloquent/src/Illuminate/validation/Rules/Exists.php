<?php

namespace Illuminate\Validation\Rules;

class Exists
{
    use \Illuminate\Validation\Rules\DatabaseRule;
    /**
     * Convert the rule to a validation string.
     *
     * @return string
     */
    public function __toString()
    {
        return \rtrim(\sprintf('exists:%s,%s,%s', $this->table, $this->column, $this->formatWheres()), ',');
    }
}
