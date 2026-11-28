<?php
/** Public progress reports real application stages without exposing private work. */
namespace DirectoristAIPro\Chat;

final class VisitorProgress {
	public const STAGES = array( 'preparing_request', 'finding_listings', 'reading_details', 'comparing_options', 'writing_answer', 'checking_details' );

	/** Fixed presentation text; the provider never invents its own progress claims. */
	private const LABELS = array(
		'en' => array( 'Preparing your request…', 'Finding matching listings…', 'Checking listing details…', 'Comparing your options…', 'Writing your answer…', 'Checking the details…', 'This is taking longer than usual.' ),
		'es' => array( 'Preparando tu solicitud…', 'Buscando anuncios que coincidan…', 'Revisando los detalles de los anuncios…', 'Comparando tus opciones…', 'Preparando tu respuesta…', 'Comprobando los detalles…', 'Esto está tardando más de lo habitual.' ),
		'sv' => array( 'Förbereder din fråga…', 'Letar efter matchande annonser…', 'Kontrollerar annonsuppgifter…', 'Jämför dina alternativ…', 'Skriver ditt svar…', 'Kontrollerar uppgifterna…', 'Det här tar längre tid än vanligt.' ),
		'bn' => array( 'আপনার অনুরোধ প্রস্তুত করা হচ্ছে…', 'মিলে যাওয়া লিস্টিং খোঁজা হচ্ছে…', 'লিস্টিংয়ের তথ্য দেখা হচ্ছে…', 'আপনার বিকল্পগুলো তুলনা করা হচ্ছে…', 'আপনার উত্তর লেখা হচ্ছে…', 'তথ্য যাচাই করা হচ্ছে…', 'স্বাভাবিকের চেয়ে বেশি সময় লাগছে।' ),
		'ar' => array( 'جارٍ تجهيز طلبك…', 'جارٍ البحث عن إعلانات مطابقة…', 'جارٍ مراجعة تفاصيل الإعلانات…', 'جارٍ مقارنة الخيارات…', 'جارٍ إعداد إجابتك…', 'جارٍ التحقق من التفاصيل…', 'يستغرق هذا وقتًا أطول من المعتاد.' ),
		'fr' => array( 'Préparation de votre demande…', 'Recherche des annonces correspondantes…', 'Vérification des détails des annonces…', 'Comparaison de vos options…', 'Rédaction de votre réponse…', 'Vérification des informations…', 'Cela prend plus de temps que prévu.' ),
	);

	/** A finished/paused run never advertises ongoing processing. */
	public static function present( array $run ): ?array {
		if ( ! in_array( $run['state'] ?? null, array( 'queued', 'running' ), true ) ) { return null; }
		$checkpoint = json_decode( $run['checkpoint_json'] ?? '{}' );
		$stage = $checkpoint->visitor_progress_stage ?? ( isset( $checkpoint->visitor_delivery ) ? 'writing_answer' : 'preparing_request' );
		if ( ! in_array( $stage, self::STAGES, true ) ) { $stage = 'preparing_request'; }
		$language = $checkpoint->response_language->reply->base ?? 'en';
		if ( 'zh' === $language ) {
			$code = $checkpoint->response_language->reply->code ?? '';
			$language = preg_match( '/(?:Hant|TW|HK|MO)/i', $code ) ? 'zh-Hant' : 'zh-Hans';
		}
		static $translations = null;
		if ( null === $translations ) {
			$raw = file_get_contents( __DIR__ . '/progress-translations.json' );
			$translations = is_string( $raw ) ? json_decode( $raw, true ) : array();
		}
		$labels = self::LABELS[ $language ] ?? ( $translations[ $language ] ?? self::LABELS['en'] );
		if ( ! is_array( $labels ) || count( $labels ) !== 7 || count( array_filter( $labels, static fn( $text ): bool => is_string( $text ) && '' !== $text && mb_strlen( $text ) <= 240 ) ) !== 7 ) { $labels = self::LABELS['en']; }
		$index = array_search( $stage, self::STAGES, true );
		$elapsed = isset( $checkpoint->visitor_progress_at ) && is_int( $checkpoint->visitor_progress_at ) ? max( 0, time() - $checkpoint->visitor_progress_at ) : 0;
		return array( 'stage' => $stage, 'message' => $labels[ $index ], 'waiting_message' => $labels[6], 'elapsed_seconds' => min( 86400, $elapsed ) );
	}
}
