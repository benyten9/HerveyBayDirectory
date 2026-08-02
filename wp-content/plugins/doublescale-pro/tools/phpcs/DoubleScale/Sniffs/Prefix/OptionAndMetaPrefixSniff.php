<?php
/**
 * Enforces DoubleScale option and meta key prefixes (or documented allowlists).
 *
 * Only flags string literals and the first literal of a concatenation; dynamic keys are skipped.
 *
 * @package DoubleScaleCS
 */

if (class_exists('DoubleScale_Sniffs_Prefix_OptionAndMetaPrefixSniff', false)) {
	return;
}

/**
 * Sniff for ds_/doublescale_/ds- keys on options and literal meta keys.
 */
final class DoubleScale_Sniffs_Prefix_OptionAndMetaPrefixSniff implements PHP_CodeSniffer\Sniffs\Sniff {

	/**
	 * WordPress core options commonly read by plugins (avoid false positives).
	 *
	 * @var array<int, string>
	 */
	private const CORE_OPTIONS = array(
		'active_plugins',
		'active_sitewide_plugins',
		'admin_email',
		'blog_charset',
		'blog_public',
		'blogdescription',
		'blogname',
		'category_base',
		'close_comments_days_old',
		'comments_notify',
		'comment_max_links',
		'comment_moderation',
		'comment_registration',
		'comment_whitelist',
		'cron',
		'default_category',
		'default_comment_status',
		'default_email_category',
		'default_link_category',
		'default_ping_status',
		'default_pingback_flag',
		'default_post_format',
		'default_role',
		'date_format',
		'embed_autourl',
		'gmt_offset',
		'hack_file',
		'home',
		'large_size_h',
		'large_size_w',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'moderation_keys',
		'moderation_notify',
		'permalink_structure',
		'posts_per_page',
		'posts_per_rss',
		'recently_activated',
		'require_name_email',
		'rewrite_rules',
		'rss_use_excerpt',
		'show_avatars',
		'show_comments_cookies_opt_in',
		'site_icon',
		'siteurl',
		'start_of_week',
		'stylesheet',
		'tag_base',
		'template',
		'time_format',
		'timezone_string',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		'upload_path',
		'upload_url_path',
		'use_balanceTags',
		'use_smilies',
		'use_trackback',
		'users_can_register',
		'WPLANG',
	);

	/**
	 * Prefixes owned by DoubleScale / legacy hyphen form.
	 *
	 * @var array<int, string>
	 */
	private const DS_PREFIXES = array(
		'doublescale_',
		'ds_',
		'ds-',
	);

	/**
	 * External plugin option namespaces used in this codebase.
	 *
	 * @var array<int, string>
	 */
	private const THIRD_PARTY_OPTION_PREFIXES = array(
		'edd_',
		'smtp_',
		'wc_',
		'woocommerce_',
	);

	/**
	 * External post/user meta key prefixes (third-party integrations).
	 *
	 * @var array<int, string>
	 */
	private const THIRD_PARTY_META_PREFIXES = array(
		'_edd_',
		'_lp_',
		'_sfwd',
		'_woocommerce',
		'_wishlist',
		'learndash',
	);

	/**
	 * WordPress core user meta keys (exact match).
	 *
	 * @var array<int, string>
	 */
	private const WP_USER_META_KEYS = array(
		'admin_color',
		'comment_shortcuts',
		'description',
		'first_name',
		'last_name',
		'locale',
		'nickname',
		'rich_editing',
		'show_admin_bar_front',
		'syntax_highlighting',
		'timezone_string',
		'use_ssl',
	);

	/**
	 * Functions whose first argument is an option name.
	 *
	 * @var array<string, bool>
	 */
	private const OPTION_FUNCTIONS = array(
		'delete_option'   => true,
		'get_option'      => true,
		'update_option'   => true,
	);

	/**
	 * Functions whose second argument is user meta key.
	 *
	 * @var array<string, bool>
	 */
	private const USER_META_FUNCTIONS = array(
		'add_user_meta'    => true,
		'delete_user_meta' => true,
		'get_user_meta'    => true,
		'update_user_meta' => true,
	);

	/**
	 * Functions whose second argument is post meta key.
	 *
	 * @var array<string, bool>
	 */
	private const POST_META_FUNCTIONS = array(
		'add_post_meta'    => true,
		'delete_post_meta' => true,
		'get_post_meta'    => true,
		'update_post_meta' => true,
	);

