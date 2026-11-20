<?php
/**
 * What the server says in the chat, in the chat's language.
 *
 * The chat sends `lang` ('ar' when it shows in Arabic on a site in another
 * language). A refusal from the chat routes (limits, a bad email, an empty
 * question…) then comes in Arabic; anything else keeps the site's words.
 * HelpChatLanguageTest reads every code from the routes and fails for one
 * missing here.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;

/**
 * ChatWords class.
 */
final class ChatWords {

	/**
	 * Arabic words for each refusal the chat can show.
	 */
	public const ARABIC = array(
		'help_empty_question'        => 'من فضلك اكتب سؤالك.',
		'help_question_too_long'     => 'من فضلك اجعل سؤالك أقصر.',
		'help_rate_limited'          => 'أنت تسأل بسرعة كبيرة. من فضلك انتظر لحظة ثم حاول مرة أخرى.',
		'help_conversation_full'     => 'هذه المحادثة ممتلئة. ابدأ محادثة جديدة، أو تواصل مع فريق الدعم.',
		'help_daily_limit'           => 'أجاب المساعد على كل ما يستطيع من أسئلة اليوم. يمكن لفريق الدعم مساعدتك بدلاً منه.',
		'help_visitor_daily_limit'   => 'سألت المساعد كثيراً اليوم. يمكن لفريق الدعم مساعدتك بدلاً منه.',
		'help_busy'                  => 'المساعد مشغول الآن. من فضلك حاول مرة أخرى بعد دقيقة.',
		'help_unavailable'           => 'لا يستطيع المساعد الإجابة الآن. يمكن لفريق الدعم مساعدتك بدلاً منه.',
		'help_rejected'              => 'تعذّر إرسال رسالتك.',
		'help_live_failed'           => 'تعذّر إرسال رسالتك. من فضلك حاول مرة أخرى.',
		'not_found'                  => 'لم يتم العثور على المحادثة.',
		'rest_forbidden'             => 'سجّل الدخول إلى بوابة العملاء لتسأل هنا.',
		'help_handoff_consent'       => 'من فضلك وافق على إرسال هذه المحادثة إلى فريق الدعم.',
		'help_handoff_duplicate'     => 'لقد أرسلنا هذا الطلب إلى فريقنا بالفعل.',
		'help_handoff_email'         => 'من فضلك اكتب بريداً إلكترونياً صحيحاً.',
		'help_handoff_empty'         => 'لا يوجد شيء لإرساله إلى فريق الدعم بعد.',
		'help_handoff_in_progress'   => 'طلبك قيد الإرسال بالفعل.',
		'help_handoff_name'          => 'من فضلك اكتب اسمك.',
		'help_handoff_needs_contact' => 'أخبرنا كيف نتواصل معك.',
		'help_team_only'             => 'أرسل رسالتك إلى فريقنا.',
		'help_handoff_limited'       => 'لقد أرسلت عدة طلبات بالفعل. من فضلك انتظر قليلاً قبل إرسال طلب آخر.',
		'help_handoff_failed'        => 'تعذّر إرسال طلبك إلى فريق الدعم. من فضلك حاول مرة أخرى.',
		// Files sent to the team (free's AttachmentService).
		'no_file'                    => 'لم يتم رفع أي ملف.',
		'too_many_files'             => 'وصلت إلى أقصى عدد من الملفات لهذه الرسالة.',
		'file_too_large'             => 'هذا الملف أكبر من المسموح.',
		'invalid_extension'          => 'لا يمكن إرسال هذا النوع من الملفات.',
		'invalid_mime'               => 'لا يمكن إرسال هذا النوع من الملفات.',
		'invalid_upload'             => 'تعذّر رفع الملف. من فضلك حاول مرة أخرى.',
		'empty_attachment'           => 'هذا الملف فارغ.',
	);

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_request_after_callbacks', array( self::class, 'on_response' ), 10, 3 );
	}

	/**
	 * A refusal from a chat route, in the chat's language.
	 *
	 * @param mixed           $response Response.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function on_response( $response, $handler, $request ) {
		if ( ! $response instanceof WP_Error || ! $request instanceof WP_REST_Request || 0 !== strpos( (string) $request->get_route(), '/doublescale/v1/help/public/' ) ) {
			return $response;
		}

		return self::localize( $response, (string) $request->get_param( 'lang' ) );
	}

	/**
	 * $error with Arabic words when $lang is 'ar' and they exist.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $lang  The chat's language.
	 * @return WP_Error
	 */
	public static function localize( WP_Error $error, string $lang ): WP_Error {
		$code = (string) $error->get_error_code();
		if ( 'ar' !== $lang || ! isset( self::ARABIC[ $code ] ) ) {
			return $error;
		}

		return new WP_Error( $code, self::ARABIC[ $code ], $error->get_error_data() );
	}
}
