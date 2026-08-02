<?php
/**
 * PDF HTML template for contracts.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 *
 * @var array<string, mixed> $document Shaped contract data.
 * @var array<string, string> $company Company block.
 */

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\Services\ArabicPdfText;

$number_value = (string) ( $document['contract_number'] ?? '' );
// Already resolved by the shaper (global for drafts, frozen once sent).
$currency     = (string) ( $document['currency'] ?? 'USD' );
$value        = (float) ( $document['contract_value'] ?? 0 );
$description  = (string) ( $document['description'] ?? '' );
$type_name    = '';
if ( ! empty( $document['contract_type']['name'] ) ) {
	$type_name = (string) $document['contract_type']['name'];
} elseif ( ! empty( $document['contract_type_id'] ) && ! empty( $document['contract_type'] ) ) {
	$type_name = (string) $document['contract_type'];
}

$format_money = static function ( float $amount ) use ( $currency ): string {
	return number_format_i18n( $amount, 2 ) . ' ' . $currency;
};

$customer_lines = array();
if ( ! empty( $document['contact'] ) && is_array( $document['contact'] ) ) {
	$contact = $document['contact'];
	if ( ! empty( $contact['company_name'] ) ) {
		$customer_lines[] = (string) $contact['company_name'];
	}
	$name = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
	if ( '' !== $name ) {
		$customer_lines[] = $name;
	}
	if ( ! empty( $contact['email'] ) ) {
		$customer_lines[] = (string) $contact['email'];
	}
	$registration = trim( (string) ( $contact['company_registration_number'] ?? '' ) );
	if ( '' !== $registration ) {
		$customer_lines[] = \DoubleScale\Modules\Documents\Services\DocumentCustomerDetails::registration_line( $registration );
	}
	$tax_vat = trim( (string) ( $contact['tax_vat_number'] ?? '' ) );
	if ( '' !== $tax_vat ) {
		$customer_lines[] = \DoubleScale\Modules\Documents\Services\DocumentCustomerDetails::tax_vat_line( $tax_vat );
	}
}

$company_lines = array();
if ( '' !== trim( (string) ( $company['name'] ?? '' ) ) ) {
	$company_lines[] = (string) $company['name'];
}
$address_block = trim( (string) ( $company['address'] ?? '' ) );
if ( '' !== $address_block ) {
	$address_lines = preg_split( '/\r\n|\r|\n/', $address_block ) ?: array();
	foreach ( $address_lines as $line ) {
		$line = trim( (string) $line );
		if ( '' !== $line ) {
			$company_lines[] = $line;
		}
	}
}
if ( ! empty( $company['url'] ) ) {
	$company_lines[] = (string) $company['url'];
}
$company_lines = \DoubleScale\Modules\Documents\Services\DocumentPdf::append_company_legal_lines( $company_lines, $company );

