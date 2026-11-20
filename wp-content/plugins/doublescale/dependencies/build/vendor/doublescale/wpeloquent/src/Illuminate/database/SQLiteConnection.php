<?php

namespace DoubleScale\Vendor\Illuminate\Database;

use DoubleScale\Vendor\Illuminate\Database\Schema\SQLiteBuilder;
use DoubleScale\Vendor\Illuminate\Database\Query\Processors\SQLiteProcessor;
use DoubleScale\Vendor\Doctrine\DBAL\Driver\PDOSqlite\Driver as DoctrineDriver;
use DoubleScale\Vendor\Illuminate\Database\Query\Grammars\SQLiteGrammar as QueryGrammar;
use DoubleScale\Vendor\Illuminate\Database\Schema\Grammars\SQLiteGrammar as SchemaGrammar;
class SQLiteConnection extends Connection
{
    /**
     * Create a new database connection instance.
     *
     * @param  \PDO|\Closure     $pdo
     * @param  string   $database
     * @param  string   $tablePrefix
     * @param  array    $config
     * @return void
     */
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
        $enableForeignKeyConstraints = $this->getForeignKeyConstraintsConfigurationValue();
        if ($enableForeignKeyConstraints === null) {
            return;
        }
        $enableForeignKeyConstraints ? $this->getSchemaBuilder()->enableForeignKeyConstraints() : $this->getSchemaBuilder()->disableForeignKeyConstraints();
    }
    /**
     * Get the default query grammar instance.
     *
     * @return \Illuminate\Database\Query\Grammars\SQLiteGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar());
    }
    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\SQLiteBuilder
     */
    public function getSchemaBuilder()
    {
        if (\is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new SQLiteBuilder($this);
    }
    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\SQLiteGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar());
    }
    /**
     * Get the default post processor instance.
     *
     * @return \Illuminate\Database\Query\Processors\SQLiteProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new SQLiteProcessor();
    }
    /**
     * Get the Doctrine DBAL driver.
     *
     * @return \Doctrine\DBAL\Driver\PDOSqlite\Driver
     */
    protected function getDoctrineDriver()
    {
        return new DoctrineDriver();
    }
    /**
     * Get the database connection foreign key constraints configuration option.
     *
     * @return bool|null
     */
    protected function getForeignKeyConstraintsConfigurationValue()
    {
        return $this->getConfig('foreign_key_constraints');
    }
}
