<?php
/**
 * Preserve query meaning and complete public evidence before visitor prose.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Validation\ContractException;

/** Public catalog metadata supplies vocabulary, never permissions or listing facts. */
final class VisitorEvidencePolicy {
	private const ADDRESS_PRECISION_FEEDBACK = 'The retrieved address is a known published value. State that exact value and the known location. If the user needs greater precision, say: I cannot verify a more specific street address from the retrieved address value. Do not claim that the listing omits a street address or that only this address is published; other source details were not checked.';
	private const RESERVATION_EXEMPT = '/\\b(?:without (?:having to )?(?:book(?:ing)?|reserv(?:e|ing|ation))|no (?:need to (?:book|reserve)|(?:booking|reservation)s? (?:is |are )?(?:required|needed))|(?:do not|don.t|need not|needn.t) (?:need to )?(?:book|reserve)|(?:booking|reservation)s? (?:is |are )?(?:not required|not needed|optional)|(?:do not|don.t) require (?:a )?(?:booking|reservation)|utan att (?:boka|reservera)|(?:behöver|måste) inte (?:boka|reservera)|(?:bokning|reservation) (?:krävs|behövs) inte|sin (?:reservar|una reserva)|no (?:necesitas|hace falta|es necesario) reservar|no se requiere (?:una )?reserva|reserva no (?:es )?(?:obligatoria|necesaria)|reservas? (?:es |son )?opcional(?:es)?)\\b/iu';
	private const RESERVATION_REQUIRED = '/\\b(?:(?:must|need to|have to) (?:book|reserve)|(?:booking|reservation)s? (?:is |are )?(?:required|needed)|(?:bokning|reservation) (?:krävs|behövs)|(?:måste|behöver) (?:boka|reservera)|(?:debes|tienes que|hay que|es necesario) reservar|se requiere (?:una )?reserva|reserva (?:es )?(?:obligatoria|necesaria|requerida))\\b/iu';

