<?php
/**
 * Puts the help assistant where customers are, for each channel the owner
 * turned on:
 *
 *   portal     `help_assistant` in the client portal config: free's Help
 *              Center section then shows Pro's chat panel (compiled into the
 *              portal bundle). Absent → free shows articles only.
 *   widget     A floating button on the site's pages (all, or the ones the
 *              owner picked) — never on the portal page, which has its own.
 *   help_page  The help center page and its articles: the same floating
 *              chat whenever the floating button is on (whatever pages were
 *              picked), opened by the help center's own buttons too. It has
 *              no switch of its own ({@see HelpSettings::channel_on()}).
 *
 * The page carries only a ~3 KB loader (no libraries). The chat itself
 * (chat.js + chat.css) loads when the visitor opens it, inside a shadow root
 * so the theme's styles cannot reach it.
 *
 * Nothing shows until an AI provider is connected and free ships the
 * counters the limits need (an older free: the assistant stays asleep).
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Support\Services\TeamPresence;
use DoubleScale\Modules\KnowledgeBase\Services\KbAudience;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;
use DoubleScale\Modules\Portal\Services\PortalUrl;
use DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers\RestHelpPublicChatController;

/**
 * CustomerChannels class.
 */
final class CustomerChannels {

	public const LOADER_HANDLE = 'doublescale-help-chat-loader';

