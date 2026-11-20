<?php
/**
 * One search across the client portal: documents, projects, tasks, tickets,
 * bookings and files, each with the portal page that opens it.
 *
 * It searches through each area's own portal route, called for the signed-in
 * client, so it finds exactly what those pages list: the access gate, the
 * client's own rows, drafts left out — every rule they apply. A route that is
 * missing (module off, no Pro) or refuses (area hidden) adds nothing.
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use WP_REST_Request;

/**
 * PortalSearch.
 */
final class PortalSearch {

	/** Results shown per kind (the total says how many matched). */
	public const PER_GROUP = 5;

	/** Letters needed before searching. */
	public const MIN_LENGTH = 2;

	/** Projects whose tasks are searched (newest first). */
	private const TASK_PROJECTS = 20;

	private const NS = '/doublescale/v1';

	/**
	 * Groups of results, in the portal menu's order; kinds with none left out.
	 *
	 * @param ContactModel|null $contact The signed-in client.
	 * @param string            $query   What they typed.
	 * @return array<int, array{key:string, total:int, results:array<int, array<string, string>>}>
	 */
	public static function search( ?ContactModel $contact, string $query ): array {
		$query = trim( $query );
		if ( ! $contact || mb_strlen( $query ) < self::MIN_LENGTH ) {
			return array();
		}

		$projects = self::list( '/portal/projects', array(), 'data' );
		$found    = array(
			'documents' => self::documents( $query ),
			'projects'  => self::projects( $query, $projects ),
			'tasks'     => self::tasks( $query, $projects ),
			'tickets'   => self::tickets( $query, $contact ),
			'bookings'  => self::bookings( $query ),
			'files'     => self::files( $query ),
		);

		$groups = array();
		foreach ( $found as $key => $results ) {
			if ( $results ) {
				$groups[] = array(
					'key'     => $key,
					'total'   => count( $results ),
					'results' => array_slice( $results, 0, self::PER_GROUP ),
				);
			}
		}
		return $groups;
	}

	/**
	 * @param string $query Query.
	 * @return array<int, array<string, string>>
	 */
	private static function documents( string $query ): array {
		$results = array();
		foreach ( self::list( '/portal/documents', array( 'type' => 'all' ), 'data' ) as $row ) {
			$number  = (string) ( $row['number'] ?? '' );
			$subject = (string) ( $row['subject'] ?? '' );
			$type    = (string) ( $row['type'] ?? '' );
			$hash    = (string) ( $row['hash'] ?? '' );
			if ( '' === $hash || ! self::matches( $query, $number, $subject ) ) {
				continue;
			}
			$results[] = self::result( $type, '' !== $number ? $number : $subject, '' !== $number ? $subject : '', (string) ( $row['date'] ?? '' ), '/documents/' . str_replace( '_', '-', $type ) . '/' . $hash );
		}
		return $results;
	}

	/**
	 * @param string                           $query    Query.
	 * @param array<int, array<string, mixed>> $projects The client's projects.
	 * @return array<int, array<string, string>>
	 */
	private static function projects( string $query, array $projects ): array {
		$results = array();
		foreach ( $projects as $project ) {
			if ( self::matches( $query, (string) ( $project['title'] ?? '' ) ) ) {
				$results[] = self::result( 'project', (string) $project['title'], '', (string) ( $project['due_date'] ?? '' ), '/projects/' . (int) ( $project['id'] ?? 0 ) );
			}
		}
		return $results;
	}

	/**
	 * Tasks of the projects that share them with the client.
	 *
	 * @param string                           $query    Query.
	 * @param array<int, array<string, mixed>> $projects The client's projects.
	 * @return array<int, array<string, string>>
	 */
	private static function tasks( string $query, array $projects ): array {
		$results = array();
		foreach ( array_slice( $projects, 0, self::TASK_PROJECTS ) as $project ) {
			$id = (int) ( $project['id'] ?? 0 );
			foreach ( self::list( '/portal/projects/' . $id . '/tasks', array(), 'tasks' ) as $task ) {
				if ( self::matches( $query, (string) ( $task['title'] ?? '' ) ) ) {
					$results[] = self::result( 'task', (string) $task['title'], (string) ( $project['title'] ?? '' ), (string) ( $task['due_date'] ?? '' ), '/projects/' . $id . '/tasks/' . (int) ( $task['id'] ?? 0 ) );
				}
			}
		}
		return $results;
	}

	/**
	 * Tickets: their routes serve the separate support page too, so the
	 * portal's Support Tickets area is asked here.
	 *
	 * @param string       $query   Query.
	 * @param ContactModel $contact Client.
	 * @return array<int, array<string, string>>
	 */
	private static function tickets( string $query, ContactModel $contact ): array {
		if ( ! PortalAccess::allows( 'requests', $contact ) ) {
			return array();
		}
		$results = array();
		$rows    = self::list(
			'/support/portal/tickets',
			array(
				'search'   => $query,
				'per_page' => 50,
			),
			'data'
		);
		foreach ( $rows as $ticket ) {
			$results[] = self::result( 'ticket', (string) ( $ticket['title'] ?? '' ), '#' . (int) ( $ticket['id'] ?? 0 ), (string) ( $ticket['updated_at'] ?? '' ), '/tickets/' . (int) ( $ticket['id'] ?? 0 ) );
		}
		return $results;
	}

	/**
	 * @param string $query Query.
	 * @return array<int, array<string, string>>
	 */
	private static function bookings( string $query ): array {
		$results = array();
		foreach ( self::list( '/portal/bookings', array( 'filter' => 'all' ), 'data' ) as $booking ) {
			$name = (string) ( $booking['event']['name'] ?? '' );
			if ( self::matches( $query, $name ) ) {
				$results[] = self::result( 'booking', $name, '', (string) ( $booking['start_time'] ?? '' ), '/bookings/' . (int) ( $booking['id'] ?? 0 ) );
			}
		}
		return $results;
	}

	/**
	 * @param string $query Query.
	 * @return array<int, array<string, string>>
	 */
	private static function files( string $query ): array {
		$results = array();
		foreach ( self::list( '/portal/files', array(), 'items' ) as $file ) {
			$name = (string) ( $file['file_name'] ?? '' );
			if ( self::matches( $query, $name ) ) {
				$results[] = self::result( 'file', $name, '', (string) ( $file['created_at'] ?? '' ), '/files' );
			}
		}
		return $results;
	}

	/**
	 * The rows a portal route lists for the signed-in client; none when the
	 * route is missing or refuses.
	 *
	 * @param string               $route  Route under the namespace.
	 * @param array<string, mixed> $params Query.
	 * @param string               $key    Rows key in the response.
	 * @return array<int, array<string, mixed>>
	 */
	private static function list( string $route, array $params, string $key ): array {
		$request = new WP_REST_Request( 'GET', self::NS . $route );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$response = rest_do_request( $request );
		if ( $response->is_error() || 200 !== $response->get_status() ) {
			return array();
		}
		$data = $response->get_data();
		$rows = is_array( $data ) && isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? $data[ $key ] : array();
		return array_values( array_filter( $rows, 'is_array' ) );
	}

	/**
	 * @param string $query    Query.
	 * @param string ...$texts Texts to look in.
	 */
	private static function matches( string $query, string ...$texts ): bool {
		foreach ( $texts as $text ) {
			if ( '' !== $text && false !== mb_stripos( $text, $query ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private static function result( string $type, string $title, string $subtitle, string $date, string $route ): array {
		return array(
			'type'     => $type,
			'title'    => $title,
			'subtitle' => $subtitle,
			'date'     => $date,
			'route'    => $route,
		);
	}
}
