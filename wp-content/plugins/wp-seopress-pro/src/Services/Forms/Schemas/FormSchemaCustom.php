<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;

class FormSchemaCustom extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_custom':
				return 'textarea';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_custom':
				return __( 'Custom schema', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_custom':
				return __(
					'e.g. <script type="application/ld+json">{
                    "@context": "https://schema.org/",
                    "@type": "Review",
                    "itemReviewed": {
                    "@type": "Restaurant",
                    "image": "http://www.example.com/seafood-restaurant.jpg",
                    "name": "Legal Seafood",
                    "servesCuisine": "Seafood",
                    "telephone": "1234567",
                    "address" :{
                        "@type": "PostalAddress",
                        "streetAddress": "123 William St",
                        "addressLocality": "New York",
                        "addressRegion": "NY",
                        "postalCode": "10038",
                        "addressCountry": "US"
                    }
                    },
                    "reviewRating": {
                    "@type": "Rating",
                    "ratingValue": "4"
                    },
                    "name": "A good seafood place.",
                    "author": {
                    "@type": "Person",
                    "name": "Bob Smith"
                    },
                    "reviewBody": "The seafood is great.",
                    "publisher": {
                    "@type": "Organization",
                    "name": "Washington Times"
                    }
                }</script>',
					'wp-seopress-pro'
				);
		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_custom':
				// Validation message container (hidden by default, shown by JS if validation fails)
				$html  = '<div class="seopress-notice is-error seopress-custom-schema-validation" style="margin-top: 10px; display: none;">';
				$html .= '<p>';
				$html .= wp_kses_post( __( '<strong>Error:</strong> Your custom schema is missing the required <code>&lt;script type="application/ld+json"&gt;</code> and/or <code>&lt;/script&gt;</code> tags. Please add them to prevent the schema from displaying as visible text on your page.', 'wp-seopress-pro' ) );
				$html .= '</p>';
				$html .= '</div>';

				// Only add the dynamic variables link if docs are available
				$docs = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();
				if ( isset( $docs['schemas']['dynamic'] ) ) {
					$html .= '<p class="description" style="margin-top: 10px;">';
					$html .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="vertical-align: middle;"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg>';
					/* translators: %s: documentation link */
					$html .= sprintf( __( '<a href="%s" target="_blank">You can use dynamic variables in your schema.</a>', 'wp-seopress-pro' ), $docs['schemas']['dynamic'] );
					$html .= '</p>';
				}

				return $html;
		}
	}

	protected function getDetails( $postId = null ) {
		return array(
			array(
				'key'   => '_seopress_pro_rich_snippets_custom',
				'class' => 'seopress-textarea-extra-high-size',
			),
		);
	}
}
