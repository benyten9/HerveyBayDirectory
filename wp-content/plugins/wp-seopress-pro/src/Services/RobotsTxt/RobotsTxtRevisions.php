<?php

namespace SEOPressPro\Services\RobotsTxt;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

/**
 * Stores, lists and restores snapshots (revisions) of the custom robots.txt
 * content edited from the SEOPress PRO settings.
 *
 * Two independent histories are kept:
 *  - `site`    : the per-site robots.txt (`seopress_robots_file` in
 *                `seopress_pro_option_name`).
 *  - `network` : the network robots.txt used on subdomain multisite installs
 *                (`seopress_mu_robots_file` in `seopress_pro_mu_option_name`,
 *                stored on the main network blog).
 *
 * @since 10.0.0
 */
class RobotsTxtRevisions {

	/**
	 * Site (single install / per-site) history.
	 */
	const TYPE_SITE = 'site';

	/**
	 * Network-wide history (subdomain multisite).
	 */
	const TYPE_NETWORK = 'network';

	/**
	 * Option name holding the per-site revisions history.
	 */
	const OPTION_SITE = 'seopress_robots_revisions';

	/**
	 * Option name holding the network revisions history.
	 */
	const OPTION_NETWORK = 'seopress_mu_robots_revisions';

	/**
	 * Default number of revisions kept per history before the oldest ones are
	 * pruned. Override with the `seopress_robots_revisions_max` filter.
	 */
	const DEFAULT_MAX = 30;

	/**
	 * Maximum number of revisions to keep for a given history.
	 *
	 * @param string $type Self::TYPE_* constant.
	 *
	 * @return int Always >= 1.
	 */
	public function getMax( $type = self::TYPE_SITE ) {
		/**
		 * Filter the number of robots.txt revisions kept in the history.
		 *
		 * @param int    $max  Maximum revisions to keep.
		 * @param string $type Self::TYPE_* history identifier.
		 */
		$max = (int) apply_filters( 'seopress_robots_revisions_max', self::DEFAULT_MAX, $type );

		return max( 1, $max );
	}

	/**
	 * Return the whole history for a type, newest first.
	 *
	 * @param string $type Self::TYPE_* constant.
	 *
	 * @return array<int,array{id:int,content:string,author:int,time:int}>
	 */
	public function all( $type = self::TYPE_SITE ) {
		$revisions = $this->read( $type );

		usort(
			$revisions,
			function ( $a, $b ) {
				return $b['id'] - $a['id'];
			}
		);

		return $revisions;
	}

	/**
	 * Return a single revision by id, or null when not found.
	 *
	 * @param int    $id   Revision id.
	 * @param string $type Self::TYPE_* constant.
	 *
	 * @return array{id:int,content:string,author:int,time:int}|null
	 */
	public function get( $id, $type = self::TYPE_SITE ) {
		$id = (int) $id;

		foreach ( $this->read( $type ) as $revision ) {
			if ( $id === (int) $revision['id'] ) {
				return $revision;
			}
		}

		return null;
	}

	/**
	 * Record a new revision when the content actually changed compared to the
	 * most recent stored revision. No-op saves are ignored so the history only
	 * holds meaningful states.
	 *
	 * @param string $content The robots.txt content being saved.
	 * @param string $type    Self::TYPE_* constant.
	 *
	 * @return bool True when a revision was added.
	 */
	public function record( $content, $type = self::TYPE_SITE ) {
		$content = (string) $content;

		$revisions = $this->read( $type );

		$latest = $this->latest( $revisions );
		if ( null !== $latest && $latest['content'] === $content ) {
			return false;
		}

		$revisions[] = array(
			'id'      => $this->nextId( $revisions ),
			'content' => $content,
			'author'  => get_current_user_id(),
			'time'    => time(),
		);

		$this->write( $type, $this->prune( $revisions, $this->getMax( $type ) ) );

		return true;
	}

