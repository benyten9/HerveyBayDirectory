<?php
/**
 * Preserve unrelated styles in narrowly requested native refinements.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\{ContractException, JsonCanonicalizer};

// phpcs:disable Squiz.Commenting.FunctionComment -- Small internal refinement constraints.

/** Intent can restrict a valid native proposal; it can never grant a capability. */
final class BuilderStyleIntent {
	public static function background_only( string $text ): bool {
		$text = self::user_text( $text );
		if ( ! preg_match( '/\bbackground(?:s|\s+colou?rs?)?\b/i', $text ) || preg_match( '/\b(?:generate|build|create|redesign|restyle|rearrange|reorder|insert|delete|remove|add|rename)\b/i', $text ) ) {
			return false;
		}
		// These clauses explicitly preserve properties rather than request edits.
		$clauses = preg_split( '/[.!?;\n]+|(?:\band|\bbut|,)\s+(?=(?:keep|preserve|retain|leave|change|make|set|give|apply|add|adjust|update|increase|decrease|reduce|enlarge|shrink|raise|lower|move|replace|rewrite|retarget|point|round|soften)\b)/i', $text );
		$text    = implode( ' ', array_filter( $clauses, static fn( string $clause ): bool => ! preg_match( '/^\s*(?:keep|preserve|retain|leave)\b/i', $clause ) ) );
		if ( preg_match( '/\b(?:bind|rebind|connect|map|sort|filter|paginate|rewrite|move|replace|duplicate|wrap|unwrap|links?|urls?|href|destinations?|permalinks?|retarget)\b/i', $text ) ) {
			return false;
		}
		// This optional restriction must be conservative: active copy or
		// foreground goals retain their full native authoring contracts.
		if ( preg_match( '/\b(?:labels?|copy|wording|texts?|lettering|foreground|titles?|headings?|paragraphs?|captions?|taglines?|intro(?:duction)?s?|descriptions?|summar(?:y|ies)|messages?|sentences?|content|placeholders?|icons?|show|display)\b/i', $text ) ) {
			return false;
		}
		if ( preg_match( '/\b(?:borders?|corners?|rounder|shadows?|padding|margins?|spacing|fonts?|typography|text\s+colou?rs?|radius|radii|rounded|alignment|align|sizes?|width|height|images?|photos?|animations?)\b/i', $text ) ) {
			return false;
		}
		return (bool) preg_match( '/\bbackgrounds?\b/i', $text );
	}

	/** Carry a narrow goal only through a terse refinement of that same saved area. */
	public static function for_run( Database $db, Context $context, array $run ): string {
		$checkpoint = $run['checkpoint'] ?? new \stdClass();
		$text       = (string) ( $checkpoint->reply->text ?? $checkpoint->intent->text ?? '' );
		if ( self::background_only( $text ) || ! self::terse_refinement( $text ) ) {
			return $text;
		}
		if ( isset( $checkpoint->reply->text ) && self::background_only( (string) ( $checkpoint->intent->text ?? '' ) ) ) {
			return 'Change only the background. ' . self::user_text( $text );
		}
		$binding = json_decode( $run['context_json'] ?? '{}' );
		$memory  = $binding->builder_area_memory ?? null;
		if ( $memory instanceof \stdClass ) {
			$prior = ( new BuilderAreaHistory( $db ) )->prior_requests( $memory, $context->actor()->owner() );
			foreach ( $prior as $request ) {
				if ( self::terse_refinement( $request['request'] ) ) {
					continue;
				}
				return self::continue_text( $text, $request['request'] );
			}
		}
		return $text;
	}

	public static function continue_text( string $current, string $prior ): string {
		return self::terse_refinement( $current ) && self::background_only( $prior ) ? 'Change only the background. ' . self::user_text( $current ) : $current;
	}

	/** Compare only normalized server-read preimages, never model-owned before values. */
	public static function assert_changes( array $changes, string $text ): void {
		if ( ! self::background_only( $text ) ) {
			return;
		}
		foreach ( $changes as $change ) {
			if ( 'set_control' !== ( $change->op ?? null ) || ! is_string( $change->control_path ?? null ) ) {
				self::reject();
			}
			foreach ( self::changed_paths( $change->before ?? null, $change->value ?? null, $change->control_path ) as $path ) {
				if ( ! self::background_path( $path ) ) {
					self::reject();
				}
			}
		}
	}

	/** Active and reviewed callers already establish the exact owner/run binding. */
	public static function assert_run( Database $db, Context $context, array $changes, ?string $conversation = null, ?string $run = null ): void {
		$conversation = $conversation ?? $context->conversation_id();
		$run          = $run ?? $context->run_id();
		if ( 'builder' !== $context->profile() || null === $conversation || null === $run ) {
			return;
		}
		$rows = $db->rows( 'SELECT r.checkpoint_json,c.context_json FROM ' . $db->table( 'runs' ) . ' r JOIN ' . $db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s', array( $run, $conversation, $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			return;
		}
		$checkpoint = json_decode( $rows[0]['checkpoint_json'] );
		self::assert_changes(
			$changes,
			self::for_run(
				$db,
				$context,
				array(
					'checkpoint'   => $checkpoint,
					'context_json' => $rows[0]['context_json'],
				)
			)
		);
	}

