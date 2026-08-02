<?php
/**
 * App class.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Providers\Outlook;

defined( 'ABSPATH' ) || exit;

/**
 * App class.
 *
 * @since 1.0.0
 */
class App {

	/**
	 * Provider
	 *
	 * @var Outlook
	 */
	protected $provider;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Outlook $provider Provider.
	 */
	public function __construct( $provider ) {
		$this->provider = $provider;

		add_action( 'admin_init', array( $this, 'maybe_authorize' ) );
		add_action( 'admin_init', array( $this, 'maybe_add_account' ) );
	}

	/**
	 * Redirect the user to authorization page
	 *
	 * @return void
	 */
	public function maybe_authorize() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public OAuth entry point; only fires when an admin clicks "Authorize" from the Outlook settings screen.
		$action = isset( $_GET['smtp-outlook'] ) ? sanitize_text_field( wp_unslash( $_GET['smtp-outlook'] ) ) : null;
		if ( $action !== 'authorize' ) {
			return;
		}

		$app_credentials = $this->get_app_credentials();

		if ( empty( $app_credentials ) ) {
			echo esc_html__( 'Cannot find app credentials!', 'doublescale' );
			exit;
		}

		$auth_url = add_query_arg(
			array(
				'response_type' => 'code',
				'access_type'   => 'offline',
				'client_id'     => $app_credentials['client_id'],
				'redirect_uri'  => $this->get_redirect_uri(),
				'state'         => 'smtp-outlook',
				'scope'         => 'openid profile offline_access User.Read Mail.ReadWrite Mail.Send',
			),
			'https://login.microsoftonline.com/common/oauth2/v2.0/authorize'
		);
		\doublescale_safe_redirect( $auth_url );
	}

	/**
	 * Add account after authorization
	 *
	 * @return void
	 */
	public function maybe_add_account() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback URL hit by Microsoft; `state` is the OAuth CSRF token validated below.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( $state !== 'smtp-outlook' ) {
			return;
		}

		// ensure authorize code.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( empty( $code ) ) {
			echo esc_html__( 'Error, There is no authorize code passed!', 'doublescale' );
			exit;
		}

		$app_credentials = $this->get_app_credentials();

		if ( empty( $app_credentials ) ) {
			echo esc_html__( 'Cannot find app credentials!', 'doublescale' );
			exit;
		}

		// get account tokens.
		$tokens = $this->get_tokens(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $app_credentials['client_id'],
				'client_secret' => $app_credentials['client_secret'],
				'redirect_uri'  => $this->get_redirect_uri(),
			)
		);

		if ( empty( $tokens ) ) {
			echo esc_html__( 'Error, Cannot get account tokens!', 'doublescale' );
			exit;
		}

		// get account details.
		$account_api       = new Account_API( $this, '', array( 'credentials' => $tokens ) );
		$accounts_response = $account_api->get_profile();

		if ( is_wp_error( $accounts_response ) ) {
			echo esc_html__( 'Error, Cannot get profile details!', 'doublescale' );
			exit;
		}

		$account      = $accounts_response;
		$account_name = $account->mail;
		$account_id   = str_replace( '@outlook.com', '', $account->displayName );

		$account_data = array(
			'name'        => $account_name,
			'credentials' => $tokens,
		);

		// check account existence.
		if ( in_array( $account_id, array_keys( $this->provider->accounts->get_accounts() ), true ) ) {
			$result = $this->provider->accounts->update_account( $account_id, $account_data );
			if ( empty( $result ) || is_wp_error( $result ) ) {
				echo esc_html__( 'Error, Cannot update the account!', 'doublescale' );
				exit;
			}
		} else {
			$result = $this->provider->accounts->add_account( $account_id, $account_data );
			if ( empty( $result ) || is_wp_error( $result ) ) {
				echo esc_html__( 'Error, Cannot add the new account!', 'doublescale' );
				exit;
			}
		}

		// sucessfully added.
		?>
		<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="UTF-8">
			<meta http-equiv="X-UA-Compatible" content="IE=edge">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title>Authorization done</title>
		</head>
		<body>
			<?php echo esc_html__( "The account is added/updated successfully. If this window isn't closed automatically. Please close it and refersh your accounts select menu.", 'doublescale' ); ?>
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML response for OAuth popup that messages the opener and closes itself; outside WP page lifecycle, wp_enqueue cannot run here. ?>
			<script>
				if ( typeof window.opener.add_new_outlook_account === 'function' ) {
					window.opener.add_new_outlook_account( '<?php echo esc_attr( $account_id ); ?>', '<?php echo esc_attr( $account_name ); ?>' );
					window.close();
				}
			</script>
		</body>
		</html>
		<?php
		exit;
	}

	/**
	 * Get tokens
	 *
	 * @param array $query Query to get account tokens.
	 * @return boolean|array
	 */
	public function get_tokens( $query ) {
		$response = wp_remote_post(
			'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			array(
				'body' => $query,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$tokens = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $tokens['access_token'] ) ) {

			// log in case of first request.
			if ( $query['grant_type'] === 'authorization_code' && empty( $tokens['refresh_token'] ) ) {
				return false;
			}

			return false;
		}

		return $tokens;
	}

	/**
	 * Refresh account tokens
	 *
	 * @param string      $account_id Account id.
	 * @param string|null $refresh_token Refresh token.
	 * @return array|false|WP_Error
	 */
	public function refresh_tokens( $account_id, $refresh_token = null ) {
		if ( empty( $refresh_token ) ) {
			$refresh_token = $this->provider->accounts->get_accounts( array( 'credentials' ) )[ $account_id ]['credentials']['refresh_token'];
		}

		$app_credentials = $this->get_app_credentials();

		if ( empty( $app_credentials ) ) {
			return false;
		}

		$tokens = $this->get_tokens(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $app_credentials['client_id'],
				'client_secret' => $app_credentials['client_secret'],
			)
		);

		if ( empty( $tokens ) ) {
			return false;
		}

		$updated = $this->provider->accounts->update_account(
			$account_id,
			array(
				'credentials' => $tokens,
			),
			false
		);
		if ( empty( $updated ) ) {
			return false;
		}
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return $tokens;
	}

	/**
	 * Get global app credentials
	 *
	 * @return array|false Array of client_id & client_secret. false on failure.
	 */
	public function get_app_credentials() {
		$app_settings = $this->provider->settings->get( 'app' ) ?? array();
		if ( empty( $app_settings['client_id'] ) || empty( $app_settings['client_secret'] ) ) {
			return false;
		} else {
			return $app_settings;
		}
	}

	/**
	 * Get redirect uri
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php' );
	}
}
