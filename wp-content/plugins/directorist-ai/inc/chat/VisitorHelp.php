<?php
/** Native answers to ordinary visitor help and site inventory questions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed native visitor helper and planning contracts.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** No model prose, private configuration, or unverified listing totals enter these replies. */
final class VisitorHelp {
	/** Current native count-helper evidence format; old signatures retain their original format. */
	public const EVIDENCE_VERSION = 2;
	/** Recognize only complete, unambiguous helper requests. */
	public static function topic( string $question, ?array $public_directories = null ): ?string {
		$text = strtolower( trim( preg_replace( '/[\s?!.,]+/u', ' ', $question ) ) );
		if ( preg_match( '/^(?:(?:what is|check|show me|show) (?:the )?(?:status of my|my) (?:booking|reservation|review|request)(?: status)?|did my (?:booking|reservation|review|request) (?:go through|complete))$/D', $text ) ) {
			return 'workflow_status';
		}
		if ( preg_match( '/^(hi|hello|hey|good (morning|afternoon|evening))$/D', $text ) ) {
			return 'greeting';
		}
		if ( preg_match( '/^(thanks|thank you|thank you very much)$/D', $text ) ) {
			return 'thanks';
		}
		if ( preg_match( '/^(help|who are you|what (can|could) you (do|help (me )?with)|what (is|are) your (capability|capabilities|features)|how (can|do) you help( me)?)$/D', $text ) ) {
			return 'capabilities';
		}
		$directory = '(?:directories|directory(?: types)?|directorists?)';
		if ( preg_match( '/^(?:(?:how many|what|which|list|show|show me|count|number of|total) (?:the |available |published )?' . $directory . ')(?: (?:do you have|are there|are available|on this (?:site|website)|does this site have))?$/D', $text ) ) {
			return 'directories';
		}
		if ( preg_match( '/^(?:how many|count|number of|total) (?:public |published |available )?(?:listings|businesses|places)(?: (?:do you have|are there|are available|on this (?:site|website)))?$/D', $text ) ) {
			return 'listing_count';
		}
		// Whole unfiltered named inventories are helper reads, never example searches.
		if ( preg_match( '/^(?:how many|count|number of|total(?: number of)?) (?:public |published |available )?(?:listings|businesses|places)(?: are there)? (?:in|across|for) (.+?)(?: (?:separately|respectively|per directory|in each directory))?$/D', $text, $match ) ) {
			$directories = $public_directories ?? Operations::public_directories();
			$names       = array();
			foreach ( $directories as $directory ) {
				$name = $directory['name'] ?? null;
				if ( ! is_string( $name ) || '' === trim( $name ) || mb_strlen( $name ) > 160 || count( $names ) >= 100 ) {
					return null;
				}
				$name    = strtolower( trim( preg_replace( '/[\s?!.,]+/u', ' ', $name ) ) );
				$names[] = preg_quote( $name, '/' );
			}
			if ( $names && preg_match( '/^(?:' . implode( '|', $names ) . ')(?: (?:and|&) (?:' . implode( '|', $names ) . '))*$/D', $match[1] ) ) {
				return 'listing_count';
			}
		}
		return null;
	}

