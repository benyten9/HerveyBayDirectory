<?php
/**
 * A Pro field type while DoubleScale Pro isn't installed.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a Pro question in saved forms (so saving the form without Pro doesn't delete it),
 * takes no answer, and is left out of the public form. DoubleScale Pro registers the real
 * class under the same slug, which replaces this one.
 */
class ProField extends FieldType {

	/**
	 * Slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Name shown to managers.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Its answer is uploaded files.
	 *
	 * @var bool
	 */
	private $upload;

	/**
	 * A placeholder for a Pro type.
	 *
	 * @param string $slug   Type slug.
	 * @param string $name   Name shown to managers.
	 * @param bool   $upload Its answer is uploaded files.
	 */
	public function __construct( string $slug, string $name, bool $upload = false ) {
		$this->slug   = $slug;
		$this->name   = $name;
		$this->upload = $upload;
	}

	/** {@inheritDoc} */
	public function type(): string {
		return $this->slug;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return $this->name;
	}

	/** {@inheritDoc} */
	public function is_pro(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function is_upload(): bool {
		return $this->upload;
	}

	/** {@inheritDoc} */
	public function takes_default(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function saves_to_contact(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function saved_in_partial(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function in_timeline(): bool {
		return false;
	}

	/** {@inheritDoc} */
	public function sanitize( array $field, $value ) {
		return null;
	}
}
