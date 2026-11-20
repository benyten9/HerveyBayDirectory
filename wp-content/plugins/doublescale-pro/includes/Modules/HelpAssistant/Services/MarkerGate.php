<?php
/**
 * Hold a streamed answer back until it is clear it does not start with the
 * "no answer" marker, then pass everything through.
 *
 * The marker can arrive split across chunks ("[[NO_", "ANSWER]]"), so text is
 * buffered while it is still a possible beginning of the marker. Once the
 * buffer can no longer be the marker it is released and later chunks stream
 * straight through; if it IS the marker, nothing ever reaches the reader.
 *
 * Pure PHP, unit-tested without WordPress.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

/**
 * MarkerGate class.
 */
final class MarkerGate {

	/** @var string */
	private $marker;

	/** @var callable|null */
	private $on_delta;

	/** @var string */
	private $buffer = '';

	/** @var bool */
	private $released = false;

	/** @var bool */
	private $matched = false;

	/**
	 * After release: text held back in case it is the start of a marker
	 * written mid-answer.
	 *
	 * @var string
	 */
	private $tail = '';

	/**
	 * @param string        $marker   Marker text.
	 * @param callable|null $on_delta Receives text that may be shown.
	 */
	public function __construct( string $marker, ?callable $on_delta ) {
		$this->marker   = $marker;
		$this->on_delta = $on_delta;
	}

	/**
	 * One streamed chunk.
	 *
	 * @param string $delta Chunk.
	 * @return void
	 */
	public function push( $delta ): void {
		$delta = (string) $delta;
		if ( $this->matched ) {
			return;
		}
		if ( $this->released ) {
			$this->pass_through( $delta );
			return;
		}

		$this->buffer .= $delta;
		$head          = ltrim( $this->buffer );

		if ( '' === $head ) {
			return;
		}
		if ( 0 === strpos( $head, $this->marker ) ) {
			$this->matched = true;
			return;
		}
		if ( 0 === strpos( $this->marker, $head ) ) {
			// Still a possible beginning of the marker: keep waiting.
			return;
		}

		$this->released = true;
		$text           = $this->buffer;
		$this->buffer   = '';
		$this->pass_through( $text );
	}

	/**
	 * Stream released text, removing the marker wherever it shows up, even
	 * split across chunks: the last few characters wait for the next chunk.
	 *
	 * @param string $text Text.
	 * @return void
	 */
	private function pass_through( string $text ): void {
		$this->tail = str_replace( $this->marker, '', $this->tail . $text );
		$keep       = mb_strlen( $this->marker, 'UTF-8' ) - 1;
		$length     = mb_strlen( $this->tail, 'UTF-8' );
		if ( $length <= $keep ) {
			return;
		}
		$this->emit( mb_substr( $this->tail, 0, $length - $keep, 'UTF-8' ) );
		$this->tail = mb_substr( $this->tail, $length - $keep, null, 'UTF-8' );
	}

	/**
	 * End of stream. Releases a short answer still held back.
	 *
	 * @return bool True when the answer was the marker.
	 */
	public function finish(): bool {
		if ( ! $this->matched && ! $this->released && '' !== trim( $this->buffer ) ) {
			$this->released = true;
			$this->emit( $this->buffer );
			$this->buffer = '';
		}
		if ( '' !== $this->tail ) {
			$this->emit( str_replace( $this->marker, '', $this->tail ) );
			$this->tail = '';
		}

		return $this->matched;
	}

	/**
	 * Whether any text reached the reader.
	 *
	 * @return bool
	 */
	public function released(): bool {
		return $this->released;
	}

	/**
	 * @param string $text Text.
	 * @return void
	 */
	private function emit( string $text ): void {
		if ( '' !== $text && null !== $this->on_delta ) {
			call_user_func( $this->on_delta, $text );
		}
	}
}
