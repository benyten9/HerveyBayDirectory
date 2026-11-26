<?php
/**
 * Abuse and cost limits on the customers' help assistant.
 *
 *   visitor()        per_minute and per_hour per visitor — the address free's
 *                    ClientIp gives (the trusted proxy header's when the owner
 *                    set one; IPv6 by /64).
 *   conversation()   per_conversation questions in one chat.
 *   daily()          daily questions that reach the model: per visitor (a
 *                    tenth of the day, at least 5), then the whole site.
 *   acquire()        one of `concurrent` slots while an answer is written: each
 *                    answer holds a PHP worker for 10-30 seconds, and a shared
 *                    host runs out of workers after a handful.
 *
 * Every counter is free's atomic RateLimiter. A cap reached is a WP_Error
 * whose data carries `handoff: true` where support is the better next step.
 * A count that could not be taken (the counter table failed) refuses too:
 * every question costs money, so the limits never fail open.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use WP_Error;

/**
 * ChatGuard class.
 */
final class ChatGuard {

	/** Live chat flood guard: messages per visitor to the team. */
	public const TEAM_PER_MINUTE = 30;
	public const TEAM_PER_HOUR   = 600;


	/**
	 * RateLimiter pool of answers being written.
	 */
	public const SLOT_POOL = 'help_chat';

	/**
	 * Seconds before a slot left by a request that died frees itself.
	 */
	private const SLOT_LEASE = 180;

	/**
	 * @var array<string, int>
	 */
	private $limits;

	/**
	 * @param array<string, int> $limits HelpSettings limits.
	 */
	public function __construct( array $limits ) {
		$this->limits = $limits;
	}

	/**
	 * @return self
	 */
	public static function from_settings(): self {
		return new self( HelpSettings::get()['limits'] );
	}

	/**
	 * Whether free ships the counters this guard needs.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( RateLimiter::class ) && class_exists( ClientIp::class ) && RateLimiter::ready();
	}

	/**
	 * Count one question from this visitor.
	 *
	 * @return WP_Error|null
	 */
	public function visitor(): ?WP_Error {
		$visitor = ClientIp::bucket();
		$minute  = RateLimiter::hit( 'help_min|' . $visitor, MINUTE_IN_SECONDS );
		$hour    = RateLimiter::hit( 'help_hour|' . $visitor, HOUR_IN_SECONDS );

		if ( $minute < 1 || $hour < 1 ) {
			return self::unavailable();
		}
		if ( $minute > $this->limits['per_minute'] || $hour > $this->limits['per_hour'] ) {
			return new WP_Error(
				'help_rate_limited',
				__( 'You are asking a little too fast. Please wait a moment and try again.', 'doublescale' ),
				array( 'status' => 429 )
			);
		}

		return null;
	}

	/**
	 * Messages to the team in a live chat: a fixed flood guard (not the AI
	 * limits the owner sets), generous enough for any real conversation.
	 *
	 * @return WP_Error|null
	 */
	public static function team_flood(): ?WP_Error {
		$visitor = ClientIp::bucket();
		$minute  = RateLimiter::hit( 'help_team_min|' . $visitor, MINUTE_IN_SECONDS );
		$hour    = RateLimiter::hit( 'help_team_hour|' . $visitor, HOUR_IN_SECONDS );

		if ( $minute < 1 || $hour < 1 ) {
			return self::unavailable();
		}
		if ( $minute > self::TEAM_PER_MINUTE || $hour > self::TEAM_PER_HOUR ) {
			return new WP_Error(
				'help_rate_limited',
				__( 'You are sending messages a little too fast. Please wait a moment and try again.', 'doublescale' ),
				array( 'status' => 429 )
			);
		}

		return null;
	}

	/**
	 * @param int $asked Questions already asked in the conversation.
	 * @return WP_Error|null
	 */
	public function conversation( int $asked ): ?WP_Error {
		if ( $asked < $this->limits['per_conversation'] ) {
			return null;
		}

		return new WP_Error(
			'help_conversation_full',
			__( 'This conversation is full. Start a new one, or contact our support team.', 'doublescale' ),
			array(
				'status'  => 429,
				'handoff' => true,
			)
		);
	}

	/**
	 * Count one question against the site's daily cap.
	 *
	 * @return WP_Error|null
	 */
	public function daily(): ?WP_Error {
		// One visitor's share first: a single address asking all day would
		// otherwise spend every visitor's questions (and the owner's money).
		$mine = RateLimiter::hit( 'help_vday|' . ClientIp::bucket() . '|' . gmdate( 'Ymd' ), DAY_IN_SECONDS + HOUR_IN_SECONDS );
		if ( $mine < 1 ) {
			return self::unavailable();
		}
		if ( $mine > self::visitor_daily_cap( (int) $this->limits['daily'] ) ) {
			return new WP_Error(
				'help_visitor_daily_limit',
				__( 'You have asked the assistant a lot today. Our support team can help you instead.', 'doublescale' ),
				array(
					'status'  => 429,
					'handoff' => true,
				)
			);
		}

		$today = RateLimiter::hit( 'help_day|' . gmdate( 'Ymd' ), DAY_IN_SECONDS + HOUR_IN_SECONDS );
		if ( $today < 1 ) {
			return self::unavailable();
		}
		if ( $today <= $this->limits['daily'] ) {
			return null;
		}

		return new WP_Error(
			'help_daily_limit',
			__( 'The assistant has answered all the questions it can for today. Our support team can help you instead.', 'doublescale' ),
			array(
				'status'  => 429,
				'handoff' => true,
			)
		);
	}

	/**
	 * Questions one visitor may have answered by the model in a day: a tenth
	 * of the site's day, at least 5 (never more than the day itself).
	 *
	 * @param int $daily The site's daily cap.
	 * @return int
	 */
	public static function visitor_daily_cap( int $daily ): int {
		return min( max( 1, $daily ), max( 5, intdiv( $daily, 10 ) ) );
	}

	/**
	 * Take an answer slot.
	 *
	 * @return string|WP_Error Token for release().
	 */
	public function acquire() {
		$token = RateLimiter::acquire( self::SLOT_POOL, $this->limits['concurrent'], self::SLOT_LEASE );
		if ( null !== $token ) {
			return $token;
		}

		return new WP_Error(
			'help_busy',
			__( 'The assistant is busy right now. Please try again in a minute.', 'doublescale' ),
			array(
				'status'  => 503,
				'handoff' => true,
			)
		);
	}

	/**
	 * The assistant cannot answer now (a counter or the AI provider failed).
	 * Never carries the underlying reason: a visitor must not read a
	 * provider's error text or the state of the site's AI account.
	 *
	 * @return WP_Error
	 */
	public static function unavailable(): WP_Error {
		return new WP_Error(
			'help_unavailable',
			__( 'The assistant cannot answer right now. Our support team can help you instead.', 'doublescale' ),
			array(
				'status'  => 503,
				'handoff' => true,
			)
		);
	}

	/**
	 * @param string $token From acquire().
	 * @return void
	 */
	public function release( string $token ): void {
		RateLimiter::release( self::SLOT_POOL, $token );
	}
}
