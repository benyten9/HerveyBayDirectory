<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Events_DB {

	private static $instance = null;

	private $table_name = 'hbl_events';

	private $table;

	private $db_version = '1.4.0';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . $this->table_name;
		
		add_action( 'after_switch_theme', array( $this, 'create_table' ) );
		add_action( 'init', array( $this, 'maybe_create_table' ) );
	}

	public function get_table_name() {
		return $this->table;
	}

	public function maybe_create_table() {
		$installed_version = get_option( 'hbl_events_db_version', '0' );
		
		if ( version_compare( $installed_version, $this->db_version, '<' ) ) {
			$this->create_table();
			$this->upgrade_database( $installed_version );
			update_option( 'hbl_events_db_version', $this->db_version );
			
			flush_rewrite_rules();
		}
	}

	private function upgrade_database( $from_version ) {
		global $wpdb;
		
		if ( version_compare( $from_version, '1.1.0', '<' ) ) {
			$column_exists = $wpdb->get_results( 
				"SHOW COLUMNS FROM {$this->table} LIKE 'slug'" 
			);
			
			if ( empty( $column_exists ) ) {
				$wpdb->query( 
					"ALTER TABLE {$this->table} ADD COLUMN slug varchar(255) NOT NULL DEFAULT '' AFTER title" 
				);
				$wpdb->query( 
					"ALTER TABLE {$this->table} ADD UNIQUE KEY slug (slug)" 
				);
			}
			
			$events = $wpdb->get_results( 
				"SELECT id, title FROM {$this->table} WHERE slug = '' OR slug IS NULL" 
			);
			
			foreach ( $events as $event ) {
				$slug = $this->generate_slug( $event->title, $event->id );
				$wpdb->update(
					$this->table,
					array( 'slug' => $slug ),
					array( 'id' => $event->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		if ( version_compare( $from_version, '1.2.0', '<' ) ) {
			$columns_to_add = array(
				'recurrence_type'     => "varchar(50) DEFAULT NULL AFTER event_frequency",
				'recurrence_interval' => "int(11) DEFAULT 1 AFTER recurrence_type",
				'recurrence_days'     => "varchar(100) DEFAULT NULL AFTER recurrence_interval",
				'recurrence_week'     => "varchar(50) DEFAULT NULL AFTER recurrence_days",
			);

			foreach ( $columns_to_add as $column => $definition ) {
				$column_exists = $wpdb->get_results( 
					$wpdb->prepare( "SHOW COLUMNS FROM {$this->table} LIKE %s", $column )
				);
				
				if ( empty( $column_exists ) ) {
					$wpdb->query( 
						"ALTER TABLE {$this->table} ADD COLUMN {$column} {$definition}"
					);
				}
			}
		}

		if ( version_compare( $from_version, '1.3.0', '<' ) ) {
			$column_exists = $wpdb->get_results( 
				"SHOW COLUMNS FROM {$this->table} LIKE 'address'" 
			);
			
			if ( empty( $column_exists ) ) {
				$wpdb->query( 
					"ALTER TABLE {$this->table} ADD COLUMN address varchar(255) DEFAULT NULL AFTER venue" 
				);
			}
		}

		if ( version_compare( $from_version, '1.3.1', '<' ) ) {
			$columns_to_add = array(
				'scheduling_type'  => "varchar(20) DEFAULT 'single' AFTER event_cost",
				'daily_start_time' => "time DEFAULT NULL AFTER end_date",
				'daily_end_time'   => "time DEFAULT NULL AFTER daily_start_time",
			);
			
			foreach ( $columns_to_add as $column => $definition ) {
				$column_exists = $wpdb->get_results( 
					$wpdb->prepare( "SHOW COLUMNS FROM {$this->table} LIKE %s", $column )
				);
				
				if ( empty( $column_exists ) ) {
					$wpdb->query( 
						"ALTER TABLE {$this->table} ADD COLUMN {$column} {$definition}" 
					);
				}
			}
		}

		if ( version_compare( $from_version, '1.3.2', '<' ) ) {
			$columns_to_add = array(
				'scheduling_type'  => "varchar(20) DEFAULT 'single' AFTER event_cost",
				'daily_start_time' => "time DEFAULT NULL AFTER end_date",
				'daily_end_time'   => "time DEFAULT NULL AFTER daily_start_time",
			);

			foreach ( $columns_to_add as $column => $definition ) {
				$column_exists = $wpdb->get_results(
					$wpdb->prepare( "SHOW COLUMNS FROM {$this->table} LIKE %s", $column )
				);

				if ( empty( $column_exists ) ) {
					$wpdb->query(
						"ALTER TABLE {$this->table} ADD COLUMN {$column} {$definition}"
					);
				}
			}
		}

		if ( version_compare( $from_version, '1.4.0', '<' ) ) {
			$column_exists = $wpdb->get_results(
				$wpdb->prepare( "SHOW COLUMNS FROM {$this->table} LIKE %s", 'tags' )
			);

			if ( empty( $column_exists ) ) {
				$wpdb->query(
					"ALTER TABLE {$this->table} ADD COLUMN tags text DEFAULT NULL AFTER category_id"
				);
			}
		}
	}

	public function create_table() {
		global $wpdb;
		
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			description longtext,
			start_date datetime NOT NULL,
			end_date datetime DEFAULT NULL,
			daily_start_time time DEFAULT NULL,
			daily_end_time time DEFAULT NULL,
			is_allday tinyint(1) NOT NULL DEFAULT 0,
			venue varchar(255) DEFAULT NULL,
			address varchar(255) DEFAULT NULL,
			event_url varchar(500) DEFAULT NULL,
			contact_email varchar(100) DEFAULT NULL,
			event_type varchar(50) DEFAULT NULL,
			event_cost varchar(20) DEFAULT 'free',
			scheduling_type varchar(20) DEFAULT 'single',
			event_frequency varchar(50) DEFAULT NULL,
			recurrence_type varchar(50) DEFAULT NULL,
			recurrence_interval int(11) DEFAULT 1,
			recurrence_days varchar(100) DEFAULT NULL,
			recurrence_week varchar(50) DEFAULT NULL,
			is_program tinyint(1) NOT NULL DEFAULT 0,
			organiser_type varchar(50) DEFAULT NULL,
			category_id bigint(20) UNSIGNED DEFAULT NULL,
			tags text DEFAULT NULL,
			featured_image bigint(20) UNSIGNED DEFAULT NULL,
			event_color varchar(10) DEFAULT '#008080',
			internal_tags text DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY user_id (user_id),
			KEY start_date (start_date),
			KEY status (status),
			KEY event_type (event_type),
			KEY event_cost (event_cost),
			KEY organiser_type (organiser_type),
			KEY category_id (category_id)
		) $charset_collate;";
		
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function generate_slug( $title, $exclude_id = 0 ) {
		global $wpdb;
		
		$slug = sanitize_title( $title );
		$original_slug = $slug;
		$counter = 1;
		
		while ( true ) {
			$sql = $wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE slug = %s AND id != %d LIMIT 1",
				$slug,
				$exclude_id
			);
			
			$exists = $wpdb->get_var( $sql );
			
			if ( ! $exists ) {
				break;
			}
			
			$slug = $original_slug . '-' . $counter;
			$counter++;
		}
		
		return $slug;
	}

	public function get_by_slug( $slug ) {
		global $wpdb;
		
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s",
				$slug
			)
		);
		
		if ( $event && $event->internal_tags ) {
			$event->internal_tags = maybe_unserialize( $event->internal_tags );
		}
		
		return $event;
	}

	public function insert( $data ) {
		global $wpdb;
		
		$defaults = array(
			'user_id'             => get_current_user_id(),
			'title'               => '',
			'slug'                => '',
			'description'         => '',
			'start_date'          => current_time( 'mysql' ),
			'end_date'            => null,
			'daily_start_time'    => null,
			'daily_end_time'      => null,
			'is_allday'           => 0,
			'venue'               => '',
			'address'             => '',
			'event_url'           => '',
			'contact_email'       => '',
			'event_type'          => '',
			'event_cost'          => 'free',
			'scheduling_type'     => 'single',
			'event_frequency'     => '',
			'recurrence_type'     => null,
			'recurrence_interval' => 1,
			'recurrence_days'     => null,
			'recurrence_week'     => null,
			'is_program'          => 0,
			'organiser_type'      => '',
			'category_id'         => null,
			'featured_image'      => null,
			'event_color'         => '#008080',
			'internal_tags'       => '',
			'status'              => 'publish',
			'created_at'          => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);
		
		$data = wp_parse_args( $data, $defaults );
		
		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = $this->generate_slug( $data['title'] );
		}
		
		if ( is_array( $data['internal_tags'] ) ) {
			$data['internal_tags'] = maybe_serialize( $data['internal_tags'] );
		}

		if ( is_array( $data['recurrence_days'] ) ) {
			$data['recurrence_days'] = implode( ',', $data['recurrence_days'] );
		}

		if ( is_array( $data['recurrence_week'] ) ) {
			$data['recurrence_week'] = implode( ',', $data['recurrence_week'] );
		}
		
		$result = $wpdb->insert(
			$this->table,
			$data,
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%d',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
		
		if ( $result ) {
			return $wpdb->insert_id;
		}
		
		return false;
	}

	public function update( $id, $data ) {
		global $wpdb;
		
		if ( isset( $data['title'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = $this->generate_slug( $data['title'], $id );
		}
		
		if ( isset( $data['internal_tags'] ) && is_array( $data['internal_tags'] ) ) {
			$data['internal_tags'] = maybe_serialize( $data['internal_tags'] );
		}

		if ( isset( $data['recurrence_days'] ) && is_array( $data['recurrence_days'] ) ) {
			$data['recurrence_days'] = implode( ',', $data['recurrence_days'] );
		}

		if ( isset( $data['recurrence_week'] ) && is_array( $data['recurrence_week'] ) ) {
			$data['recurrence_week'] = implode( ',', $data['recurrence_week'] );
		}
		
		$data['updated_at'] = current_time( 'mysql' );
		
		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);
		
		return $result !== false;
	}

	public function get_event_url( $event ) {
		if ( is_numeric( $event ) ) {
			$event = $this->get( $event );
		}
		
		if ( ! $event || empty( $event->slug ) ) {
			return home_url( '/events/' );
		}
		
		return home_url( '/events/' . $event->slug . '/' );
	}

	public function delete( $id ) {
		global $wpdb;
		
		$result = $wpdb->delete(
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);
		
		return $result !== false;
	}

	public function get( $id ) {
		global $wpdb;
		
		$event = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$id
			)
		);
		
		if ( $event && $event->internal_tags ) {
			$event->internal_tags = maybe_unserialize( $event->internal_tags );
		}
		
		return $event;
	}

	public function get_events( $args = array() ) {
		global $wpdb;
		
		$defaults = array(
			'user_id'        => null,
			'status'         => null,
			'event_type'     => null,
			'event_cost'     => null,
			'event_frequency' => null,
			'organiser_type' => null,
			'category_id'    => null,
			'search'         => null,
			'upcoming'       => null,
			'orderby'        => 'start_date',
			'order'          => 'DESC',
			'limit'          => 20,
			'offset'         => 0,
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$where = array( '1=1' );
		$values = array();
		
		if ( $args['user_id'] !== null ) {
			$where[] = 'user_id = %d';
			$values[] = $args['user_id'];
		}
		
		if ( $args['status'] !== null ) {
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}
		
		if ( $args['event_type'] !== null ) {
			$where[] = 'event_type = %s';
			$values[] = $args['event_type'];
		}
		
		if ( $args['event_cost'] !== null ) {
			$where[] = 'event_cost = %s';
			$values[] = $args['event_cost'];
		}
		
		if ( $args['event_frequency'] !== null ) {
			$where[] = 'event_frequency = %s';
			$values[] = $args['event_frequency'];
		}
		
		if ( $args['organiser_type'] !== null ) {
			$where[] = 'organiser_type = %s';
			$values[] = $args['organiser_type'];
		}
		
		if ( $args['category_id'] !== null ) {
			$where[] = 'category_id = %d';
			$values[] = $args['category_id'];
		}
		
		if ( $args['upcoming'] === true ) {
			$where[] = 'start_date >= %s';
			$values[] = current_time( 'mysql' );
		} elseif ( $args['upcoming'] === false ) {
			$where[] = 'start_date < %s';
			$values[] = current_time( 'mysql' );
		}
		
		if ( $args['search'] !== null && ! empty( $args['search'] ) ) {
			$search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(title LIKE %s OR description LIKE %s OR venue LIKE %s)';
			$values[] = $search_term;
			$values[] = $search_term;
			$values[] = $search_term;
		}
		
		$where_clause = implode( ' AND ', $where );
		
		$allowed_orderby = array( 'id', 'title', 'start_date', 'end_date', 'created_at', 'updated_at', 'status' );
		$orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'start_date';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		
		$sql = "SELECT * FROM {$this->table} WHERE {$where_clause} ORDER BY {$orderby} {$order}";
		
		if ( $args['limit'] > 0 ) {
			$sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $args['limit'], $args['offset'] );
		}
		
		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}
		
		$events = $wpdb->get_results( $sql );
		
		foreach ( $events as &$event ) {
			if ( $event->internal_tags ) {
				$event->internal_tags = maybe_unserialize( $event->internal_tags );
			}
		}
		
		return $events;
	}

	public function count_by_category( $status = null ) {
		global $wpdb;

		$where  = 'category_id IS NOT NULL';
		$values = array();

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$values[] = $status;
		}

		$sql = "SELECT category_id, COUNT(*) as count FROM {$this->table} WHERE {$where} GROUP BY category_id";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}

		$results = $wpdb->get_results( $sql );

		$counts = array();
		foreach ( $results as $row ) {
			$counts[ (int) $row->category_id ] = (int) $row->count;
		}

		return $counts;
	}

	public function count_events( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'user_id'        => null,
			'status'         => null,
			'event_type'     => null,
			'event_cost'     => null,
			'event_frequency' => null,
			'organiser_type' => null,
			'category_id'    => null,
			'upcoming'       => null,
		);
		
		$args = wp_parse_args( $args, $defaults );
		
		$where = array( '1=1' );
		$values = array();
		
		if ( $args['user_id'] !== null ) {
			$where[] = 'user_id = %d';
			$values[] = $args['user_id'];
		}
		
		if ( $args['status'] !== null ) {
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}
		
		if ( $args['event_type'] !== null ) {
			$where[] = 'event_type = %s';
			$values[] = $args['event_type'];
		}
		
		if ( $args['event_cost'] !== null ) {
			$where[] = 'event_cost = %s';
			$values[] = $args['event_cost'];
		}
		
		if ( $args['event_frequency'] !== null ) {
			$where[] = 'event_frequency = %s';
			$values[] = $args['event_frequency'];
		}
		
		if ( $args['organiser_type'] !== null ) {
			$where[] = 'organiser_type = %s';
			$values[] = $args['organiser_type'];
		}
		
		if ( $args['category_id'] !== null ) {
			$where[] = 'category_id = %d';
			$values[] = $args['category_id'];
		}
		
		if ( $args['upcoming'] === true ) {
			$where[] = 'start_date >= %s';
			$values[] = current_time( 'mysql' );
		} elseif ( $args['upcoming'] === false ) {
			$where[] = 'start_date < %s';
			$values[] = current_time( 'mysql' );
		}
		
		$where_clause = implode( ' AND ', $where );
		
		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE {$where_clause}";
		
		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values );
		}
		
		return (int) $wpdb->get_var( $sql );
	}

	public function get_user_events( $user_id, $args = array() ) {
		$args['user_id'] = $user_id;
		return $this->get_events( $args );
	}

	public function get_upcoming_events( $args = array() ) {
		$args['upcoming'] = true;
		$args['status'] = 'publish';
		$args['orderby'] = 'start_date';
		$args['order'] = 'ASC';
		return $this->get_events( $args );
	}

	public function get_stats() {
		global $wpdb;
		
		$stats = array(
			'total'        => 0,
			'upcoming'     => 0,
			'past'         => 0,
			'free'         => 0,
			'paid'         => 0,
			'by_type'      => array(),
			'by_frequency' => array(),
			'by_organiser' => array(),
			'by_status'    => array(),
		);
		
		$stats['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		
		$now = current_time( 'mysql' );
		$stats['upcoming'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table} WHERE start_date >= %s",
			$now
		) );
		$stats['past'] = $stats['total'] - $stats['upcoming'];
		
		$stats['free'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE event_cost = 'free' OR event_cost = '' OR event_cost IS NULL"
		);
		$stats['paid'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE event_cost = 'paid'"
		);
		
		$types = $wpdb->get_results(
			"SELECT event_type, COUNT(*) as count FROM {$this->table} WHERE event_type != '' AND event_type IS NOT NULL GROUP BY event_type"
		);
		foreach ( $types as $type ) {
			$stats['by_type'][ $type->event_type ] = (int) $type->count;
		}
		
		$frequencies = $wpdb->get_results(
			"SELECT event_frequency, COUNT(*) as count FROM {$this->table} WHERE event_frequency != '' AND event_frequency IS NOT NULL GROUP BY event_frequency"
		);
		foreach ( $frequencies as $freq ) {
			$stats['by_frequency'][ $freq->event_frequency ] = (int) $freq->count;
		}
		
		$organisers = $wpdb->get_results(
			"SELECT organiser_type, COUNT(*) as count FROM {$this->table} WHERE organiser_type != '' AND organiser_type IS NOT NULL GROUP BY organiser_type"
		);
		foreach ( $organisers as $org ) {
			$stats['by_organiser'][ $org->organiser_type ] = (int) $org->count;
		}
		
		$statuses = $wpdb->get_results(
			"SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status"
		);
		foreach ( $statuses as $status ) {
			$stats['by_status'][ $status->status ] = (int) $status->count;
		}
		
		return $stats;
	}

	public function user_owns_event( $event_id, $user_id = null ) {
		if ( $user_id === null ) {
			$user_id = get_current_user_id();
		}
		
		$event = $this->get( $event_id );
		
		if ( ! $event ) {
			return false;
		}
		
		return (int) $event->user_id === (int) $user_id;
	}
}

HBL_Events_DB::get_instance();

function hbl_events_db() {
	return HBL_Events_DB::get_instance();
}

