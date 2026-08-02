<?php
/**
 * Class Slack Remote Data
 *
 * This class is responsible for handling the Slack Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Slack;

use DoubleScale\Pro\Modules\Integrations\Abstracts\IntegrationRemoteData;

/**
 * Slack Remote Data class
 */
class RemoteData extends IntegrationRemoteData {

	/**
	 * Entities.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $entities = array(
		'user'          => array(
			'callback' => 'fetch_user',
		),
		'conversations' => array(
			'callback' => 'fetch_conversations',
		),
	);

	/**
	 * Fetch user.
	 *
	 * @param string $user_id User ID.
	 * @return array
	 */
	public function fetch_user( $user_id ) {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}
		$response = $api->get_user( $user_id );

		return $response;
	}

	/**
	 * Fetch conversations.
	 *
	 * @return array
	 */
	public function fetch_conversations() {
		/** @var Api $api */
		$api = $this->integration->connect();
		if ( ! $api ) {
			return array();
		}

		$cursor = null;

		$response = $api->get_conversations(
			array(
				'cursor' => $cursor,
				'limit'  => 200,
				'types'  => 'public_channel,private_channel,mpim,im',
			)
		);
		$result   = array();

		if ( ! $response['success'] ) {
			return $result;
		}

		foreach ( $response['data']['channels'] as $channel ) {
			if ( ! isset( $channel['name'] ) ) {
				continue;
			}

			$result[] = array(
				'value' => $channel['id'],
				'label' => $channel['name'],
			);
		}

		return $result;
	}
}
