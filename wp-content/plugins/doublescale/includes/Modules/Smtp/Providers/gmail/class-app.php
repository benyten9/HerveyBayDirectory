<?php
/**
 * App class.
 *
 * @since 1.0.0
 * @package smtp
 */

namespace DoubleScale\Modules\Smtp\Providers\Gmail;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * App class.
 *
 * @since 1.0.0
 */
class App {

	/**
	 * OAuth scopes for Gmail SMTP (no google/apiclient dependency).
	 */
	private const OAUTH_SCOPE_MAIL_GOOGLE = 'https://mail.google.com/';

	private const OAUTH_SCOPE_GMAIL_SEND = 'https://www.googleapis.com/auth/gmail.send';

	private const OAUTH_SCOPE_OPENID = 'openid';

	private const OAUTH_SCOPE_USERINFO_EMAIL = 'https://www.googleapis.com/auth/userinfo.email';

	private const OAUTH_SCOPE_USERINFO_PROFILE = 'https://www.googleapis.com/auth/userinfo.profile';

	/**
	 * Space-separated scope string for the authorize request.
	 *
	 * @return string
	 */
	private function get_oauth_scope_string() {
		return implode(
			' ',
			array(
				self::OAUTH_SCOPE_MAIL_GOOGLE,
				self::OAUTH_SCOPE_GMAIL_SEND,
				self::OAUTH_SCOPE_OPENID,
				self::OAUTH_SCOPE_USERINFO_EMAIL,
				self::OAUTH_SCOPE_USERINFO_PROFILE,
			)
		);
	}

	/**
	 * Provider
	 *
	 * @var Gmail
	 */
	protected $provider;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Gmail $provider Provider.
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce not required for checking action parameter
		$action = isset( $_GET['smtp-gmail'] ) ? sanitize_text_field( wp_unslash( $_GET['smtp-gmail'] ) ) : '';
		if ( $action !== 'authorize' ) {
			return;
		}

		$app_credentials = $this->get_app_credentials();

		if ( empty( $app_credentials ) ) {
			echo esc_html__( 'Cannot find app credentials!', 'doublescale' );
			exit;
		}

