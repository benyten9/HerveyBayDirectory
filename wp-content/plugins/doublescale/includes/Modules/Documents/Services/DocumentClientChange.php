<?php
/**
 * A sales document handed to another client stops opening for the first one.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Models\InvoiceModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

/**
 * The team sent a document to the wrong person and fixes its client: the
 * public link it carried was the first client's way in (pay, accept, sign),
 * so the document gets a new one, and `doublescale_document_client_changed`
 * lets the portal drop the first client's notifications about it.
 *
 * Every save goes through the model, so one watcher covers the edit form, a
 * bulk change and an automation. Pro's contracts and credit notes carry the
 * same link and portal route; they are watched when Pro is loaded.
 */
final class DocumentClientChange {

	/** Document model => its portal route segment (`/documents/{segment}/{hash}`). */
	private const MODELS = array(
		InvoiceModel::class                                         => 'invoice',
		ProposalModel::class                                        => 'proposal',
		'DoubleScale\Pro\Modules\Contracts\Models\ContractModel'    => 'contract',
		'DoubleScale\Pro\Modules\CreditNotes\Models\CreditNoteModel' => 'credit-note',
	);

	/** @var bool */
	private static $watching = false;

	/**
	 * Watch the documents once Pro's classes can load.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( did_action( 'init' ) ) {
			self::watch();
			return;
		}
		add_action( 'init', array( self::class, 'watch' ), 1 );
	}

	/**
	 * @return void
	 */
	public static function watch(): void {
		if ( self::$watching ) {
			return;
		}
		self::$watching = true;

		foreach ( self::MODELS as $class => $segment ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$class::updating(
				static function ( $document ) use ( $segment ) {
					self::on_updating( $document, $segment );
				}
			);
		}
	}

	/**
	 * A new client: a new link, and the first client forgets the old one.
	 *
	 * @param object $document Document being saved.
	 * @param string $segment  Portal route segment.
	 * @return void
	 */
	public static function on_updating( $document, string $segment ): void {
		if ( ! $document->isDirty( 'contact_id' ) ) {
			return;
		}
		$old_contact = (int) $document->getOriginal( 'contact_id' );
		if ( $old_contact <= 0 || (int) $document->contact_id === $old_contact ) {
			return;
		}

		$old_hash       = (string) $document->getOriginal( 'hash' );
		$document->hash = self::new_hash();

		if ( '' !== $old_hash ) {
			/**
			 * A document's client changed: its old link no longer opens it.
			 *
			 * @param string $segment     Portal route segment (invoice, proposal, contract, credit-note).
			 * @param int    $old_contact The client who had it.
			 * @param string $old_hash    The link they had.
			 * @param object $document    The document.
			 */
			do_action( 'doublescale_document_client_changed', $segment, $old_contact, $old_hash, $document );
		}
	}

	/**
	 * The same kind of link the models make.
	 *
	 * @return string
	 */
	private static function new_hash(): string {
		try {
			return md5( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}
