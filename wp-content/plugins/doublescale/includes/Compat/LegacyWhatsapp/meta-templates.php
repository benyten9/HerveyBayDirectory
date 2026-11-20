<?php
/**
 * Stand-ins for free's former Meta template fetcher and saver, served only next
 * to a Pro that predates its own engine. See {@see \DoubleScale\Compat\LegacyWhatsapp}.
 *
 * Every older-Pro caller builds these inside try/catch( \Exception ), so the
 * constructor throws the "update Pro" message and the caller shows it.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Modules\Campaigns\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Compat\LegacyWhatsapp;

if ( ! class_exists( MetaTemplateFetcher::class, false ) ) {
	/**
	 * MetaTemplateFetcher.
	 */
	final class MetaTemplateFetcher {

		/**
		 * WhatsApp is off.
		 *
		 * @throws \Exception Always.
		 */
		public function __construct() {
			throw new \Exception( LegacyWhatsapp::message() );
		}

		/**
		 * Nothing cached.
		 *
		 * @return void
		 */
		public static function flush_cache(): void {}
	}
}

if ( ! class_exists( MetaTemplateSaver::class, false ) ) {
	/**
	 * MetaTemplateSaver.
	 */
	final class MetaTemplateSaver {

		/**
		 * WhatsApp is off.
		 *
		 * @throws \Exception Always.
		 */
		public function __construct() {
			throw new \Exception( LegacyWhatsapp::message() );
		}
	}
}