		// add_query_arg() builds the query via http_build_query() which already
		// urlencodes values. Pre-encoding redirect_uri here would double-encode and
		// produce `redirect_uri_mismatch` — Google compares the *decoded* value to
		// the URI list configured on the OAuth client.
		$auth_url = add_query_arg(
			array(
				'response_type' => 'code',
				'access_type'   => 'offline',
				'client_id'     => $app_credentials['client_id'],
				'redirect_uri'  => $this->get_redirect_uri(),
				'state'         => 'smtp-gmail',
				'scope'         => $this->get_oauth_scope_string(),
				'prompt'        => 'consent',
			),
			'https://accounts.google.com/o/oauth2/auth'
		);
		\doublescale_safe_redirect( $auth_url );
	}

	/**
	 * Add account after authorization
	 *
	 * @return void
	 */
	public function maybe_add_account() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, nonce not applicable
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( $state !== 'smtp-gmail' ) {
			return;
		}

		// ensure authorize code.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback, nonce not applicable
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
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
			$last   = get_transient( 'doublescale_smtp_gmail_oauth_last_error' );
			$detail = is_array( $last ) ? $last : array();
			?>
			<!DOCTYPE html>
			<html lang="en"><head><meta charset="UTF-8"><title>OAuth error</title>
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone HTML response for OAuth error, terminates with exit; outside WP page lifecycle, wp_enqueue cannot run here. ?>
			<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;color:#222}code{background:#f4f4f4;padding:2px 6px;border-radius:4px}pre{background:#f4f4f4;padding:12px;border-radius:6px;white-space:pre-wrap;word-break:break-word}.err{color:#b00020;font-weight:600}</style>
			</head><body>
			<h2 class="err"><?php echo esc_html__( 'Cannot get account tokens', 'doublescale' ); ?></h2>
			<p><?php echo esc_html__( 'Google rejected the token exchange. Details:', 'doublescale' ); ?></p>
			<pre><?php echo esc_html( wp_json_encode( $detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
			<p><strong><?php echo esc_html__( 'Common causes:', 'doublescale' ); ?></strong></p>
			<ul>
				<li><code>invalid_grant</code> &mdash; <?php echo esc_html__( 'the auth code expired (>10 min) or was already used. Restart authorization.', 'doublescale' ); ?></li>
				<li><code>redirect_uri_mismatch</code> &mdash; <?php echo esc_html__( 'add this exact URI to Google Cloud > Credentials > Authorized redirect URIs:', 'doublescale' ); ?> <code><?php echo esc_html( $this->get_redirect_uri() ); ?></code></li>
				<li><code>invalid_client</code> &mdash; <?php echo esc_html__( 'wrong client_id / client_secret, or the OAuth client type is "Desktop" / "Installed" instead of "Web application".', 'doublescale' ); ?></li>
			</ul>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=doublescale&path=smtp/settings' ) ); ?>"><?php echo esc_html__( 'Back to SMTP settings', 'doublescale' ); ?></a></p>
			</body></html>
			<?php
			exit;
		}

		// get account details.
		$account_api       = new Account_API( $this, '', array( 'credentials' => $tokens ) );
		$accounts_response = $account_api->get_profile();

		if ( is_wp_error( $accounts_response ) ) {
			doublescale_get_logger()->error(
				'Cannot get profile details',
				array(
					'code'  => 'cannot_get_profile',
					'error' => $accounts_response,
				)
			);
			echo esc_html__( 'Error, Cannot get profile details!', 'doublescale' );
			exit;
		}

		$account      = $accounts_response;
		$account_name = $account->emailAddress;
		$account_id   = str_replace( '@gmail.com', '', $account->emailAddress );

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
			<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone HTML response for OAuth popup that messages the opener via window.opener and closes itself; outside WP page lifecycle, wp_enqueue cannot run here. ?>
			<script>
				if ( typeof window.opener.add_new_gmail_account === 'function' ) {
					window.opener.add_new_gmail_account( '<?php echo esc_attr( $account_id ); ?>', '<?php echo esc_attr( $account_name ); ?>' );
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
			'https://accounts.google.com/o/oauth2/token',
			array(
				'body'    => $query,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$detail = array(
				'stage' => 'http_transport',
				'error' => $response->get_error_message(),
			);
			set_transient( 'doublescale_smtp_gmail_oauth_last_error', $detail, 5 * MINUTE_IN_SECONDS );
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error( 'Gmail OAuth token HTTP request failed', array( 'code' => 'gmail_oauth_token_http' ) + $detail );
			}
			return false;
		}

		$raw    = wp_remote_retrieve_body( $response );
		$http   = wp_remote_retrieve_response_code( $response );
		$tokens = json_decode( $raw, true );

		if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
			$log_detail = array(
				'stage'     => 'token_exchange',
				'http_code' => $http,
				'grant'     => isset( $query['grant_type'] ) ? (string) $query['grant_type'] : '',
			);
			if ( is_array( $tokens ) ) {
				foreach ( array( 'error', 'error_description', 'error_uri' ) as $k ) {
					if ( isset( $tokens[ $k ] ) ) {
						$log_detail[ $k ] = $tokens[ $k ];
					}
				}
			} else {
				$log_detail['raw'] = is_string( $raw ) ? substr( $raw, 0, 500 ) : '';
			}
			set_transient( 'doublescale_smtp_gmail_oauth_last_error', $log_detail, 5 * MINUTE_IN_SECONDS );
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error( 'Gmail OAuth token exchange rejected by Google', array( 'code' => 'gmail_oauth_token_rejected' ) + $log_detail );
			}

			return false;
		}

		delete_transient( 'doublescale_smtp_gmail_oauth_last_error' );
		if ( ! empty( $tokens['access_token'] ) ) {
			$tokens['created'] = time();
		}
		return $tokens;
	}

	/**
	 * Refresh account tokens
	 *
	 * @param string      $account_id Account id.
	 * @param string|null $refresh_token Refresh token.
	 * @return array|false
	 */
	public function refresh_tokens( $account_id, $refresh_token = null ) {
		if ( empty( $refresh_token ) ) {
			$accounts      = $this->provider->accounts->get_accounts( array( 'credentials' ) );
			$refresh_token = $accounts[ $account_id ]['credentials']['refresh_token'] ?? '';
		}

		if ( empty( $refresh_token ) ) {
			return false;
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

		// Google doesn't return the refresh_token on refresh responses — preserve the original.
		if ( empty( $tokens['refresh_token'] ) ) {
			$tokens['refresh_token'] = $refresh_token;
		}

		$updated = $this->provider->accounts->update_account(
			$account_id,
			array( 'credentials' => $tokens ),
			false
		);
		if ( empty( $updated ) || is_wp_error( $updated ) ) {
			return false;
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