	/**
	 * Register targets.
	 *
	 * @return array<int, int|string>
	 */
	public function register() {
		return array( T_STRING );
	}

	/**
	 * Process token.
	 *
	 * @param PHP_CodeSniffer\Files\File $phpcsFile File.
	 * @param int                        $stackPtr  Stack pointer.
	 * @return void
	 */
	public function process(PHP_CodeSniffer\Files\File $phpcsFile, $stackPtr) {
		$tokens = $phpcsFile->getTokens();
		if (! isset($tokens[ $stackPtr ]['content'])) {
			return;
		}

		$name = $tokens[ $stackPtr ]['content'];
		if (! isset(self::OPTION_FUNCTIONS[ $name ])
			&& ! isset(self::USER_META_FUNCTIONS[ $name ])
			&& ! isset(self::POST_META_FUNCTIONS[ $name ])
		) {
			return;
		}

		$prev = $phpcsFile->findPrevious(T_WHITESPACE, ($stackPtr - 1), null, true);
		if (false !== $prev && T_OBJECT_OPERATOR === $tokens[ $prev ]['code']) {
			return;
		}

		$openParen = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $stackPtr, null, false, null, true);
		if (false === $openParen) {
			return;
		}

		if (isset(self::OPTION_FUNCTIONS[ $name ])) {
			$argPtr = $this->find_start_of_nth_function_arg($phpcsFile, $openParen, 1);
			$this->check_option_literal($phpcsFile, $argPtr, $stackPtr);
			return;
		}

		if (isset(self::USER_META_FUNCTIONS[ $name ])) {
			$argPtr = $this->find_start_of_nth_function_arg($phpcsFile, $openParen, 2);
			$this->check_meta_literal($phpcsFile, $argPtr, $stackPtr, 'user');
			return;
		}

