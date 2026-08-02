<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Video Sitemap XML Sitemap XSL.
 *
 * @package SEOPress PRO
 * @subpackage Video Sitemap
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

// XML.

// Headers.
seopress_get_service( 'SitemapHeaders' )->printHeaders();

// WPML - Home URL.
if ( 2 == apply_filters( 'wpml_setting', false, 'language_negotiation_type' ) ) {
	add_filter(
		'seopress_sitemaps_home_url',
		function ( $home_url ) {
			$home_url = apply_filters( 'wpml_home_url', get_option( 'home' ) );
			return trailingslashit( $home_url );
		}
	);
} else {
	add_filter( 'wpml_get_home_url', 'seopress_remove_wpml_home_url_filter', 20, 5 );
}

/**
 * XML Sitemap index XSL template.
 *
 * @return string XML Sitemap index XSL template.
 */
function seopress_xml_sitemap_index_xsl() {
	$home_url = home_url() . '/';

	$home_url = apply_filters( 'seopress_sitemaps_home_url', $home_url );

	$seopress_sitemaps_xsl     = '<?xml version="1.0" encoding="UTF-8"?><xsl:stylesheet version="2.0"
				xmlns:html="http://www.w3.org/TR/REC-html40"
				xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
                xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
				xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
	<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes"/>
	<xsl:template match="/">
		<html xmlns="http://www.w3.org/1999/xhtml">';
	$seopress_sitemaps_xsl    .= "\n";
	$seopress_sitemaps_xsl    .= '<head>';
	$seopress_sitemaps_xsl    .= "\n";
	$seopress_sitemaps_xsl    .= '<title>XML Sitemaps</title>';
	$seopress_sitemaps_xsl    .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>';
	$seopress_sitemaps_xsl    .= "\n";
	$seopress_sitemaps_xsl_css = '<style type="text/css">';

	$seopress_sitemaps_xsl_css .= apply_filters(
		'seopress_sitemaps_xsl_css',
		'
	* {
		margin: 0;
		padding: 0;
		box-sizing: border-box;
	}
	body {
		background: #F7F7F7;
		font-size: 14px;
		font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif;
	}
	h1 {
		color: #23282d;
		font-weight:bold;
		font-size:20px;
		margin: 20px 0;
	}
	p {
		margin: 0 0 15px 0;
	}
	p a {
		color: rgb(0, 135, 190);
	}
	p.footer {
		padding: 15px;
	    background: rgb(250, 251, 252) none repeat scroll 0% 0%;
	    margin: 10px 0px 0px;
	    display: inline-block;
	    width: 100%;
	    color: rgb(68, 68, 68);
	    font-size: 13px;
	    border-top: 1px solid rgb(224, 224, 224);
	}
	#main {
		margin: 0 auto;
		max-width: 55rem;
		padding: 1.5rem;
		width: 100%;
	}
	#sitemaps {
		width: 100%;
		box-shadow: 0 0 0 1px rgba(224, 224, 224, 0.5),0 1px 2px #a8a8a8;
		background: #fff;
		margin-top: 20px;
		display: inline-block;
	}
	#sitemaps .col {
	    font-weight: bold;
	    border-bottom: 1px solid rgba(224, 224, 224, 1);
	    padding: 15px;
	}
    #sitemaps table {
        font-size: 14px;
    }'
	);

	$seopress_sitemaps_xsl_css .= '</style>';

	$seopress_sitemaps_xsl .= $seopress_sitemaps_xsl_css;
	$seopress_sitemaps_xsl .= '</head>';
	$seopress_sitemaps_xsl .= '<body>';
	$seopress_sitemaps_xsl .= '<div id="main">';
	$seopress_sitemaps_xsl .= '<h1>' . __( 'XML Sitemaps', 'wp-seopress-pro' ) . '</h1>';
	$seopress_sitemaps_xsl .= '<p><a href="' . $home_url . 'sitemaps.xml">Index sitemaps</a></p>';
	$seopress_sitemaps_xsl .= '<xsl:if test="sitemap:urlset/sitemap:url">';
	$seopress_sitemaps_xsl .= '<p>' . /* translators: %s number of videos */ sprintf( __( 'This XML Sitemap contains %s videos.', 'wp-seopress-pro' ), '<xsl:value-of select="count(sitemap:urlset/sitemap:url/video:video)"/>' ) . '</p>';
	$seopress_sitemaps_xsl .= '</xsl:if>';
	$seopress_sitemaps_xsl .= '<div id="sitemaps">';

	$seopress_sitemaps_xsl .= '<table><thead>';

	$seopress_sitemaps_xsl .= '<td class="col" width="20%">';
	$seopress_sitemaps_xsl .= __( 'Thumbnail', 'wp-seopress-pro' );
	$seopress_sitemaps_xsl .= '</td>';
	$seopress_sitemaps_xsl .= '<td class="col" width="40%">';
	$seopress_sitemaps_xsl .= __( 'Title', 'wp-seopress-pro' );
	$seopress_sitemaps_xsl .= '</td>';
	$seopress_sitemaps_xsl .= '<td class="col" width="15%" style="text-align:center">';
	$seopress_sitemaps_xsl .= __( 'Duration (sec)', 'wp-seopress-pro' );
	$seopress_sitemaps_xsl .= '</td>';
	$seopress_sitemaps_xsl .= '<td class="col" width="25%" style="text-align:center">';
	$seopress_sitemaps_xsl .= __( 'Publication Date', 'wp-seopress-pro' );
	$seopress_sitemaps_xsl .= '</td>';

	$seopress_sitemaps_xsl .= '</thead><tbody>';

	$seopress_sitemaps_xsl .= '<xsl:for-each select="sitemap:urlset/sitemap:url">';

	$seopress_sitemaps_xsl .= '<xsl:variable name="url_loc"><xsl:value-of select="sitemap:loc"/></xsl:variable>';

	$seopress_sitemaps_xsl .= '<xsl:for-each select="video:video">';

	$seopress_sitemaps_xsl .= '<tr>';

	$seopress_sitemaps_xsl .= '<td>';
	$seopress_sitemaps_xsl .= '<xsl:if test="video:thumbnail_loc">';
	$seopress_sitemaps_xsl .= '<xsl:variable name="thumbnail_loc"><xsl:value-of select="video:thumbnail_loc"/></xsl:variable>';
	$seopress_sitemaps_xsl .= '<xsl:if test="$thumbnail_loc != \'\'">';
	$seopress_sitemaps_xsl .= '<img src="{$thumbnail_loc}" width="115" height="86" />';
	$seopress_sitemaps_xsl .= '</xsl:if>';
	$seopress_sitemaps_xsl .= '</xsl:if>';
	$seopress_sitemaps_xsl .= '</td>';

	$seopress_sitemaps_xsl .= '<td>';
	$seopress_sitemaps_xsl .= '<xsl:if test="video:title">';
	$seopress_sitemaps_xsl .= '<a href="{$url_loc}"><xsl:value-of disable-output-escaping="yes" select="video:title"/></a>';
	$seopress_sitemaps_xsl .= '</xsl:if>';
	$seopress_sitemaps_xsl .= '</td>';

	$seopress_sitemaps_xsl .= '<td style="text-align:center">';
	$seopress_sitemaps_xsl .= '<xsl:if test="video:duration">';
	$seopress_sitemaps_xsl .= '<xsl:value-of select="video:duration"/>';
	$seopress_sitemaps_xsl .= '</xsl:if>';
	$seopress_sitemaps_xsl .= '</td>';

	$seopress_sitemaps_xsl .= '<td style="text-align:center">';
	$seopress_sitemaps_xsl .= '<xsl:if test="video:publication_date">';
	$seopress_sitemaps_xsl .= '<xsl:value-of select="concat(substring(video:publication_date,0,11),concat(\' \', substring(video:publication_date,12,5)))"/>';
	$seopress_sitemaps_xsl .= '</xsl:if>';
	$seopress_sitemaps_xsl .= '</td>';

	$seopress_sitemaps_xsl .= '</tr>';

	$seopress_sitemaps_xsl .= '</xsl:for-each>';

	$seopress_sitemaps_xsl .= '</xsl:for-each>';
	$seopress_sitemaps_xsl .= '</tbody></table>';

	$seopress_sitemaps_xsl .= '</div>';
	$seopress_sitemaps_xsl .= '</div>';
	$seopress_sitemaps_xsl .= '</body>';
	$seopress_sitemaps_xsl .= '</html>';

	$seopress_sitemaps_xsl .= '</xsl:template>';

	$seopress_sitemaps_xsl .= '</xsl:stylesheet>';

	$seopress_sitemaps_xsl = apply_filters( 'seopress_sitemaps_video_xsl', $seopress_sitemaps_xsl );

	return $seopress_sitemaps_xsl;
}
echo seopress_xml_sitemap_index_xsl();