	/** Constrain model-only schemas with exact existing values for unrelated members. */
	public static function project( array $projected, array $original, string $text ): array {
		if ( ! self::background_only( $text ) ) {
			return $projected;
		}
		$nodes     = array();
		$canonical = new JsonCanonicalizer();
		foreach ( $original as $entry ) {
			$data    = $entry->response->data ?? null;
			$locator = $data->authoring->selected_area->locator ?? null;
			if ( ! $locator instanceof \stdClass ) {
				continue;
			}
			foreach ( $data->graph->nodes ?? array() as $node ) {
				if ( $canonical->encode( $node->locator ) === $canonical->encode( $locator ) ) {
					$nodes[ $entry->call_id ] = $node;
					break;
				}
			}
		}
		foreach ( $projected as &$entry ) {
			$node = $nodes[ $entry->call_id ?? '' ] ?? null;
			if ( null === $node || ! ( $entry->response->data->authoring ?? null ) instanceof \stdClass ) {
				continue;
			}
			$entry  = json_decode( json_encode( $entry, JSON_THROW_ON_ERROR ), false, 64, JSON_THROW_ON_ERROR );
			$packet = $entry->response->data->authoring;
			foreach ( $packet->components ?? array() as $component ) {
				if ( $component->provider_id !== $node->provider_id || $component->native_id !== $node->native_id ) {
					continue;
				}
				$allowed = array();
				foreach ( $component->controls ?? array() as $control ) {
					$value = self::path_value( $node->attributes, $control->path );
					if ( ( $control->value_schema ?? null ) instanceof \stdClass && self::background_path( $control->path ) ) {
						$allowed[] = $control;
						continue;
					}
					if ( ( $control->value_schema ?? null ) instanceof \stdClass && null !== $value ) {
						if ( ! self::has_background( $value, $control->path ) ) {
							continue;
						}
						self::freeze_schema( $control->value_schema, $value, $control->path );
						$allowed[] = $control;
					}
				}
				$component->controls = $allowed;
				if ( ( $packet->draft->control_edits ?? null ) instanceof \stdClass ) {
					$packet->draft->control_edits->{$node->native_id} = array_column( $allowed, 'path' );
				}
			}
			$packet->requested_style_scope = 'Any proposed edit may change only the background. Other native values are frozen to their exact current values. Preserve all borders, text, spacing, bindings, layout and other styles. This constraint grants no action: honor explanation-only, options-only and ask-first requests.';
		}
		unset( $entry );
		return $projected;
	}

	private static function freeze_schema( object $schema, $value, string $path ): void {
		if ( self::background_path( $path ) ) {
			return;
		}
		if ( $value instanceof \stdClass && ( $schema->properties ?? null ) instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $member ) {
				if ( ( $schema->properties->$key ?? null ) instanceof \stdClass ) {
					self::freeze_schema( $schema->properties->$key, $member, $path . '.' . $key );
				}
			}
			return;
		}
		$schema->const = $value;
	}

	private static function path_value( object $attributes, string $path ) {
		$value = $attributes;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) {
				return null;
			}
			$value = $value->$part;
		}
		return $value;
	}

	private static function has_background( $value, string $path ): bool {
		if ( self::background_path( $path ) ) {
			return true;
		}
		if ( $value instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $member ) {
				if ( self::has_background( $member, $path . '.' . $key ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private static function background_path( string $path ): bool {
		return (bool) preg_match( '/(?:^|[._])background(?:$|[._A-Z])/D', $path );
	}

	private static function changed_paths( $before, $after, string $path ): array {
		$canonical = new JsonCanonicalizer();
		if ( $canonical->digest( $before ) === $canonical->digest( $after ) ) {
			return array();
		}
		if ( $before instanceof \stdClass && $after instanceof \stdClass ) {
			$paths = array();
			foreach ( array_unique( array_merge( array_keys( get_object_vars( $before ) ), array_keys( get_object_vars( $after ) ) ) ) as $key ) {
				$paths = array_merge( $paths, property_exists( $before, $key ) !== property_exists( $after, $key ) ? array( $path . '.' . $key ) : self::changed_paths( $before->$key, $after->$key, $path . '.' . $key ) );
			}
			return $paths;
		}
		return array( $path );
	}

	public static function user_text( string $text ): string {
		if ( preg_match( '/(?:^|\n)User request: (.*?)(?:\nEarlier conversation \(context only\):|$)/sD', $text, $parts ) ) {
			return $parts[1];
		}
		$boundary = strpos( $text, ' Request: ' );
		return false === $boundary ? $text : preg_split( '/\n(?:Selected-area|Edit only this|Human-observed)/', substr( $text, $boundary + 10 ) )[0];
	}

	public static function terse_refinement( string $text ): bool {
		$text = self::user_text( $text );
		return strlen( $text ) <= 200 && (bool) preg_match( '/\b(?:warmer|cooler|softer|subtler|lighter|darker|brighter|more\s+subtle)\b/i', $text ) && self::background_only( 'Change the background. ' . $text );
	}

	private static function reject(): void {
		throw new ContractException( 'invalid_input', 'Change only the requested background. Preserve the existing borders, text, spacing and other style settings.', array( 'preserve_unrequested_style_properties' ) );
	}
}
