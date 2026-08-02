<?php

namespace WPEloquent\Eloquent;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Translation\Translator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Validation\Factory as ValidatorFactory;
/**
 * Model Class
 *
 * @package WeDevs\ERP\Framework
 */
abstract class Model extends Eloquent
{
    /**
     * Rules
     *
     * @var array
     */
    protected $rules = array();
    /**
     * Messages
     *
     * @var array
     */
    protected $messages = array();
    /**
     * @param array $attributes
     */
    public function __construct(array $attributes = array())
    {
        static::$resolver = new \WPEloquent\Eloquent\Resolver();
        parent::__construct($attributes);
    }
    /**
     * Get the database connection for the model.
     *
     * @return Database
     */
    public function getConnection()
    {
        return \WPEloquent\Eloquent\Database::instance();
    }
    /**
     * Overide parent method to make sure prefixing is correct.
     *
     * @return string
     */
    public function getTable()
    {
        global $wpdb;
        if (\strpos($this->table, $wpdb->prefix) === 0) {
            return $this->table;
        }
        return $wpdb->prefix . $this->table;
    }
    /**
     * Get a new query builder instance for the connection.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();
        return new \WPEloquent\Eloquent\Builder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }
    /**
     * Define a many-to-many relationship.
     *
     * @param  string $related
     * @param  string $table
     * @param  string $foreignPivotKey
     * @param  string $relatedPivotKey
     * @param  string $parentKey
     * @param  string $relatedKey
     * @param  string $relation
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null, $relation = null)
    {
        global $wpdb;
        if (\strpos($table, $wpdb->prefix) === 0) {
            return parent::belongsToMany($related, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relation);
        }
        return parent::belongsToMany($related, $wpdb->prefix . $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relation);
    }
    /**
     * Override the save method to add validation.
     *
     * @param array $options
     * @return bool
     * @throws \Exception
     */
    public function save(array $options = array())
    {
        if (empty($this->rules)) {
            return parent::save($options);
        }
        // Trim all the values
        $this->trim();
        $validator = $this->validator()->make($this->toArray(), $this->rules, $this->messages);
        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
        // Call the parent save method to perform the actual saving
        return parent::save($options);
    }
    /**
     * Trim all the values
     *
     * @return void
     */
    public function trim()
    {
        \array_walk_recursive($this->attributes, function (&$value) {
            if (\is_string($value)) {
                $value = \trim($value);
            }
        });
    }
    /**
     * Get the validator instance
     *
     * @return \Illuminate\Validation\Factory
     */
    public function validator()
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $validator = new ValidatorFactory($translator);
        return $validator;
    }
}
