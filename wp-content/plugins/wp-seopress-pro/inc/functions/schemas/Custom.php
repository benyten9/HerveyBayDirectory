<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Custom Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Fill in variables the tag engine cannot resolve, from the legacy list.
 *
 * `seopress_dyn_variables_fn` is how a site adds its own variable to the title
 * and meta templates, and this schema path used to be built entirely on it, so
 * dropping it would break anyone extending it.
 *
 * It cannot simply run before or after the engine. After is too late: the
 * engine's opening pass strips the names it does not know, so a site's own
 * variable would already be gone. Before is too early: the legacy list also
 * holds the core names, and resolving those here rather than through the
 * engine is exactly the divergence between the two attachment paths that this
 * is meant to end.
 *
 * So it is applied per name, and only where the engine comes back with
 * nothing. The engine keeps every name it owns; the legacy list only fills the
 * gaps.
 *
 * The `htmlentities()` mapping the old code applied to each replacement value
 * is deliberately not kept. Paired with the `wp_specialchars_decode()` that
 * followed it, it was a neutral round trip for `&`, `"` and tags, but it
 * turned accented characters into entities nothing ever decoded, so a French
 * title reached the JSON-LD as `Cr&egrave;me br&ucirc;l&eacute;e`.
 *
 * @since 10.2.0
 *
 * @param string $custom  The raw schema.
 * @param array  $context The page context.
 *
 * @return string
 */
function seopress_pro_schema_custom_legacy_variables( $custom, $context ) {
	if ( false === strpos( $custom, '%%' ) ) {
		return $custom;
	}

	if ( ! preg_match_all( '/%%[^%\s]+%%/', $custom, $matches ) ) {
		return $custom;
	}

	$variables = apply_filters( 'seopress_dyn_variables_fn', null );

	if ( ! is_array( $variables )
		|| ! isset( $variables['seopress_titles_template_variables_array'] )
		|| ! isset( $variables['seopress_titles_template_replace_array'] )
		|| ! is_array( $variables['seopress_titles_template_variables_array'] )
		|| ! is_array( $variables['seopress_titles_template_replace_array'] ) ) {
		return $custom;
	}

	// The names and the values are two separate public filters,
	// `seopress_titles_template_variables_array` and
	// `seopress_titles_template_replace_array`, so a site can lengthen one
	// without the other and there is no guarantee they still match. The old
	// `str_replace()` took a mismatch in its stride; `array_combine()` does
	// not, and raises a ValueError on PHP 8 that would take the front end
	// down. Cut first, then pad: padding alone only ever lengthens.
	$names  = array_values( $variables['seopress_titles_template_variables_array'] );
	$values = array_values( $variables['seopress_titles_template_replace_array'] );

	$legacy = array_combine(
		$names,
		array_pad( array_slice( $values, 0, count( $names ) ), count( $names ), '' )
	);

	if ( ! is_array( $legacy ) ) {
		return $custom;
	}

	$engine = seopress_get_service( 'TagsToString' );

	foreach ( array_unique( $matches[0] ) as $needle ) {
		if ( ! isset( $legacy[ $needle ] ) || '' === (string) $legacy[ $needle ] ) {
			continue;
		}

		// The engine owns every name it can resolve, so only the ones it
		// answers nothing for are filled in here.
		if ( '' !== (string) $engine->replace( $needle, $context ) ) {
			continue;
		}

		$custom = str_replace( $needle, (string) $legacy[ $needle ], $custom );
	}

	return $custom;
}

/**
 * Automatic rich snippets custom option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_custom_option( $schema_datas ) {
	// If no data.
	if ( 0 === count( array_filter( $schema_datas ) ) ) {
		return;
	}

	$custom = $schema_datas['custom'];

	/**
	 * One engine for both ways of attaching a custom schema.
	 *
	 * A schema set per post goes through `TagsToString`, which registers 105
	 * variables including the schema-specific ones. This path, the one a
	 * template rule uses, ran three `str_replace()` passes over the legacy
	 * title and meta list instead, which knows 51 names and none of
	 * `schema_post_date`, `schema_post_modified_date` or `post_date_iso8601`.
	 *
	 * So the same schema resolved differently depending on how it was attached,
	 * and the template path is the one most people use since it is the one that
	 * scales. Worse, `str_replace()` leaves an unmatched needle alone, so an
	 * unresolved variable was printed literally into public JSON-LD:
	 *
	 *     "datePublished": "%%schema_post_date%%"
	 *     "dateModified":  "10. August 2026"
	 *
	 * `TagsToString` already covers the legacy names, the schema names and the
	 * `_cf_` / `_ct_` custom formats, and removes a known variable that
	 * resolves to nothing rather than leaving its markers behind.
	 */
	$context = seopress_get_service( 'ContextPage' )->getContext();

	// Fill in only what the engine cannot resolve, so a site extending
	// `seopress_dyn_variables_fn` keeps working without taking names back from
	// the engine.
	$custom = seopress_pro_schema_custom_legacy_variables( $custom, $context );

	$custom = seopress_get_service( 'TagsToString' )->replace( $custom, $context );

	// TagsToString returns its values entity-encoded, the way WordPress stores
	// them, so decoding the string as a whole is not an option: a `&quot;` that
	// is part of a value would become the quote that ends it. The payload is
	// parsed instead, and re-encoded with JSON's own escapes, which resolves
	// the entities without ever letting one act as JSON syntax. A schema that
	// does not parse is left exactly as it was.
	$html = seopress_pro_json_ld_normalize_custom( $custom );

	$html .= "\n";

	$html = apply_filters( 'seopress_schemas_auto_custom_html', $html );

	echo $html;
}
