<?php

namespace SEOPressPro\Services\Table;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableStructure;
use SEOPressPro\Models\Table\TableColumn;
use SEOPressPro\Models\Table\Table;

class TableList {

	public function getTableSignificantKeywords() {
		$tableStructureImportantKeywords = new TableStructure(
			array(
				new TableColumn(
					'id',
					array(
						'type'       => 'bigint(20)',
						'primaryKey' => true,
					)
				),
				new TableColumn(
					'post_id',
					array(
						'type'    => 'bigint(20)',
						'index'   => true,
						'default' => 0,
					)
				),
				new TableColumn(
					'word',
					array(
						'type'    => 'varchar(100)',
						'index'   => true,
						'default' => '',
					)
				),
				new TableColumn(
					'count',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'tf',
					array(
						'type'    => 'float',
						'default' => 0.0,
					)
				),
			)
		);

		return new Table( 'seopress_significant_keywords', $tableStructureImportantKeywords, 1 );
	}

	public function getTableSEOIssues() {
		$tableStructure = new TableStructure(
			array(
				new TableColumn(
					'id',
					array(
						'type'       => 'bigint(20)',
						'primaryKey' => true,
					)
				),
				new TableColumn(
					'post_id',
					array(
						'type'    => 'bigint(20)',
						'index'   => true,
						'default' => 0,
					)
				),
				new TableColumn(
					'issue_name',
					array(
						'type'    => 'longtext',
						'default' => '',
					)
				),
				new TableColumn(
					'issue_desc',
					array(
						'type'    => 'longtext',
						'default' => '',
					)
				),
				new TableColumn(
					'issue_type',
					array(
						// Narrow enum-ish column kept short so it can be covered
						// by a composite index — TEXT can't be indexed without
						// a prefix length. Migration in
						// inc/admin/updater/site-audit-table-migrations.php
						// ALTERs existing installs from TEXT to VARCHAR(64).
						'type'    => 'varchar(64)',
						'default' => '',
					)
				),
				new TableColumn(
					'issue_priority',
					array(
						// Effectively an enum of 'high' | 'medium' | 'low' | 'good'.
						'type'    => 'varchar(16)',
						'default' => '',
					)
				),
				new TableColumn(
					'issue_ignore',
					array(
						'type'    => 'boolean',
						'default' => 0,
					)
				),
			)
		);

		return new Table( 'seopress_seo_issues', $tableStructure, 1 );
	}

	public function getTableSiteAuditHistory() {
		$tableStructure = new TableStructure(
			array(
				new TableColumn(
					'id',
					array(
						'type'       => 'bigint(20)',
						'primaryKey' => true,
					)
				),
				new TableColumn(
					'scan_date',
					array(
						'type'    => 'datetime',
						'index'   => true,
						'default' => 'CURRENT_TIMESTAMP',
					)
				),
				new TableColumn(
					'duration_seconds',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'total_crawled',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'total_issues',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'total_ignored',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'priority_high',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'priority_medium',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'priority_low',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'priority_good',
					array(
						'type'    => 'int(11)',
						'default' => 0,
					)
				),
				new TableColumn(
					'health_score',
					array(
						'type'    => 'tinyint(3)',
						'default' => 0,
					)
				),
			)
		);

		return new Table( 'seopress_site_audit_history', $tableStructure, 1 );
	}

	public function getTableAIConversations() {
		$tableStructure = new TableStructure(
			array(
				new TableColumn(
					'id',
					array(
						'type'       => 'bigint(20)',
						'primaryKey' => true,
					)
				),
				new TableColumn(
					'user_id',
					array(
						'type'    => 'bigint(20)',
						'index'   => true,
						'default' => 0,
					)
				),
				new TableColumn(
					'title',
					array(
						'type'    => 'varchar(255)',
						'default' => '',
					)
				),
				new TableColumn(
					'messages',
					array(
						'type'    => 'longtext',
						'default' => '',
					)
				),
				new TableColumn(
					'created_at',
					array(
						'type'    => 'datetime',
						'default' => '0000-00-00 00:00:00',
					)
				),
				new TableColumn(
					'updated_at',
					array(
						'type'    => 'datetime',
						'index'   => true,
						'default' => '0000-00-00 00:00:00',
					)
				),
			)
		);

		return new Table( 'seopress_ai_conversations', $tableStructure, 1 );
	}

	public function getTables() {
		return array(
			'seopress_significant_keywords' => $this->getTableSignificantKeywords(),
			'seopress_seo_issues'           => $this->getTableSEOIssues(),
			'seopress_site_audit_history'   => $this->getTableSiteAuditHistory(),
			'seopress_ai_conversations'     => $this->getTableAIConversations(),
		);
	}
}
