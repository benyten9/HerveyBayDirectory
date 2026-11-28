<?php
/**
 * Scoped, reviewed native listing drafts and form suggestions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Owned native listing workflow boundary. */
final class ListingFormService {

	/**
	 * Bind the site runtime and native listing services.
	 *
	 * @param Database $db Site-scoped runtime database.
	 */
	public function __construct( private Database $db ) {}

	/**
	 * Installed schemas and bounded native typed controls remain authoritative.
	 *
	 * @param int  $directory_id Native directory identity.
	 * @param bool $manager Whether admin-only fields are available.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return array
	 */
	public static function fields( int $directory_id, bool $manager ): array {
		$result = array();
		foreach ( directorist_get_listing_form_fields( $directory_id ) as $key => $props ) {
			$widget = $props['widget_name'] ?? '';
			if ( ! ListingFieldValues::supported( $props ) || ( ! $manager && ! empty( $props['only_for_admin'] ) ) ) {
				continue;
			}
			$id = array(
				'title'       => 'title',
				'description' => 'description',
				'excerpt'     => 'excerpt',
				'category'    => 'categories',
				'tag'         => 'tags',
				'location'    => 'locations',
			)[ $widget ] ?? sanitize_key( $props['field_key'] ?? $key );
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $id ) || isset( $result[ $id ] ) ) {
				throw new ContractException( 'unsupported_contract', 'A listing field has an ambiguous identity.' );
			}
			try {
				ListingFieldValues::contract( $props, $directory_id );
			} catch ( ContractException $error ) {
				if ( 'unsupported_contract' !== $error->error_code() ) {
					throw $error; }
				continue;
			}
			$result[ $id ] = $props;
		}
		return $result;
	}

	/**
	 * Read-only native business facts; these never become AI write targets.
	 *
	 * @param int  $directory_id Native directory identity.
	 * @param bool $manager Whether admin-only fields are available.
	 * @return array
	 */
	public static function native_only_fields( int $directory_id, bool $manager ): array {
		$allowed = array_column( self::fields( $directory_id, $manager ), 'field_key' );
		$result  = array();
		foreach ( directorist_get_listing_form_fields( $directory_id ) as $key => $props ) {
			if ( in_array( $props['field_key'] ?? $key, $allowed, true ) || ( ! $manager && ! empty( $props['only_for_admin'] ) ) || ! empty( $props['private'] ) || ! empty( $props['exclude_from_ai'] ) || ! empty( $props['readonly'] ) || ! empty( $props['read_only'] ) || ! empty( $props['disabled'] ) ) {
				continue; }
			$result[] = (object) array(
				'label'  => mb_substr( wp_strip_all_tags( ( $props['label'] ?? '' ) ? $props['label'] : ucfirst( str_replace( '_', ' ', $key ) ) ), 0, 80 ),
				'widget' => $props['widget_name'] ?? '',
			);
			if ( count( $result ) >= 50 ) {
				break; }
		}
		return $result;
	}

	/**
	 * Read-only native facts outside the current supported write controls.
	 *
	 * @param int  $directory_id Native directory.
	 * @param bool $manager Whether admin-only fields are readable.
	 * @return array
	 */
	public static function fact_fields( int $directory_id, bool $manager ): array {
		$result   = array();
		$editable = array_column( self::fields( $directory_id, $manager ), 'field_key' );
		foreach ( directorist_get_listing_form_fields( $directory_id ) as $key => $props ) {
			if ( ! in_array( $props['field_key'] ?? $key, $editable, true ) && in_array( $props['widget_name'] ?? '', array( 'email', 'phone', 'website', 'address', 'number', 'url', 'video', 'select', 'radio', 'checkbox', 'category', 'tag', 'location', 'date', 'time', 'switch', 'color' ), true ) && ( $manager || empty( $props['only_for_admin'] ) ) ) {
				$identity            = in_array( $props['widget_name'] ?? '', array( 'category', 'tag', 'location' ), true ) ? $props['widget_name'] : ( $props['field_key'] ?? $key );
				$result[ $identity ] = $props;
			}
		}
		return $result;
	}

	/**
	 * Validate and sanitize prose with the native Directorist field.
	 *
	 * @param array        $props Installed native field schema.
	 * @param string       $value Untrusted requested field value.
	 * @param int          $directory Bound native directory.
	 * @param Context|null $context Current assignment authority.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return string
	 */
	public static function sanitize_value( array $props, string $value, int $directory = 0, ?Context $context = null ): string {
		return ListingFieldValues::sanitize( $props, $value, $directory, $context );
	}

	/**
	 * Fingerprint the current native directory form schema.
	 *
	 * @param int $directory_id Native directory identity.
	 * @return string
	 */
	public static function schema_hash( int $directory_id ): string {
		return hash( 'sha256', wp_json_encode( directorist_get_listing_form_fields( $directory_id ) ) );
	}

	/**
	 * Fingerprint saved listing content, metadata, status and taxonomy.
	 *
	 * @param int $id Native listing or operation identity.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return string
	 */
	public static function source_hash( int $id ): string {
		if ( ! $id ) {
			return hash( 'sha256', 'new-listing' );
		}
		$post = get_post( $id );
		if ( ! $post || ATBDP_POST_TYPE !== $post->post_type ) {
			throw new ContractException( 'unauthorized', 'The listing is unavailable.' );
		}
		$meta = get_post_meta( $id );
		// Native view tracking does not change the captured editable listing.
		// Keep all business fields, taxonomy, media and authority facts versioned.
		unset( $meta['_edit_lock'], $meta['_edit_last'], $meta['_atbdp_post_views_count'] );
		ksort( $meta );
		$terms = wp_get_object_terms( $id, array( ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ), array( 'fields' => 'tt_ids' ) );
		if ( is_wp_error( $terms ) ) {
			throw new ContractException( 'evidence_stale', 'Listing terms are unavailable.' );
		}
		sort( $terms );
		return hash( 'sha256', serialize( array( $post->post_title, $post->post_content, $post->post_excerpt, $post->post_status, $post->post_author, $post->post_modified_gmt, $meta, $terms ) ) );
	}

	/**
	 * Bind browser values as untrusted drafting facts, never permissions.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param object  $input Validated native form or tool input.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public function bind( Context $context, object $input ): object {
		$directory = $input->directory_id;
		$id        = $input->listing_id;
		$this->authority( $context, $directory, $id );
		$fields = self::fields( $directory, $context->actor()->can( 'manage_options' ) );
		if ( isset( $input->term_field ) ) {
			if ( ! is_string( $input->term_field ) || ! in_array( $input->term_field, array( 'categories', 'tags' ), true ) || 'edit_terms' !== $input->mode || array( $input->term_field ) !== $input->field_ids || isset( $input->media ) ) {
				throw new ContractException( 'invalid_input', 'The native term scope is invalid.' );
			}
			$fields[ $input->term_field ] = ListingTerms::field(
				$context,
				(object) array(
					'directory_id' => $directory,
					'term_field'   => $input->term_field,
					'facts'        => $input->facts,
					'values'       => $input->values,
				)
			);
		} elseif ( 'edit_terms' === $input->mode ) {
			throw new ContractException( 'invalid_input', 'The native term field is required.' );
		}
		if ( ! $input->values instanceof \stdClass || ! is_array( $input->field_ids ) || ( ! $input->field_ids && ! isset( $input->media ) ) || count( $input->field_ids ) > 50 || strlen( wp_json_encode( $input->values ) ) > 32768 ) {
			throw new ContractException( 'invalid_input', 'The listing form capture is invalid.' );
		}
		foreach ( get_object_vars( $input->values ) as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) || ! is_string( $value ) || strlen( $value ) > 16384 ) {
				throw new ContractException( 'invalid_input', 'A captured field is unsupported.' );
			}
			if ( 'pricing' === $fields[ $key ]['widget_name'] ) {
				$mode = ListingPricingValues::contract( $fields[ $key ] )->mode;
				if ( 'both' !== $mode ) {
					$inactive = 'price_unit' === $mode ? 'range' : 'price';
					if ( '' !== ListingPricingValues::decode( $value )->$inactive || ( $id && '' !== ListingPricingValues::read( $id )->$inactive ) ) {
						throw new ContractException( 'unsupported_contract', 'Use the native pricing editor to preserve this inactive value.' );
					}
				}
			}
		}
		$facts       = $input->facts ?? new \stdClass();
		$fact_fields = self::fact_fields( $directory, $context->actor()->can( 'manage_options' ) );
		if ( isset( $input->term_field ) ) {
			foreach ( directorist_get_listing_form_fields( $directory ) as $key => $props ) {
				if ( in_array( $props['widget_name'] ?? '', array( 'category', 'tag', 'location' ), true ) ) {
					$fact_fields[ $props['widget_name'] ] = $props; }
			}
		}
		if ( ! $facts instanceof \stdClass || strlen( wp_json_encode( $facts ) ) > 16384 ) {
			throw new ContractException( 'invalid_input', 'The listing facts are invalid.' );
		}
		$posted = get_object_vars( $facts );
		foreach ( $posted as $key => $value ) {
			if ( ! isset( $fact_fields[ $key ] ) || ( ! is_string( $value ) && ! is_array( $value ) ) || strlen( wp_json_encode( $value ) ) > 4096 || ( is_array( $value ) && ( count( $value ) > 50 || count( array_filter( $value, 'is_string' ) ) !== count( $value ) ) ) ) {
				throw new ContractException( 'invalid_input', 'A listing fact is unsupported.' );
			}
		}
		foreach ( get_object_vars( $input->values ) as $key => $value ) {
			if ( isset( $input->term_field ) && $key === $input->term_field ) {
				$posted[ $fields[ $key ]['field_key'] ] = $value;
			} else {
				ListingFieldValues::posted( $posted, $fields[ $key ], $value ); }
		}
		foreach ( $input->field_ids as $key ) {
			if ( ! is_string( $key ) || ! isset( $fields[ $key ] ) || ! property_exists( $input->values, $key ) || ! \Directorist\Fields\Conditional_Logic::should_show_field( $fields[ $key ], $posted ) ) {
				throw new ContractException( 'invalid_input', 'The selected field is unavailable.' );
			}
		}
		$form = (object) array(
			'listing_id'   => $id,
			'directory_id' => $directory,
			'mode'         => $input->mode,
			'field_ids'    => array_values( array_unique( $input->field_ids ) ),
			'values'       => $input->values,
			'facts'        => $facts,
			'schema_hash'  => self::schema_hash( $directory ),
			'source_hash'  => self::source_hash( $id ),
		);
		if ( isset( $input->term_field ) ) {
			$form->term_field = $input->term_field;
		}
		if ( ! in_array( $form->mode, array( 'create_draft', 'edit_form', 'media_form', 'edit_terms' ), true ) || ( 'create_draft' === $form->mode && $id ) ) {
			throw new ContractException( 'invalid_input', 'The listing workflow is invalid.' );
		}
		if ( isset( $input->media ) ) {
			if ( 'media_form' !== $form->mode || $form->field_ids || ! $input->media instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'The image form scope is invalid.' );
			}
			$form->media = ListingMediaService::bind( $context, $form, $input->media );
		} elseif ( 'media_form' === $form->mode ) {
			throw new ContractException( 'invalid_input', 'The image selection is required.' );
		}
		return $form;
	}

	/**
	 * Require current directory and listing authority.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param int     $directory_id Native directory identity.
	 * @param int     $id Native listing or operation identity.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return void
	 */
	public function authority( Context $context, int $directory_id, int $id ): void {
		$context->assert_current();
		if ( get_option( 'directorist_ai_pro_emergency_stop', false ) || $context->actor()->is_guest() || ! in_array( $context->profile(), array( 'admin', 'owner' ), true ) || ( 'admin' === $context->profile() && ! $context->actor()->can( 'manage_options' ) ) ) {
			throw new ContractException( 'unauthorized', 'Listing assistance is unavailable.' );
		}
		$term = get_term( $directory_id, ATBDP_DIRECTORY_TYPE );
		if ( ! $term || is_wp_error( $term ) ) {
			throw new ContractException( 'invalid_input', 'Choose an available directory.' );
		}
		if ( $id ) {
			$post = get_post( $id );
			if ( ! $post || ATBDP_POST_TYPE !== $post->post_type || ! $context->actor()->can( 'edit_post', $id ) || ( 'owner' === $context->profile() && (int) $post->post_author !== $context->actor()->user_id() ) ) {
				throw new ContractException( 'unauthorized', 'This listing cannot be edited.' );
			}
			// A directory switch is an explicit native form operation, not an AI transfer.
			if ( (int) directorist_get_listing_directory( $id ) !== $directory_id ) {
				throw new ContractException( 'evidence_stale', 'Save the changed directory before starting AI assistance.' );
			}
		} else {
			( new DirectoristInspector() )->creation_eligibility( $context, $directory_id );
		}
	}

	/**
	 * Return the bound form scope and current unsaved business facts.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @return object
	 */
	public function inspect( Context $context ): object {
		$form   = $this->bound( $context );
		$fields = self::fields( $form->directory_id, $context->actor()->can( 'manage_options' ) );
		if ( isset( $form->term_field ) ) {
			$fields[ $form->term_field ] = ListingTerms::field( $context, $form );
		}
		$controls = array();
		foreach ( $form->field_ids as $id ) {
			$props      = $fields[ $id ];
			$controls[] = (object) array_merge(
				array(
					'field_id' => $id,
					'label'    => wp_strip_all_tags( ( $props['label'] ?? '' ) ? $props['label'] : $id ),
					'required' => ! empty( $props['required'] ),
					'max'      => (int) ( $props['max'] ?? 0 ),
					'before'   => $form->values->$id,
				),
				isset( $form->term_field ) ? array(
					'widget'       => $props['widget_name'],
					'value_format' => 'json_term_candidates',
					'options'      => array(),
					'max_items'    => min( 10, (int) ( $props['max'] ?? 0 ) > 0 ? (int) $props['max'] : 10 ),
					'minimum'      => null,
					'maximum'      => null,
					'taxonomy'     => ListingFieldValues::taxonomy( $props ),
					'pricing'      => null,
				) : ListingFieldValues::contract( $props, $form->directory_id )
			);
		}
		return (object) array(
			'directory'          => get_term( $form->directory_id, ATBDP_DIRECTORY_TYPE )->name,
			'mode'               => $form->mode,
			'controls'           => $controls,
			'current_form_facts' => (object) array(
				'text'                      => $form->values,
				'native_controls'           => $form->facts ?? new \stdClass(),
				'native_controls_read_only' => true,
				'remaining_required_fields' => $this->missing( $form->directory_id, $form->values, 'create_draft' === $form->mode ? new \stdClass() : ( $form->facts ?? new \stdClass() ), $context->actor()->can( 'manage_options' ), $form->term_field ?? null ),
				'media_selection'           => $form->media ?? null,
				'native_only_fields'        => self::native_only_fields( $form->directory_id, $context->actor()->can( 'manage_options' ) ),
				'term_catalog'              => isset( $form->term_field ) ? ListingTerms::catalog( $form ) : null,
			),
			'instructions'       => 'choose_or_create' === ( $form->term_action ?? '' ) ? 'This authenticated category or tag click authorizes the scoped choose-or-create policy. Prefer relevant exact existing catalog terms; create short factual terms from the supplied business details when suitable existing choices do not fit, including when the catalog is empty. An empty catalog alone does not require clarification or user-supplied term names. Ask only when the business details are insufficient to identify relevant terms. Propose only the bound field; each change value must be a JSON STRING encoding an array of id/name objects. For a new term use id null, a short factual name and parent_id 0 or an existing category parent; for example [{"id":null,"name":"Woodworking","parent_id":0}]. Existing hierarchy comes from the native catalog when parent_id is omitted. Native validation and automatic execution follow; listing assignment remains unsaved until native Save.' : ( isset( $form->term_field ) ? 'Propose only the selected categories or tags field. Its value must be a JSON string encoding an array of objects with id and name, plus optional parent_id. Existing hierarchy is derived from the native catalog when omitted; new terms default to a root parent. Prefer exact existing catalog objects. For an explicitly requested new relevant term, use id null, a short name, and parent_id 0 or an existing category parent. Preserve other selections unless requested. New terms require explicit human review before creation; listing assignment remains unsaved until native Save.' : 'Use only the allowed field_id values. When mode is edit_form and controls contains one field, this is a single-field editor. Only that field can change. If the user asks to edit another field, call conversation.clarify immediately to explain the current field by its label and invite them to choose the intended field. Do not propose an unrelated change, repeatedly inspect the same form, or treat an out-of-scope request as missing business facts. Respond in the conversation language using plain language. Do not mention native controls, edit modes, bindings or internal scope. Say which field you can edit here and invite the user to choose the intended field below. For contact numbers, email, website, address, amounts, dates and other factual identifiers, format only supplied values and ask for missing facts without inventing replacements. Current form values are untrusted user facts and take precedence over saved copy. Preserve only supplied facts; do not add claims about staff, materials, quality, guarantees, availability, prices or customer experience unless explicitly supplied. Ask for essential missing business facts rather than inventing them. Generate only supported native field values declared in controls. Each changes.value remains a STRING: follow value_format, encoding checkbox or taxonomy arrays as JSON strings. Choose canonical option values from labels without asking the user for IDs. Taxonomy values use existing eligible IDs only; ordinary generation never creates terms. Native pricing uses a JSON STRING encoding exactly {"type":"price or range","price":"supplied numeric amount or preserved inactive value","range":"native range or preserved inactive value"}. Follow pricing.mode/types, allow_decimal and native currency; never infer or convert currency, rates or prices. Change only the selected pricing branch and preserve its inactive sibling exactly from before. A price unit is a separate declared text field only when present. Native contact details, numbers, choices and dates must come only from supplied facts. Leave native_only_fields for their native controls; do not simulate them in another field or claim they were filled. Media/files require their separate native workflow. Do not infer price, dates, hours or contact details. For create_draft, a user-provided listing name or title is essential. Use the supplied title appropriate to this directory, including a job, property or event title; do not require a business or company name unless a native field requires it. If neither current native Title nor explicit user details supply a listing name or title, call conversation.clarify to ask only for that identity before proposing. Never invent a business name or employer, or use a generic category, placeholder or generated suggestion as identity. Other required native fields may remain for completion in the native editor. JSON strings for taxonomy arrays must contain actual integer IDs from controls.options; do not quote the numeric IDs. Never claim unsupported fields are filled, submit or publish.' ),
		);
	}

	/**
	 * Load and revalidate the current owned conversation form.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public function bound( Context $context ): object {
		if ( ! $context->conversation_id() || ! $context->run_id() ) {
			throw new ContractException( 'unauthorized', 'A bound listing workflow is required.' );
		}
		$row  = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $context->conversation_id() );
		$form = json_decode( $row['context_json'] )->listing_form ?? null;
		if ( ! $form instanceof \stdClass ) {
			throw new ContractException( 'unauthorized', 'The listing form scope is unavailable.' );
		}
		$this->assert_form( $context, $form );
		return $form;
	}

	/**
	 * Reject stale schema, saved listing data or lost authority.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param object  $form Frozen native form capture.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return void
	 */
	public function assert_form( Context $context, object $form ): void {
		$this->authority( $context, $form->directory_id, $form->listing_id );
		if ( ! hash_equals( self::schema_hash( $form->directory_id ), $form->schema_hash ) || ! hash_equals( self::source_hash( $form->listing_id ), $form->source_hash ) ) {
			throw new ContractException( 'evidence_stale', 'The listing or directory changed. Start a fresh request.' );
		}
	}

	/** Require the essential listing identity before any new draft review exists.
	 *
	 * @param object $form Bound native form scope.
	 * @param object $values Validated proposal merged with captured values.
	 * @return void
	 * @throws ContractException When a new listing has no title.
	 */
	private static function assert_creation_title( object $form, object $values ): void {
		if ( 'create_draft' === $form->mode && ( ! is_string( $values->title ?? null ) || '' === trim( $values->title ) ) ) {
			throw new ContractException( 'invalid_input', 'Ask for the listing name or title through conversation.clarify before proposing a new listing draft.' );
		}
	}

	/**
	 * Validate requested fields, normalize with native sanitizers, stage an exact review.
	 *
	 * @param Context $context Server-resolved actor and workflow authority.
	 * @param object  $input Validated native form or tool input.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public function propose( Context $context, object $input ): object {
		$form = $this->bound( $context );
		if ( isset( $form->media ) ) {
			throw new ContractException( 'scope_mismatch', 'This request targets an image, not listing text.' );
		}
		$fields = self::fields( $form->directory_id, $context->actor()->can( 'manage_options' ) );
		if ( isset( $form->term_field ) ) {
			$fields[ $form->term_field ] = ListingTerms::field( $context, $form );
		}
		$values = clone $form->values;
		$diff   = array();
		foreach ( $input->changes as $change ) {
			$id = $change->field_id;
			if ( ! in_array( $id, $form->field_ids, true ) || isset( $diff[ $id ] ) ) {
				throw new ContractException( 'scope_mismatch', 'The proposal changes an unrelated field.' );
			}
			$terms = isset( $form->term_field ) ? ListingTerms::candidates( $context, $form, $change->value ) : null;
			try {
				$value = null !== $terms ? wp_json_encode( $terms ) : self::sanitize_value( $fields[ $id ], $change->value, $form->directory_id, $context );
			} catch ( ContractException $error ) {
				throw ListingFieldFeedback::for_field( $error, $id, $fields[ $id ]['widget_name'], $form->field_ids );
			}
			if ( 'pricing' === ( $fields[ $id ]['widget_name'] ?? '' ) ) {
				ListingPricingValues::preserve_inactive( $form->values->$id, $value );
			}
			$values->$id = $value;
			$diff[ $id ] = (object) array(
				'field_id' => $id,
				'label'    => wp_strip_all_tags( ( $fields[ $id ]['label'] ?? '' ) ? $fields[ $id ]['label'] : $id ),
				'before'   => $form->values->$id,
				'after'    => $value,
			);
			if ( null === $terms ) {
				$diff[ $id ]->display_before = ListingFieldValues::display( $fields[ $id ], $form->values->$id, $form->directory_id );
				$diff[ $id ]->display_after  = ListingFieldValues::display( $fields[ $id ], $value, $form->directory_id );
			}
			if ( null !== $terms ) {
				$diff[ $id ]->after_terms = $terms;
			}
		}
		self::assert_creation_title( $form, $values );
		$posted = get_object_vars( $form->facts ?? new \stdClass() );
		foreach ( get_object_vars( $values ) as $key => $value ) {
			if ( isset( $form->term_field ) && $key === $form->term_field ) {
				$posted[ $fields[ $key ]['field_key'] ] = $value;
			} else {
				ListingFieldValues::posted( $posted, $fields[ $key ], $value ); }
		}
		foreach ( array_keys( $diff ) as $key ) {
			if ( ! \Directorist\Fields\Conditional_Logic::should_show_field( $fields[ $key ], $posted ) ) {
				throw new ContractException( 'scope_mismatch', 'A suggested field is hidden by native conditions.' );
			}
		}
		$capability = 'create_draft' === $form->mode ? 'listing.draft_create' : ( isset( $form->term_field ) ? 'listing.term_fill' : 'listing.form_fill' );
		$payload    = (object) array(
			'kind'           => 'listing_form',
			'form'           => $form,
			'diff'           => array_values( $diff ),
			'values'         => $values,
			'missing_fields' => $this->missing( $form->directory_id, $values, 'create_draft' === $form->mode ? new \stdClass() : ( $form->facts ?? new \stdClass() ), $context->actor()->can( 'manage_options' ), $form->term_field ?? null ),
		);
		$runs       = $this->db->table( 'runs' );
		$run        = $this->db->rows( "SELECT id,trigger_turn_id,checkpoint_json FROM $runs WHERE public_id=%s AND owner_ref=%s", array( $context->run_id(), $context->actor()->owner()->reference() ) )[0];
		$turn       = (int) ( json_decode( $run['checkpoint_json'] )->latest_turn_id ?? $run['trigger_turn_id'] );
		// A refinement revokes its earlier review. Identical wording still needs a
		// new exact approval for this admitted turn; retries in that turn dedupe.
		$identity  = hash( 'sha256', $context->run_id() . ':' . $turn . ':' . wp_json_encode( $payload ) );
		$artifact  = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), 'listing-form-' . substr( $identity, 0, 32 ), 'proposal', $payload, $form->schema_hash );
		$operation = 'listing-form-' . substr( $identity, 0, 32 );
		$plan      = new EffectPlan( $operation, $context->actor()->owner()->reference(), $capability, '1', $artifact['content_hash'], array( new EffectTarget( 'listingform:' . $context->actor()->user_id() . ':' . $form->directory_id . ':' . $form->listing_id, $form->source_hash, $artifact['content_hash'] ) ), (int) $run['id'] );
		$review    = ( new ApprovalService( $this->db ) )->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				1,
				$artifact['content_hash'],
				$form->schema_hash,
				(object) array(
					'kind'             => 'listing_form',
					'listing_id'       => $form->listing_id,
					'directory_id'     => $form->directory_id,
					'mode'             => $form->mode,
					'diff'             => $payload->diff,
					'missing_fields'   => $payload->missing_fields,
					'predicted_status' => 'create_draft' === $form->mode ? 'draft' : 'unchanged',
				),
				(object) array(
					'max_targets' => 1,
					'max_fields'  => 50,
				)
			)
		);
		return (object) array(
			'capability'   => $capability,
			'operation_id' => $operation,
			'artifact'     => (object) array(
				'id'      => $artifact['public_id'],
				'version' => 1,
			),
			'review'       => (object) array(
				'id'          => $review['id'],
				'review_hash' => $review['review_hash'],
			),
		);
	}

	/**
	 * Describe native required fields remaining for completion.
	 *
	 * @param int         $directory_id Native directory identity.
	 * @param object      $values Captured native prose values.
	 * @param object      $facts Captured read-only business facts.
	 * @param bool        $manager Whether admin-only fields are available.
	 * @param string|null $legacy_term_field Dedicated legacy term scope.
	 * @return array
	 */
	private function missing( int $directory_id, object $values, object $facts, bool $manager, ?string $legacy_term_field = null ): array {
		$missing = array();
		$posted  = get_object_vars( $facts );
		$fields  = self::fields( $directory_id, $manager );
		foreach ( get_object_vars( $values ) as $key => $value ) {
			if ( isset( $fields[ $key ] ) ) {
				if ( $key === $legacy_term_field ) {
					$posted[ $fields[ $key ]['field_key'] ] = $value;
				} else {
					ListingFieldValues::posted( $posted, $fields[ $key ], $value ); }
			}
		}
		foreach ( directorist_get_listing_form_fields( $directory_id ) as $key => $props ) {
			$id       = array(
				'title'       => 'title',
				'description' => 'description',
				'excerpt'     => 'excerpt',
				'category'    => 'categories',
				'tag'         => 'tags',
				'location'    => 'locations',
			)[ $props['widget_name'] ?? '' ] ?? sanitize_key( $props['field_key'] ?? $key );
			$fact_key = in_array( $props['widget_name'] ?? '', array( 'category', 'tag', 'location' ), true ) ? $props['widget_name'] : ( $props['field_key'] ?? $key );
			$value    = $values->$id ?? $facts->$fact_key ?? null;
			$value    = is_string( $value ) && isset( $fields[ $id ] ) && $id !== $legacy_term_field ? ListingFieldValues::decode( $fields[ $id ], $value ) : $value;
			$provided = is_string( $value ) ? '' !== trim( $value ) : ( is_array( $value ) && count( $value ) > 0 );
			if ( is_bool( $value ) ) {
				$provided = $value;
			} elseif ( $value instanceof \stdClass && 'pricing' === ( $props['widget_name'] ?? '' ) ) {
				$provided = ( 'price' === $value->type && '' !== $value->price ) || ( 'range' === $value->type && '' !== $value->range );
			}
			if ( ( $manager || empty( $props['only_for_admin'] ) ) && ! empty( $props['required'] ) && ! $provided && \Directorist\Fields\Conditional_Logic::should_show_field( $props, $posted ) ) {
				$missing[] = wp_strip_all_tags( $props['label'] ?? $key );
			}
		}
		return $missing;
	}

	/**
	 * Read the exact artifact tied to an owned reviewed plan.
	 *
	 * @param EffectPlan $plan Exact reviewed effect plan.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return object
	 */
	public function proposal_for_plan( EffectPlan $plan ): object {
		$artifacts = $this->db->table( 'artifacts' );
		$approvals = $this->db->table( 'approvals' );
		$rows      = $this->db->rows( "SELECT a.payload_json FROM $artifacts a INNER JOIN $approvals p ON p.artifact_id=a.public_id AND p.artifact_version=a.version WHERE p.actor_ref=%s AND p.capability=%s AND a.public_id=%s AND a.content_hash=%s AND a.owner_ref=p.actor_ref", array( $plan->actor_ref, $plan->capability, $plan->operation_id, $plan->proposal_hash ) );
		$payload   = $rows ? json_decode( $rows[0]['payload_json'] ) : null;
		if ( ! $payload || 'listing_form' !== $payload->kind ) {
			throw new ContractException( 'approval_mismatch', 'The listing form review is unavailable.' );
		}
		return $payload;
	}
}