		$argPtr = $this->find_start_of_nth_function_arg($phpcsFile, $openParen, 2);
		$this->check_meta_literal($phpcsFile, $argPtr, $stackPtr, 'post');
	}

	/**
	 * Check first option argument when it exposes a literal (or concat start).
	 *
	 * @param PHP_CodeSniffer\Files\File $phpcsFile File.
	 * @param int|false                  $argPtr    Start of argument.
	 * @param int                        $stackPtr  Function name pointer.
	 * @return void
	 */
	private function check_option_literal($phpcsFile, $argPtr, $stackPtr) {
		if (false === $argPtr) {
			return;
		}

		$literal = $this->read_literal_or_concat_prefix($phpcsFile, $argPtr);
		if (null === $literal['key'] && ! $literal['had_literal']) {
			return;
		}

		if (null === $literal['key']) {
			return;
		}

		if ($this->is_allowed_option_key($literal['key'])) {
			return;
		}

		$phpcsFile->addWarning(
			'Option key "%s" should use doublescale_, ds_, or ds- prefix (or a documented core/third-party option).',
			$stackPtr,
			'InvalidOptionKey',
			array( $literal['key'] )
		);
	}

	/**
	 * Check meta key literal.
	 *
	 * @param PHP_CodeSniffer\Files\File $phpcsFile File.
	 * @param int|false                  $argPtr    Argument pointer.
	 * @param int                        $stackPtr  Function name pointer.
	 * @param string                     $kind      post|user.
	 * @return void
	 */
	private function check_meta_literal($phpcsFile, $argPtr, $stackPtr, $kind) {
		if (false === $argPtr) {
			return;
		}

		$literal = $this->read_literal_or_concat_prefix($phpcsFile, $argPtr);
		if (null === $literal['key'] && ! $literal['had_literal']) {
			return;
		}

		if (null === $literal['key']) {
			return;
		}

		if ($this->is_allowed_meta_key($literal['key'])) {
			return;
		}

		$label = 'post' === $kind ? 'Post meta key' : 'User meta key';
		$phpcsFile->addWarning(
			'%s "%s" should use doublescale_/ds_/ds- (optional leading _), or a documented third-party prefix.',
			$stackPtr,
			'InvalidMetaKey',
			array( $label, $literal['key'] )
		);
	}

	/**
	 * Whether an option key is allowed.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private function is_allowed_option_key($key) {
		if ('' === $key) {
			return true;
		}

		if (in_array($key, self::CORE_OPTIONS, true)) {
			return true;
		}

		foreach (self::DS_PREFIXES as $p) {
			if (0 === strpos($key, $p)) {
				return true;
			}
		}

		foreach (self::THIRD_PARTY_OPTION_PREFIXES as $p) {
			if (0 === strpos($key, $p)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a meta key is allowed.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function is_allowed_meta_key($key) {
		if ('' === $key) {
			return true;
		}

		if (in_array($key, self::WP_USER_META_KEYS, true)) {
			return true;
		}

		foreach (self::THIRD_PARTY_META_PREFIXES as $p) {
			if (0 === strpos($key, $p)) {
				return true;
			}
		}

		$trim = $key;
		if (strlen($key) > 1 && '_' === $key[0]) {
			$trim = substr($key, 1);
		}

		foreach (self::DS_PREFIXES as $p) {
			if (0 === strpos($trim, $p)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read a string literal or first literal segment in a concatenation.
	 *
	 * @param PHP_CodeSniffer\Files\File $phpcsFile File.
	 * @param int                        $start     First token of the argument.
	 * @return array{had_literal: bool, key: string|null}
	 */
	private function read_literal_or_concat_prefix($phpcsFile, $start) {
		$tokens = $phpcsFile->getTokens();

		if (! isset($tokens[ $start ])) {
			return array(
				'had_literal' => false,
				'key'         => null,
			);
		}

		if (T_CONSTANT_ENCAPSED_STRING === $tokens[ $start ]['code']) {
			$key = self::strip_quotes($tokens[ $start ]['content']);
			$next = $phpcsFile->findNext(T_WHITESPACE, $start + 1, null, true);

			if (false !== $next && T_STRING_CONCAT === $tokens[ $next ]['code']) {
				return array(
					'had_literal' => true,
					'key'         => $key,
				);
			}

			return array(
				'had_literal' => true,
				'key'         => $key,
			);
		}

		return array(
			'had_literal' => false,
			'key'         => null,
		);
	}

	/**
	 * Strip surrounding quotes from a T_CONSTANT_ENCAPSED_STRING.
	 *
	 * @param string $content Token content.
	 * @return string
	 */
	private static function strip_quotes($content) {
		if (strlen($content) < 2) {
			return $content;
		}

		$q = $content[0];
		if (("'" === $q || '"' === $q) && $content[ strlen($content) - 1 ] === $q) {
			return substr($content, 1, -1);
		}

		return $content;
	}

	/**
	 * Find the first token of the Nth argument in a parenthesized argument list (1-based).
	 *
	 * @param PHP_CodeSniffer\Files\File $phpcsFile File.
	 * @param int                        $openParen Opening paren pointer.
	 * @param int                        $n         1-based index.
	 * @return int|false
	 */
	private function find_start_of_nth_function_arg($phpcsFile, $openParen, $n) {
		if ($n < 1) {
			return false;
		}

		$tokens = $phpcsFile->getTokens();
		$max    = count($tokens);

		$first = $phpcsFile->findNext(T_WHITESPACE, $openParen + 1, null, true);
		if (false === $first) {
			return false;
		}

		if (1 === $n) {
			return $first;
		}

		$depth   = 0;
		$bracket = 0;
		$square  = 0;
		$argNum  = 1;
		$i       = $first;

		while ($i < $max) {
			$code = $tokens[ $i ]['code'];

			if (T_OPEN_PARENTHESIS === $code) {
				++$depth;
			} elseif (T_CLOSE_PARENTHESIS === $code) {
				if (0 === $depth) {
					return false;
				}
				--$depth;
			} elseif (T_OPEN_CURLY_BRACKET === $code) {
				++$bracket;
			} elseif (T_CLOSE_CURLY_BRACKET === $code) {
				--$bracket;
			} elseif (T_OPEN_SHORT_ARRAY === $code || T_OPEN_SQUARE_BRACKET === $code) {
				++$square;
			} elseif (T_CLOSE_SHORT_ARRAY === $code || T_CLOSE_SQUARE_BRACKET === $code) {
				--$square;
			} elseif (T_COMMA === $code && 0 === $depth && 0 === $bracket && 0 === $square) {
				++$argNum;
				if ($argNum === $n) {
					$next = $phpcsFile->findNext(T_WHITESPACE, $i + 1, null, true);
					return false === $next ? false : $next;
				}
			}

			++$i;
		}

		return false;
	}

}
