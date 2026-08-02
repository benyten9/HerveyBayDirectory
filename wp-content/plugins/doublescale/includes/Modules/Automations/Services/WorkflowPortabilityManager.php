<?php
/**
 * Class WorkflowPortabilityManager
 *
 * Exports a single automation (workflow) to a portable JSON envelope and
 * imports it back into a (possibly different) site. Export reuses the same
 * serializer that powers undo / redo ({@see VersionManager::export_snapshot()})
 * so the on-disk shape matches what the editor already knows how to restore.
 *
 * Cross-site portability problem: step settings reference local database IDs
 * (tag / list term IDs, template IDs) that are meaningless on another install.
 * Export therefore inflates those references into a name-based portable form,
 * and import remaps them back to local IDs (auto-creating tags / lists by name
 * via {@see \DoubleScale\Core\Abstracts\TaxonomyModel::getOrCreate()} and
 * recreating email / SMS templates through the existing step save-event).
 * References that cannot be resolved on the destination are surfaced as
 * warnings by the REST layer rather than failing the import.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Automations\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Contacts\Models\TagModel;
use DoubleScale\Modules\Contacts\Models\ListModel;
use DoubleScale\Modules\Tracking\Models\TrackingTemplateModel;
use WP_Error;

/**
 * WorkflowPortabilityManager class
 */
final class WorkflowPortabilityManager {

	/**
	 * Envelope marker key. Its presence (and truthiness) identifies a file as a
	 * DoubleScale workflow export.
	 *
	 * @var string
	 */
	const ENVELOPE_KEY = '_doublescale_workflow';

	/**
	 * Current export format version. Bumped when the envelope / workflow shape
	 * changes in a backward-incompatible way so import can refuse newer files.
	 *
	 * @var int
	 */
	const FORMAT_VERSION = 1;

	/**
	 * Transient bookkeeping keys the editor injects into automation / step
	 * settings (labels, warning flags, undo cursor). These are recomputed on
	 * every load, so they are stripped from exports and ignored on import.
	 *
	 * @var string[]
	 */
	private static $bookkeeping_keys = array(
		'_version_cursor',
		'_trigger_label',
		'_trigger_warning',
		'_trigger_warning_message',
		'_action_label',
		'_action_warning',
		'_action_warning_message',
		'_goal_label',
		'_goal_warning',
		'_goal_warning_message',
	);

	/**
	 * Action slug → channel type for steps that carry templates.
	 *
	 * @var array<string,string>
	 */
	private static $channel_actions = array(
		'send_email'    => 'email',
		'send_sms'      => 'sms',
		'send_whatsapp' => 'whatsapp',
	);

