<?php

/**
 * Presto Player Video Completed Trigger
 * This trigger will be fired when a user completes a Presto Player video.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Prestoplayer;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use WP_User;

/**
 * Video Completed Trigger
 */
class VideoCompleted extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Video Completed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'prestoplayer_video_completed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a user completes a Presto Player video.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'video';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'prestoplayer';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'presto_player_progress', array( $this, 'handle_progress' ), 10, 3 );
	}

	/**
	 * Handle Progress
	 *
	 * @since 1.0.0
	 *
	 * @param int      $video_id   Video ID.
	 * @param int      $percent    Progress percentage (0-100).
	 * @param int|bool $visit_time Visit time.
	 *
	 * @return void
	 */
	public function handle_progress( $video_id, $percent, $visit_time ) {
		// Only trigger when video is completed (100%)
		if ( $percent < 100 ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		// Prevent duplicate triggers within 24 hours for the same user/video combination
		$transient_key = "doublescale_pp_completed_{$user_id}_{$video_id}";
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, DAY_IN_SECONDS );

		$user = get_user_by( 'ID', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$this->process(
			array(
				'email' => $user->user_email,
				'data'  => array(
					'video_id'   => $video_id,
					'user_id'    => $user_id,
					'percent'    => $percent,
					'visit_time' => $visit_time,
				),
			)
		);
	}

	/**
	 * Check if trigger should be processed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array            $args       Arguments.
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$video_ids = $automation->get_setting( 'video_ids', array() );

		// If no videos specified, process for all videos
		if ( empty( $video_ids ) ) {
			return true;
		}

		// Check if the completed video matches the filter
		$completed_video_id = $args['data']['video_id'] ?? null;
		return in_array( $completed_video_id, $video_ids, false );
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'video_ids' => array(
				'label'   => __( 'Target Videos', 'doublescale'),
				'type'    => 'multiselect',
				'options' => $this->get_video_options(),
				'help'    => __( 'Select videos to filter this trigger. Leave empty to trigger for all videos.', 'doublescale'),
			),
		);
	}

	/**
	 * Get Presto Player video options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_video_options() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'presto_player_videos';

		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return array();
		}

		$videos = $wpdb->get_results(
			"SELECT id, title FROM {$table_name} WHERE deleted_at IS NULL ORDER BY title ASC"
		);

		$options = array();
		if ( is_array( $videos ) ) {
			foreach ( $videos as $video ) {
				$options[ $video->id ] = $video->title ?: sprintf( __( 'Video #%d', 'doublescale'), $video->id );
			}
		}

		return $options;
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		);
	}
}
