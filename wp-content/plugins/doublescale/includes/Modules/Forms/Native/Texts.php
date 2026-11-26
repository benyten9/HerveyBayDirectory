<?php
/**
 * Every text a visitor can see on a DoubleScale form, in one place.
 *
 * These are the defaults (translated by WordPress language packs). A form can reword any of
 * them in the builder (Form panel → Buttons and messages); its wording is stored in
 * form_schema.labels.messages and wins over the default here, in the browser and on the server.
 * The builder reads the same list (admin config "formTexts") for its preview and placeholders.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

defined( 'ABSPATH' ) || exit;

/**
 * Default visitor texts and the form's own wording on top.
 */
final class Texts {

	/** Validation messages a form can reword (FieldType::validate() and the browser's checks). */
	public const MESSAGE_KEYS = array( 'invalidEmail', 'invalidPhone', 'invalidNumber', 'invalidUrl', 'invalidDate', 'invalidRating', 'invalidChoice', 'invalidChoices', 'minNumber', 'maxNumber', 'minLength', 'maxLength', 'datePast', 'dateFuture', 'minChoicesOne', 'minChoices', 'maxChoicesOne', 'maxChoices' );

	/** Other texts a form can reword (buttons, hints, name and address boxes, file upload). */
	public const TEXT_KEYS = array( 'sending', 'error', 'step', 'navigation', 'press_enter', 'resumed', 'other', 'select', 'choose_many', 'chooseRange', 'chooseExactly', 'chooseUpTo', 'chooseAtLeast', 'first', 'last', 'address_1', 'address_2', 'city', 'state', 'zip', 'country', 'choose_file', 'uploading', 'remove', 'fileSize', 'fileCount' );

	/** Texts with a counted noun ("3 options", "2 files"): their wording depends on the number. */
	public const PLURAL_KEYS = array( 'minLength', 'maxLength', 'minChoices', 'maxChoices', 'fileCount' );

	/**
	 * Every key a form may reword.
	 *
	 * @return string[]
	 */
	public static function customizable(): array {
		return array_merge( self::MESSAGE_KEYS, self::TEXT_KEYS );
	}

	/**
	 * The default texts. Plural texts ("Choose at least %d options.") are worded for $count
	 * (the browser gets them for 1 and for 2; the server asks for the real number).
	 *
	 * @param int $count Number the plural texts are worded for.
	 * @return array<string, string>
	 */
	public static function defaults( int $count = 2 ): array {
		return array(
			'sending'        => __( 'Sending…', 'doublescale' ),
			'error'          => __( 'Something went wrong. Please try again.', 'doublescale' ),
			/* translators: 1: current step number, 2: number of steps */
			'step'           => __( 'Step %1$d of %2$d', 'doublescale' ),
			'other'          => __( 'Other', 'doublescale' ),
			'first'          => __( 'First name', 'doublescale' ),
			'last'           => __( 'Last name', 'doublescale' ),
			'address_1'      => __( 'Street address', 'doublescale' ),
			'address_2'      => __( 'Apartment, suite, etc.', 'doublescale' ),
			'city'           => __( 'City', 'doublescale' ),
			'state'          => __( 'State / Province', 'doublescale' ),
			'zip'            => __( 'ZIP / Postal code', 'doublescale' ),
			'country'        => __( 'Country', 'doublescale' ),
			'select'         => __( 'Select…', 'doublescale' ),
			'press_enter'    => __( 'press Enter ↵', 'doublescale' ),
			'ok'             => __( 'OK', 'doublescale' ),
			'choose_many'    => __( 'Choose as many as you like', 'doublescale' ),
			'navigation'     => __( 'Previous or next question', 'doublescale' ),
			'draft'          => __( 'Preview: this form is not published yet.', 'doublescale' ),
			'choose_file'    => __( 'Drop a file here or click to choose', 'doublescale' ),
			'uploading'      => __( 'Uploading…', 'doublescale' ),
			'remove'         => __( 'Remove', 'doublescale' ),
			'resumed'        => __( 'Welcome back! We kept your answers.', 'doublescale' ),
			// Validation messages.
			'invalidEmail'   => __( 'Enter a valid email address.', 'doublescale' ),
			'invalidPhone'   => __( 'Enter a valid phone number.', 'doublescale' ),
			'invalidNumber'  => __( 'Enter a number.', 'doublescale' ),
			'invalidUrl'     => __( 'Enter a valid web address.', 'doublescale' ),
			'invalidDate'    => __( 'Enter a valid date.', 'doublescale' ),
			'invalidRating'  => __( 'Choose a rating.', 'doublescale' ),
			'invalidChoice'  => __( 'Choose one of the options.', 'doublescale' ),
			'invalidChoices' => __( 'Choose from the options.', 'doublescale' ),
			/* translators: %s: smallest allowed number */
			'minNumber'      => __( 'Enter %s or more.', 'doublescale' ),
			/* translators: %s: largest allowed number */
			'maxNumber'      => __( 'Enter %s or less.', 'doublescale' ),
			'datePast'       => __( 'Choose a date in the past.', 'doublescale' ),
			'dateFuture'     => __( 'Choose a date in the future.', 'doublescale' ),
			/* translators: %d: smallest number of choices */
			'minChoicesOne'  => _n( 'Choose at least %d option.', 'Choose at least %d options.', 1, 'doublescale' ),
			/* translators: %d: largest number of choices */
			'maxChoicesOne'  => _n( 'Choose at most %d option.', 'Choose at most %d options.', 1, 'doublescale' ),
			// Hints under a field.
			/* translators: 1: fewest choices, 2: most choices */
			'chooseRange'    => __( 'Choose %1$d–%2$d', 'doublescale' ),
			/* translators: %d: number of choices */
			'chooseExactly'  => __( 'Choose %d', 'doublescale' ),
			/* translators: %d: most choices */
			'chooseUpTo'     => __( 'Choose up to %d', 'doublescale' ),
			/* translators: %d: fewest choices */
			'chooseAtLeast'  => __( 'Choose at least %d', 'doublescale' ),
			/* translators: %s: largest file size in MB */
			'fileSize'       => __( 'up to %s MB', 'doublescale' ),
		) + self::plurals( $count );
	}