	/**
	 * Restore a stored revision as the live robots.txt content.
	 *
	 * Saving the option goes through the normal update flow, so a fresh
	 * revision capturing the restored state is recorded by the save hook and
	 * caches are purged as usual.
	 *
	 * @param int    $id   Revision id to restore.
	 * @param string $type Self::TYPE_* constant.
	 *
	 * @return bool True on success.
	 */
	public function restore( $id, $type = self::TYPE_SITE ) {
		$revision = $this->get( $id, $type );
		if ( null === $revision ) {
			return false;
		}

		if ( self::TYPE_NETWORK === $type ) {
			$network = function_exists( 'get_network' ) ? get_network() : null;
			if ( null === $network ) {
				return false;
			}

			$option   = get_blog_option( $network->site_id, 'seopress_pro_mu_option_name' );
			$option   = is_array( $option ) ? $option : array();
			$option['seopress_mu_robots_file'] = $revision['content'];

			return (bool) update_blog_option( $network->site_id, 'seopress_pro_mu_option_name', $option );
		}

		$option = get_option( 'seopress_pro_option_name' );
		$option = is_array( $option ) ? $option : array();
		$option['seopress_robots_file'] = $revision['content'];

		return (bool) update_option( 'seopress_pro_option_name', $option );
	}

	/**
	 * Most recent revision in a (chronological, by id) list, or null.
	 *
	 * @param array $revisions Raw revisions list.
	 *
	 * @return array|null
	 */
	protected function latest( array $revisions ) {
		$latest = null;
		foreach ( $revisions as $revision ) {
			if ( null === $latest || (int) $revision['id'] > (int) $latest['id'] ) {
				$latest = $revision;
			}
		}

		return $latest;
	}

	/**
	 * Next monotonic revision id.
	 *
	 * @param array $revisions Raw revisions list.
	 *
	 * @return int
	 */
	protected function nextId( array $revisions ) {
		$max = 0;
		foreach ( $revisions as $revision ) {
			$max = max( $max, (int) $revision['id'] );
		}

		return $max + 1;
	}

	/**
	 * Keep only the most recent $max revisions (by id).
	 *
	 * @param array $revisions Raw revisions list.
	 * @param int   $max       Maximum to keep.
	 *
	 * @return array
	 */
	protected function prune( array $revisions, $max ) {
		if ( count( $revisions ) <= $max ) {
			return $revisions;
		}

		usort(
			$revisions,
			function ( $a, $b ) {
				return (int) $a['id'] - (int) $b['id'];
			}
		);

		return array_slice( $revisions, -$max );
	}

	/**
	 * Read the raw revisions list from storage.
	 *
	 * @param string $type Self::TYPE_* constant.
	 *
	 * @return array
	 */
	protected function read( $type ) {
		if ( self::TYPE_NETWORK === $type ) {
			$network = function_exists( 'get_network' ) ? get_network() : null;
			if ( null === $network ) {
				return array();
			}

			$data = get_blog_option( $network->site_id, self::OPTION_NETWORK, array() );
		} else {
			$data = get_option( self::OPTION_SITE, array() );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist the raw revisions list.
	 *
	 * @param string $type      Self::TYPE_* constant.
	 * @param array  $revisions Raw revisions list.
	 *
	 * @return void
	 */
	protected function write( $type, array $revisions ) {
		if ( self::TYPE_NETWORK === $type ) {
			$network = function_exists( 'get_network' ) ? get_network() : null;
			if ( null === $network ) {
				return;
			}

			// Keep the history off the autoloaded option set on the main
			// network blog. update_blog_option() has no autoload parameter, so
			// switch context and pass autoload = false explicitly (mirrors the
			// single-site branch below); otherwise the option defaults to
			// autoload on creation and is loaded on every main-site request.
			switch_to_blog( $network->site_id );
			update_option( self::OPTION_NETWORK, $revisions, false );
			restore_current_blog();

			return;
		}

		update_option( self::OPTION_SITE, $revisions, false );
	}
}