	/** Derived counts preserve known zero prices and distinct native billing periods. */
	public static function price_basis_groups( array $facts ): array {
		$units = array();
		$amounts = array();
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			$subject = $fact['subject'] ?? '';
			if ( ! preg_match( '/^listing:([1-9][0-9]*)$/D', $subject, $match ) ) { continue; }
			$id = (int) $match[1];
			if ( 'price' === ( $fact['field'] ?? null ) && is_numeric( $fact['value'] ?? null ) && is_finite( (float) $fact['value'] ) ) { $amounts[ $id ] = (float) $fact['value']; }
			if ( ( 'price_basis' === ( $fact['semantic_role'] ?? null ) || preg_match( '/(?:^|_)(?:price_unit|pricing_unit|billing_period)$/D', $fact['field'] ?? '' ) || preg_match( '/^(?:price basis|billing unit|billing period|salary basis|salary period|pay period)$/D', self::words( $fact['label'] ?? '' ) ) ) && is_string( $fact['value'] ?? null ) && '' !== trim( $fact['value'] ) ) { $units[ $id ][] = $fact['value']; }
		}
		$groups = array();
		foreach ( $units as $id => $values ) {
			$values = array_values( array_unique( $values ) );
			if ( count( $values ) !== 1 ) { continue; }
			$unit = $values[0];
			if ( ! isset( $groups[ $unit ] ) ) { $groups[ $unit ] = array( 'basis' => $unit, 'listing_ids' => array(), 'known_amount_ids' => array(), 'zero_amount_ids' => array(), 'unknown_amount_ids' => array() ); }
			$groups[ $unit ]['listing_ids'][] = $id;
			if ( array_key_exists( $id, $amounts ) ) { $groups[ $unit ]['known_amount_ids'][] = $id; if ( 0.0 === $amounts[ $id ] ) { $groups[ $unit ]['zero_amount_ids'][] = $id; } }
			else { $groups[ $unit ]['unknown_amount_ids'][] = $id; }
		}
		return array_values( $groups );
	}
	/**
	 * Identify native always-open overrides without treating stored weekly slots as active.
	 *
	 * @param array $facts Current released facts.
	 * @return array Exact listing and fact bindings whose native hours override is active.
	 */
	public static function always_open_hours( array $facts ): array {
		$result = array();
		foreach ( $facts as $fact ) {
			$fact  = (array) $fact;
			$value = is_object( $fact['value'] ?? null ) ? get_object_vars( $fact['value'] ) : ( $fact['value'] ?? null );
			if ( 'hours' === ( $fact['field'] ?? null ) && preg_match( '/^listing:([1-9][0-9]*)$/D', $fact['subject'] ?? '', $match ) && is_array( $value ) && true === ( $value['always_open'] ?? null ) && false !== ( $value['enabled'] ?? null ) ) {
				$result[] = array(
					'listing_id'     => (int) $match[1],
					'hours_fact_id'  => $fact['id'] ?? null,
					'effective_mode' => 'always_open',
					'stored_week'    => 'inactive_under_native_override',
				);
			}
		}
		return $result;
	}
	/**
	 * Bind each released price to its current listing currency or configured default.
	 *
	 * @param array       $facts Current released public facts with native labels when available.
	 * @param string|null $site_currency Configured site price currency.
	 * @return array Effective price currencies; no units, conversions or new listing facts.
	 */
	public static function price_currencies( array $facts, ?string $site_currency ): array {
		$explicit = array();
		$prices   = array();
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( ! preg_match( '/^listing:([1-9][0-9]*)$/D', $fact['subject'] ?? '', $match ) ) {
				continue;
			}
			$id     = (int) $match[1];
			$value  = is_object( $fact['value'] ?? null ) ? get_object_vars( $fact['value'] ) : ( $fact['value'] ?? null );
			$scalar = 'price' === ( $fact['field'] ?? null ) && is_numeric( $value ) && is_finite( (float) $value );
			$native = 'pricing' === ( $fact['field'] ?? null ) && is_array( $value ) && 'price' === ( $value['type'] ?? null ) && is_numeric( $value['price'] ?? null ) && is_finite( (float) $value['price'] );
			if ( $scalar || $native ) {
				$prices[ $id ] = array(
					'listing_id'    => $id,
					'price_fact_id' => $fact['id'] ?? null,
				);
			}
			if ( ( preg_match( '/(?:^|_)currency$/D', $fact['field'] ?? '' ) || 'currency' === self::words( $fact['label'] ?? '' ) ) && is_string( $fact['value'] ?? null ) && '' !== trim( $fact['value'] ) && strlen( $fact['value'] ) <= 32 ) {
				$explicit[ $id ][] = trim( $fact['value'] );
			}
		}
		$default = is_string( $site_currency ) && '' !== trim( $site_currency ) && strlen( $site_currency ) <= 32 ? trim( $site_currency ) : null;
		foreach ( $prices as $id => &$price ) {
			$currencies                  = array_values( array_unique( $explicit[ $id ] ?? array() ) );
			$price['effective_currency'] = 1 === count( $currencies ) ? $currencies[0] : ( $currencies ? null : $default );
			$price['source']             = $currencies ? ( 1 === count( $currencies ) ? 'listing_currency' : 'conflicting_listing_currencies' ) : ( null !== $default ? 'configured_site_default' : 'unknown' );
		}
		unset( $price );
		return array_values( $prices );
	}

	/**
	 * Legacy attested facts cannot turn an inactive stored pricing value into public prose.
	 *
	 * @param string $message  Proposed visitor reply.
	 * @param array  $facts    Attested public facts.
	 * @param string $question Visitor request when available.
	 * @throws ContractException The message relies on an inactive price.
	 */
	public static function assert_pricing_branches( string $message, array $facts, string $question = '' ): void {
		$active   = array();
		$inactive = array();
		$collect  = static function ( $value, int $depth = 0 ) use ( &$collect, &$active ): void {
			if ( $depth > 18 ) {
				return; }
			if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
				$active[] = (string) $value; } elseif ( is_array( $value ) || is_object( $value ) ) {
				foreach ( (array) $value as $part ) {
					$collect( $part, $depth + 1 ); }
				}
		};
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( ! preg_match( '/^listing:[1-9][0-9]*$/D', $fact['subject'] ?? '' ) ) {
				continue; }
			$value = $fact['value'] ?? null;
			if ( 'pricing' === ( $fact['field'] ?? null ) && ( is_object( $value ) || is_array( $value ) ) ) {
				$raw = (array) $value;
				foreach ( array( 'price', 'range' ) as $branch ) {
					if ( ( $raw['type'] ?? null ) !== $branch && is_scalar( $raw[ $branch ] ?? null ) && '' !== trim( (string) $raw[ $branch ] ) ) {
						$inactive[] = (string) $raw[ $branch ]; }
				}
				$value = \DirectoristAIPro\Chat\Workflows\PublicListingFacts::active_pricing( $value );
			}
			$collect( $value );
		}
		foreach ( array_unique( $inactive ) as $value ) {
			$variants = array( $value );
			if ( is_numeric( $value ) && is_finite( (float) $value ) && floor( (float) $value ) === (float) $value && abs( (float) $value ) >= 1000 ) {
				$variants[] = number_format( (float) $value, 0, '.', ',' ); }
			$literal = '/(?<![\p{L}\p{N}])(?:' . implode( '|', array_map( static fn( string $item ): string => preg_quote( $item, '/' ), $variants ) ) . ')(?![\p{L}\p{N}])/iu';
			if ( array_filter( $active, static fn( string $current ): bool => 1 === preg_match( $literal, $current ) ) ) {
				continue; } // Exact literals, never substrings of phone/address numbers.
			if ( ! preg_match( $literal, $message ) ) {
				continue; }
			if ( preg_match( $literal, $question ) && preg_match( '/\b(?:not|inactive|unused|cannot|can.t|unconfirmed|unknown)\b|প্রযোজ্য নয়|নিশ্চিত নয়|inte|inte tillämplig/iu', $message ) ) {
				continue; }
			throw new ContractException( 'invalid_output', 'A stored pricing value belongs to an inactive native pricing branch. State only the active fixed price or active range. Do not publish the unused stored amount/range or infer what the price covers.' );
		}
	}
	/**
	 * A natural period token is not automatically an exact stored billing-unit literal.
	 *
	 * @param string $field Public field ID.
	 * @param string $label Fresh native field label.
	 * @param string $value Proposed equality value.
	 * @param string $question Current visitor request.
	 * @param array  $observed Bounded current authorized public examples.
	 * @return void
	 * @throws ContractException An inferred equality mismatches the observed full values.
	 */
	public static function assert_billing_literal( string $field, string $label, string $value, string $question, array $observed ): void {
		if ( ! preg_match( '/^(?:price unit|pricing unit|billing period|billing unit|rate unit|price basis|salary basis|salary period|pay period)$/D', self::words( $label ) ) || preg_match( '/\b(?:exact|exactly|literal|exactamente|literalmente)\b|হুবহু/iu', $question ) || preg_match( '/["\x27“‘«]' . preg_quote( $value, '/' ) . '["\x27”’»]/iu', $question ) ) {
			return;
		}
		$literal  = self::words( $value );
		$observed = array_values( array_unique( array_filter( $observed, static fn( $unit ): bool => is_string( $unit ) && '' !== trim( $unit ) && strlen( $unit ) <= 160 ) ) );
		foreach ( $observed as $unit ) {
			if ( 0 === strcasecmp( trim( $unit ), trim( $value ) ) ) {
				return;
			}
		}
		foreach ( $observed as $unit ) {
			if ( '' !== $literal && str_contains( ' ' . self::words( $unit ) . ' ', ' ' . $literal . ' ' ) ) {
				throw new ContractException( 'invalid_input', 'The inferred equality literal for public billing field ' . $field . ' does not match the freshly observed public values ' . json_encode( array_slice( $observed, 0, 5 ), JSON_UNESCAPED_UNICODE ) . '. Equality requires the complete exact stored value. For a natural-language period use the advertised text operator with its period token, or an exact supported full value. Preserve the amount, mode and other visitor requirements. These are bounded public examples, not proof that other values are absent.' );
			}
		}
	}
	/**
	 * Order released amounts without inferring comparable purchase or billing basis.
	 *
	 * @param array $facts Current released public facts.
	 * @return array|null Numeric order with exact listing bindings, when supported.
	 */
	public static function amount_order( array $facts ): ?array {
		$amounts = array();
		$titles  = array();
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( 'title' === ( $fact['field'] ?? null ) && is_string( $fact['value'] ?? null ) ) {
				$titles[ $fact['subject'] ?? '' ] = $fact['value'];
			}
		}
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( 'price' !== ( $fact['field'] ?? null ) || ! preg_match( '/^listing:([1-9][0-9]*)$/D', $fact['subject'] ?? '', $match ) || ! is_numeric( $fact['value'] ?? null ) || ! is_finite( (float) $fact['value'] ) ) {
				continue;
			}
			$id = (int) $match[1];
			if ( isset( $amounts[ $id ] ) && (float) $amounts[ $id ]['amount'] !== (float) $fact['value'] ) {
				return null;
			}
			$amounts[ $id ] = array(
				'listing_id' => $id,
				'amount'     => $fact['value'],
			);
			if ( isset( $titles[ $fact['subject'] ] ) ) {
				$amounts[ $id ]['title'] = $titles[ $fact['subject'] ];
			}
			if ( isset( $fact['id'] ) ) {
				$amounts[ $id ]['fact_id'] = $fact['id'];
			}
		}
		if ( count( $amounts ) < 2 ) {
			return null;
		}
		usort( $amounts, static fn( array $a, array $b ): int => (float) $a['amount'] <=> (float) $b['amount'] );
		return array(
			'ascending' => $amounts,
			'lowest'    => $amounts[0],
			'highest'   => $amounts[ count( $amounts ) - 1 ],
			'meaning'   => 'Numeric order of current listed amounts only. Currency, billing basis, transaction mode and eligibility must be checked separately. Correct any reversed lower/higher premise in the question; a lower number does not establish a comparable cost or a budget match.',
		);
	}

	/**
	 * Reject definite numeric rankings with two unambiguous current amount bindings.
	 *
	 * @param string $message Proposed answer.
	 * @param array  $facts Current released public facts.
	 * @return void
	 * @throws ContractException The definite comparative reverses current amounts.
	 */
	private static function assert_amount_order( string $message, array $facts ): void {
		$order = self::amount_order( $facts );
		if ( null === $order || 2 !== count( $order['ascending'] ) ) {
			return;
		}
		$low  = (float) $order['ascending'][0]['amount'];
		$high = (float) $order['ascending'][1]['amount'];
		if ( $low === $high ) {
			return;
		}
		foreach ( preg_split( '/[.!?]\s+/u', $message ) as $sentence ) {
			// Quotes, hypotheticals, negation and comparisons to an external budget
			// need semantic interpretation; this check does not reinterpret them.
			if ( preg_match( '/["“”«»]|\b(?:if|would|could|hypothetical|earlier|previous|quoted|old|not|isn.t|isn\x{2019}t|rather than|instead of|less than|more than)\b|\b(?:relative to|compared (?:to|with)|than)\b[^.!?]{0,50}\bbudget\b/iu', $sentence ) ) {
				continue;
			}
			if ( preg_match_all( '/\bthe\s+(lower|smaller|higher|larger)\s+(?:listed\s+)?(?:amount|figure|price)\s+(?:of\s+|is\s+|at\s+)?(?:USD\s*|EUR\s*|GBP\s*|[$€£]\s*)?([0-9]+(?:,[0-9]{3})*(?:\.[0-9]+)?)(?![0-9.,])/iu', $sentence, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$amount   = (float) str_replace( ',', '', $match[2] );
					$expected = in_array( strtolower( $match[1] ), array( 'lower', 'smaller' ), true ) ? $low : $high;
					if ( in_array( $amount, array( $low, $high ), true ) && $amount !== $expected ) {
						throw new ContractException( 'invalid_output', 'The definite lower/higher amount reverses the two current listing prices. Use numeric_amount_order to correct the premise, with each amount bound to its own listing. Numeric order does not establish comparable billing basis, transaction mode or budget eligibility.' );
					}
				}
			}
		}
	}
	/**
	 * Describe only the fields actually released to the writer, per listing.
	 *
	 * @param array $facts Released public facts.
	 * @return array Read coverage without adding facts or listing authority.
	 */
	public static function coverage( array $facts ): array {
		$coverage = array();
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( ! preg_match( '/^listing:[1-9][0-9]*$/D', $fact['subject'] ?? '' ) || ! is_string( $fact['field'] ?? null ) || 'native_actions' === $fact['field'] ) {
				continue;
			}
			$value = $fact['value'] ?? null;
			$state = is_object( $value ) || is_array( $value ) ? ( (array) $value )['state'] ?? null : null;
			// A missing query-relative metric does not establish publication absence.
			if ( 'distance' === $fact['field'] && in_array( $state, array( 'unknown', 'missing' ), true ) ) { continue; }
			$coverage[ $fact['subject'] ][ $fact['field'] ] = in_array( $state, array( 'unknown', 'missing' ), true ) ? 'checked_unknown' : 'known';
		}
		return $coverage;
	}

	/**
	 * Prevent selecting or submitting from becoming a confirmation guarantee.
	 *
	 * @param string $message Proposed answer or clarification.
	 * @return void
	 * @throws ContractException Workflow stages are conflated.
	 */
	public static function assert_booking_sequence( string $message ): void {
		foreach ( preg_split( '/[.!?]\s+|\n+/u', $message ) as $sentence ) {
			$patterns = array(
				'/\b(?:availability|slots?|booking|reservation)\b[^.!?;—]{0,80}\b(?:confirmed|guaranteed|reserved|secured)\b[^.!?;—]{0,40}\b(?:once|when|after|upon|by|as soon as)\b[^.!?;—]{0,160}\b(?:submit\w*|send\w*|pick\w*|choos\w*|select\w*)\b[^.!?;—]*/iu',
				'/\b(?:once|when|after|upon|by|as soon as)\b[^.!?;—]{0,80}\b(?:submit\w*|send\w*|pick\w*|choos\w*|select\w*)\b[^.!?;—]{0,100}\b(?:availability|slots?|booking|reservation)\b[^.!?;—]{0,60}\b(?:confirmed|guaranteed|reserved|secured)\b[^.!?;—]*/iu',
				'/\b(?:submit\w*|send\w*|pick\w*|choos\w*|select\w*)\b[^.!?;—]{0,80}\b(?:confirms?|guarantees?|reserves?|secures?)\b[^.!?;—]{0,60}\b(?:availability|slots?|booking|reservation)\b[^.!?;—]*/iu',
				'/\b(?:request|form|submission)\b[^.!?;—]{0,50}\b(?:becomes?|is|counts as)\s+(?:a\s+)?(?:confirmed\s+)?(?:booking|reservation)\b[^.!?;—]{0,60}\b(?:once|when|after|upon)\b[^.!?;—]{0,140}\b(?:submit\w*|send\w*|pick\w*|choos\w*|chosen|select\w*|review\w*|confirm\w*)\b[^.!?;—]*/iu',
				'/\b(?:held|reserved|confirmed|booked|guaranteed|secured)\b[^.!?;—]{0,40}\b(?:once|when|after|upon|until|by)\b[^.!?;—]{0,100}\b(?:you|visitor|customer)\b[^.!?;—]{0,45}\b(?:review\w*|confirm\w*|submit\w*|send\w*|complete\w*|select\w*|pick\w*)\b[^.!?;—]*/iu',
			);
			$claims   = array();
			foreach ( $patterns as $pattern ) {
				preg_match_all( $pattern, $sentence, $matches );
				$claims = array_merge( $claims, $matches[0] );
			}
			if ( ! $claims ) {
				continue;
			}
			foreach ( $claims as $claim ) {
				// A denial in an earlier clause cannot excuse a later positive guarantee.
				if ( preg_match( '/\b(?:does not|doesn.t|will not|won.t|cannot|can.t|not automatically|not guaranteed|not confirmed|no guarantee)\b|\b(?:receipt|explicit confirmation|confirmation (?:message|receipt)|provider confirms|business confirms)\b/iu', $claim ) ) {
					continue;
				}
				throw new ContractException( 'invalid_output', 'Checking slots establishes current availability only. Selecting or submitting sends a request; it does not guarantee availability or booking confirmation. Confirmation requires the exact native confirmation receipt.' );
			}
		}
	}

	/**
	 * Keep a resolved single detail from inventing another listing's status.
	 *
	 * @param string $message Proposed prose.
	 * @param object $delivery Current public delivery.
	 * @param string $question Current visitor request.
	 * @return void
	 * @throws ContractException Unrequested comparison or a repeated resolved target question.
	 */
	public static function assert_detail_scope( string $message, object $delivery, string $question ): void {
		$coverage = self::coverage( VisitorReply::facts( $delivery ) );
		if ( 1 !== count( $coverage ) || 'compare' !== ( $delivery->response->task->kind ?? null ) ) {
			return;
		}
		if ( preg_match( '/\b(?:which (?:listing|cafe|business|place)|tell me which (?:one|listing)|specify (?:the|which) listing)\b/iu', $message ) ) {
			throw new ContractException( 'invalid_output', 'This current public detail already resolves one listing. Answer it directly; do not ask the visitor to identify it again.' );
		}
		$fields = array_diff( array_keys( reset( $coverage ) ), array( 'title' ) );
		foreach ( preg_split( '/[.!?]\s+/u', $message ) as $sentence ) {
			$exhaustive = preg_replace( '/\bonly (?:a |the )?(?:published |listed )?(?:amenity |accessibility )?label\b/iu', '', $sentence );
			if ( count( $fields ) <= 2 && ! in_array( 'checked_unknown', reset( $coverage ), true ) && ! self::scope_limited( $sentence ) && preg_match( '/\b(?:only|full|complete|whole)\b[^.!?]{0,80}\b(?:published|listed|shown|on (?:file|record))\b/iu', $exhaustive ) ) {
				throw new ContractException( 'invalid_output', 'These are only the requested known fields, not an exhaustive listing inspection. Do not say they are the only published details or the full address on file. State the known value directly; supplementary details were not checked.' );
			}
		}
		if ( count( $fields ) <= 2 && ! preg_match( '/\b(?:compar\w*|versus|vs|both|between|difference|cheaper|cheapest|least|other|same|those|they|their)\b/iu', $question ) && preg_match( '/\b(?:the other (?:listing|cafe|business|place)|unless that one|(?:not|isn.t|aren.t) (?:directly )?comparable)\b/iu', $message ) ) {
			throw new ContractException( 'invalid_output', 'Answer only the requested detail for the resolved listing. No comparison was requested or freshly retrieved for the other choices. Do not speculate about their units or describe their facts as missing.' );
		}
	}

	/**
	 * Distinguish bounded lack of evidence from a listing-wide absence claim.
	 *
	 * @param string $sentence Proposed sentence.
	 * @return bool Whether the uncertainty is explicitly limited to checked evidence.
	 */
	private static function scope_limited( string $sentence ): bool {
		// An evidence preface cannot turn a direct listing-wide publication
		// denial into a checked-data limitation. Known titles are normalized
		// to "the listing" by assert_answer before this check.
		$listing_subject = '(?:(?:(?:the|this|that|these|those|both|all|each|neither)\s+)?(?:listings?|business(?:es)?|places?|websites?|sites?)|they|it)';
		if ( preg_match_all( '/\b' . $listing_subject . '\s+(?:(?:do not|does not|don.t|doesn.t|never)\s+(?:publish|list|provide|specify|state|show|have)|(?:has|have)\s+no\s+(?:published|listed)|(?:lack|lacks|omit|omits)\b)/iu', $sentence, $denials, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $denials[0] as [ $claim, $offset ] ) {
				$prefix = substr( $sentence, 0, $offset );
				if ( ! preg_match( '/\b(?:(?:cannot|can.t|could not|couldn.t|do not|don.t)\s+(?:conclude|claim|say|determine)(?:\s+(?:that|whether|if))?|(?:do not|don.t)\s+know\s+(?:whether|if))\s*$/iu', $prefix ) ) {
					return false; }
			}
		}
		// A retrieved-facts subject can bound billing uncertainty just as "from
		// the retrieved facts" does. It cannot excuse a publication-wide denial
		// added to that sentence. Known billing units are checked independently.
		$billing_uncertainty = '/\b(?:(?:the|these|this)\s+)?(?:supplied|retrieved|current|checked)\s+(?:facts|evidence|details|information|fields)\s+(?:do not|don.t|does not|doesn.t)\s+(?:state|specify|establish|confirm|show)\s+(?:what\s+(?:(?:each|the|this|that|these|those)\s+)?(?:price|amount)s?\s+covers?|(?:the\s+)?(?:billing|price)\s+(?:basis|unit|period))\b/iu';
		if ( preg_match( $billing_uncertainty, $sentence ) ) {
			$other_claims = preg_replace( $billing_uncertainty, '', $sentence, 1 );
			// "Compare the listed amounts" is affirmative, not a denial. Only
			// another negative claim can exceed this evidence-scoped uncertainty.
			$denial = '/\b(?:no|neither|nothing)\b[^.!?;]{0,100}\b(?:publish(?:ed)?|list(?:ed)?|provid(?:e|ed)|specif(?:y|ied)|stat(?:e|ed)|show(?:n)?|available|on record)\b|\b(?:not|never)(?:\s+been)?\s+(?:currently\s+)?(?:published|listed|provided|specified|stated|shown|available|on record)\b|\b(?:isn.t|aren.t|wasn.t|weren.t|hasn.t been|haven.t been)\s+(?:currently\s+)?(?:published|listed|provided|specified|stated|shown|available|on record)\b|\b(?:do not|does not|don.t|doesn.t)\s+(?:currently\s+)?(?:publish|list|provide|specify|state|show|have)\b|\b(?:unpublished|unlisted|unavailable|missing|omit(?:s|ted)?|lacks?)\b/iu';
			if ( ! preg_match( $denial, $other_claims ) ) {
				return true;
			}
		}
		return (bool) preg_match( '/\bnot (?:checked|retrieved)\b|\b(?:no|not|isn.t|aren.t)\b[^.!?]{0,100}\bretrieved\b|\b(?:in|from) (?:the |this |these )?(?:supplied|retrieved|current|checked) (?:public )?(?:facts|evidence|details|information|fields)\b|\b(?:in|from) (?:these|this) (?:facts|evidence)\b|\b(?:in|among|from) (?:the )?(?:details|facts|fields|information) I (?:have|checked|can see|retrieved)\b|\b(?:in|from) (?:the |this )?(?:retrieved|published|supplied|checked|current) address (?:field|value)\b|\bI (?:do not|don.t) have (?:a |any )?(?:published )?\b|\b(?:nothing|no (?:supporting )?(?:detail|evidence|information))\b[^.!?]{0,80}\bconfirms?\b/iu', $sentence );
	}

	/**
	 * Preserve explicitly named checkbox requirements even when a model omits one.
	 *
	 * @param object $node Proposed predicate.
	 * @param object $catalog Authorized public vocabulary.
	 * @param string $question Current search request.
	 * @return object Predicate with the literal mandatory requirements retained.
	 */
	public static function requirements( object $node, object $catalog, string $question ): object {
		$text = self::words( $question );
		if ( preg_match( '/\b(?:or|either|without|not|no|neither|prefer|ideally|optional)\b/u', $text ) ) {
			return $node; }
		$existing = array();
		$walk     = static function ( $part ) use ( &$walk, &$existing ): void {
			if ( isset( $part->field ) && 'eq' === ( $part->op ?? null ) ) {
				$existing[ $part->field . ':' . json_encode( $part->value ) ] = true; }
			foreach ( $part->all ?? array() as $child ) {
				$walk( $child ); }
		};
		$walk( $node );
		$clauses = $node->all ?? array( $node );
		foreach ( $catalog->fields as $field ) {
			if ( 'enum_set' !== ( $field->value_type ?? null ) ) {
				continue; }
			$mentioned = array();
			foreach ( $field->allowed_values ?? array() as $choice ) {
				$choice = (array) $choice;
				foreach ( array_unique( array( $choice['value'], $choice['label'] ?? $choice['value'] ) ) as $label ) {
					if ( is_string( $label ) && '' !== self::words( $label ) && str_contains( ' ' . $text . ' ', ' ' . self::words( $label ) . ' ' ) ) {
						$mentioned[] = $choice['value'];
						break; }
				}
			}
			if ( count( $mentioned ) < 2 ) {
				continue; }
			foreach ( $mentioned as $value ) {
				if ( ! isset( $existing[ $field->field_id . ':' . json_encode( $value ) ] ) ) {
					$clauses[] = (object) array(
						'field' => $field->field_id,
						'op'    => 'eq',
						'value' => $value,
					); }
			}
		}
		return count( $clauses ) === 1 ? $clauses[0] : (object) array( 'all' => $clauses );
	}

	/**
	 * Normalize explicit conjunctive choices without changing native IN semantics.
	 *
	 * @param object $node Model predicate.
	 * @param object $catalog Authorized public fields.
	 * @param string $question Current visitor request.
	 * @return object Meaning-preserving predicate.
	 */
	public static function query( object $node, object $catalog, string $question ): object {
		$node = clone $node;
		if ( isset( $node->field ) ) {
			$field = array_values( array_filter( $catalog->fields, static fn( $field ) => $field->field_id === $node->field ) )[0] ?? null;
			if ( 'enum_set' !== ( $field->value_type ?? null ) || 'in' !== ( $node->op ?? null ) || ! is_array( $node->value ) || count( $node->value ) < 2 ) {
				return $node;
			}
			$text = self::words( $question );
			// Mixed alternatives, negation and soft preferences require the model's
			// explicit Boolean tree. Never infer a stronger requirement from them.
			if ( preg_match( '/\b(?:or|either|without|not|no|neither|prefer|ideally|optional)\b/u', $text ) ) {
				return $node;
			}
			$advertised = array_column( $field->allowed_values ?? array(), 'value' );
			foreach ( $node->value as $value ) {
				if ( ! is_string( $value ) || ! in_array( $value, $advertised, true ) || ! str_contains( ' ' . $text . ' ', ' ' . self::words( $value ) . ' ' ) ) {
					return $node;
				}
			}
			return (object) array(
				'all' => array_map(
					static fn( $value ) => (object) array(
						'field' => $node->field,
						'op'    => 'eq',
						'value' => $value,
					),
					$node->value
				),
			);
		}
		foreach ( array( 'all', 'any' ) as $group ) {
			if ( isset( $node->$group ) ) {
				$node->$group = array_map( static fn( $child ) => self::query( $child, $catalog, $question ), $node->$group );
			}
		}
		return $node;
	}

	/**
	 * Complete price dependencies from the authorized public catalog.
	 *
	 * @param array  $requested Requested field identifiers.
	 * @param object $catalog Current public catalog.
	 * @param array  $labels Native public labels.
	 * @return array Complete field identifiers.
	 * @throws ContractException Complete evidence exceeds the native contract.
	 */
	public static function fields( array $requested, object $catalog, array $labels = array() ): array {
		$requested = self::resolve_fields( $requested, $catalog, $labels );
		if ( ! array_intersect( array( 'price', 'pricing' ), $requested ) ) {
			return $requested;
		}
		foreach ( $catalog->fields as $field ) {
			$id    = $field->field_id;
			$label = self::words( $labels[ $id ] ?? $field->label ?? $id );
			if ( preg_match( '/(?:^|_)(?:price_unit|pricing_unit|billing_period|currency)$/D', $id ) || preg_match( '/^(?:price unit|pricing unit|billing period|billing unit|rate unit|price basis|salary basis|salary period|pay period|currency)$/D', $label ) ) {
				$requested[] = $id;
			}
		}
		$requested = array_values( array_unique( $requested ) );
		if ( count( $requested ) > 12 ) {
			throw new ContractException( 'invalid_input', 'Price details need their public unit and currency. Request fewer unrelated fields so the complete price can be retrieved.' );
		}
		return $requested;
	}

	/**
	 * Resolve public read fields; exact native IDs and unique labels keep priority.
	 *
	 * @param array  $requested Requested public field identifiers.
	 * @param object $catalog   Current public field catalog.
	 * @param array  $labels    Attested directory field labels.
	 * @return array Resolved requested field identifiers.
	 */
	public static function resolve_fields( array $requested, object $catalog, array $labels = array() ): array {
		// A semantic name may stand for one exact public label on a custom field.
		// Resolve only a unique current label; blank or ambiguous labels grant no
		// mapping, and an unavailable requested detail remains explicit downstream.
		$available = array_fill_keys( array_column( $catalog->fields, 'field_id' ), true );
		$requested = array_values(
			array_unique(
				array_map(
					static function ( string $id ) use ( $catalog, $labels, $available ): string {
						if ( isset( $available[ $id ] ) ) {
							return $id; }
						// Canonical native read IDs must survive both plan normalization and
						// comparison hydration. A missing native field cannot become a custom
						// field merely because its public label happens to be "Categories".
						if ( in_array( $id, array( 'categories', 'locations', 'tags' ), true ) ) {
							return $id; }
						$matches = array();
						foreach ( $catalog->fields as $field ) {
							$label = $labels[ $field->field_id ] ?? $field->label ?? '';
							if ( '' !== trim( $label ) && self::words( str_replace( array( '_', '-' ), ' ', $id ) ) === self::words( html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
								$matches[] = $field->field_id; }
						}
						if ( 1 === count( $matches ) ) {
							return $matches[0]; }
						// Singular native taxonomy names are read aliases, never permission
						// to substitute an unrelated custom choice or expose a hidden field.
						$taxonomy = array(
							'category' => 'categories',
							'location' => 'locations',
							'tag'      => 'tags',
						)[ $id ] ?? null;
						if ( ! $matches && null !== $taxonomy ) {
							foreach ( $catalog->fields as $field ) {
								if ( $taxonomy === $field->field_id && 'term_ids' === ( $field->value_type ?? null ) ) {
									return $taxonomy; }
							}
							if ( ! isset( $available[ $taxonomy ] ) ) {
								return $taxonomy; }
						}
						// Directorist's native pricing widget is readable as a structured
						// price/range even when scalar price predicates are not available.
						// This is a read projection only: preserve its native mode and value,
						// and never reinterpret a filter or invent a numeric price field.
						if ( ! $matches && 'price' === $id ) {
							foreach ( $catalog->fields as $field ) {
								if ( 'pricing' === $field->field_id && 'structured' === ( $field->value_type ?? null ) ) {
									return 'pricing'; }
							}
						}
						return $id;
					},
					$requested
				)
			)
		);
		return $requested;
	}

	/**
	 * Prioritize query-critical facts in the bounded writer projection.
	 *
	 * @param string $field Native field identifier.
	 * @param array  $input_fields Query field identifiers.
	 * @return int Sort priority.
	 */
	public static function priority( string $field, array $input_fields ): int {
		if ( in_array( $field, $input_fields, true ) || preg_match( '/(?:^|_)(?:price_unit|pricing_unit|billing_period|currency)$/D', $field ) ) {
			return 1;
		}
		return 30;
	}

	/**
	 * Reject unsupported absence and stronger access claims.
	 *
	 * @param string $message Proposed answer.
	 * @param array  $facts Current released public evidence.
	 * @param string $question Current visitor request, used only to recognize quoted old units.
	 * @return void
	 * @throws ContractException Proposed prose contradicts or exceeds its evidence.
	 */
	public static function assert_answer( string $message, array $facts, string $question = '' ): void {
		self::assert_pricing_branches( $message, $facts, $question );
		self::assert_booking_sequence( $message );
		self::assert_amount_order( $message, $facts );
		self::assert_distance_context( $message, $facts, $question );
		self::assert_reservation_requirement( $message, $facts );
		if ( preg_match( '/\b(?:next.door|doors? apart|close together on the same street)\b/iu', $message ) && ! preg_match( '/\b(?:next.door|neighbou?rs?|doors? apart|close together)\b/iu', (string) json_encode( $facts ) ) ) {
			throw new ContractException( 'invalid_output', 'Address numbers do not verify physical adjacency or distance. Give the published addresses without calling the listings next-door neighbours, a couple of doors apart or close together unless current evidence explicitly establishes that relationship.' );
		}
		$units    = array();
		$subjects = array();
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( preg_match( '/^listing:[1-9][0-9]*$/D', $fact['subject'] ?? '' ) ) {
				$subjects[ $fact['subject'] ][ $fact['field'] ] = $fact['value'];
			}
			if ( ( 'price_basis' === ( $fact['semantic_role'] ?? null ) || preg_match( '/(?:price_unit|pricing_unit|billing_period)$/D', $fact['field'] ?? '' ) ) && is_string( $fact['value'] ?? null ) && '' !== trim( $fact['value'] ) ) {
				$units[] = $fact['value'];
			}
		}
		$states_unit = (bool) array_filter( $units, static fn( $unit ) => false !== mb_stripos( $message, $unit ) );
		if ( $units && ! $states_unit && preg_match( '/(?:do(?:n.t| not)|does(?:n.t| not)|is(?:n.t| not))\s+(?:specify|specified|state|stated|publish|published)|(?:not|no)\s+(?:specified|published|provided|information|details)|\bnone\b.{0,60}\b(?:publish(?:es)?|list(?:s)?|state(?:s)?|specif(?:y|ies))\b/iu', $message ) && preg_match( '/(?:what (?:that|the|this) (?:price|amount)|per.person|per.item|per.meal|(?:price|billing) (?:basis|unit|period)|what.*covers)/iu', $message ) ) {
			throw new ContractException( 'invalid_output', 'A published price unit is supplied in the current facts. State that unit with its corresponding listing price. Do not say the listing omits it.' );
		}
		$retrieved      = array_column( array_map( static fn( $fact ) => (array) $fact, $facts ), 'field' );
		$coverage       = self::coverage( $facts );
		$unknown_fields = array();
		$known_addresses = 0;
		$titles = array_values( array_filter( array_map( static function( $fact ) { $fact = (array) $fact; return 'title' === ( $fact['field'] ?? null ) && is_string( $fact['value'] ?? null ) && mb_strlen( $fact['value'] ) >= 3 && mb_strlen( $fact['value'] ) <= 512 ? $fact['value'] : null; }, $facts ) ) );
		usort( $titles, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		foreach ( $coverage as $fields ) {
			$unknown_fields = array_merge( $unknown_fields, array_keys( $fields, 'checked_unknown', true ) );
			if ( 'known' === ( $fields['address'] ?? null ) ) { ++$known_addresses; }
		}
		foreach ( preg_split( '/[.!?]\s+/u', $message ) as $sentence ) {
			$claims = $sentence;
			foreach ( $titles as $title ) { $claims = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $title, '/' ) . '(?![\p{L}\p{N}])/iu', 'the listing', $claims ); }
			// Remove only a derived clock clause for one verified always-open target.
			if ( 1 === count( $coverage ) && 1 === count( self::always_open_hours( $facts ) ) ) {
				$sentence = preg_replace( '/\bno\s+(?:(?:set|fixed|restricted|separate)\s+)*(?:(?:opening(?:\s+and\s+closing)?|closing)\s+(?:days(?:\s+or\s+times)?|times)|(?:weekday|weekly)\s+schedule)(?:\s+to\s+(?:report|list))?/iu', '', $sentence );
			}
			$absence = preg_match( '/\b(?:no|not|nothing|isn.t|aren.t|doesn.t|don.t|hasn.t|haven.t)\b[^.!?]{0,100}\b(?:publish(?:ed)?|list(?:ed)?|provid(?:e|ed)|specif(?:y|ied)|stat(?:e|ed)|show(?:n)?|available|on record)\b|\b(?:published|listed|provided|specified|stated|shown)\s+(?:(?:status|value)\s+)?(?:(?:is|are)\s+)?(?:unknown|missing)\b/iu', $sentence );
			$scoped  = self::scope_limited( $claims );
			if ( $absence && $known_addresses && $known_addresses === count( $coverage ) && preg_match( '/\b(?:no (?:street )?address(?:es)?|address(?:es)? (?:is|are|was|were) not|address(?:es)? (?:aren.t|isn.t)|address(?:es)? (?:has|have) not been (?:published|provided))\b/iu', $sentence ) ) {
				throw new ContractException( 'invalid_output', self::ADDRESS_PRECISION_FEEDBACK );
			}
			if ( $coverage && $absence && ! $scoped ) {
				if ( ! $unknown_fields ) {
					if ( $known_addresses && preg_match( '/\b(?:street|address|location)\b/iu', $question ) ) {
						throw new ContractException( 'invalid_output', self::ADDRESS_PRECISION_FEEDBACK );
					}
					throw new ContractException( 'invalid_output', 'Every released listing field in this answer is known. No checked-unknown field supports a publication-absence claim. Return the requested known value; supplementary fields are not checked, not proven unpublished. For a rate and visit question, state the exact amount and billing period, then say that this rate does not confirm a guaranteed total quote and opening hours do not confirm a visit tomorrow. Omit claims that a complete total, parts, fees, availability or booking form are not published or unavailable unless that exact field or capability was checked.' );
				}
				if ( preg_match( '/\b(?:inclusions?|extras?|drinks|sides|unit.level|more precise|entrance|beyond (?:the|this|that))\b/iu', $claims ) ) {
					throw new ContractException( 'invalid_output', 'The response did not check supplementary inclusions or address/entrance details. Unknown unrelated fields do not establish that these details are unpublished. State that the requested supplementary detail cannot be confirmed from this evidence.' );
				}
			}
			// A retrieved scalar is not an exhaustive read of its surrounding details.
			if ( preg_match( '/\b(?:full|complete|only) published address\b|\b(?:full|complete|only) address\b[^.!?]{0,40}\b(?:published|on (?:file|record))\b|\bno supporting detail\b[^.!?]{0,40}\b(?:this|the) listing\b|\b(?:unit.level|additional address|further address|exact entrance|meal inclusions?|what [^.!?]{0,30}(?:price|meal) (?:includes|covers))\b[^.!?]{0,100}(?:aren.t|isn.t|not|no)[^.!?]{0,35}(?:listed|published|stated|specified)/iu', $sentence ) ) {
				throw new ContractException( 'invalid_output', 'The supplied address or billing unit does not establish that supplementary address details or meal inclusions are unpublished. State the requested known value. If essential, say supplementary details were not checked, rather than claiming the listing omits them.' );
			}
			if ( ! preg_match( '/(?:not|no|hasn.t|haven.t|aren.t|isn.t|doesn.t)[^.!?]{0,40}(?:publish|listed|available|provided|specif)|(?:publish|available|provided|specif)[^.!?]{0,25}(?:not|unknown)/iu', $sentence ) ) {
				continue; }
			foreach ( array(
				'hours'   => '/opening hours|business hours/iu',
				'phone'   => '/phone number/iu',
				'email'   => '/email address/iu',
				'website' => '/website/iu',
			) as $field => $pattern ) {
				if ( preg_match( $pattern, $sentence ) && ! in_array( $field, $retrieved, true ) ) {
					throw new ContractException( 'invalid_output', 'The answer makes an absence claim about a field that was not retrieved: ' . $field . '. Remove that unrelated claim. Missing from this evidence does not mean missing from the listing.' );
				}
			}
			if ( preg_match( '/step[ -]free[^.!?]{0,50}(?:not (?:published|listed|specified)|isn.t published)/iu', $sentence ) ) {
				throw new ContractException( 'invalid_output', 'The Accessible label does not establish whether step-free details are published elsewhere. Say that step-free access cannot be confirmed from this evidence, without claiming the listing omits it.' );
			}
		}
		if ( count( $subjects ) > 1 && preg_match( '/\b(?:all|both|every)\b[^.!?]{0,70}\baccessible\b|\b(?:found|are)\s+\d+[^.!?]{0,45}\baccessible\b/iu', $message ) && ! preg_match( '/\b(?:not all|not both|not every)\b/iu', $message ) ) {
			foreach ( $subjects as $values ) {
				$confirmed = false;
				foreach ( $values as $value ) {
					if ( is_array( $value ) && in_array( 'Accessible', $value, true ) ) {
						$confirmed = true; }
				}
				if ( ! $confirmed ) {
					throw new ContractException( 'invalid_output', 'Do not describe every result as Accessible. That published label is not confirmed for every returned listing. Distinguish confirmed matches from alternatives and unknown information.' );
				}
			}
		}
		if ( $units && 1 === count( array_unique( $units ) ) && preg_match_all( '/\bper\s+(?:meal|person|night|hour|day|week|month|entry|session|year)\b/iu', $message, $mentions, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $mentions[0] as [ $unit, $offset ] ) {
				$prefix         = substr( $message, max( 0, $offset - 20 ), min( 20, $offset ) );
				$history_prefix = substr( $message, max( 0, $offset - 70 ), min( 70, $offset ) );
				if ( false !== mb_stripos( $question, $unit ) && preg_match( '/\b(?:earlier|previous(?:ly)?|quoted|old)\b[^.!?]{0,55}$/iu', $history_prefix ) ) {
					continue;
				}
				if ( ! preg_match( '/\b(?:not|rather than|instead of)(?:\s+(?:[0-9.,$€£]+|USD|EUR|GBP)){0,2}\s*$/iu', $prefix ) && mb_strtolower( $unit ) !== mb_strtolower( $units[0] ) ) {
					throw new ContractException( 'invalid_output', 'The stated billing unit contradicts the current published unit. Use the supplied unit with the correct listing price.' );
				}
			}
		}
		// A broad Accessible checkbox is not evidence of a specific physical facility.
		if ( preg_match( '/(?:step.free (?:access|entrance) (?:is )?(?:confirmed|stated|available)|(?:confirmed|has|have|offers?|provides?) (?:a )?step.free|step.free access is stated)/iu', $message ) ) {
			$support = false;
			foreach ( $facts as $fact ) {
				$value = ( (array) $fact )['value'] ?? null;
				if ( preg_match( '/step[ -]free/iu', (string) json_encode( $value ) ) ) {
					$support = true; }
			}
			if ( ! $support ) {
				throw new ContractException( 'invalid_output', 'Accessible is only the published accessibility label. It does not confirm step-free entrances. Describe that label and keep specific facilities unconfirmed.' );
			}
		}
	}

	/** Amenities and available forms do not establish reservation requirements. */
	private static function assert_reservation_requirement( string $message, array $facts ): void {
		$subjects = array(); $titles = array(); $policies = array();
		foreach ( $facts as $fact ) {
			$fact = (array) $fact; $subject = $fact['subject'] ?? '';
			if ( ! preg_match( '/^listing:[1-9][0-9]*$/D', $subject ) ) { continue; }
			$subjects[ $subject ] = true; $value = $fact['value'] ?? null;
			if ( 'title' === ( $fact['field'] ?? null ) ) { if ( is_string( $value ) && mb_strlen( $value ) >= 3 ) { $titles[ $subject ] = $value; } continue; }
			if ( 'native_actions' === ( $fact['field'] ?? null ) || ( ( is_array( $value ) || is_object( $value ) ) && in_array( ( (array) $value )['state'] ?? null, array( 'unknown', 'missing' ), true ) ) ) { continue; }
			$descriptor = str_replace( array( '_', '-' ), ' ', ( $fact['field'] ?? '' ) . ' ' . ( $fact['label'] ?? '' ) );
			if ( is_bool( $value ) && ! self::reservation_mixed( $descriptor ) && null !== self::reservation_polarity( $descriptor ) ) {
				$meaning = self::reservation_polarity( $descriptor );
				// An explicit required=false is an exemption. An unchecked optional
				// flag alone does not establish the opposite mandatory requirement.
				if ( $value || true === $meaning ) { $policies[ $subject ][] = array( 'required' => $value ? $meaning : false, 'resources' => self::reservation_resources( $descriptor ) ); }
				continue;
			}
			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $text ) {
				if ( ! is_string( $text ) ) { continue; }
				foreach ( preg_split( '/[.!?]\\s+|;\\s*|\\R+|\\s+(?:but|however|men|däremot|pero|sin embargo)\\s+/iu', str_replace( '_', ' ', $text ) ) as $clause ) {
					$mode = self::reservation_polarity( $clause );
					if ( null !== $mode && ! self::reservation_mixed( $clause ) && ! self::reservation_uncertain( $clause ) ) { $policies[ $subject ][] = array( 'required' => $mode, 'resources' => self::reservation_resources( $descriptor . ' ' . $clause ) ); }
				}
			}
		}
		foreach ( preg_split( '/[.!?]\\s+|;\\s*|\\R+/u', $message ) as $sentence ) {
			$sentence_targets = array();
			foreach ( $titles as $subject => $title ) { if ( false !== mb_stripos( $sentence, $title ) ) { $sentence_targets[] = $subject; } }
		foreach ( preg_split( '/\\s+(?:but|however|men|däremot|pero|sin embargo)\\s+/iu', $sentence ) as $clause ) {
			$targets = array();
			foreach ( $titles as $subject => $title ) { if ( false !== mb_stripos( $clause, $title ) ) { $targets[] = $subject; $clause = str_ireplace( $title, 'the listing', $clause ); } }
			$mode = self::reservation_polarity( $clause );
			if ( self::reservation_mixed( $clause ) && ! self::reservation_uncertain( $clause ) ) { throw new ContractException( 'invalid_output', 'This combines opposite reservation claims without exact resource binding. State each reservation claim separately using its same-listing, same-resource public policy facts; amenities and form support cannot establish the rule.' ); }
			if ( null === $mode && ! self::reservation_uncertain( $clause ) && preg_match( '/\\b(?:bookings?|reservations?|bokning|bokningskrav|bokningsregler|solstolsbokning)\\b/iu', $clause ) && preg_match( '/\\b(?:not|no|isn.t|doesn.t|inte|ingen|inga)\\b[^.!?]{0,70}\\b(?:publish(?:ed)?|specif(?:y|ied)|list(?:ed)?|publicerad(?:e)?|publicerat|angiv(?:en|et|na))\\b/iu', $clause ) ) {
				throw new ContractException( 'invalid_output', 'Missing reservation-policy evidence does not establish that the policy is unpublished. State that the booking requirement cannot be verified from the retrieved facts; do not infer a requirement, exemption or publication absence from amenities.' );
			}
			if ( null === $mode || self::reservation_uncertain( $clause ) || preg_match( '/^\\s*(?:to (?:book|reserve)|for (?:a |your )?(?:booking|reservation)|för att (?:boka|reservera)|para reservar)\\b/iu', $clause ) ) { continue; }
			// A neutral quotation of a preference does not assert that it is met.
			if ( preg_match( '/^\\s*(?:you (?:want|asked for|prefer)|your (?:goal|request)|du (?:vill|söker|önskar)|quieres|buscas|prefieres|tu (?:objetivo|solicitud))\\b/iu', $clause ) && ! preg_match( '/\\b(?:so|therefore|choose|recommend|därför|valet|rekommenderar|passar|por eso|elige|recomiendo|opción)\\b/iu', $clause ) ) { continue; }
			$targets = $targets ?: ( $sentence_targets ?: array_keys( $subjects ) ); $resources = self::reservation_resources( $clause );
			if ( ! $targets ) { continue; }
			foreach ( $targets as $subject ) {
				$supported = false;
				foreach ( $policies[ $subject ] ?? array() as $policy ) { if ( $mode === $policy['required'] && ( $resources ? ! array_diff( $resources, $policy['resources'] ) : ! $policy['resources'] ) ) { $supported = true; break; } }
				if ( ! $supported ) { throw new ContractException( 'invalid_output', 'Amenities such as natural shade, showers or sunbeds do not establish whether booking is required or unnecessary. No explicit current policy supports this reservation claim for that same listing and resource. Keep the useful amenity recommendation. Omit an unasked booking inference; when asked, say the reservation requirement cannot be verified from the retrieved facts, without claiming it is unpublished. A form or general booking capability alone does not establish this rule.' ); }
			}
		}
		}
	}

	/** True means required, false means exempt, null means no requirement claim. */
	private static function reservation_polarity( string $text ): ?bool {
		if ( preg_match( '/\\bwithout (?:a )?(?:booking|reservation)\\b[^.!?]{0,60}\\b(?:cannot|can.t|not allowed)|\\butan (?:att boka|bokning)\\b[^.!?]{0,60}\\b(?:kan inte|får inte)\\b/iu', $text ) ) { return true; }
		if ( preg_match( self::RESERVATION_EXEMPT, $text ) ) { return false; }
		if ( preg_match( self::RESERVATION_REQUIRED, $text ) ) { return true; }
		return null;
	}

	/** Opposite unsplit resource policies grant neither direction to another resource. */
	private static function reservation_mixed( string $text ): bool {
		return preg_match( self::RESERVATION_EXEMPT, $text ) && preg_match( self::RESERVATION_REQUIRED, preg_replace( self::RESERVATION_EXEMPT, '', $text ) );
	}

	/** Explicit uncertainty is answerable without inventing either policy direction. */
	private static function reservation_uncertain( string $text ): bool {
		return (bool) preg_match( '/\\b(?:cannot|can.t|could not|do not|don.t) (?:confirm|verify|tell|know)|\\bnot (?:confirmed|verified|checked)|\\b(?:kan inte|går inte att) (?:bekräfta|verifiera|avgöra)|\\b(?:vet inte|inte (?:bekräftat|verifierat|kontrollerat))|\\b(?:no puedo|no se puede) (?:confirmar|verificar|saber)|\\b(?:no está (?:confirmado|verificado)|desconozco si)\\b/iu', $text );
	}

	/** A sunbed policy cannot grant a room/table/entry policy, or vice versa. */
	private static function reservation_resources( string $text ): array {
		$resources = array();
		foreach ( array( 'sunbed' => 'sunbeds?|solstol(?:ar|en)?|tumbonas?|hamacas?', 'umbrella' => 'umbrellas?|parasoll(?:er)?|sombrillas?', 'room' => 'rooms?|rum|habitaci[oó]n(?:es)?', 'table' => 'tables?|bord|restaurant|restaurang|mesas?|restaurante', 'entry' => 'entry|admission|inträde|entrada', 'parking' => 'parking|parkering|aparcamiento|estacionamiento', 'ticket' => 'tickets?|biljett(?:er)?|billetes?' ) as $id => $pattern ) { if ( preg_match( '/\\b(?:' . $pattern . ')\\b/iu', $text ) ) { $resources[] = $id; } }
		return $resources;
	}

	/** Distance is a query-relative calculation, never a publication-absence fact. */
	private static function assert_distance_context( string $message, array $facts, string $question ): void {
		$distance = '\\b(?:distances?|avstånd(?:et)?|dist[aâ]ncias?|entfernungen?|distanza|afstand)\\b';
		$requested = (bool) preg_match( '/' . $distance . '|\\b(?:how far|near(?:by)?|nearest|closest|radius|kilomet(?:er|re)s?|miles?|hur långt|nära|närmast|wie weit|qué tan lejos|quão longe)\\b/iu', $question );
		$known = false;
		$metric_pattern = '/\\b[0-9]+(?:[.,][0-9]+)?\\s*(?:km|kilomet(?:er|re)s?|miles?)\\b/iu';
		$published_metrics = array();
		$metric_key = static fn( string $value ): string => preg_replace( '/\\s+/u', '', mb_strtolower( str_replace( ',', '.', $value ) ) );
		foreach ( $facts as $fact ) {
			$fact = (array) $fact;
			if ( 'title' === ( $fact['field'] ?? null ) && is_string( $fact['value'] ?? null ) && mb_strlen( $fact['value'] ) >= 3 ) { $message = str_ireplace( $fact['value'], 'the listing', $message ); }
			if ( 'distance' === ( $fact['field'] ?? null ) && ( is_int( $fact['value'] ?? null ) || is_float( $fact['value'] ?? null ) ) && is_finite( (float) $fact['value'] ) && $fact['value'] >= 0 ) { $known = true; }
			// A literal landmark distance in public descriptive text is different
			// from the synthetic visitor-origin metric, and remains answerable.
			if ( 'title' !== ( $fact['field'] ?? null ) && is_string( $fact['value'] ?? null ) && preg_match_all( $metric_pattern, $fact['value'], $matches ) ) { foreach ( $matches[0] as $metric ) { $published_metrics[ $metric_key( $metric ) ] = true; } }
		}
		foreach ( preg_split( '/[.!?]\\s+|;\\s*|,\\s+|\\s+(?:and|but|och|men)\\s+/iu', $message ) as $sentence ) {
			$mentions = (bool) preg_match( '/' . $distance . '/iu', $sentence );
			if ( $mentions && preg_match( '/\\b(?:not|no|nothing|isn.t|aren.t|doesn.t|saknas|inte|ej|nicht|aucun|aucune)\\b[^.!?]{0,100}\\b(?:publish(?:ed)?|list(?:ed)?|provid(?:e|ed)|specif(?:y|ied)|angiv(?:et|en|na)|publicer(?:at|ad)|veröffentlicht|publi[ée]e?)\\b|\\b(?:unpublished|not listed)\\b/iu', $sentence ) ) {
				throw new ContractException( 'invalid_output', 'Distance is calculated by an origin-based search, not a published listing field. Never describe it as unpublished. Omit unrequested distance; if the visitor asks for it without a computed metric, ask for the starting location.' );
			}
			$unknown = $mentions && preg_match( '/\\b(?:unknown|unavailable|not available|not known|not calculated|not computed|not checked|cannot (?:calculate|verify)|can.t (?:calculate|verify)|okänt|okänd|inte beräknat|saknas)\\b/iu', $sentence );
			if ( ! $known && preg_match_all( $metric_pattern, $sentence, $reported ) ) { foreach ( $reported[0] as $metric ) { if ( ! isset( $published_metrics[ $metric_key( $metric ) ] ) ) { throw new ContractException( 'invalid_output', 'No current origin-based distance was computed. Do not invent a numeric distance. Request the starting location when distance is actually requested; otherwise omit it.' ); } } }
			if ( ! $requested && $unknown ) { throw new ContractException( 'invalid_output', 'Distance was not requested or computed. Omit this unrelated derived-metric uncertainty and answer the requested current listing facts.' ); }
		}
	}

	/**
	 * Normalize advertised choice text for literal matching.
	 *
	 * @param string $value Public choice text.
	 * @return string Normalized words.
	 */
	private static function words( string $value ): string {
		$value = mb_strtolower( str_replace( array( '-', '‑', '–' ), '', str_replace( '_', ' ', $value ) ) );
		return trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) );
	}
}