	/**
	 * The texts with a counted noun, worded for one number (languages have up to six forms).
	 *
	 * @param int $count The number.
	 * @return array<string, string>
	 */
	public static function plurals( int $count ): array {
		return array(
			/* translators: %d: smallest number of characters */
			'minLength'  => _n( 'Enter at least %d character.', 'Enter at least %d characters.', $count, 'doublescale' ),
			/* translators: %d: largest number of characters */
			'maxLength'  => _n( 'Enter at most %d character.', 'Enter at most %d characters.', $count, 'doublescale' ),
			/* translators: %d: smallest number of choices */
			'minChoices' => _n( 'Choose at least %d option.', 'Choose at least %d options.', $count, 'doublescale' ),
			/* translators: %d: largest number of choices */
			'maxChoices' => _n( 'Choose at most %d option.', 'Choose at most %d options.', $count, 'doublescale' ),
			/* translators: %d: most files */
			'fileCount'  => _n( 'max %d file', 'max %d files', $count, 'doublescale' ),
		);
	}

	/**
	 * The default texts for the browser, plus the counted texts worded for each number given,
	 * as "key#number" (the renderer picks "minChoices#3" over "minChoices" for 3).
	 *
	 * @param int[] $numbers Numbers the form can put in its texts.
	 * @return array<string, string>
	 */
	public static function for_browser( array $numbers ): array {
		$texts = self::defaults();
		foreach ( array_unique( array_map( 'intval', $numbers ) ) as $number ) {
			foreach ( self::plurals( $number ) as $key => $text ) {
				$texts[ $key . '#' . $number ] = $text;
			}
		}
		return $texts;
	}

	/**
	 * The numbers a form's texts can show: its fields' limits (choices, characters, files).
	 *
	 * @param array $schema Normalized form schema.
	 * @return int[]
	 */
	public static function numbers_in( array $schema ): array {
		$numbers = array();
		foreach ( (array) ( $schema['steps'] ?? array() ) as $step ) {
			foreach ( (array) ( $step['fields'] ?? array() ) as $field ) {
				$limits = array_merge( array_values( (array) ( $field['validation'] ?? array() ) ), array( $field['props']['max_files'] ?? null ) );
				foreach ( $limits as $limit ) {
					if ( is_numeric( $limit ) && (int) $limit == $limit && $limit >= 0 && $limit <= 100000 ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- 3.0 counts as 3.
						$numbers[] = (int) $limit;
					}
				}
			}
		}
		return array_values( array_unique( $numbers ) );
	}

	/**
	 * A form's own wording: known keys only, plain text, 200 characters at most.
	 *
	 * @param mixed $raw Raw labels.messages.
	 * @return array<string, string>
	 */
	public static function clean( $raw ): array {
		$out = array();
		foreach ( self::customizable() as $key ) {
			$value = is_array( $raw ) && isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
			if ( '' !== $value ) {
				$out[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200 ) : substr( $value, 0, 200 );
			}
		}
		return $out;
	}

	/**
	 * One text: the form's own wording when it has one, else the default, with %d / %s / %1$d
	 * filled in. Placeholders are replaced as text, so a custom text with a stray "%" (or too
	 * many placeholders) can never break. The first number also picks the plural default.
	 *
	 * @param array  $custom Form's own wording by key.
	 * @param string $key    Text key.
	 * @param mixed  ...$args Values for the placeholders, in order.
	 * @return string
	 */
	public static function say( array $custom, string $key, ...$args ): string {
		$own    = isset( $custom[ $key ] ) && is_string( $custom[ $key ] ) ? trim( $custom[ $key ] ) : '';
		$count  = isset( $args[0] ) && is_int( $args[0] ) ? $args[0] : 2;
		$format = '' !== $own ? $own : ( self::defaults( $count )[ $key ] ?? '' );
		$next   = 0;
		return (string) preg_replace_callback(
			'/%(?:(\d+)\$)?[sd]/',
			static function ( $match ) use ( $args, &$next ) {
				$value = ! empty( $match[1] ) ? ( $args[ (int) $match[1] - 1 ] ?? '' ) : ( $args[ $next++ ] ?? '' );
				return (string) $value;
			},
			$format
		);
	}
}
