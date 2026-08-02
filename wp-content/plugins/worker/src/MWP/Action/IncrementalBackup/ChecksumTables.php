<?php
/*
 * This file is part of the ManageWP Worker plugin.
 *
 * (c) ManageWP LLC <contact@managewp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class MWP_Action_IncrementalBackup_ChecksumTables extends MWP_Action_IncrementalBackup_Abstract
{

    public function execute(array $params = array(), MWP_Worker_Request $request)
    {
        // escapeName() validates and escapes each table name. Filter out any
        // names that fail validation (returns null) to avoid injecting nulls
        // into the query.
        $tables = array_filter(array_map(array($this, 'escapeName'), $params['query']));

        if (empty($tables)) {
            return $this->createResult(array('checksum' => array(), 'db' => $this->container->getWordPressContext()->getConstant('DB_NAME')));
        }

        $query  = implode(',', $tables);

        $wpdb     = $this->container->getWordPressContext()->getDb();
        $results  = $wpdb->get_results('CHECKSUM TABLE '.$query, ARRAY_A);
        $checksum = array();

        foreach ($results as $row) {
            $checksum[$row['Table']] = $row['Checksum'];
        }

        return $this->createResult(array('checksum' => $checksum, 'db' => $this->container->getWordPressContext()->getConstant('DB_NAME')));
    }

    public function escapeName($tableName)
    {
        // Validate that the table name contains only characters that are valid
        // in MySQL identifiers: letters, digits, underscores, and dollar signs.
        // Dots are intentionally excluded: wrapping "db.table" in a single pair
        // of backticks produces the literal identifier `db.table` rather than
        // the qualified `db`.`table` that MySQL expects. Callers always supply
        // unqualified table names so dot support is not needed.
        if (!preg_match('/^[a-zA-Z0-9_$]+$/', $tableName)) {
            return null;
        }

        // Double any backtick characters within the name as per the MySQL
        // standard for escaping identifier delimiters. This is defence-in-depth:
        // the regex above already rejects backticks, but explicit escaping
        // ensures safety if the validation rule is ever relaxed.
        return '`' . str_replace('`', '``', $tableName) . '`';
    }
}