	private const BUILD = 'build/renderer/help-widget/';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'doublescale_client_portal_config', array( self::class, 'portal_config' ) );
		add_filter( 'doublescale_help_center_help_options', array( self::class, 'help_options' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_widget' ), 20 );
	}

	/**
	 * Whether customers can be answered at all (the places are checked apart).
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		// Someone answers: the AI assistant, the team (live chat), or both.
		return ChatGuard::available() && HelpSettings::chat_on();
	}

	/**
	 * @param array<string, mixed> $config Portal config.
	 * @return array<string, mixed>
	 */
	public static function portal_config( $config ) {
		$config = (array) $config;
		if ( HelpSettings::channel_on( 'portal' ) && self::ready() ) {
			// The portal sends its own nonce and needs no loader: it loads the
			// chat app from these files and mounts it in its Help section.
			$config['help_assistant']        = self::client_config( 'portal' );
			$config['help_assistant']['app'] = self::app_files();
		}

		return $config;
	}

	/**
	 * Mount point on the help center page.
	 *
	 * @param string $html Markup so far.
	 * @param string $view home or search.
	 * @return string
	 */
	public static function help_page( $html, $view = 'home' ) {
		// The help center shows the floating chat now ({@see self::help_center_widget()}),
		// opened by its own buttons too: nothing sits in the page.
		return (string) $html;
	}

	/**
	 * The help center's "Can't find what you need?" choices: chat with the
	 * team (live chat) and/or ask the AI assistant. Both open the same chat
	 * panel on the help center (the AI answers first and hands over).
	 *
	 * @param array<int, array<string, string>> $options Choices so far.
	 * @return array<int, array<string, mixed>>
	 */
	public static function help_options( $options ) {
		$options = (array) $options;
		if ( ! HelpSettings::channel_on( 'help_page' ) || ! self::ready() ) {
			return $options;
		}

		$mine = array();
		if ( LiveChat::enabled() ) {
			$online = TeamPresence::online();
			$mine[] = array(
				'key'    => 'chat',
				'title'  => __( 'Chat with our team', 'doublescale' ),
				'text'   => $online ? __( 'Online · replies in a few minutes', 'doublescale' ) : __( 'Leave a message, we reply as soon as we’re back', 'doublescale' ),
				'online' => $online ? '1' : '',
				'icon'   => 'chat',
				'style'  => 'primary',
				'action' => 'chat',
			);
		}
		if ( HelpSettings::assistant_answers() ) {
			$mine[] = array(
				'key'    => 'assistant',
				'title'  => __( 'Ask the assistant', 'doublescale' ),
				'text'   => __( 'Instant answers from these articles', 'doublescale' ),
				'icon'   => 'sparkle',
				'style'  => array() === $mine ? 'primary' : 'soft',
				'action' => 'chat',
			);
		}

		// Chat first, then the free choices (a ticket).
		return array_merge( $mine, $options );
	}

	/**
	 * Floating button on the pages the owner chose.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_widget(): void {
		$site_widget = HelpSettings::channel_on( 'widget' ) && self::widget_belongs_here();
		if ( ( ! $site_widget && ! self::help_center_widget() ) || ! self::ready() ) {
			return;
		}

		self::enqueue_loader();
	}

	/**
	 * The help center page and its articles show the floating chat while
	 * "Floating button on your site" is on (Helpdesk → Settings → Live chat →
	 * Where the chat shows). Their "Chat with our team", "Ask the assistant"
	 * and search open it through `window.doublescaleHelpWidget.open()`.
	 *
	 * @return bool
	 */
	public static function help_center_widget(): bool {
		if ( ! HelpSettings::channel_on( 'help_page' ) || ! is_singular() ) {
			return false;
		}
		$post = get_post();

		return $post instanceof \WP_Post
			&& ( 'ds_kb_article' === $post->post_type || has_shortcode( (string) $post->post_content, 'doublescale_help_center' ) );
	}

	/**
	 * The greeting rules for the page being served: a rule with no pages and
	 * no address parts is for every page; otherwise it needs this page's id or
	 * an address part found in this address. Sent without its pages.
	 *
	 * @param array<int, array<string, mixed>> $rules Stored rules.
	 * @return array<int, array{text: string, hours: array{from: int, to: int}|null, visitor: string}>
	 */
	private static function greeting_rules_here( array $rules ): array {
		$page = (int) get_queried_object_id();
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$out  = array();
		foreach ( $rules as $rule ) {
			$pages = (array) ( $rule['pages'] ?? array() );
			$parts = (array) ( $rule['paths'] ?? array() );
			$fits  = ( ! $pages && ! $parts ) || ( $page && in_array( $page, $pages, true ) );
			foreach ( $parts as $part ) {
				$fits = $fits || ( '' !== $path && false !== strpos( $path, (string) $part ) );
			}
			if ( $fits ) {
				$out[] = array(
					'text'    => (string) $rule['text'],
					'hours'   => $rule['hours'] ?? null,
					'visitor' => (string) ( $rule['visitor'] ?? 'any' ),
				);
			}
		}

		return $out;
	}

	/**
	 * Settings the chat reads in the browser.
	 *
	 * @param string $channel portal, help_page or widget.
	 * @return array<string, mixed>
	 */
	public static function client_config( string $channel ): array {
		$settings = HelpSettings::get();
		$name     = '' !== $settings['name'] ? $settings['name'] : __( 'Help assistant', 'doublescale' );
		// The chat may offer a person (live chat, or the AI's contact form).
		$handoff  = HelpSettings::handoff_offered();
		// Online: someone who answers tickets was in wp-admin in the last minutes.
		$online = $handoff && TeamPresence::online();
		// The line under the name: the owner's, else an honest default — "can
		// help you too" while the team is here, "usually reply within a few
		// hours" while it is not; none when the chat cannot reach the team.
		$subtitle = '' !== $settings['subtitle'] ? $settings['subtitle'] : ( $handoff ? ( $online ? __( 'Our team can help you too', 'doublescale' ) : __( 'We usually reply within a few hours', 'doublescale' ) ) : '' );

		$config = array(
			'endpoint' => esc_url_raw( rest_url( 'doublescale/v1/help/public/chat' ) ),
			'handoff_endpoint' => $handoff ? esc_url_raw( rest_url( 'doublescale/v1/help/public/handoff' ) ) : '',
			'feedback_endpoint' => esc_url_raw( rest_url( 'doublescale/v1/help/public/feedback' ) ),
			// Where the visitor rates the team's help once it is solved.
			'satisfaction_endpoint' => esc_url_raw( rest_url( 'doublescale/v1/help/public/satisfaction' ) ),
			// Where the chat reads the team's replies once it is handed over, and
			// where a file for them goes: only when the team answers in the chat
			// (off: the chat never asks, and the handoff is answered by email).
			'updates_endpoint'  => LiveChat::enabled() ? esc_url_raw( rest_url( 'doublescale/v1/help/public/updates' ) ) : '',
			'typing_endpoint'   => LiveChat::enabled() ? esc_url_raw( rest_url( 'doublescale/v1/help/public/typing' ) ) : '',
			'upload_endpoint'   => LiveChat::enabled() ? esc_url_raw( rest_url( 'doublescale/v1/help/public/upload' ) ) : '',
			'channel'  => $channel,
			// The browser keeps the conversation under this: one key for every
			// visitor, one per account (hashed, never the user id).
			'store'    => is_user_logged_in() ? 'u' . substr( wp_hash( 'dshc-chat-' . get_current_user_id() ), 0, 12 ) : 'v',
			'color'    => $settings['widget']['color'],
			'position' => $settings['widget']['position'],
			'rtl'      => is_rtl(),
			'quick_replies' => $settings['quick_replies'],
			'avatar'   => self::avatar( (int) $settings['avatar'] ),
			'theme'    => $settings['theme'],
			// The site's Date / Time Format, for the times between messages.
			'dates'    => class_exists( '\\DoubleScale\\Core\\Settings\\SiteDates' ) ? \DoubleScale\Core\Settings\SiteDates::config() : null,
			'online'   => $online,
			'strings'  => array(
				'title'       => $name,
				'welcome'     => self::welcome( $settings['welcome'] ),
				'placeholder' => __( 'Type your question…', 'doublescale' ),
				'send'        => __( 'Send', 'doublescale' ),
				'open'        => __( 'Ask a question', 'doublescale' ),
				'close'       => __( 'Close', 'doublescale' ),
				'thinking'    => __( 'Looking through our help articles…', 'doublescale' ),
				'sources'     => __( 'From these articles:', 'doublescale' ),
				'error'       => __( 'Something went wrong. Please try again.', 'doublescale' ),
				'handoff'     => __( 'Our support team can help you with this.', 'doublescale' ),
				'restart'     => __( 'New conversation', 'doublescale' ),
				'note'        => __( 'Answers come from our help articles.', 'doublescale' ),
				'you'         => __( 'You', 'doublescale' ),
				'label'       => __( 'Your question', 'doublescale' ),
				'handoff_button' => __( 'Contact support', 'doublescale' ),
				'typing'         => __( 'Typing…', 'doublescale' ),
				'handoff_intro'  => __( 'Leave your name and email, and our team will reply to you.', 'doublescale' ),
				'name_label'     => __( 'Your name', 'doublescale' ),
				'email_label'    => __( 'Your email', 'doublescale' ),
				'consent'        => __( 'I agree to send this conversation and my email to the support team.', 'doublescale' ),
				'handoff_send'   => __( 'Send to support', 'doublescale' ),
				'handoff_error'  => __( 'We could not send your request. Please try again.', 'doublescale' ),
				'helpful_question' => __( 'Was this helpful?', 'doublescale' ),
				'helpful_yes'      => __( 'Yes', 'doublescale' ),
				'helpful_no'       => __( 'No', 'doublescale' ),
				'helpful_thanks'   => __( 'Thanks for your feedback!', 'doublescale' ),
				'just_now'         => __( 'Just now', 'doublescale' ),
				'expand'           => __( 'Make the chat larger', 'doublescale' ),
				'collapse'         => __( 'Make the chat smaller', 'doublescale' ),
				'download'         => __( 'Download the conversation', 'doublescale' ),
				'more'             => __( 'More options', 'doublescale' ),
				'emoji'            => __( 'Insert an emoji', 'doublescale' ),
				'suggestions'      => __( 'These articles may help:', 'doublescale' ),
				'back'             => __( 'Back', 'doublescale' ),
				'continue'         => __( 'Continue the conversation', 'doublescale' ),
				'search_help'      => __( 'Search our help articles', 'doublescale' ),
				'popular'          => __( 'Popular articles', 'doublescale' ),
				'no_results'       => __( 'No articles found.', 'doublescale' ),
				'online'           => __( 'Online', 'doublescale' ),
				'team'             => __( 'Support team', 'doublescale' ),
				/* translators: %s: a team member's first name */
				'face_online'      => __( '%s, online', 'doublescale' ),
				/* translators: %d: how many support team members are online */
				'online_count'     => __( '%d online now', 'doublescale' ),
				'one_online'       => __( '1 online now', 'doublescale' ),
				'attach'           => __( 'Attach a file', 'doublescale' ),
				/* translators: %s: a team member's first name */
				'joined'           => __( '%s joined the conversation', 'doublescale' ),
				'active_now'       => __( 'Active now', 'doublescale' ),
				/* translators: %s: how long ago, e.g. "15 minutes ago" */
				'active_ago'       => __( 'Active %s', 'doublescale' ),
				'failed'           => __( "Couldn't send", 'doublescale' ),
				'retry'            => __( 'Try again', 'doublescale' ),
				'resolved'         => __( 'Our team marked this as solved. Reply here if you still need help.', 'doublescale' ),
				// Under the visitor's last message to the team.
				'seen'             => __( 'Seen', 'doublescale' ),
				'not_seen'         => __( 'Not seen yet', 'doublescale' ),
				// Once the team solved it: the visitor rates the help.
				'rate_question'    => __( 'How would you rate our help?', 'doublescale' ),
				'rate_1'           => __( 'Terrible', 'doublescale' ),
				'rate_2'           => __( 'Bad', 'doublescale' ),
				'rate_3'           => __( 'OK', 'doublescale' ),
				'rate_4'           => __( 'Good', 'doublescale' ),
				'rate_5'           => __( 'Great', 'doublescale' ),
				'rate_comment'     => __( 'Tell us more (optional)', 'doublescale' ),
				'rate_thanks'      => __( 'Thanks for your rating!', 'doublescale' ),
				// The floating chat's tabs.
				'tab_home'         => __( 'Home', 'doublescale' ),
				'tab_messages'     => __( 'Messages', 'doublescale' ),
				'tab_help'         => __( 'Help', 'doublescale' ),
				'send_message'     => __( 'Send us a message', 'doublescale' ),
				'no_messages'      => __( 'No messages yet', 'doublescale' ),
				'portal_title'     => __( 'Looking for your past tickets?', 'doublescale' ),
				'portal_link'      => __( 'Go to the customer portal', 'doublescale' ),
				/* translators: %d: number of article collections */
				'collections'      => __( '%d collections', 'doublescale' ),
				/* translators: %d: number of articles */
				'articles'         => __( '%d articles', 'doublescale' ),
				'one_collection'   => __( '1 collection', 'doublescale' ),
				'one_article'      => __( '1 article', 'doublescale' ),
				'open_article'     => __( 'Open the full article', 'doublescale' ),
				'search_chat'      => __( 'Search the conversation', 'doublescale' ),
				'sound_off'        => __( 'Mute sounds', 'doublescale' ),
				'sound_on'         => __( 'Turn sounds on', 'doublescale' ),
				'previous'         => __( 'Previous', 'doublescale' ),
				'next'             => __( 'Next', 'doublescale' ),
				'closed'           => __( 'Our team closed this conversation. I am here if you need anything else.', 'doublescale' ),
			),
		);

		if ( '' !== $subtitle ) {
			$config['strings']['subtitle'] = $subtitle;
		}

		// No AI answering: the chat is a live chat with the team. Its words
		// say so (the name and welcome on the Assistant tab are the AI's).
		$team                = ! HelpSettings::assistant_answers();
		$config['answers']   = $team ? 'team' : 'assistant';
		if ( $team ) {
			$config['strings'] = array_merge( $config['strings'], self::team_strings() );
		}

		// The floating chat opens on a home page: the most read articles and an
		// article search. The help center page and the portal are that already.
		if ( 'widget' === $channel ) {
			$config['home'] = array(
				'popular'    => self::popular_articles(),
				'search'     => esc_url_raw( rest_url( 'doublescale/v1/kb/public/articles' ) ),
				// The Help tab: the article collections, each opened in the chat.
				'categories' => esc_url_raw( rest_url( 'doublescale/v1/kb/public/categories' ) ),
				// The faces of the team who answer, when the chat can reach them.
				'team'       => $handoff ? TeamPresence::faces( 3 ) : array(),
				// How many of them are here now: "3 online now" under the faces.
				'online'     => $handoff ? TeamPresence::online_count() : 0,
				// The Messages tab: a client's tickets live in the portal.
				'portal'     => self::portal_tickets_url(),
			);
		}

		// The greeting over the button, when the owner turned it on: the
		// floating chat on the site and on the help center page and its
		// articles (the same button there).
		if ( in_array( $channel, array( 'widget', 'help_page' ), true ) ) {
			if ( $settings['widget']['greeting'] ) {
				$config['greeting']            = array(
					'delay'  => (int) $settings['widget']['greeting_delay'],
					// This page's rules only; the browser picks by the time
					// and by whether the visitor came before (a cached page
					// is cached per address, never per hour or per visitor).
					'rules'  => self::greeting_rules_here( $settings['widget']['greeting_rules'] ),
					// The site's clock, in minutes from UTC, for the rules' hours.
					'offset' => (int) round( wp_timezone()->getOffset( new \DateTime( 'now' ) ) / 60 ),
				);
				$config['strings']['greeting'] = '' !== $settings['widget']['greeting_text'] ? $settings['widget']['greeting_text'] : ( $team ? __( 'Hi 👋 Need help? Send us a message.', 'doublescale' ) : __( 'Hi 👋 Need help? Ask me anything.', 'doublescale' ) );
			}
		}

		// A reader whose browser speaks Arabic gets the chat's own words in
		// Arabic on a site in another language; what the owner wrote stays.
		if ( 0 !== strpos( (string) get_locale(), 'ar' ) ) {
			$config['strings_ar'] = self::arabic_strings( '' === $settings['name'], '' === $settings['welcome'] );
			if ( $team ) {
				$config['strings_ar'] = array_merge( $config['strings_ar'], self::team_strings_ar() );
			}
			if ( isset( $config['greeting'] ) && '' === $settings['widget']['greeting_text'] ) {
				$config['strings_ar']['greeting'] = $team ? 'مرحباً 👋 تحتاج مساعدة؟ أرسل لنا رسالة.' : 'مرحباً 👋 تحتاج مساعدة؟ اسألني أي شيء.';
			}
			if ( '' === $settings['subtitle'] && $handoff ) {
				$config['strings_ar']['subtitle'] = $online ? 'فريقنا يمكنه مساعدتك أيضاً' : 'نرد عادةً خلال بضع ساعات';
			}
		}

		return $config;
	}

	/**
	 * The chat's words when the team answers (no AI): no "ask me", no
	 * "answers come from our articles", not the AI's name.
	 *
	 * @return array<string, string>
	 */
	private static function team_strings(): array {
		$name    = self::reader_name();
		$strings = array(
			'placeholder'  => __( 'Write a message…', 'doublescale' ),
			'open'         => __( 'Chat with us', 'doublescale' ),
			'label'        => __( 'Your message', 'doublescale' ),
			'note'         => '',
			'handoff_send' => __( 'Start the chat', 'doublescale' ),
			'consent'      => __( 'I agree to send my message and my email to the support team.', 'doublescale' ),
			'closed'       => __( 'Our team closed this conversation. Write here if you need anything else.', 'doublescale' ),
		);
		// The name and welcome on the Assistant tab are the AI's: the team's
		// chat has its own.
		$strings['title']   = __( 'Support team', 'doublescale' );
		$strings['welcome'] = '' !== $name
			/* translators: %s: the reader's first name */
			? sprintf( __( 'Hi %s! Send us a message and our team will reply right here.', 'doublescale' ), $name )
			: __( 'Hi! Send us a message and our team will reply right here.', 'doublescale' );
		return $strings;
	}

	/**
	 * {@see team_strings()} for a reader whose browser speaks Arabic.
	 *
	 * @return array<string, string>
	 */
	private static function team_strings_ar(): array {
		$name    = self::reader_name();
		$strings = array(
			'placeholder'  => 'اكتب رسالتك…',
			'open'         => 'تحدث معنا',
			'label'        => 'رسالتك',
			'note'         => '',
			'handoff_send' => 'ابدأ المحادثة',
			'consent'      => 'أوافق على إرسال رسالتي وبريدي الإلكتروني إلى فريق الدعم.',
			'closed'       => 'أغلق فريقنا هذه المحادثة. اكتب هنا إن احتجت أي شيء آخر.',
		);
		$strings['title']   = 'فريق الدعم';
		$strings['welcome'] = ( '' !== $name ? 'أهلاً ' . $name . '!' : 'أهلاً!' ) . ' أرسل لنا رسالة وسيرد فريقنا هنا.';
		return $strings;
	}

	/**
	 * The welcome, with the signed-in reader's first name: the default says
	 * "Hi Mona!"; the owner's own text may hold {name}, which a reader
	 * without a name simply loses ("Hi {name}!" → "Hi!").
	 *
	 * @param string $custom The owner's welcome, '' for the default.
	 * @return string
	 */
	private static function welcome( string $custom ): string {
		$name = self::reader_name();

		if ( '' === $custom ) {
			return '' !== $name
				/* translators: %s: the reader's first name */
				? sprintf( __( 'Hi %s! Ask me anything about our products and services.', 'doublescale' ), $name )
				: __( 'Hi! Ask me anything about our products and services.', 'doublescale' );
		}

		return '' !== $name
			? str_replace( '{name}', $name, $custom )
			: trim( (string) preg_replace( '/[ \t]*\{name\}/u', '', $custom ) );
	}

	/**
	 * The customer portal's tickets page, '' without a portal page.
	 *
	 * @return string
	 */
	private static function portal_tickets_url(): string {
		if ( ! class_exists( PortalUrl::class ) ) {
			return '';
		}
		$url = PortalUrl::get_route_url( 'tickets' );

		return '' !== $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * The five most read articles this reader may open.
	 *
	 * @return array<int, array{title: string, url: string}>
	 */
	private static function popular_articles(): array {
		if ( ! class_exists( KbPublicArticles::class ) || ! class_exists( KbAudience::class ) ) {
			return array();
		}

		$out = array();
		foreach ( KbPublicArticles::query( KbAudience::current() )->orderBy( 'views', 'desc' )->orderBy( 'title', 'asc' )->limit( 5 )->get()->all() as $article ) {
			$url = KbPublicArticles::article_url( (string) $article->slug );
			if ( '' !== $url ) {
				$out[] = array(
					'title' => (string) $article->title,
					'url'   => $url,
				);
			}
		}

		return $out;
	}

	/**
	 * The picture by the assistant's name: the owner's, else the site icon;
	 * '' draws the name's first letter.
	 *
	 * @param int $image Owner's image id, 0 for none.
	 * @return string
	 */
	private static function avatar( int $image ): string {
		$url = $image > 0 ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '';
		if ( ! $url ) {
			$url = get_site_icon_url( 96 );
		}

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * The signed-in reader's first name (or first word of the display name).
	 *
	 * @return string '' for a visitor.
	 */
	private static function reader_name(): string {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return '';
		}
		$name = trim( (string) $user->first_name );
		if ( '' === $name ) {
			$parts = preg_split( '/\s+/u', trim( (string) $user->display_name ) );
			$name  = (string) ( $parts[0] ?? '' );
		}

		return $name;
	}

	/**
	 * The chat's own words in Arabic.
	 *
	 * @param bool $title   Include the default assistant name (the owner set none).
	 * @param bool $welcome Include the default welcome (the owner wrote none).
	 * @return array<string, string>
	 */
	private static function arabic_strings( bool $title, bool $welcome ): array {
		$name    = self::reader_name();
		$strings = array(
			'title'            => 'مساعد الدعم',
			'welcome'          => ( '' !== $name ? 'أهلاً ' . $name . '!' : 'أهلاً!' ) . ' اسألني عن أي شيء يخص منتجاتنا وخدماتنا.',
			'placeholder'      => 'اكتب سؤالك…',
			'send'             => 'إرسال',
			'open'             => 'اطرح سؤالاً',
			'close'            => 'إغلاق',
			'thinking'         => 'أبحث في مقالات المساعدة…',
			'sources'          => 'من هذه المقالات:',
			'error'            => 'حدث خطأ ما. حاول مرة أخرى.',
			'handoff'          => 'يمكن لفريق الدعم مساعدتك في هذا.',
			'restart'          => 'محادثة جديدة',
			'note'             => 'الإجابات من مقالات المساعدة لدينا.',
			'you'              => 'أنت',
			'label'            => 'سؤالك',
			'handoff_button'   => 'تواصل مع الدعم',
			'typing'           => 'يكتب…',
			'handoff_intro'    => 'اترك اسمك وبريدك الإلكتروني، وسيرد عليك فريقنا.',
			'name_label'       => 'اسمك',
			'email_label'      => 'بريدك الإلكتروني',
			'consent'          => 'أوافق على إرسال هذه المحادثة وبريدي الإلكتروني إلى فريق الدعم.',
			'handoff_send'     => 'أرسل إلى الدعم',
			'handoff_error'    => 'تعذّر إرسال طلبك. حاول مرة أخرى.',
			'helpful_question' => 'هل كان هذا مفيداً؟',
			'helpful_yes'      => 'نعم',
			'helpful_no'       => 'لا',
			'helpful_thanks'   => 'شكراً على رأيك!',
			'just_now'         => 'الآن',
			'expand'           => 'تكبير نافذة المحادثة',
			'collapse'         => 'تصغير نافذة المحادثة',
			'download'         => 'تنزيل المحادثة',
			'more'             => 'خيارات أخرى',
			'emoji'            => 'إدراج رمز تعبيري',
			'suggestions'      => 'قد تفيدك هذه المقالات:',
			'back'             => 'رجوع',
			'continue'         => 'متابعة المحادثة',
			'search_help'      => 'ابحث في مقالات المساعدة',
			'popular'          => 'المقالات الأكثر قراءة',
			'no_results'       => 'لم يتم العثور على مقالات.',
			'online'           => 'متصل',
			'team'             => 'فريق الدعم',
			'face_online'      => '%s، متصل',
			'online_count'     => '%d متصلون الآن',
			'one_online'       => 'واحد متصل الآن',
			'attach'           => 'إرفاق ملف',
			'joined'           => 'انضم %s إلى المحادثة',
			'active_now'       => 'نشط الآن',
			'active_ago'       => 'نشط %s',
			'failed'           => 'تعذّر الإرسال',
			'retry'            => 'إعادة المحاولة',
			'resolved'         => 'اعتبر فريقنا طلبك محلولاً. اكتب هنا إذا كنت لا تزال بحاجة إلى مساعدة.',
			'seen'             => 'شوهدت',
			'not_seen'         => 'لم تتم رؤيتها بعد',
			'rate_question'    => 'كيف تقيّم مساعدتنا؟',
			'rate_1'           => 'سيئة جداً',
			'rate_2'           => 'سيئة',
			'rate_3'           => 'مقبولة',
			'rate_4'           => 'جيدة',
			'rate_5'           => 'ممتازة',
			'rate_comment'     => 'أخبرنا بالمزيد (اختياري)',
			'rate_thanks'      => 'شكراً على تقييمك!',
			'tab_home'         => 'الصفحة الرئيسية',
			'tab_messages'     => 'الرسائل',
			'tab_help'         => 'المساعدة',
			'send_message'     => 'أرسل لنا رسالة',
			'no_messages'      => 'لا توجد رسائل بعد',
			'portal_title'     => 'هل تبحث عن طلباتك السابقة؟',
			'portal_link'      => 'انتقل إلى بوابة العملاء',
			'collections'      => '%d مجموعات',
			'articles'         => '%d مقالات',
			'one_collection'   => 'مجموعة واحدة',
			'one_article'      => 'مقالة واحدة',
			'open_article'     => 'افتح المقالة كاملة',
			'search_chat'      => 'ابحث في المحادثة',
			'sound_off'        => 'كتم الأصوات',
			'sound_on'         => 'تشغيل الأصوات',
			'previous'         => 'السابق',
			'next'             => 'التالي',
			'closed'           => 'أغلق فريقنا هذه المحادثة. أنا هنا إذا احتجت أي شيء آخر.',
		);
		if ( ! $title ) {
			unset( $strings['title'] );
		}
		if ( ! $welcome ) {
			unset( $strings['welcome'] );
		}

		return $strings;
	}

	/**
	 * Register and enqueue the loader with its config (once).
	 *
	 * @return void
	 */
	private static function enqueue_loader(): void {
		if ( wp_script_is( self::LOADER_HANDLE, 'enqueued' ) ) {
			return;
		}

		$dir = DOUBLESCALE_PRO_PLUGIN_DIR;
		$url = DOUBLESCALE_PRO_PLUGIN_URL;
		$ver = self::version( $dir );

		wp_register_script( self::LOADER_HANDLE, $url . self::BUILD . 'loader.js', array(), $ver, true );

		// On the help center its own channel (no "home" with articles: the
		// page is that already), elsewhere the site's floating chat.
		$help_center = self::help_center_widget();
		$channel     = $help_center ? 'help_page' : 'widget';
		$config      = self::client_config( $channel );

		$config['app']       = self::app_files();
		$config['nonce_url'] = esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) );
		$config['widget']    = $help_center || ( HelpSettings::channel_on( 'widget' ) && self::widget_belongs_here() );

		self::keep_out_of_page_caches();

		wp_add_inline_script( self::LOADER_HANDLE, 'window.doublescaleHelpChat = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP ) . ';', 'before' );
		wp_enqueue_script( self::LOADER_HANDLE );
	}

	/**
	 * A signed-in reader's page carries their first name, their chat's
	 * storage key and the articles their audience may open: a page cache must
	 * not keep it for the next visitor. WordPress already sends no-cache
	 * headers to signed-in readers; this also tells the cache plugins
	 * (DONOTCACHEPAGE) and covers the case where nothing sent them yet.
	 *
	 * @return void
	 */
	public static function keep_out_of_page_caches(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * The chat app (built into build/renderer/help-widget/).
	 *
	 * @return array{js: string, css: string}
	 */
	public static function app_files(): array {
		$ver = self::version( DOUBLESCALE_PRO_PLUGIN_DIR );
		return array(
			'js'  => add_query_arg( 'ver', $ver, DOUBLESCALE_PRO_PLUGIN_URL . self::BUILD . 'chat.js' ),
			'css' => add_query_arg( 'ver', $ver, DOUBLESCALE_PRO_PLUGIN_URL . self::BUILD . 'chat.css' ),
		);
	}

	/**
	 * Not on the portal or help center pages (they hold the chat already),
	 * and only on the chosen pages when the owner picked some.
	 *
	 * @return bool
	 */
	private static function widget_belongs_here(): bool {
		$post = is_singular() ? get_post() : null;
		if ( $post && ( has_shortcode( (string) $post->post_content, 'doublescale_client_portal' ) || has_shortcode( (string) $post->post_content, 'doublescale_help_center' ) ) ) {
			return false;
		}
		// A help article follows the help center's own switch ({@see self::help_center_widget()}).
		if ( $post && 'ds_kb_article' === $post->post_type && HelpSettings::channel_on( 'help_page' ) ) {
			return false;
		}

		$pages = HelpSettings::get()['widget']['pages'];
		if ( array() === $pages ) {
			return true;
		}

		return $post && in_array( (int) $post->ID, $pages, true );
	}

	/**
	 * @param string $dir Plugin dir.
	 * @return string
	 */
	private static function version( string $dir ): string {
		$asset = $dir . self::BUILD . 'chat.asset.php';
		if ( '' !== $dir && file_exists( $asset ) ) {
			$data = require $asset;
			if ( isset( $data['version'] ) ) {
				return (string) $data['version'];
			}
		}

		return DOUBLESCALE_PRO_VERSION;
	}
}