// Dompdf cannot shape or reorder Arabic itself, so every free-text field that
// reaches the PDF is converted to visual-order presentation forms here.
$customer_lines = array_map( array( ArabicPdfText::class, 'shape' ), $customer_lines );
$company_lines  = array_map( array( ArabicPdfText::class, 'shape' ), $company_lines );
$type_name      = ArabicPdfText::shape( $type_name );
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( __( 'Contract', 'doublescale' ) . ' ' . $number_value ); ?></title>
	<style>
		body { font-family: DejaVu Sans, sans-serif; color: #1a202c; font-size: 12px; line-height: 1.5; }
		h1 { margin: 0 0 4px; font-size: 22px; }
		h2 { margin: 0; font-size: 14px; color: #4c6fff; text-transform: uppercase; letter-spacing: 0.08em; }
		.header, .meta { width: 100%; margin-bottom: 20px; }
		.meta td { vertical-align: top; width: 50%; }
		.muted { color: #64748b; font-size: 11px; }
		.section { margin-top: 18px; }
		.body-content { border: 1px solid #e2e8f0; padding: 16px; border-radius: 6px; }
		/*
		 * Dompdf's default stylesheet maps these to the built-in Courier, a Type1 font
		 * with no glyphs outside Latin-1 -- editor code blocks would render non-Latin
		 * text (Arabic, Cyrillic, ...) as "?". DejaVu Sans Mono keeps the monospace
		 * look while covering those scripts.
		 */
		code, pre, kbd, samp, tt { font-family: DejaVu Sans Mono, monospace; }
		table.summary { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
		table.summary th, table.summary td { border-bottom: 1px solid #e2e8f0; padding: 8px 6px; text-align: left; }
		table.summary th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: #64748b; width: 30%; }
	</style>
</head>
<body>
	<table class="header">
		<tr>
			<td>
				<h2><?php esc_html_e( 'Contract', 'doublescale' ); ?></h2>
				<h1><?php echo esc_html( $number_value ); ?></h1>
				<?php if ( ! empty( $document['subject'] ) ) : ?>
					<p class="muted"><?php echo esc_html( ArabicPdfText::shape( (string) $document['subject'] ) ); ?></p>
				<?php endif; ?>
			</td>
			<td style="text-align:right;">
				<?php foreach ( $company_lines as $line ) : ?>
					<?php if ( 0 === array_search( $line, $company_lines, true ) ) : ?>
						<strong><?php echo esc_html( $line ); ?></strong><br>
					<?php else : ?>
						<span class="muted"><?php echo esc_html( $line ); ?></span><br>
					<?php endif; ?>
				<?php endforeach; ?>
			</td>
		</tr>
	</table>

	<table class="meta">
		<tr>
			<td>
				<strong><?php esc_html_e( 'Customer', 'doublescale' ); ?></strong><br>
				<?php foreach ( $customer_lines as $line ) : ?>
					<?php echo esc_html( $line ); ?><br>
				<?php endforeach; ?>
				<?php if ( empty( $customer_lines ) ) : ?>
					<span class="muted">—</span>
				<?php endif; ?>
			</td>
			<td style="text-align:right;">
				<?php if ( ! empty( $document['start_date'] ) ) : ?>
					<strong><?php esc_html_e( 'Start', 'doublescale' ); ?>:</strong>
					<?php echo esc_html( (string) $document['start_date'] ); ?><br>
				<?php endif; ?>
				<?php if ( ! empty( $document['end_date'] ) ) : ?>
					<strong><?php esc_html_e( 'End', 'doublescale' ); ?>:</strong>
					<?php echo esc_html( (string) $document['end_date'] ); ?><br>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<table class="summary">
		<tr>
			<th><?php esc_html_e( 'Contract Value', 'doublescale' ); ?></th>
			<td><strong><?php echo esc_html( $format_money( $value ) ); ?></strong></td>
		</tr>
		<?php if ( '' !== $type_name ) : ?>
			<tr>
				<th><?php esc_html_e( 'Type', 'doublescale' ); ?></th>
				<td><?php echo esc_html( $type_name ); ?></td>
			</tr>
		<?php endif; ?>
		<?php if ( ! empty( $document['status'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Status', 'doublescale' ); ?></th>
				<td><?php echo esc_html( ucfirst( (string) $document['status'] ) ); ?></td>
			</tr>
		<?php endif; ?>
	</table>

	<?php if ( '' !== trim( wp_strip_all_tags( $description ) ) ) : ?>
		<div class="section">
			<h2><?php esc_html_e( 'Contract Body', 'doublescale' ); ?></h2>
			<div class="body-content">
				<?php
				// Shape after sanitizing so wp_kses_post still sees logical-order text.
				echo ArabicPdfText::shape_html( wp_kses_post( $description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above.
				?>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $document['signed_name'] ) || ! empty( $document['has_signature'] ) ) : ?>
		<div class="section">
			<p><strong><?php esc_html_e( 'Signed by', 'doublescale' ); ?>:</strong>
				<?php echo esc_html( ArabicPdfText::shape( (string) ( $document['signed_name'] ?? '' ) ) ); ?>
			</p>
			<?php if ( ! empty( $document['signed_at'] ) ) : ?>
				<p class="muted"><?php echo esc_html( (string) $document['signed_at'] ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</body>
</html>
