<?php

namespace Illuminate\Validation;

use Illuminate\Support\Traits\Macroable;
use Illuminate\Contracts\Support\Arrayable;
class Rule
{
    use Macroable;
    /**
     * Get a dimensions constraint builder instance.
     *
     * @param  array  $constraints
     * @return \Illuminate\Validation\Rules\Dimensions
     */
    public static function dimensions(array $constraints = [])
    {
        return new \Illuminate\Validation\Rules\Dimensions($constraints);
    }
    /**
     * Get a exists constraint builder instance.
     *
     * @param  string  $table
     * @param  string  $column
     * @return \Illuminate\Validation\Rules\Exists
     */
    public static function exists($table, $column = 'NULL')
    {
        return new \Illuminate\Validation\Rules\Exists($table, $column);
    }
    /**
     * Get an in constraint builder instance.
     *
     * @param  \Illuminate\Contracts\Support\Arrayable|array|string  $values
     * @return \Illuminate\Validation\Rules\In
     */
    public static function in($values)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }
        return new \Illuminate\Validation\Rules\In(\is_array($values) ? $values : \func_get_args());
    }
    /**
     * Get a not_in constraint builder instance.
     *
     * @param  \Illuminate\Contracts\Support\Arrayable|array|string  $values
     * @return \Illuminate\Validation\Rules\NotIn
     */
    public static function notIn($values)
    {
        if ($values instanceof Arrayable) {
            $values = $values->toArray();
        }
        return new \Illuminate\Validation\Rules\NotIn(\is_array($values) ? $values : \func_get_args());
    }
    /**
     * Get a required_if constraint builder instance.
     *
     * @param  callable  $callback
     * @return \Illuminate\Validation\Rules\RequiredIf
     */
    public static function requiredIf($callback)
    {
        return new \Illuminate\Validation\Rules\RequiredIf($callback);
    }
    /**
     * Get a unique constraint builder instance.
     *
     * @param  string  $table
     * @param  string  $column
     * @return \Illuminate\Validation\Rules\Unique
     */
    public static function unique($table, $column = 'NULL')
    {
        return new \Illuminate\Validation\Rules\Unique($table, $column);
    }
}