	/**
	 * Singleton instance.
	 *
	 * @var WorkflowPortabilityManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return WorkflowPortabilityManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Export a workflow to a portable envelope array.
	 *
	 * @since 1.0.0
	 *
	 * @param int $automation_id Automation ID.
	 *
	 * @return array|WP_Error The envelope, or WP_Error if the automation is missing.
	 */
	public function export( $automation_id ) {
		$snapshot = VersionManager::instance()->export_snapshot( $automation_id );

		if ( null === $snapshot ) {
			return new WP_Error(
				'workflow_not_found',
				__( 'Workflow not found.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		// Strip bookkeeping from the automation-level settings.
		if ( isset( $snapshot['automation']['settings'] ) && is_array( $snapshot['automation']['settings'] ) ) {
			$snapshot['automation']['settings'] = $this->strip_bookkeeping( $snapshot['automation']['settings'] );
		}

		// Make each step's settings portable.
		$steps = isset( $snapshot['steps'] ) && is_array( $snapshot['steps'] ) ? $snapshot['steps'] : array();
		foreach ( $steps as $index => $step ) {
			$settings                    = isset( $step['settings'] ) && is_array( $step['settings'] ) ? $step['settings'] : array();
			$settings                    = $this->strip_bookkeeping( $settings );
			$settings                    = $this->inflate_references_for_export( $step['action'] ?? '', $settings );
			$steps[ $index ]['settings'] = $settings;
		}
		$snapshot['steps'] = $steps;

		return array(
			self::ENVELOPE_KEY => true,
			'format_version'   => self::FORMAT_VERSION,
			'exported_from'    => get_site_url(),
			'exported_at'      => gmdate( 'c' ),
			'workflow'         => $snapshot,
		);
	}

	/**
	 * Import a workflow from a previously exported envelope.
	 *
	 * The created automation is forced to the `inactive` status so a workflow
	 * whose references only partially resolved never starts enrolling contacts
	 * before the user has reviewed it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Decoded export envelope.
	 *
	 * @return AutomationModel|WP_Error The created automation, or WP_Error on failure.
	 */
	public function import( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload[ self::ENVELOPE_KEY ] ) || ! isset( $payload['workflow'] ) || ! is_array( $payload['workflow'] ) ) {
			return new WP_Error(
				'invalid_workflow_file',
				__( 'This file is not a valid DoubleScale workflow export.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$format_version = isset( $payload['format_version'] ) ? (int) $payload['format_version'] : 0;
		if ( $format_version > self::FORMAT_VERSION ) {
			return new WP_Error(
				'unsupported_workflow_version',
				__( 'This workflow was exported by a newer version of DoubleScale. Please update the plugin and try again.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$workflow      = $payload['workflow'];
		$automation_in = isset( $workflow['automation'] ) && is_array( $workflow['automation'] ) ? $workflow['automation'] : array();

		if ( empty( $automation_in['trigger'] ) ) {
			return new WP_Error(
				'invalid_workflow_file',
				__( 'The workflow file is missing its trigger and cannot be imported.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$settings = isset( $automation_in['settings'] ) && is_array( $automation_in['settings'] ) ? $automation_in['settings'] : array();
		$settings = $this->strip_bookkeeping( $settings );

		$name = isset( $automation_in['name'] ) && '' !== $automation_in['name']
			? (string) $automation_in['name']
			: __( 'Imported workflow', 'doublescale' );

		$current_user_id = get_current_user_id();

		$automation = AutomationModel::create(
			array(
				'name'       => $name,
				'trigger'    => (string) $automation_in['trigger'],
				'status'     => 'inactive',
				'settings'   => $settings,
				'created_by' => $current_user_id ? $current_user_id : null,
			)
		);

		if ( ! $automation ) {
			return new WP_Error(
				'workflow_import_failed',
				__( 'Failed to create the imported workflow.', 'doublescale' ),
				array( 'status' => 500 )
			);
		}

		$steps = isset( $workflow['steps'] ) && is_array( $workflow['steps'] ) ? $workflow['steps'] : array();
		$this->import_steps( (int) $automation->id, $steps );

		return $automation;
	}

	/**
	 * Insert the step tree, minting fresh IDs and rewriting parent links.
	 *
	 * Steps are inserted parent-first (a step is only inserted once its parent
	 * has a new ID) and, within each sibling group, in ascending `order`. This
	 * keeps the model's `creating` guard from re-positioning the trailing
	 * `end_automation` step.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $automation_id New automation ID.
	 * @param array $steps         Exported step rows.
	 *
	 * @return void
	 */
	private function import_steps( $automation_id, array $steps ) {
		// Root parent (0) maps to itself; child ids are filled in as we insert.
		$id_map  = array( 0 => 0 );
		$pending = array_values( $steps );

		usort(
			$pending,
			static function ( $a, $b ) {
				return ( (int) ( $a['order'] ?? 0 ) ) <=> ( (int) ( $b['order'] ?? 0 ) );
			}
		);

		// Bounded loop: each pass must insert at least one step or we stop, so a
		// step whose parent never appears (corrupt file) is skipped, not looped.
		$max_passes = count( $pending ) + 1;
		for ( $pass = 0; $pass < $max_passes && ! empty( $pending ); $pass++ ) {
			$made_progress = false;

			foreach ( $pending as $key => $step ) {
				$old_parent_id = (int) ( $step['parent_id'] ?? 0 );
				if ( ! array_key_exists( $old_parent_id, $id_map ) ) {
					continue;
				}

				$new_step = $this->insert_step( $automation_id, $step, $id_map[ $old_parent_id ] );
				if ( $new_step ) {
					$id_map[ (int) ( $step['id'] ?? 0 ) ] = (int) $new_step->id;
				}

				unset( $pending[ $key ] );
				$made_progress = true;
			}

			if ( ! $made_progress ) {
				break;
			}
		}
	}

	/**
	 * Insert a single step with remapped references.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $automation_id New automation ID.
	 * @param array $step          Exported step row.
	 * @param int   $new_parent_id Already-remapped parent ID.
	 *
	 * @return AutomationStepModel|null
	 */
	private function insert_step( $automation_id, array $step, $new_parent_id ) {
		$settings = isset( $step['settings'] ) && is_array( $step['settings'] ) ? $step['settings'] : array();
		$settings = $this->strip_bookkeeping( $settings );
		$settings = $this->remap_references_for_import( $step['action'] ?? '', $settings );

		return AutomationStepModel::create(
			array(
				'automation_id' => $automation_id,
				'parent_id'     => (int) $new_parent_id,
				'action'        => isset( $step['action'] ) ? (string) $step['action'] : '',
				'type'          => isset( $step['type'] ) ? (string) $step['type'] : '',
				'condition'     => isset( $step['condition'] ) ? (string) $step['condition'] : '',
				'status'        => 'active',
				'settings'      => $settings,
				'order'         => (int) ( $step['order'] ?? 1 ),
			)
		);
	}

	/**
	 * Replace local IDs in a step's settings with portable, name-based refs.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action   Step action slug.
	 * @param array  $settings Step settings.
	 *
	 * @return array
	 */
	private function inflate_references_for_export( $action, array $settings ) {
		if ( 'add_tags' === $action && ! empty( $settings['tags'] ) && is_array( $settings['tags'] ) ) {
			$settings['tags'] = $this->ids_to_named_refs( TagModel::class, $settings['tags'] );
		}

		if ( 'add_lists' === $action && ! empty( $settings['lists'] ) && is_array( $settings['lists'] ) ) {
			$settings['lists'] = $this->ids_to_named_refs( ListModel::class, $settings['lists'] );
		}

		// Email / SMS: embed the raw template body (and subject for email) so the
		// destination can recreate the template, then drop the local template_ids.
		$channel = self::$channel_actions[ $action ] ?? null;
		if ( in_array( $channel, array( 'email', 'sms' ), true ) && ! empty( $settings['template_ids'] ) && is_array( $settings['template_ids'] ) ) {
			$template_id = (int) reset( $settings['template_ids'] );
			$template    = TrackingTemplateModel::find( $template_id );

			if ( $template ) {
				if ( ! isset( $settings['body'] ) && ! isset( $settings['email_body'] ) ) {
					$settings['body'] = (string) $template->body;
				}
				if ( 'email' === $channel ) {
					$subject = isset( $settings['subject'] )
						? (string) $settings['subject']
						: (string) $template->get_setting( 'subject', '' );

					// The destination only recreates an email template when the
					// subject is non-empty ({@see TemplateDataPreparer::prepare_from_settings()}).
					// A template whose subject was never stored in settings would
					// otherwise import with no template at all, silently breaking the
					// step — fall back to the template name so it always resolves.
					if ( '' === trim( $subject ) ) {
						$subject = (string) $template->name;
					}

					$settings['subject'] = $subject;
				}
			}

			unset( $settings['template_ids'] );
		}

		return $settings;
	}

	/**
	 * Resolve portable, name-based refs in a step's settings back to local IDs.
	 *
	 * Tags / lists are matched by name (created if absent). Stale template IDs
	 * are removed so the step save-event recreates email / SMS templates from
	 * the embedded body / subject.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action   Step action slug.
	 * @param array  $settings Step settings.
	 *
	 * @return array
	 */
	private function remap_references_for_import( $action, array $settings ) {
		if ( 'add_tags' === $action && ! empty( $settings['tags'] ) && is_array( $settings['tags'] ) ) {
			$settings['tags'] = $this->named_refs_to_ids( TagModel::class, $settings['tags'] );
		}

		if ( 'add_lists' === $action && ! empty( $settings['lists'] ) && is_array( $settings['lists'] ) ) {
			$settings['lists'] = $this->named_refs_to_ids( ListModel::class, $settings['lists'] );
		}

		$channel = self::$channel_actions[ $action ] ?? null;
		if ( in_array( $channel, array( 'email', 'sms' ), true ) ) {
			// Force recreation from the embedded raw fields on the destination.
			unset( $settings['template_ids'] );
		}

		return $settings;
	}

	/**
	 * Map taxonomy IDs to `{id, name}` pairs for export.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_class Taxonomy model class (TagModel / ListModel).
	 * @param array  $ids         Local taxonomy IDs.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function ids_to_named_refs( $model_class, array $ids ) {
		$refs = array();

		foreach ( $ids as $id ) {
			$term = $model_class::find( (int) $id );
			if ( $term ) {
				$refs[] = array(
					'id'   => (int) $term->id,
					'name' => (string) $term->name,
				);
			}
		}

		return $refs;
	}

	/**
	 * Map exported `{id, name}` pairs back to local taxonomy IDs by name.
	 *
	 * Accepts bare integers too, so a hand-edited or legacy file still imports.
	 *
	 * @since 1.0.0
	 *
	 * @param string $model_class Taxonomy model class (TagModel / ListModel).
	 * @param array  $refs        Exported references.
	 *
	 * @return int[]
	 */
	private function named_refs_to_ids( $model_class, array $refs ) {
		$ids = array();

		foreach ( $refs as $ref ) {
			if ( is_array( $ref ) && isset( $ref['name'] ) && '' !== $ref['name'] ) {
				$term = $model_class::getOrCreate( (string) $ref['name'] );
				if ( $term ) {
					$ids[] = (int) $term->id;
				}
			} elseif ( is_numeric( $ref ) ) {
				$ids[] = (int) $ref;
			}
		}

		return $ids;
	}

	/**
	 * Remove transient bookkeeping keys from a settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Settings array.
	 *
	 * @return array
	 */
	private function strip_bookkeeping( array $settings ) {
		foreach ( self::$bookkeeping_keys as $key ) {
			unset( $settings[ $key ] );
		}

		return $settings;
	}
}
