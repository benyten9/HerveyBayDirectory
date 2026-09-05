<?php

/**
 * Email Renderer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Emails;

// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This file builds HTML emails (not WP pages); emails do not run wp_head/wp_enqueue, so styles must be inlined into the HTML body.

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Models\TemplateModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Modules\Emails\Layouts\LayoutHandlerRegistry;
use DoubleScale\Modules\Contacts\Filters\ConditionEvaluator;

/**
 * Renderer for email templates
 */
class EmailRenderer {

	/**
	 * Block registry instance
	 *
	 * @var BlockRegistry
	 */
	private $block_registry;

	/**
	 * Button settings from template
	 *
	 * @var array
	 */
	private $button_settings = array();

	/**
	 * Theme link settings from template
	 *
	 * @var array
	 */
	private $link_settings = array();

	/**
	 * Rendered conditional section IDs for current render
	 *
	 * @var array
	 */
	private $rendered_section_ids = array();

	/**
	 * Tracking context section IDs (when viewing a sent email)
	 *
	 * @var array|null
	 */
	private $tracking_section_ids = null;

	/**
	 * Canvas width in pixels from global settings.
	 *
	 * @var int
	 */
	private $canvas_width = 900;

	/**
	 * Current content width in pixels (canvas width minus section padding).
	 * Public so layout handlers can read it via the global renderer instance.
	 *
	 * @var int
	 */
	public $content_width = 600;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->block_registry = BlockRegistry::instance();
	}

	/**
	 * Render email template
	 *
	 * @param int                                      $template_id Template ID
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @param int|null                                 $tracking_id Optional tracking ID for stored merge tag values
	 * @return string HTML output
	 */
	public function render_template( $template_id, $contact = null, $tracking_id = null ) {
		// Use the TemplateModel to fetch the template
		$template = TemplateModel::find( $template_id );

		if ( ! $template ) {
			return '';
		}

		// Load tracking context section IDs if tracking_id is provided
		if ( $tracking_id ) {
			$this->load_tracking_section_ids( $tracking_id );
		}

		// Set tracking context on contact if tracking_id is provided
		if ( $tracking_id && $contact && method_exists( $contact, 'set_tracking_context' ) ) {
			$contact->set_tracking_context( $tracking_id );
		}

		// Parse the JSON content
		$content = json_decode( $template->body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			// Plain-HTML templates: return as-is, no builder transform.
			return $template->body;
		}

		// Check if this is a builder template with type='builder' structure
		if ( isset( $content['type'] ) && $content['type'] === 'builder' && isset( $content['value'] ) ) {
			$content = $content['value'];
		}

		// Get global settings from content (builder stores them in body.globalSettings)
		$global_settings = isset( $content['globalSettings'] ) ? $content['globalSettings'] : array();

		// Get preview_text from template and process merge tags
		$preview_text = ! empty( $template->preview_text ) ? $template->preview_text : '';
		if ( ! empty( $preview_text ) && $contact ) {
			$preview_text = MergeTagsManager::instance()->process_merge_tags( $preview_text, $contact );
		}

		// Generate HTML for email body
		$html = $this->build_email_structure( $content, $global_settings, $contact, $preview_text );

		return $html;
	}

	/**
	 * Render builder content directly from builder data (without template ID)
	 * Useful for email sequences and campaigns where content is stored in email_body field
	 *
	 * @param array                                    $builder_data Builder content data (sections, globalSettings, buttonSettings)
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @param string                                   $preview_text Optional preview text
	 * @param string                                   $footer_html Optional footer HTML to inject before </body> tag
	 * @return string HTML output
	 */
	public function render_from_builder_data( $builder_data, $contact = null, $preview_text = '', $footer_html = '' ) {
		if ( ! is_array( $builder_data ) ) {
			return '';
		}

		// Reset rendered section IDs for this render
		$this->rendered_section_ids = array();

		// Get global settings from builder data
		$global_settings = isset( $builder_data['globalSettings'] ) ? $builder_data['globalSettings'] : array();

		if ( isset( $builder_data['linkSettings'] ) && is_array( $builder_data['linkSettings'] ) ) {
			$this->link_settings = $builder_data['linkSettings'];
		}
		if ( isset( $builder_data['buttonSettings'] ) && is_array( $builder_data['buttonSettings'] ) ) {
			$this->button_settings = $builder_data['buttonSettings'];
		}

		// Process preview text if provided
		if ( ! empty( $preview_text ) && $contact ) {
			$preview_text = MergeTagsManager::instance()->process_merge_tags( $preview_text, $contact );
		}

		// Generate HTML for email body
		$html = $this->build_email_structure( $builder_data, $global_settings, $contact, $preview_text, $footer_html );

		return $html;
	}

	/**
	 * Get the list of rendered conditional section IDs from the last render
	 *
	 * @return array Array of section IDs that were rendered
	 */
	public function get_rendered_section_ids() {
		return $this->rendered_section_ids;
	}


	/**
	 * FIXED: Email Renderer responsive methods
	 * This version fixes the desktop layout while maintaining mobile stacking
	 *
	 * Replace your existing methods with these.
	 */

	/**
	 * BULLETPROOF Responsive Email Renderer
	 *
	 * This approach works WITHOUT media queries by using:
	 * 1. display: inline-block (naturally wraps when space is insufficient)
	 * 2. min-width + max-width combination for responsive behavior
	 * 3. Ghost tables for Outlook only
	 *
	 * Replace your render_section and build_email_structure methods with these.
	 */

	/**
	 * Build email HTML structure
	 *
	 * @param array                                    $content Template content
	 * @param array                                    $global_settings Global email settings
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @param string                                   $preview_text Preview text for email clients
	 * @param string                                   $footer_html Optional footer HTML
	 * @return string HTML output
	 */
	private function build_email_structure( $content, $global_settings, $contact, $preview_text = '', $footer_html = '' ) {
		// Extract button settings from content if available
		if ( isset( $content['buttonSettings'] ) && is_array( $content['buttonSettings'] ) ) {
			$this->button_settings = $content['buttonSettings'];
		}

		if ( isset( $content['linkSettings'] ) && is_array( $content['linkSettings'] ) ) {
			$this->link_settings = $content['linkSettings'];
		}

		// Extract global settings (with defaults)
		$canvas_color        = isset( $global_settings['canvasColor'] ) ? $global_settings['canvasColor'] : '#ffffff';
		$canvas_width        = isset( $global_settings['canvasWidth'] ) ? $global_settings['canvasWidth'] : 900;
		$background_image    = isset( $global_settings['backgroundImage']['url'] ) ? $global_settings['backgroundImage']['url'] : '';
		$background_repeat   = isset( $global_settings['backgroundRepeat'] ) ? $global_settings['backgroundRepeat'] : 'no-repeat';
		$background_size     = isset( $global_settings['backgroundSize'] ) ? $global_settings['backgroundSize'] : 'cover';
		$background_position = isset( $global_settings['backgroundPosition'] ) ? $global_settings['backgroundPosition'] : 'center';

		// Store canvas width for use in render_section
		$this->canvas_width = $canvas_width;

		if ( ! empty( $background_image ) && false !== strpos( $background_image, '{{ASSETS_URL}}' ) && defined( 'DOUBLESCALE_PLUGIN_URL' ) ) {
			$background_image = str_replace(
				'{{ASSETS_URL}}',
				trailingslashit( DOUBLESCALE_PLUGIN_URL ) . 'assets/images/',
				$background_image
			);
		}

		// Process background image through merge tags if present
		if ( ! empty( $background_image ) && $contact ) {
			$background_image = MergeTagsManager::instance()->process_merge_tags( $background_image, $contact );
		}

		// Use a light gray for outer wrapper background
		$bg_color = '#f7f7f7';

		// Mobile breakpoint for media query fallback
		$mobile_breakpoint = 480;

		// Generate preheader HTML if preview_text is provided
		$preheader_html = '';
		if ( ! empty( $preview_text ) ) {
			$preheader_html = '<div style="display:none;font-size:1px;color:#ffffff;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">'
				. esc_html( $preview_text )
				. str_repeat( '&nbsp;&zwnj;', 50 )
				. '</div>';
		}

		// Start with proper email structure
		$html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
	<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<title>Email Template</title>
		<!--[if !mso]><!-->
		<meta http-equiv="X-UA-Compatible" content="IE=edge" />
		<!--<![endif]-->
		<style type="text/css">
			/* Email Client Compatibility Reset */
			body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
			table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
			img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
			
			/* Reset styles */
			body { margin: 0 !important; padding: 0 !important; background-color: ' . $bg_color . ' !important; }
			table { border-collapse: collapse !important; }
			
			/* Image styles */
			img { max-width: 100% !important; height: auto !important; }
			
			/* Font inheritance */
			* { font-family: Arial, sans-serif; }
			
			/* Decoration lives only on .ds-text-link. Gmail underlines every
			   <a> by default — none !important on `a` cancels that extra line
			   without touching button size/color. */
			a { ' . $this->get_link_css_declarations( false, 'none' ) . ' }
			a { text-decoration: none !important; }
			.ds-text-link { ' . $this->get_link_css_declarations( true ) . ' }

			/* Fallback media query for clients that support it */
			@media only screen and (max-width: ' . $mobile_breakpoint . 'px) {
				.responsive-table {
					width: 100% !important;
				}
				.gallery-item {
					display: block !important;
					width: 100% !important;
				}
				/* Each column stacks full width on mobile */
				.mobile-container {
					display: block !important;
					width: 100% !important;
					max-width: 100% !important;
					box-sizing: border-box !important;
				}
				.mobile-table {
					display: block !important;
					width: 100% !important;
					max-width: 100% !important;
					box-sizing: border-box !important;
				}
			}
		</style>
		<!--[if mso]>
		<style type="text/css">
			table { border-collapse: collapse; border-spacing: 0; }
		</style>
		<noscript>
		<xml>
			<o:OfficeDocumentSettings>
				<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
		</xml>
		</noscript>
		<![endif]-->
	</head>
	<body style="margin: 0; padding: 0; background-color: ' . $bg_color . ';">
		' . $preheader_html . '
		
		<!-- Full-width wrapper table -->
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: ' . $bg_color . ';">
			<tr>
				<td align="center" style="padding: 20px 10px;">';

		// Build canvas inline styles
		$canvas_styles = array(
			'background-color' => $canvas_color,
		);

		// Add background image styles if set
		if ( ! empty( $background_image ) && strpos( $background_image, 'localhost' ) === false ) {
			$canvas_styles['background-image']    = "url('{$background_image}')";
			$canvas_styles['background-repeat']   = $background_repeat;
			$canvas_styles['background-size']     = $background_size;
			$canvas_styles['background-position'] = $background_position;
		}

		$canvas_style_string = $this->build_style_string( $canvas_styles );

		// Main container with max-width (this is key for responsiveness)
		$html .= '
					<!-- Email container - max-width makes it responsive -->
					<table role="presentation" class="responsive-table" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: ' . $canvas_width . 'px; ' . $canvas_style_string . '">
						<tr>
							<td style="padding: 0;">';

		// Process all sections
		if ( isset( $content['sections'] ) && is_array( $content['sections'] ) ) {
			foreach ( $content['sections'] as $index => $section ) {
				if ( $this->section_has_content( $section ) ) {
					$html .= $this->render_section( $section, $contact );
				}
			}
		} elseif ( is_array( $content ) ) {
			$is_section_array = false;
			foreach ( $content as $item ) {
				if ( is_array( $item ) && isset( $item['columns'] ) ) {
					$is_section_array = true;
					break;
				}
			}

			if ( $is_section_array ) {
				foreach ( $content as $index => $section ) {
					if ( $this->section_has_content( $section ) ) {
						$html .= $this->render_section( $section, $contact );
					}
				}
			} else {
				$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
				foreach ( $content as $block ) {
					if ( isset( $block['type'] ) ) {
						$html .= '<tr><td style="padding: 10px;">' . $this->render_block( $block, $contact ) . '</td></tr>';
					}
				}
				$html .= '</table>';
			}
		}

		$html .= '
							</td>
						</tr>
					</table>';

		// Footer
		if ( ! empty( $footer_html ) ) {
			$html .= '
					<!-- Email Footer -->
					<table role="presentation" class="responsive-table" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: ' . esc_attr( $canvas_width ) . 'px;">
						<tr>
							<td style="padding: 20px; text-align: center; font-size: 12px; color: #666; line-height: 1.5;">
								' . $footer_html . '
							</td>
						</tr>
					</table>';
		}

		$html .= '
				</td>
			</tr>
		</table>
	</body>
	</html>';

		return $html;
	}


	/**
	 * Check if a section should be rendered for a specific contact
	 *
	 * Note: Conditional section rendering is a Pro feature. If Pro is not active
	 * but conditions exist, the section will render for all contacts (graceful degradation).
	 *
	 * @param array                                    $section Section data
	 * @param ContactModel|AutomationContactModel|null $contact Contact model
	 * @return bool True if section should be rendered
	 */
	private function should_render_section( $section, $contact ) {
		// If no contact or no conditions, always render
		if ( ! $contact || empty( $section['conditions'] ) || ! is_array( $section['conditions'] ) ) {
			return true;
		}

		// If tracking context is set, use stored section IDs (viewing a sent email)
		if ( ! is_null( $this->tracking_section_ids ) ) {
			// Check if this section ID exists in the tracking data
			if ( isset( $section['id'] ) ) {
				return in_array( $section['id'], $this->tracking_section_ids, true );
			}
			// If section has no ID but has conditions, don't render (safety check)
			return false;
		}

		// No tracking context - this is a new send, evaluate conditions live
		// Conditional sections are a Pro feature
		// Check if Pro is active via filter (Pro plugin sets this to true)
		$is_pro_active = doublescale_is_pro_addon_active();
		if ( ! $is_pro_active ) {
			return true;
		}

		// Extract actual ContactModel if AutomationContactModel is passed
		$actual_contact = $contact instanceof \DoubleScale\Modules\Automations\Models\AutomationContactModel
			? $contact->contact
			: $contact;

		if ( ! $actual_contact ) {
			return false;
		}

		// Pro is active - use shared ConditionEvaluator for in-memory evaluation
		$matches = ConditionEvaluator::instance()->evaluate( $section['conditions'], $actual_contact );

		// Log conditional section evaluation for debugging
		doublescale_get_logger()->debug(
			'Conditional section evaluated',
			array(
				'code'          => 'conditional_section_evaluation',
				'section_id'    => $section['id'] ?? 'unknown',
				'contact_id'    => $actual_contact->id,
				'contact_email' => $actual_contact->email ?? 'N/A',
				'conditions'    => $section['conditions'],
				'matches'       => $matches,
			)
		);

		return $matches;
	}

	/**
	 * Render a section with BULLETPROOF responsive columns
	 *
	 * KEY TECHNIQUE:
	 * - Parent div has font-size:0 to remove whitespace gaps
	 * - Each column is display:inline-block with percentage width
	 * - min-width forces stacking on small screens (NO media query needed!)
	 * - max-width prevents overflow on desktop
	 *
	 * @param array                                    $section Section data
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	private function render_section( $section, $contact ) {
		$has_conditions = ! empty( $section['conditions'] ) && is_array( $section['conditions'] );

		// Check if section should be rendered based on conditions
		$should_render = $this->should_render_section( $section, $contact );

		if ( ! $should_render ) {
			return '';
		}

		// Track conditional section if it has conditions and was rendered
		if ( $has_conditions && isset( $section['id'] ) && ! empty( $section['id'] ) ) {
			if ( ! in_array( $section['id'], $this->rendered_section_ids, true ) ) {
				$this->rendered_section_ids[] = $section['id'];
			}
		}

		// Build section styles, separating margin for the outer wrapper
		$section_styles = array();
		$section_margin = '';
		if ( isset( $section['styles'] ) ) {
			foreach ( $section['styles'] as $property => $value ) {
				if ( is_string( $value ) && false !== strpos( $value, '{{ASSETS_URL}}' ) && defined( 'DOUBLESCALE_PLUGIN_URL' ) ) {
					$value = str_replace(
						'{{ASSETS_URL}}',
						trailingslashit( DOUBLESCALE_PLUGIN_URL ) . 'assets/images/',
						$value
					);
				}
				$css_property = $this->convert_camel_to_kebab( $property );
				if ( 'margin' === $css_property ) {
					$section_margin = $value;
				} else {
					$section_styles[ $css_property ] = $value;
				}
			}
		}

		// Default section styles
		if ( ! isset( $section_styles['background-color'] ) ) {
			$section_styles['background-color'] = 'transparent';
		}
		if ( ! isset( $section_styles['padding'] ) ) {
			$section_styles['padding'] = '40px';
		}

		// Extract padding — apply to <td> (email clients ignore padding on <table>)
		// and reduce content width so grid fits inside padded area
		$section_padding = $section_styles['padding'];
		$padding_px      = $this->parse_padding_to_px( $section_padding );
		unset( $section_styles['padding'] );
		$section_style_string = $this->build_style_string( $section_styles );

		// Get canvas width; content width is reduced by horizontal padding
		$canvas_width        = isset( $this->canvas_width ) ? $this->canvas_width : 900;
		$content_width       = max( 200, $canvas_width - $padding_px['left'] - $padding_px['right'] );
		$this->content_width = $content_width;

		// Start section — wrap with a div for margin if needed
		$html = '';
		if ( $section_margin ) {
			$html .= '<div style="margin: ' . esc_attr( $section_margin ) . ';">';
		}
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="' . $section_style_string . '">';
		$html .= '<tr>';
		$html .= '<td align="center" style="padding: ' . esc_attr( $section_padding ) . ';">';

		// Render columns
		if ( ! empty( $section['columns'] ) ) {
			// Filter out columns with no valid content
			$valid_columns = array();
			foreach ( $section['columns'] as $column ) {
				if ( $this->column_has_content( $column ) ) {
					$valid_columns[] = $column;
				}
			}

			if ( ! empty( $valid_columns ) ) {
				$column_count = count( $valid_columns );

				// Calculate total ratio
				$total_ratio = 0;
				foreach ( $valid_columns as $column ) {
					$column_width = isset( $column['width'] ) ? $column['width'] : 1;
					$total_ratio += $column_width;
				}

				// Calculate minimum width for stacking
				// If a column is less than this width, force stacking
				// 200px is a good breakpoint - below this, text becomes hard to read
				$min_width_for_stacking = 200;

				// =============================================
				// GHOST TABLE PATTERN: Outlook ghost table wraps
				// shared block content. Block content is rendered
				// ONCE to avoid nested conditional comment issues
				// (blocks may contain their own <!--[if mso]> comments
				// which would break if nested inside a parent mso block).
				//
				// Structure per column:
				// <!--[if mso]><table><tr><td ...><![endif]-->
				// <div> (column wrapper visible to all clients)
				// <table> block content </table>
				// </div>
				// <!--[if mso]></td></tr></table><![endif]-->
				// =============================================

				// Wrapper div eliminates whitespace between inline-block columns
				$html .= '<div style="font-size:0; text-align:left;">';

				// Outlook: open ghost table for the row
				$html .= '<!--[if mso]>';
				$html .= '<table role="presentation" width="' . $content_width . '" cellpadding="0" cellspacing="0" border="0" align="center"><tr>';
				$html .= '<![endif]-->';

				foreach ( $valid_columns as $column_index => $column ) {
					$column_width = isset( $column['width'] ) ? $column['width'] : 1;
					$pixel_width  = round( ( $column_width / $total_ratio ) * $content_width );

					$col_parsed = $this->parse_column_styles( $column );
					$mso_valign = ! empty( $col_parsed['vertical_align'] ) ? $col_parsed['vertical_align'] : 'top';

					// Outlook: open ghost cell for this column
					$mso_bgcolor  = ! empty( $col_parsed['bg_color'] ) ? ' bgcolor="' . esc_attr( $col_parsed['bg_color'] ) . '"' : '';
					$mso_bg_style = $col_parsed['bg_style'];

					$html .= '<!--[if mso]>';
					$html .= '<td width="' . $pixel_width . '" valign="' . esc_attr( $mso_valign ) . '"' . $mso_bgcolor . ' style="' . esc_attr( $mso_bg_style ) . '">';
					$html .= '<![endif]-->';

					// Column wrapper: visible to ALL clients
					// Uses display:inline-block for non-Outlook stacking, mso-ignores it via ghost table
					$container_styles = array(
						'width'          => $pixel_width . 'px',
						'vertical-align' => $mso_valign,
						'display'        => 'inline-block',
					);
					if ( ! empty( $col_parsed['bg_color'] ) ) {
						$container_styles['background-color'] = $col_parsed['bg_color'];
					}
					if ( ! empty( $col_parsed['bg_image'] ) ) {
						$container_styles['background-image']    = $col_parsed['bg_image'];
						$container_styles['background-repeat']   = $col_parsed['bg_repeat'];
						$container_styles['background-size']     = $col_parsed['bg_size'];
						$container_styles['background-position'] = $col_parsed['bg_position'];
					}
					$container_style_str = $this->build_style_string( $container_styles );
					$bgcolor_attr        = ! empty( $col_parsed['bg_color'] ) ? ' bgcolor="' . esc_attr( $col_parsed['bg_color'] ) . '"' : '';

					$html .= '<div class="mobile-container"' . $bgcolor_attr . ' style="' . esc_attr( $container_style_str ) . '">';
					$html .= '<table border="0" cellspacing="0" cellpadding="0" width="100%" style="overflow-wrap:anywhere;word-wrap:anywhere;font-size:initial"><tbody><tr>';
					$html .= '<td style="' . esc_attr( $col_parsed['padding'] ) . '" class="mobile-table">';
					$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';

					if ( isset( $column['blocks'] ) && is_array( $column['blocks'] ) ) {
						$html .= $this->render_column_blocks( $column['blocks'], $contact );
					}

					$html .= '</table>';
					$html .= '</td></tr></tbody></table>';
					$html .= '</div>';

					// Outlook: close ghost cell
					$html .= '<!--[if mso]>';
					$html .= '</td>';
					$html .= '<![endif]-->';
				}

				// Outlook: close ghost table row
				$html .= '<!--[if mso]>';
				$html .= '</tr></table>';
				$html .= '<![endif]-->';

				$html .= '</div>';
			}
		}

		$html .= '</td>';
		$html .= '</tr>';
		$html .= '</table>';

		if ( $section_margin ) {
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Check if a section has any valid content (registered blocks)
	 *
	 * @param array $section Section data
	 * @return bool True if section has valid content, false if empty
	 */
	private function section_has_content( $section ) {
		if ( empty( $section['columns'] ) || ! is_array( $section['columns'] ) ) {
			return false;
		}

		foreach ( $section['columns'] as $column ) {
			if ( $this->column_has_content( $column ) ) {
				return true;
			}
		}

		// No valid blocks found in any column
		return false;
	}

	/**
	 * Check if a column has any valid content (registered blocks)
	 *
	 * @param array $column Column data
	 * @return bool True if column has valid content, false if empty
	 */
	private function column_has_content( $column ) {
		if ( empty( $column['blocks'] ) || ! is_array( $column['blocks'] ) ) {
			return false;
		}

		foreach ( $column['blocks'] as $block ) {
			if ( ! isset( $block['type'] ) ) {
				continue;
			}

			// Check if block type is registered
			$block_instance = $this->block_registry->get_block( $block['type'] );
			if ( $block_instance !== null ) {
				// Found at least one valid block
				return true;
			}
		}

		// No valid blocks found in this column
		return false;
	}


	/**
	 * Render blocks in a column with template-aware layout handling
	 * Uses the Layout Handler Registry pattern
	 *
	 * @param array                                    $blocks Array of blocks
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	private function render_column_blocks( $blocks, $contact ) {
		global $doublescale_email_renderer;
		$doublescale_email_renderer = $this;

		$html     = '';
		$i        = 0;
		$registry = LayoutHandlerRegistry::instance();

		while ( $i < count( $blocks ) ) {
			$block = $blocks[ $i ];

			// Try to find a layout handler for this block
			$handler = $registry->find_handler( $block );

			if ( $handler ) {
				// Use handler to render layout
				$html .= $handler->render(
					$blocks,
					$i,
					function ( $block ) use ( $contact ) {
						return $this->render_block( $block, $contact );
					}
				);
			} else {
				// Regular block - render in single row
				$html .= '<tr><td style="padding: 0;">';
				$html .= $this->render_block( $block, $contact );
				$html .= '</td></tr>';
				++$i;
			}
		}

		return $html;
	}

	/**
	 * Render a block
	 *
	 * @param array                                    $block Block data
	 * @param ContactModel|AutomationContactModel|null $contact Contact model for merge tags
	 * @return string HTML output
	 */
	private function render_block( $block, $contact ) {
		if ( ! isset( $block['type'] ) ) {
			return '<!-- Missing block type -->';
		}

		// Store current renderer instance globally so blocks can access button settings
		global $doublescale_email_renderer;
		$doublescale_email_renderer = $this;

		return $this->block_registry->render_block(
			$block['type'],
			isset( $block['props'] ) ? $block['props'] : array(),
			$contact
		);
	}

	/**
	 * Convert camelCase to kebab-case
	 *
	 * @param string $string camelCase string
	 * @return string kebab-case string
	 */
	private function convert_camel_to_kebab( $string ) {
		return strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $string ) );
	}

	/**
	 * Parse padding string (e.g. "40px 32px 0px 32px") to pixel values
	 *
	 * @param string $padding Padding CSS value
	 * @return array{top:int,right:int,bottom:int,left:int}
	 */
	private function parse_padding_to_px( $padding ) {
		$default = array(
			'top'    => 0,
			'right'  => 0,
			'bottom' => 0,
			'left'   => 0,
		);
		if ( empty( $padding ) ) {
			return $default;
		}
		$parts = preg_split( '/\s+/', trim( $padding ), 4 );
		$px    = array_map(
			function ( $p ) {
				return max( 0, (int) preg_replace( '/[^0-9]/', '', $p ) );
			},
			$parts
		);
		if ( count( $px ) === 1 ) {
			return array(
				'top'    => $px[0],
				'right'  => $px[0],
				'bottom' => $px[0],
				'left'   => $px[0],
			);
		}
		if ( count( $px ) === 2 ) {
			return array(
				'top'    => $px[0],
				'right'  => $px[1],
				'bottom' => $px[0],
				'left'   => $px[1],
			);
		}
		if ( count( $px ) >= 4 ) {
			return array(
				'top'    => $px[0],
				'right'  => $px[1],
				'bottom' => $px[2],
				'left'   => $px[3],
			);
		}
		return $default;
	}

	/**
	 * Build CSS style string from array
	 *
	 * @param array $styles Array of CSS properties
	 * @return string CSS style string
	 */
	private function build_style_string( array $styles ) {
		$style_string = '';

		foreach ( $styles as $property => $value ) {
			if ( $value !== null && $value !== '' ) {
				$style_string .= "{$property}: {$value}; ";
			}
		}

		return rtrim( $style_string );
	}

	/**
	 * Get button settings for a specific button style
	 *
	 * @param string $button_style Button style (primary, secondary, tertiary)
	 * @return array Button settings
	 */
	public function get_button_settings( $button_style = 'primary' ) {
		if ( ! empty( $this->button_settings ) && isset( $this->button_settings[ $button_style ] ) ) {
			return $this->button_settings[ $button_style ];
		}

		return array();
	}

	/**
	 * Default theme link settings (matches the builder store).
	 *
	 * @return array
	 */
	public function get_default_link_settings(): array {
		return array(
			'font'           => 'Arial, sans-serif',
			'size'           => 16,
			'letterSpacing'  => '0px',
			'color'          => '#458DC7',
			'bold'           => false,
			'italic'         => false,
			'underline'      => true,
			'strikethrough'  => false,
		);
	}

	/**
	 * Theme link settings for text-block links.
	 *
	 * @return array
	 */
	public function get_link_settings(): array {
		return wp_parse_args( $this->link_settings, $this->get_default_link_settings() );
	}

	/**
	 * CSS declarations for theme links (no trailing semicolon).
	 *
	 * @param bool $important When true, each declaration ends with !important.
	 *                        Use only on .ds-text-link (not bare `a`) so Gmail
	 *                        keeps size/color without restyling buttons.
	 * @return string
	 */
	public function get_link_css_declarations( bool $important = false, ?string $decoration = null ): string {
		$settings = $this->get_link_settings();
		if ( null === $decoration ) {
			$parts = array();
			if ( ! empty( $settings['underline'] ) ) {
				$parts[] = 'underline';
			}
			if ( ! empty( $settings['strikethrough'] ) ) {
				$parts[] = 'line-through';
			}
			$decoration = ! empty( $parts ) ? implode( ' ', $parts ) : 'none';
		}
		$bang = $important ? ' !important' : '';

		return sprintf(
			'font-family: %s%s; font-size: %spx%s; letter-spacing: %s%s; color: %s%s; font-weight: %s%s; font-style: %s%s; text-decoration: %s%s;',
			esc_attr( (string) $settings['font'] ),
			$bang,
			(int) $settings['size'],
			$bang,
			esc_attr( (string) $settings['letterSpacing'] ),
			$bang,
			esc_attr( (string) $settings['color'] ),
			$bang,
			! empty( $settings['bold'] ) ? 'bold' : 'normal',
			$bang,
			! empty( $settings['italic'] ) ? 'italic' : 'normal',
			$bang,
			esc_attr( $decoration ),
			$bang
		);
	}

	/**
	 * Render template with tracking context
	 * Convenience method that automatically sets tracking context and renders
	 *
	 * @param int                                      $template_id Template ID
	 * @param int                                      $tracking_id Communication tracking ID
	 * @param ContactModel|AutomationContactModel|null $contact Contact model (optional, will be fetched from tracking if not provided)
	 * @return string HTML output
	 */
	public function render_template_with_tracking( $template_id, $tracking_id, $contact = null ) {
		// If no contact provided, try to get it from the tracking record
		if ( ! $contact ) {
			$tracking = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel::find( $tracking_id );
			if ( $tracking && $tracking->contact ) {
				$contact = $tracking->contact;
			}
		}

		return $this->render_template( $template_id, $contact, $tracking_id );
	}

	/**
	 * Parse column styles into separated groups for layered rendering.
	 *
	 * Returns:
	 *   'padding'        – CSS padding string (for the innermost <td>)
	 *   'bg_color'       – background-color value
	 *   'bg_style'       – full background CSS string (color + image)
	 *   'bg_image'       – background-image URL
	 *   'bg_repeat'      – background-repeat value
	 *   'bg_size'        – background-size value
	 *   'bg_position'    – background-position value
	 *   'vertical_align' – vertical-align value
	 *   'margin'         – margin value
	 *
	 * @param array $column Column data with optional 'styles' key.
	 * @return array Parsed style groups.
	 */
	private function parse_column_styles( array $column ): array {
		$result = array(
			'padding'        => 'padding:12px;',
			'bg_color'       => '',
			'bg_style'       => '',
			'bg_image'       => '',
			'bg_repeat'      => 'no-repeat',
			'bg_size'        => 'cover',
			'bg_position'    => 'center',
			'vertical_align' => 'top',
			'margin'         => '',
		);

		if ( empty( $column['styles'] ) || ! is_array( $column['styles'] ) ) {
			return $result;
		}

		$bg_parts = array();
		foreach ( $column['styles'] as $property => $value ) {
			if ( $value === '' || $value === null ) {
				continue;
			}
			$css = $this->convert_camel_to_kebab( $property );

			switch ( $css ) {
				case 'padding':
					$result['padding'] = "padding:{$value};";
					break;
				case 'margin':
					$result['margin'] = $value;
					break;
				case 'vertical-align':
					$result['vertical_align'] = $value;
					break;
				case 'background-color':
					$result['bg_color'] = $value;
					$bg_parts[]         = "background-color:{$value};";
					break;
				case 'background-image':
					// Value may already contain url() wrapper from frontend
					if ( stripos( $value, 'url(' ) === 0 ) {
						$result['bg_image'] = $value;
						$bg_parts[]         = "background-image:{$value};";
					} else {
						$result['bg_image'] = "url('{$value}')";
						$bg_parts[]         = "background-image:url('{$value}');";
					}
					break;
				case 'background-repeat':
					$result['bg_repeat'] = $value;
					$bg_parts[]          = "background-repeat:{$value};";
					break;
				case 'background-size':
					$result['bg_size'] = $value;
					$bg_parts[]        = "background-size:{$value};";
					break;
				case 'background-position':
					$result['bg_position'] = $value;
					$bg_parts[]            = "background-position:{$value};";
					break;
			}
		}

		$result['bg_style'] = implode( ' ', $bg_parts );

		return $result;
	}

	/**
	 * Load tracking section IDs from tracking_meta
	 *
	 * @param int $tracking_id Communication tracking ID
	 * @return void
	 */
	private function load_tracking_section_ids( $tracking_id ) {
		$tracking_meta = \DoubleScale\Modules\Tracking\Models\CommunicationTrackingMetaModel::where( 'communication_tracking_id', $tracking_id )
			->where( 'meta_key', 'conditional_sections' )
			->first();

		if ( $tracking_meta && ! empty( $tracking_meta->meta_value ) ) {
			$this->tracking_section_ids = $tracking_meta->meta_value;
		} else {
			// If no tracking data, set empty array (no conditional sections were sent)
			$this->tracking_section_ids = array();
		}
	}
}
