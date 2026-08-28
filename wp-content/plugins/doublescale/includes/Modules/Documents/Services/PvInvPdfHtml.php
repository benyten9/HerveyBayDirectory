<?php
/**
 * PDF HTML using the same pv-inv structure as the React document preview.
 *
 * @package DoubleScale\Modules\Documents\Services
 */

namespace DoubleScale\Modules\Documents\Services;

use DoubleScale\Modules\Documents\Constants\DocumentTemplateColor;

defined( 'ABSPATH' ) || exit;

/**
 * PvInvPdfHtml — Dompdf-safe pv-inv layouts (templates 1–8).
 */
final class PvInvPdfHtml {

	/**
	 * @param array<string, mixed> $document Shaped document.
	 * @param string               $doc_type invoice|proposal.
	 * @param array<string, string> $company Company block.
	 * @param int                  $design  Template id 1–8.
	 */
	public static function render( array $document, string $doc_type, array $company, int $design ): string {
		$ctx = self::build_context( $document, $doc_type, $company, $design );

		ob_start();
		?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( $ctx['doc_title'] . ' ' . $ctx['number'] ); ?></title>
	<style><?php echo self::styles( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
</head>
<body>
<?php self::render_body( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $document Document.
	 * @param string               $doc_type Type.
	 * @param array<string, string> $company Company.
	 * @param int                  $design  Design id.
	 * @return array<string, mixed>
	 */
	private static function build_context( array $document, string $doc_type, array $company, int $design ): array {
		$is_invoice   = 'invoice' === $doc_type;
		$doc_title    = $is_invoice ? __( 'Invoice', 'doublescale' ) : __( 'Proposal', 'doublescale' );
		$number_label = $is_invoice ? __( 'Invoice No', 'doublescale' ) : __( 'Proposal No', 'doublescale' );
		$number       = $is_invoice
			? (string) ( $document['invoice_number'] ?? '' )
			: (string) ( $document['proposal_number'] ?? '' );
		$bill_label   = $is_invoice ? __( 'Bill To', 'doublescale' ) : __( 'To', 'doublescale' );

		// Already resolved by the shaper (global for drafts, frozen once sent).
		$currency      = (string) ( $document['currency'] ?? 'USD' );
		$line_items    = is_array( $document['line_items'] ?? null ) ? $document['line_items'] : array();
		$subtotal      = (float) ( $document['subtotal'] ?? 0 );
		$total_tax     = (float) ( $document['total_tax'] ?? 0 );
		$discount_type = (string) ( $document['discount_type'] ?? 'none' );
		$discount_val  = (float) ( $document['discount_value'] ?? 0 );
		$adjustment    = (float) ( $document['adjustment'] ?? 0 );
		$total         = (float) ( $document['total'] ?? 0 );
		$amount_paid   = $is_invoice ? (float) ( $document['amount_paid'] ?? 0 ) : 0.0;
		$balance       = $is_invoice ? max( 0, round( $total - $amount_paid, 2 ) ) : $total;

		$primary = '#4c6fff';
		$custom  = DocumentTemplateColor::normalize( $document['template_color'] ?? null );
		if ( null !== $custom && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $custom ) ) {
			$primary = $custom;
		}

		$company_name  = trim( (string) ( $company['name'] ?? '' ) );
		$logo_data_uri = (string) ( $company['logo_data_uri'] ?? '' );
		$from_lines    = array();
		if ( '' !== $company_name ) {
			$from_lines[] = $company_name;
		}
		$address_block = trim( (string) ( $company['address'] ?? '' ) );
		if ( '' !== $address_block ) {
			$address_lines = preg_split( '/\r\n|\r|\n/', $address_block ) ?: array();
			foreach ( $address_lines as $line ) {
				$line = trim( (string) $line );
				if ( '' !== $line ) {
					$from_lines[] = $line;
				}
			}
		}
		$url_line = trim( (string) ( $company['url'] ?? '' ) );
		if ( '' !== $url_line ) {
			$from_lines[] = $url_line;
		}
		$from_lines = DocumentPdf::append_company_legal_lines( $from_lines, $company );
		if ( empty( $from_lines ) ) {
			$from_lines[] = __( 'Your Company', 'doublescale' );
		}

		$to_lines = array();
		if ( $is_invoice ) {
			$billing = trim( (string) ( $document['billing_address'] ?? '' ) );
			if ( '' !== $billing ) {
				$to_lines = preg_split( '/\r\n|\r|\n/', $billing ) ?: array();
			} elseif ( ! empty( $document['contact'] ) && is_array( $document['contact'] ) ) {
				$contact = $document['contact'];
				$name    = trim(
					trim( (string) ( $contact['first_name'] ?? '' ) ) . ' ' .
					trim( (string) ( $contact['last_name'] ?? '' ) )
				);
				if ( '' !== $name ) {
					$to_lines[] = $name;
				} elseif ( ! empty( $contact['email'] ) ) {
					$to_lines[] = (string) $contact['email'];
				}
			}
		} else {
			if ( ! empty( $document['to_name'] ) ) {
				$to_lines[] = (string) $document['to_name'];
			}
			foreach ( array( 'address', 'city', 'state', 'zip', 'country', 'email', 'phone' ) as $field ) {
				if ( empty( $document[ $field ] ) ) {
					continue;
				}
				if ( 'address' === $field ) {
					$address_lines = preg_split( '/\r\n|\r|\n/', (string) $document[ $field ] ) ?: array();
					foreach ( $address_lines as $line ) {
						$line = trim( (string) $line );
						if ( '' !== $line ) {
							$to_lines[] = $line;
						}
					}
					continue;
				}
				$to_lines[] = (string) $document[ $field ];
			}
		}
		$to_lines = array_values(
			array_filter(
				array_map(
					static function ( $line ) {
						return trim( (string) $line );
					},
					$to_lines
				)
			)
		);

		$dates = $is_invoice
			? array(
				array(
					'label' => __( 'Invoice Date', 'doublescale' ),
					'value' => (string) ( $document['invoice_date'] ?? '' ),
				),
				array(
					'label' => __( 'Due Date', 'doublescale' ),
					'value' => (string) ( $document['due_date'] ?? '' ),
				),
				array(
					'label' => __( 'Currency', 'doublescale' ),
					'value' => $currency,
				),
			)
			: array(
				array(
					'label' => __( 'Date', 'doublescale' ),
					'value' => (string) ( $document['date'] ?? '' ),
				),
				array(
					'label' => __( 'Open Till', 'doublescale' ),
					'value' => (string) ( $document['open_till'] ?? '' ),
				),
				array(
					'label' => __( 'Currency', 'doublescale' ),
					'value' => $currency,
				),
			);

		$sections_before = array();
		$sections_after  = array();

		$raw_sections = is_array( $document['sections'] ?? null ) ? $document['sections'] : array();
		foreach ( $raw_sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$row = array(
				'title' => isset( $section['title'] ) ? (string) $section['title'] : '',
				'body'  => isset( $section['body'] ) ? (string) $section['body'] : '',
			);

			$position = isset( $section['position'] ) ? (string) $section['position'] : 'after_totals';
			if ( 'before_items' === $position ) {
				$sections_before[] = $row;
			} else {
				$sections_after[] = $row;
			}
		}

		if ( $is_invoice && ! empty( $document['client_note'] ) ) {
			$sections_after[] = array(
				'title' => __( 'Client Note', 'doublescale' ),
				'body'  => (string) $document['client_note'],
			);
		}

		if ( ! empty( $document['terms'] ) ) {
			$terms_title = $is_invoice
				? __( 'Terms', 'doublescale' )
				: __( 'Terms & Conditions', 'doublescale' );
			array_unshift(
				$sections_after,
				array(
					'title' => $terms_title,
					'body'  => (string) $document['terms'],
				)
			);
		}

		// Mirror TotalsCalculator::compute so the displayed discount matches the
		// saved total. before_tax/after_tax are PERCENT discounts with different bases.
		$discount_amount = 0.0;
		if ( $discount_val > 0 ) {
			if ( 'percent' === $discount_type || 'before_tax' === $discount_type ) {
				$discount_amount = round( $subtotal * ( $discount_val / 100 ), 2 );
			} elseif ( 'after_tax' === $discount_type ) {
				$discount_amount = round( ( $subtotal + $total_tax ) * ( $discount_val / 100 ), 2 );
			} elseif ( 'fixed' === $discount_type ) {
				$discount_amount = min( $subtotal, $discount_val );
			}
		}

		$subject = $is_invoice ? '' : trim( (string) ( $document['subject'] ?? '' ) );

		return compact(
			'is_invoice',
			'doc_title',
			'number_label',
			'number',
			'subject',
			'bill_label',
			'currency',
			'line_items',
			'subtotal',
			'total_tax',
			'discount_amount',
			'adjustment',
			'total',
			'amount_paid',
			'balance',
			'primary',
			'logo_data_uri',
			'from_lines',
			'to_lines',
			'dates',
			'sections_before',
			'sections_after',
			'design'
		);
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_body( array $ctx ): void {
		switch ( (int) $ctx['design'] ) {
			case 2:
				self::render_design_two( $ctx );
				break;
			case 3:
				self::render_design_three( $ctx );
				break;
			case 4:
				self::render_design_four( $ctx );
				break;
			case 5:
				self::render_design_modern( $ctx, 'five' );
				break;
			case 6:
				self::render_design_modern( $ctx, 'six', true );
				break;
			case 7:
				self::render_design_modern( $ctx, 'seven', false, true );
				break;
			case 8:
				self::render_design_eight( $ctx );
				break;
			case 1:
			default:
				self::render_design_one( $ctx );
				break;
		}
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_design_one( array $ctx ): void {
		?>
<div class="pv-inv pv-inv-one">
	<div class="pv-inv-body">
		<?php self::render_classic_header( $ctx, true ); ?>
		<?php self::render_sections( $ctx['sections_before'] ?? array() ); ?>
		<?php self::render_items( $ctx, 'colored' ); ?>
		<?php self::render_account_row( $ctx, 'colored' ); ?>
		<?php self::render_sections( $ctx['sections_after'] ?? array() ); ?>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_design_two( array $ctx ): void {
		?>
<div class="pv-inv pv-inv-two-wrap">
	<div class="pv-inv-two">
		<?php self::render_svg_top_two( $ctx['primary'] ); ?>
		<div class="pv-inv-body">
			<?php self::render_classic_header( $ctx, true ); ?>
			<?php self::render_sections( $ctx['sections_before'] ?? array() ); ?>
			<?php self::render_items( $ctx, 'colored' ); ?>
			<?php self::render_account_row( $ctx, 'colored' ); ?>
			<?php self::render_sections( $ctx['sections_after'] ?? array() ); ?>
		</div>
		<?php self::render_svg_footer_two( $ctx['primary'] ); ?>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_design_three( array $ctx ): void {
		?>
<div class="pv-inv pv-inv-three-wrap">
	<div class="pv-inv-three">
		<div class="pv-inv-body">
			<?php self::render_corner_shape( $ctx['primary'], false ); ?>
			<div class="pv-inv-title centered"><h2><?php echo esc_html( strtoupper( $ctx['doc_title'] ) ); ?></h2></div>
			<?php self::render_classic_header( $ctx, false ); ?>
			<?php self::render_sections( $ctx['sections_before'] ?? array() ); ?>
			<?php self::render_items( $ctx, 'colored' ); ?>
			<?php self::render_account_row( $ctx, 'colored' ); ?>
			<?php self::render_sections( $ctx['sections_after'] ?? array() ); ?>
			<?php self::render_corner_shape( $ctx['primary'], true ); ?>
		</div>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_design_four( array $ctx ): void {
		?>
<div class="pv-inv pv-inv-four-wrap">
	<div class="pv-inv-four">
		<?php self::render_svg_top_four( $ctx['primary'] ); ?>
		<div class="pv-inv-body">
			<div class="pv-inv-title centered"><h2><?php echo esc_html( strtoupper( $ctx['doc_title'] ) ); ?></h2></div>
			<table class="pv-inv-header" width="100%" cellspacing="0" cellpadding="0">
				<tr>
					<td width="50%" valign="top" class="pv-inv-from-col">
						<?php self::render_logo( $ctx ); ?>
						<?php self::render_from_block( $ctx ); ?>
						<?php self::render_dates( $ctx ); ?>
					</td>
					<td width="50%" valign="top" class="pv-inv-to-col">
						<?php self::render_to_block( $ctx ); ?>
					</td>
				</tr>
			</table>
			<?php self::render_sections( $ctx['sections_before'] ?? array() ); ?>
			<?php self::render_items( $ctx, 'colored' ); ?>
			<table class="pv-four-bottom" width="100%" cellspacing="0" cellpadding="0">
				<tr>
					<td width="58%" valign="top">
						<?php self::render_bank_placeholder(); ?>
					</td>
					<td width="42%" valign="top" align="right">
						<?php self::render_totals( $ctx, 'four' ); ?>
					</td>
				</tr>
			</table>
			<?php self::render_sections( $ctx['sections_after'] ?? array() ); ?>
		</div>
		<?php self::render_svg_footer_four( $ctx['primary'] ); ?>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx     Context.
	 * @param string               $variant CSS variant class.
	 * @param bool                 $boxed   Border frame.
	 * @param bool                 $titlebar Title bars.
	 */
	private static function render_design_modern( array $ctx, string $variant, bool $boxed = false, bool $titlebar = false ): void {
		$border = $boxed ? ' pv-inv--border-top pv-inv--border-bottom' : '';
		?>
<div class="pv-inv pv-inv-<?php echo esc_attr( $variant ); ?><?php echo esc_attr( $border ); ?>">
	<div class="pv-inv-body">
		<table width="100%" cellspacing="0" cellpadding="0" class="pv-modern-head">
			<tr>
				<td width="50%" valign="top">
					<?php self::render_logo( $ctx ); ?>
				</td>
				<td width="50%" valign="top" align="right">
					<?php if ( $titlebar ) : ?>
						<div class="pv-titlebar"><div class="bar"></div></div>
					<?php endif; ?>
					<div class="pv-inv-title"><h2><?php echo esc_html( strtoupper( $ctx['doc_title'] ) ); ?></h2></div>
					<?php if ( $titlebar ) : ?>
						<div class="pv-titlebar"><div class="bar"></div></div>
					<?php endif; ?>
					<?php self::render_dates( $ctx ); ?>
				</td>
			</tr>
		</table>
		<?php if ( ! $titlebar ) : ?>
			<div class="pv-shape-row">
				<div class="shape-left"></div>
				<div class="shape-right"></div>
			</div>
		<?php endif; ?>
		<table width="100%" cellspacing="0" cellpadding="0" class="pv-address-row">
			<tr>
				<td width="50%" valign="top"><?php self::render_from_block( $ctx ); ?></td>
				<td width="50%" valign="top"><?php self::render_to_block( $ctx ); ?></td>
			</tr>
		</table>
		<?php self::render_sections( $ctx['sections_before'] ?? array() ); ?>
		<?php self::render_items( $ctx, 'neutral' ); ?>
		<?php self::render_account_row( $ctx, 'neutral' ); ?>
		<?php self::render_sections( $ctx['sections_after'] ?? array() ); ?>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_design_eight( array $ctx ): void {
		?>
<div class="pv-inv pv-inv-eight pv-inv--border-bottom">
	<table class="pv-side-tab" width="44" cellspacing="0" cellpadding="0"><tr><td valign="middle" align="center"><span><?php echo esc_html( strtoupper( $ctx['doc_title'] ) ); ?></span></td></tr></table>
	<div class="pv-inv-body pv-inv-body--tab">
		<div class="pv-inv-title"><h2><?php echo esc_html( strtoupper( $ctx['doc_title'] ) ); ?></h2></div>
		<table width="100%" cellspacing="0" cellpadding="0">
			<tr>
				<td width="50%" valign="top">
					<?php self::render_logo( $ctx ); ?>
					<?php self::render_from_block( $ctx ); ?>
				</td>
				<td width="50%" valign="top">
					<?php self::render_to_block( $ctx ); ?>
					<?php self::render_dates( $ctx ); ?>
				</td>
			</tr>
		</table>
		<?php self::render_sections( $ctx['sections_before'] ?? array() ); ?>
		<?php self::render_items( $ctx, 'neutral' ); ?>
		<?php self::render_account_row( $ctx, 'neutral' ); ?>
		<?php self::render_sections( $ctx['sections_after'] ?? array() ); ?>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx      Context.
	 * @param bool                 $title_right Title on right column.
	 */
	private static function render_classic_header( array $ctx, bool $title_right ): void {
		?>
<table class="pv-header-split" width="100%" cellspacing="0" cellpadding="0">
	<tr>
		<td width="50%" valign="top">
			<?php self::render_logo( $ctx ); ?>
			<?php self::render_from_block( $ctx ); ?>
			<?php self::render_dates( $ctx ); ?>
		</td>
		<td width="50%" valign="top">
			<?php if ( $title_right ) : ?>
				<div class="pv-inv-title"><h2><?php echo esc_html( strtoupper( $ctx['doc_title'] ) ); ?></h2></div>
			<?php endif; ?>
			<?php self::render_to_block( $ctx ); ?>
		</td>
	</tr>
</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_logo( array $ctx ): void {
		if ( '' === $ctx['logo_data_uri'] ) {
			return;
		}
		?>
<div class="pv-inv-from-logo">
	<img src="<?php echo esc_attr( $ctx['logo_data_uri'] ); ?>" alt="" />
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_from_block( array $ctx ): void {
		$lines = $ctx['from_lines'];
		?>
<div class="pv-inv-from">
	<h5><?php esc_html_e( 'From', 'doublescale' ); ?></h5>
	<?php if ( ! empty( $lines[0] ) ) : ?>
		<h6><?php echo esc_html( (string) $lines[0] ); ?></h6>
	<?php endif; ?>
	<?php if ( count( $lines ) > 1 ) : ?>
		<p><?php echo esc_html( implode( "\n", array_slice( $lines, 1 ) ) ); ?></p>
	<?php endif; ?>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_to_block( array $ctx ): void {
		$lines = $ctx['to_lines'];
		?>
<div class="pv-inv-to">
	<h5><?php echo esc_html( $ctx['bill_label'] ); ?></h5>
	<?php if ( ! empty( $lines[0] ) ) : ?>
		<h6><?php echo esc_html( (string) $lines[0] ); ?></h6>
	<?php endif; ?>
	<?php if ( count( $lines ) > 1 ) : ?>
		<p><?php echo esc_html( implode( "\n", array_slice( $lines, 1 ) ) ); ?></p>
	<?php elseif ( empty( $lines ) ) : ?>
		<p>—</p>
	<?php endif; ?>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_dates( array $ctx ): void {
		?>
<div class="pv-inv-from-date">
	<p><?php echo esc_html( $ctx['number_label'] ); ?>: <span><?php echo esc_html( $ctx['number'] ); ?></span></p>
	<?php if ( ! $ctx['is_invoice'] && '' !== ( $ctx['subject'] ?? '' ) ) : ?>
		<p><?php esc_html_e( 'Subject', 'doublescale' ); ?>: <span><?php echo esc_html( (string) $ctx['subject'] ); ?></span></p>
	<?php endif; ?>
	<?php foreach ( $ctx['dates'] as $row ) : ?>
		<p><?php echo esc_html( (string) $row['label'] ); ?>: <span><?php echo esc_html( (string) ( $row['value'] ?? '' ) ); ?></span></p>
	<?php endforeach; ?>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx    Context.
	 * @param string               $scheme colored|neutral.
	 */
	private static function render_items( array $ctx, string $scheme ): void {
		?>
<div class="pv-inv-items pv-items-<?php echo esc_attr( $scheme ); ?>">
<table width="100%" cellspacing="0" cellpadding="0">
<thead>
<tr>
	<th width="35"><?php esc_html_e( 'SL.', 'doublescale' ); ?></th>
	<th><?php esc_html_e( 'Item Description', 'doublescale' ); ?></th>
	<th width="70"><?php esc_html_e( 'Unit', 'doublescale' ); ?></th>
	<th width="80"><?php esc_html_e( 'Rate', 'doublescale' ); ?></th>
	<th width="80"><?php esc_html_e( 'Amount', 'doublescale' ); ?></th>
	<?php if ( $ctx['is_invoice'] ) : ?>
		<th width="70"><?php esc_html_e( 'Tax', 'doublescale' ); ?></th>
	<?php endif; ?>
</tr>
</thead>
<tbody>
		<?php
		$row_no   = 0;
		$has_rows = false;
		foreach ( $ctx['line_items'] as $item ) {
			if ( ! empty( $item['optional'] ) ) {
				continue;
			}
			$has_rows = true;
			++$row_no;
			$qty      = (float) ( $item['qty'] ?? 0 );
			$rate     = (float) ( $item['rate'] ?? 0 );
			$amount   = (float) ( $item['amount'] ?? ( $qty * $rate ) );
			$taxes    = array();
			if ( ! empty( $item['tax'] ) && is_array( $item['tax'] ) ) {
				foreach ( $item['tax'] as $tax ) {
					if ( isset( $tax['name'], $tax['rate'] ) ) {
						$taxes[] = $tax['name'] . ' (' . $tax['rate'] . '%)';
					}
				}
			}
			?>
<tr>
	<td><?php echo esc_html( (string) $row_no ); ?>.</td>
	<td>
		<?php echo esc_html( (string) ( $item['description'] ?? '—' ) ); ?>
		<?php if ( ! empty( $item['long_description'] ) ) : ?>
			<br><span class="muted"><?php echo esc_html( (string) $item['long_description'] ); ?></span>
		<?php endif; ?>
	</td>
	<td><?php echo esc_html( (string) $qty ); ?></td>
	<td><?php echo esc_html( self::format_money( $rate, $ctx['currency'] ) ); ?></td>
	<td><?php echo esc_html( self::format_money( $amount, $ctx['currency'] ) ); ?></td>
	<?php if ( $ctx['is_invoice'] ) : ?>
		<td><?php echo esc_html( $taxes ? implode( ', ', $taxes ) : '—' ); ?></td>
	<?php endif; ?>
</tr>
			<?php
		}
		if ( ! $has_rows ) {
			$cols = $ctx['is_invoice'] ? 6 : 5;
			echo '<tr><td colspan="' . (int) $cols . '" class="muted">' . esc_html__( 'No line items.', 'doublescale' ) . '</td></tr>';
		}
		?>
</tbody>
</table>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx    Context.
	 * @param string               $scheme colored|neutral.
	 */
	private static function render_account_row( array $ctx, string $scheme ): void {
		?>
<table class="pv-account-row" width="100%" cellspacing="0" cellpadding="0">
<tr>
	<td width="55%" valign="top"></td>
	<td width="45%" valign="top" align="right">
		<?php self::render_totals( $ctx, $scheme ); ?>
	</td>
</tr>
</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx    Context.
	 * @param string               $variant four|colored|neutral.
	 */
	private static function render_totals( array $ctx, string $variant ): void {
		$total_class = 'four' === $variant || 'colored' === $variant ? 'pv-inv-table-bg' : ( 'neutral' === $variant ? 'pv-inv-table-bg-neutral' : '' );
		?>
<div class="pv-inv-total pv-total-<?php echo esc_attr( $variant ); ?>">
<table cellspacing="0" cellpadding="0" align="right">
<tbody>
<tr class="pv-inv-e-bold">
	<th><?php esc_html_e( 'Subtotal', 'doublescale' ); ?></th>
	<td><?php echo esc_html( self::format_money( $ctx['subtotal'], $ctx['currency'] ) ); ?></td>
</tr>
		<?php if ( $ctx['is_invoice'] && $ctx['total_tax'] > 0 ) : ?>
<tr class="pv-inv-e-bold">
	<th><?php esc_html_e( 'Tax', 'doublescale' ); ?></th>
	<td><?php echo esc_html( self::format_money( $ctx['total_tax'], $ctx['currency'] ) ); ?></td>
</tr>
		<?php endif; ?>
		<?php if ( $ctx['discount_amount'] > 0 ) : ?>
<tr>
	<th><?php esc_html_e( 'Discount', 'doublescale' ); ?></th>
	<td>-<?php echo esc_html( self::format_money( $ctx['discount_amount'], $ctx['currency'] ) ); ?></td>
</tr>
		<?php endif; ?>
		<?php if ( 0.0 !== $ctx['adjustment'] ) : ?>
<tr>
	<th><?php esc_html_e( 'Adjustment', 'doublescale' ); ?></th>
	<td><?php echo esc_html( self::format_money( $ctx['adjustment'], $ctx['currency'] ) ); ?></td>
</tr>
		<?php endif; ?>
<tr class="<?php echo esc_attr( $total_class ); ?>">
	<th><?php esc_html_e( 'Total', 'doublescale' ); ?></th>
	<td><?php echo esc_html( self::format_money( $ctx['total'], $ctx['currency'] ) ); ?></td>
</tr>
		<?php if ( $ctx['is_invoice'] ) : ?>
<tr>
	<th><?php esc_html_e( 'Amount Paid', 'doublescale' ); ?></th>
	<td><?php echo esc_html( self::format_money( $ctx['amount_paid'], $ctx['currency'] ) ); ?></td>
</tr>
<tr class="pv-inv-e-bold">
	<th><?php esc_html_e( 'Balance Due', 'doublescale' ); ?></th>
	<td><?php echo esc_html( self::format_money( $ctx['balance'], $ctx['currency'] ) ); ?></td>
</tr>
		<?php endif; ?>
</tbody>
</table>
</div>
		<?php
	}

	private static function render_bank_placeholder(): void {
		?>
<div class="pv-inv-bank">
	<h4><?php esc_html_e( 'Bank info', 'doublescale' ); ?></h4>
	<div class="pv-bank-info">
		<p><strong><?php esc_html_e( 'Name:', 'doublescale' ); ?></strong></p>
		<p><strong><?php esc_html_e( 'Account no:', 'doublescale' ); ?></strong></p>
		<p><strong><?php esc_html_e( 'Bank info:', 'doublescale' ); ?></strong></p>
	</div>
</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_sections( array $sections ): void {
		if ( empty( $sections ) ) {
			return;
		}
		?>
<div class="pv-inv-sections">
		<?php foreach ( $sections as $section ) : ?>
	<div class="pv-inv-section">
		<?php if ( ! empty( $section['title'] ) ) : ?>
			<h4 class="pv-inv-section-title"><?php echo esc_html( (string) $section['title'] ); ?></h4>
		<?php endif; ?>
		<?php // Bodies are rich text sanitized with wp_kses_post() on save. ?>
		<div class="pv-inv-section-content"><?php echo wp_kses_post( (string) ( $section['body'] ?? '' ) ); ?></div>
	</div>
		<?php endforeach; ?>
</div>
		<?php
	}

	private static function format_money( float $value, string $currency ): string {
		return number_format_i18n( $value, 2 ) . ' ' . $currency;
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function styles( array $ctx ): string {
		$p      = esc_html( $ctx['primary'] );
		$design = (int) $ctx['design'];
		$page   = in_array( $design, array( 2, 4 ), true ) ? '@page { margin: 0; }' : '@page { margin: 14px; }';

		return "
		{$page}
		* { box-sizing: border-box; }
		body { font-family: DejaVu Sans, sans-serif; color: #2d3748; font-size: 11px; line-height: 1.45; margin: 0; padding: 0; }
		.pv-inv { position: relative; width: 100%; background: #fff; }
		.pv-inv-body { padding: 0 28px 20px; position: relative; z-index: 1; }
		.pv-inv-body--tab { padding-left: 52px; }
		.pv-inv-from-logo img { max-height: 70px; max-width: 180px; margin-bottom: 10px; display: block; }
		.pv-inv-from h5, .pv-inv-to h5 { font-size: 13px; font-weight: 600; margin: 0 0 6px; color: #000; }
		.pv-inv-from h6, .pv-inv-to h6 { font-size: 11px; font-weight: 500; margin: 0 0 4px; color: #2d3748; }
		.pv-inv-from p, .pv-inv-to p { font-size: 11px; color: #4a5568; margin: 0; white-space: pre-line; }
		.pv-inv-from-date p { font-size: 11px; font-weight: 700; margin: 0 0 2px; }
		.pv-inv-from-date span { font-weight: 400; }
		.pv-inv-title h2 { font-size: 26px; font-weight: 700; text-transform: uppercase; color: #31343d; margin: 0 0 14px; }
		.pv-inv-title.centered h2, .pv-inv-title.centered { text-align: center; }
		.pv-header-split, .pv-inv-header { margin-bottom: 8px; }
		.pv-account-row { margin-top: 10px; }
		.pv-inv-items table { border-collapse: collapse; width: 100%; margin: 18px 0; }
		.pv-items-colored thead th { background: {$p}; color: #fff; padding: 9px 8px; font-size: 10px; text-align: left; font-weight: 700; }
		.pv-items-neutral thead th { background: #edf2f7; color: #718096; padding: 9px 8px; font-size: 10px; text-align: left; }
		.pv-items-colored tbody tr:nth-child(odd) td { background: #f7fafc; }
		.pv-items-colored tbody tr:nth-child(even) td { background: #edf2f7; }
		.pv-items-neutral tbody tr td { background: #fff; border-bottom: 1px solid #edf2f7; }
		.pv-inv-items td { padding: 10px 8px; font-size: 10px; vertical-align: top; color: #2d3748; }
		.pv-inv-items .muted { color: #718096; font-size: 9px; }
		.pv-inv-total table { border-collapse: collapse; min-width: 240px; width: 100%; }
		.pv-inv-total th { text-align: left; font-weight: 700; padding: 6px 8px 6px 0; font-size: 11px; color: #4a5568; }
		.pv-inv-total td { text-align: right; padding: 6px 0 6px 8px; font-size: 11px; color: #4a5568; }
		.pv-inv-e-bold th, .pv-inv-e-bold td { font-weight: 700; color: #2d3748; }
		.pv-inv-table-bg th, .pv-inv-table-bg td { background: {$p}; color: #fff !important; font-size: 15px; font-weight: 800; padding: 12px 16px; }
		.pv-inv-table-bg-neutral th, .pv-inv-table-bg-neutral td { background: #edf2f7; color: #1a202c !important; font-weight: 800; }
		.pv-total-neutral .pv-inv-table-bg-neutral th, .pv-total-neutral .pv-inv-table-bg-neutral td { border-top: 2px solid {$p}; background: transparent; }
		.pv-total-four .pv-inv-total th { padding: 8px 12px 8px 23px; }
		.pv-total-four .pv-inv-total td { padding: 8px 10px 8px 0; }
		.pv-total-four .pv-inv-table-bg th { padding: 12px 12px 12px 28px; font-size: 15px; }
		.pv-total-four .pv-inv-table-bg td { padding: 12px 16px 12px 0; font-size: 15px; }
		.pv-inv-bank h4 { font-size: 11px; font-weight: 700; margin: 0 0 6px; }
		.pv-bank-info p { margin: 0 0 4px; font-size: 10px; color: #4a5568; }
		.pv-inv-section-title { font-size: 12px; font-weight: 700; margin: 10px 0 4px; }
		.pv-inv-section-content p { font-size: 10px; color: #4a5568; margin: 0; white-space: pre-line; }
		.pv-inv-three-wrap .pv-inv-three { position: relative; min-height: 820px; }
		.pv-inv-three .pv-inv-body { margin: 0 35px; padding: 20px 0 150px; position: relative; }
		.pv-inv-three .pv-corner { position: absolute; width: 110px; height: 110px; z-index: 0; }
		.pv-inv-three .pv-corner-tl { top: 0; left: -36px; }
		.pv-inv-three .pv-corner-br { bottom: 0; right: 0; transform: rotate(180deg); }
		.pv-inv-four-wrap .pv-inv-four { position: relative; padding-bottom: 200px; min-height: 900px; }
		.pv-inv-four .pv-inv-body { margin: -90px 35px 0; padding: 0 0 40px; }
		.pv-inv-four .pv-inv-header { margin-top: 10px; }
		.pv-inv-four .pv-inv-from-col { padding-right: 12px; }
		.pv-inv-four .pv-inv-to-col { padding-left: 12px; }
		.pv-inv-top-shape, .pv-inv-footer-shape { line-height: 0; width: 100%; }
		.pv-inv-top-shape svg, .pv-inv-footer-shape svg { width: 100%; height: auto; display: block; }
		.pv-inv-footer-shape { position: absolute; left: 0; bottom: 0; width: 100%; z-index: 0; }
		.pv-four-bottom { margin-top: 16px; margin-bottom: 12px; }
		.pv-inv-four .pv-inv-sections { margin-top: 12px; }
		.pv-inv-four .pv-inv-sign { position: absolute; right: 50px; bottom: 120px; width: 180px; z-index: 2; text-align: center; }
		.pv-inv-sign__line { border-bottom: 1px solid #a0aec0; height: 48px; }
		.pv-inv-three .pv-inv-body { padding-top: 20px; padding-bottom: 40px; }
		.pv-inv-two-wrap .pv-inv-two { position: relative; padding-bottom: 90px; min-height: 700px; }
		.pv-inv-two .pv-inv-body { margin-top: -20px; position: relative; z-index: 1; }
		.pv-corner { position: absolute; width: 110px; height: 110px; z-index: 0; }
		.pv-corner-tl { top: 0; left: 0; }
		.pv-corner-br { bottom: 0; right: 0; transform: rotate(180deg); }
		.pv-inv--border-top { border-top: 4px solid {$p}; }
		.pv-inv--border-bottom { border-bottom: 4px solid {$p}; }
		.pv-shape-row { width: 100%; height: 8px; margin: 8px 0 14px; }
		.shape-left { float: left; width: 62%; height: 8px; background: {$p}; }
		.shape-right { float: right; width: 35%; height: 8px; background: #edf2f7; }
		.pv-titlebar .bar { height: 4px; background: {$p}; margin: 6px 0; }
		.pv-side-tab { position: absolute; left: 0; top: 0; bottom: 0; background: {$p}; }
		.pv-side-tab span { color: #fff; font-size: 11px; font-weight: 700; }
		.pv-inv-eight { padding-left: 44px; position: relative; min-height: 400px; }
		";
	}

	/**
	 * Inline SVG path for Dompdf (CSS fill on paths is unreliable).
	 *
	 * @param string $d    Path d attribute.
	 * @param string $fill Fill color.
	 */
	private static function svg_path( string $d, string $fill ): string {
		return '<path fill="' . esc_attr( $fill ) . '" d="' . esc_attr( $d ) . '"/>';
	}

	/**
	 * @param string $primary Accent.
	 */
	private static function render_svg_top_two( string $primary ): void {
		?>
<div class="pv-inv-top-shape"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 595 69" width="595" height="69"><?php echo self::svg_path( 'M595 29.2L575.167 22.6C555.333 16 515.667 2.80004 476 7.21378C436.333 11.4625 396.667 33.7375 357 33.6138C317.333 33.7375 277.667 10.0762 238 -3C219.166 -9.32618 198.87 -10.7301 178.5 -10.5162C155.968 -10.2796 133.347 -7.36757 112.514 -0.499996C72.8471 12.8237 39.6666 37.8625 19.8333 53.4137L0 68.8L6.01468e-06 1.38921e-06L19.8333 3.12309e-06C39.6667 4.85698e-06 72.8471 -0.5 112.514 -0.499996C152.18 -0.499993 198.333 -3 238 -3C277.667 -3 308.614 -1.61374 348.28 -1.61374C387.947 -1.61373 427.614 -1.61373 467.28 -1.61373C506.947 -1.61372 555.333 -1.61374 575.167 -1.61374L595 -1.61372L595 29.2Z', $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></svg></div>
		<?php
	}

	/**
	 * @param string $primary Accent.
	 */
	private static function render_svg_footer_two( string $primary ): void {
		?>
<div class="pv-inv-footer-shape"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 595 93" width="595" height="93"><?php echo self::svg_path( 'M0 39.6L19.8333 46.2C39.6667 52.8 79.3333 66 119 61.5862C158.667 57.3375 198.333 35.0625 238 35.1862C277.667 35.0625 317.333 57.3375 357 70.4137C396.667 83.7375 436.333 87.8625 476 74.7862C515.667 61.4625 555.333 30.9375 575.167 15.3862L595 0V118.8H575.167C555.333 118.8 515.667 118.8 476 118.8C436.333 118.8 396.667 118.8 357 118.8C317.333 118.8 277.667 118.8 238 118.8C198.333 118.8 158.667 118.8 119 118.8C79.3333 118.8 39.6667 118.8 19.8333 118.8H0L0 39.6Z', $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></svg></div>
		<?php
	}

	/**
	 * @param string $primary Accent.
	 */
	private static function render_svg_top_four( string $primary ): void {
		?>
<div class="pv-inv-top-shape"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 595 117" width="595" height="117"><?php
		echo self::svg_path( 'M595 117V0H431.187C475.3 15.874 554.243 51.495 592.739 113.272A68.804 68.804 0 01595 117z', $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::svg_path( 'M478.8 33.838a562.361 562.361 0 00-15.522-6.309c-1.595-.614-3.214-1.249-4.857-1.863a284.849 284.849 0 00-3.785-1.434 419.59 419.59 0 00-5.523-2.049 5.944 5.944 0 01-.548-.204c-2.5-.922-5.023-1.823-7.57-2.725a879.463 879.463 0 00-26.497-8.93 230.619 230.619 0 00-5.38-1.7c-1.262-.41-2.5-.8-3.762-1.189a556.549 556.549 0 00-7.166-2.191A1244.362 1244.362 0 00380.359 0H0v42.072S150.125 5.532 305.678 8.603c.285 0 .571.02.857.02 1.69.041 3.404.062 5.118.123 3.762.103 7.499.205 11.261.37 2.523.081 5.047.183 7.57.327 1.333.04 2.666.123 3.976.184 2.309.123 4.618.266 6.904.41 1.809.102 3.618.225 5.428.348 1.738.102 3.475.246 5.19.369 3.023.225 6.046.47 9.07.737 2.047.164 4.095.348 6.118.553 4.047.39 8.071.799 12.07 1.27 1.786.205 3.571.41 5.357.635.785.082 1.547.184 2.333.287 1.547.184 3.095.389 4.642.594a229.6 229.6 0 013.714.512c1.762.246 3.547.492 5.309.758 2.142.307 4.261.635 6.38.983 1.405.205 2.785.43 4.19.676 1.762.287 3.499.573 5.261.901 2.976.533 5.928 1.065 8.88 1.639a508.69 508.69 0 018.118 1.618c.214.041.452.102.667.143.285.062.595.123.881.185 1.404.307 2.785.594 4.166.921 2.095.451 4.19.922 6.261 1.434.143.02.262.041.405.082 1.309.308 2.595.615 3.904.943.214.04.405.102.619.163 2.381.574 4.761 1.188 7.118 1.803l.024.02c1.809.471 3.595.963 5.38 1.455 3.5.983 6.976 1.987 10.428 3.031 1.856.574 3.69 1.147 5.523 1.741z', $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?></svg></div>
		<?php
	}

	/**
	 * @param string $primary Accent.
	 */
	private static function render_svg_footer_four( string $primary ): void {
		?>
<div class="pv-inv-footer-shape"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 595 136" width="595" height="136"><?php
		echo self::svg_path( 'M595 0v136H431.187C475.3 117.548 554.243 76.143 592.739 4.333A83.63 83.63 0 00595 0z', $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::svg_path( 'M478.8 96.667A520.456 520.456 0 01463.278 104c-1.595.714-3.214 1.452-4.857 2.167a259.519 259.519 0 01-3.785 1.666c-1.833.81-3.666 1.596-5.523 2.381a5.643 5.643 0 00-.548.238 557.224 557.224 0 01-7.57 3.167A798.138 798.138 0 01414.498 124c-1.786.69-3.571 1.333-5.38 1.976-1.262.476-2.5.929-3.762 1.381a510.738 510.738 0 01-7.166 2.548A1120.1 1120.1 0 01380.359 136H0V87.095S150.125 129.571 305.678 126c.285 0 .571-.024.857-.024 1.69-.047 3.404-.071 5.118-.143 3.762-.119 7.499-.238 11.261-.428 2.523-.096 5.047-.215 7.57-.381 1.333-.048 2.666-.143 3.976-.215 2.309-.142 4.618-.309 6.904-.476 1.809-.119 3.618-.262 5.428-.404 1.738-.12 3.475-.286 5.19-.429a592.47 592.47 0 009.07-.857c2.047-.191 4.095-.405 6.118-.643 4.047-.452 8.071-.929 12.07-1.476a409.98 409.98 0 005.357-.738c.785-.096 1.547-.215 2.333-.334 1.547-.214 3.095-.452 4.642-.69 1.238-.191 2.476-.381 3.714-.595 1.762-.286 3.547-.572 5.309-.881 2.142-.357 4.261-.738 6.38-1.143 1.405-.238 2.785-.5 4.19-.786 1.762-.333 3.499-.667 5.261-1.048a539.228 539.228 0 008.88-1.904 452.054 452.054 0 008.118-1.881c.214-.048.452-.119.667-.167.285-.071.595-.143.881-.214 1.404-.357 2.785-.691 4.166-1.072 2.095-.523 4.19-1.071 6.261-1.666.143-.024.262-.048.405-.096 1.309-.357 2.595-.714 3.904-1.095.214-.047.405-.119.619-.19a353.851 353.851 0 007.118-2.095l.024-.024a352.3 352.3 0 005.38-1.691c3.5-1.143 6.976-2.31 10.428-3.523 1.856-.667 3.69-1.334 5.523-2.024z', $primary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?></svg></div>
		<?php
	}

	/**
	 * @param string $primary Accent.
	 * @param bool   $footer  Footer corner.
	 */
	private static function render_corner_shape( string $primary, bool $footer ): void {
		$class = $footer ? 'pv-corner pv-corner-br' : 'pv-corner pv-corner-tl';
		?>
<div class="<?php echo esc_attr( $class ); ?>">
<svg width="110" height="110" viewBox="0 0 182 183" fill="none">
<path d="M0 13H19V160L0 183V13Z" fill="#E2E8F0"/>
<path d="M0 0H19V142.676L0 165V0Z" fill="<?php echo esc_attr( $primary ); ?>"/>
<path d="M12 0L12 19L159 19L182 -7.43094e-06L12 0Z" fill="#E2E8F0"/>
<path d="M0 0L8.30517e-07 19L142.676 19L165 -7.21238e-06L0 0Z" fill="<?php echo esc_attr( $primary ); ?>"/>
<circle cx="16.5" cy="16.5" r="16.5" fill="<?php echo esc_attr( $primary ); ?>"/>
</svg>
</div>
		<?php
	}
}