	/** Describe only enabled public directories and actions that this visitor can inspect. */
	public static function answer( string $topic, Context $context, object $scope, Gateway $gateway, ?Database $db = null, int $evidence_version = self::EVIDENCE_VERSION ): string {
		$context->assert_execution_identity();
		if ( ! in_array( $evidence_version, array( 1, self::EVIDENCE_VERSION ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'Helper evidence format is unavailable.' );
		}
		if ( 'workflow_status' === $topic ) {
			if ( null === $db || null === $context->conversation_id() ) {
				throw new ContractException( 'unauthorized', 'An owned conversation is required to check request status.' );
			}
			$blocks   = ( new VisitorWorkflowService( $db ) )->list_current( $context, $context->conversation_id() )['blocks'];
			$requests = array();
			foreach ( $blocks as $block ) {
				if ( ! VisitorScope::global( $scope ) && (string) ( $scope->directory_id ?? '' ) !== (string) $block['listing']['directory_id'] ) {
					continue; }
				$requests[] = array(
					'listing' => $block['listing']['title'],
					'type'    => $block['type'],
					'intent'  => $block['intent'],
					'status'  => $block['status'],
					'summary' => $block['summary'],
					'receipt' => $block['receipt'] ?? null,
				);
			}
			return $requests ? 'These are the current native statuses of requests saved in this chat. Explain the recorded status exactly. Preparing or awaiting confirmation is not submission. Requests outside this chat were not checked. ' . wp_json_encode( array_slice( $requests, 0, 10 ) ) : 'There are no saved booking or review requests in this chat. I have not checked bookings outside this chat. This does not mean a booking elsewhere failed or was cancelled.';
		}
		$directories = Operations::public_directories();
		if ( ! VisitorScope::global( $scope ) && isset( $scope->directory_id ) ) {
			$directories = array_values( array_filter( $directories, static fn( array $directory ): bool => $directory['id'] === (string) $scope->directory_id ) );
		}
		if ( 1 === $evidence_version && 'directories' === $topic ) {
			return $directories ? sprintf( 'There %s %d available director%s: %s.', 1 === count( $directories ) ? 'is' : 'are', count( $directories ), 1 === count( $directories ) ? 'y' : 'ies', implode( ', ', array_column( $directories, 'name' ) ) ) : 'There are no directories available in this chat right now.';
		}
		if ( in_array( $topic, array( 'directories', 'listing_count' ), true ) ) {
			if ( ! $directories ) {
				return 1 === $evidence_version ? 'There are 0 public listings across the available directories.' : 'There are no directories available in this chat right now.';
			}
			$count    = 0;
			$complete = true;
			$counts   = array();
			foreach ( $directories as $directory ) {
				$catalog = $gateway->execute(
					'listing.catalog',
					wp_json_encode(
						array(
							'directory_id' => (int) $directory['id'],
							'dataset'      => 'public',
						)
					),
					$context
				);
				$result = (object) array( 'count' => ( new \DirectoristAIPro\Domain\DirectoristInspector() )->public_listing_count( $context, (int) $directory['id'] ) );
				if ( ! is_int( $result->count ?? null ) || $result->count < 0 ) {
					$complete = false;
					$counts[] = $directory['name'] . ': public listing count unknown';
					continue;
				}
				$counts[] = $directory['name'] . ': ' . $result->count . ' public listing' . ( 1 === $result->count ? '' : 's' );
				$count   += $result->count;
			}
			if ( 1 === $evidence_version ) {
				return $complete ? sprintf( 'There %s %d public listing%s across the available directories.', 1 === $count ? 'is' : 'are', $count, 1 === $count ? '' : 's' ) : 'I can help you find listings, but I cannot confirm a complete listing total right now.';
			}
			$inventory = sprintf( 'There %s %d available director%s. Current public listing counts by directory: %s.', 1 === count( $directories ) ? 'is' : 'are', count( $directories ), 1 === count( $directories ) ? 'y' : 'ies', implode( '; ', $counts ) );
			return $inventory . ( $complete ? sprintf( ' Total across these directories: %d public listing%s.', $count, 1 === $count ? '' : 's' ) : ' The complete public listing total is unknown because a directory count is unavailable.' );
		}
		if ( 'source_unavailable' === $topic ) {
			return 'At the last public-source check, one or more listings needed for the original request could not be verified as publicly available. Describe that as the result of that check, not a permanent or live availability claim. Do not quote retained details or interpret withdrawal as sold, closed or fully booked. Offer to find publicly available alternatives or compare other listings. No booking, payment or saved action has been replayed.';
		}
		if ( 'changing_information' === $topic ) {
			return 'Listing details changed repeatedly while this answer was being prepared. No stable current price, availability or listing total could be confirmed. Explain that honestly, do not quote changing numbers, and offer help finding an alternative or narrowing the search. This is not a failed booking, payment or saved action.';
		}
		if ( 'thanks' === $topic ) {
			return 'You are welcome! Let me know if you would like help finding something else.';
		}
		if ( 'knowledge_unavailable' === $topic ) {
			return 'I do not have published site information to answer that question. I can still help you find listings, compare their public details, or explore the available directories.';
		}
		if ( ! in_array( $topic, array( 'greeting', 'capabilities' ), true ) ) {
			throw new ContractException( 'invalid_input', 'This helper topic is unavailable.' );
		}
		return ( 'greeting' === $topic ? 'Hello! ' : '' ) . 'I can help you find listings across the available directories, compare their published details, and explore the actions available on a listing. Tell me what you are looking for.';
	}

	/** Old aggregate evidence omitted names; require its saved public directory visibility too. */
	public static function legacy_inventory_current( array $run, object $scope ): bool {
		$checkpoint = json_decode( $run['checkpoint_json'] );
		if ( ! isset( $checkpoint->visitor_reply ) ) {
			return true; // A direct native legacy sentence is checked literally, without model-added names.
		}
		$saved = $checkpoint->visitor_site->directories ?? null;
		if ( ! is_array( $saved ) ) {
			return false;
		}
		$current = Operations::public_directories();
		if ( ! VisitorScope::global( $scope ) && isset( $scope->directory_id ) ) {
			$current = array_values( array_filter( $current, static fn( array $directory ): bool => $directory['id'] === (string) $scope->directory_id ) );
		}
		$identities = array();
		foreach ( $saved as $directory ) {
			if ( ! is_object( $directory ) || ! is_scalar( $directory->id ?? null ) || ! is_string( $directory->name ?? null ) || isset( $identities[ (string) $directory->id ] ) ) {
				return false;
			}
			$identities[ (string) $directory->id ] = $directory->name;
		}
		$native = array_column( $current, 'name', 'id' );
		ksort( $identities );
		ksort( $native );
		return $identities === $native;
	}

	/** Bind the exact native helper reply to its owned durable message position. */
	public static function seal( array $run, int $sequence, string $text, string $topic ): object {
		return (object) array(
			'contract'  => 'directorist-ai/visitor-help-v1',
			'run_id'    => $run['public_id'],
			'topic'     => $topic,
			'signature' => self::signature( $run, $sequence, $text, $topic ),
		);
	}

	/** A copied or edited native reply never grants access to another run or sequence. */
	public static function verifies( array $run, int $sequence, object $content ): bool {
		$seal = $content->provenance ?? null;
		return $seal instanceof \stdClass && array( 'contract', 'run_id', 'topic', 'signature' ) === array_keys( get_object_vars( $seal ) ) && 'directorist-ai/visitor-help-v1' === $seal->contract && $run['public_id'] === $seal->run_id && is_string( $seal->topic ) && is_string( $seal->signature ) && is_string( $content->text ?? null ) && hash_equals( self::signature( $run, $sequence, $content->text, $seal->topic ), $seal->signature );
	}

	private static function signature( array $run, int $sequence, string $text, string $topic ): string {
		return hash_hmac( 'sha256', wp_json_encode( array( get_current_blog_id(), $run['owner_ref'], (int) $run['conversation_id'], $run['public_id'], (int) $run['trigger_turn_id'], $sequence, $text, $topic ) ), wp_salt( 'auth' ) . '|visitor-help-v1' );
	}
}
