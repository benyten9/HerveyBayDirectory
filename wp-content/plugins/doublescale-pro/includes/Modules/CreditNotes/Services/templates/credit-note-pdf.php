<?php
/**
 * PDF HTML template for credit notes.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 *
 * @var array<string, mixed> $document Shaped credit note data.
 * @var array<string, string> $company Company block.
 */

defined( 'ABSPATH' ) || exit;

$number_value = (string) ( $document['credit_note_number'] ?? '' );
// Already resolved by the shaper (global for drafts, frozen once sent).
$currency     = (string) ( $document['currency'] ?? 'USD' );
$total        = (float) ( $document['total'] ?? 0 );
$remaining    = (float) ( $document['remaining'] ?? 0 );
$reason       = (string) ( $document['reason'] ?? '' );
$line_items   = is_array( $document['line_items'] ?? null ) ? $document['line_items'] : array();

$format_money = static function ( float $amount ) use ( $currency ): string {
	return number_format_i18n( $amount, 2 ) . ' ' . $currency;
};

$customer_lines = array();
if ( ! empty( $document['billing_address'] ) ) {
	$customer_lines = preg_split( '/\r\n|\r|\n/', (string) $document['billing_address'] ) ?: array();
} elseif ( ! empty( $document['contact'] ) && is_array( $document['contact'] ) ) {
	$contact = $document['contact'];
	$name    = trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) );
	if ( '' !== $name ) {
		$customer_lines[] = $name;
	}
	if ( ! empty( $contact['email'] ) ) {
		$customer_lines[] = (string) $contact['email'];
	}
}
$customer_lines = array_values(
	array_filter(
		array_map(
			static function ( $line ) {
				return trim( (string) $line );
			},
			$customer_lines
		)
	)
);

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
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php echo esc_html( __( 'Credit Note', 'doublescale' ) . ' ' . $number_value ); ?></title>
	<style>
		body { font-family: DejaVu Sans, sans-serif; color: #1a202c; font-size: 12px; line-height: 1.5; }
		h1 { margin: 0 0 4px; font-size: 22px; }
		h2 { margin: 0; font-size: 14px; color: #4c6fff; text-transform: uppercase; letter-spacing: 0.08em; }
		.header, .meta { width: 100%; margin-bottom: 20px; }
		.meta td { vertical-align: top; width: 50%; }
		.muted { color: #64748b; font-size: 11px; }
		table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
		table.items th, table.items td { border-bottom: 1px solid #e2e8f0; padding: 8px 6px; text-align: left; }
		table.items th { background: #f8fafc; font-size: 11px; text-transform: uppercase; color: #64748b; }
		.totals { margin-top: 16px; width: 100%; }
		.totals td { padding: 4px 6px; }
		.totals .label { text-align: right; color: #64748b; }
		.totals .value { text-align: right; font-weight: 600; width: 120px; }
	</style>
</head>
<body>
	<table class="header">
		<tr>
			<td>
				<h2><?php esc_html_e( 'Credit Note', 'doublescale' ); ?></h2>
				<h1><?php echo esc_html( $number_value ); ?></h1>
				<?php if ( '' !== $reason ) : ?>
					<p class="muted"><?php echo esc_html( $reason ); ?></p>
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
				<strong><?php esc_html_e( 'Bill To', 'doublescale' ); ?></strong><br>
				<?php foreach ( $customer_lines as $line ) : ?>
					<?php echo esc_html( $line ); ?><br>
				<?php endforeach; ?>
			</td>
			<td style="text-align:right;">
				<?php if ( ! empty( $document['credit_note_date'] ) ) : ?>
					<strong><?php esc_html_e( 'Date', 'doublescale' ); ?></strong><br>
					<?php echo esc_html( (string) $document['credit_note_date'] ); ?><br><br>
				<?php endif; ?>
				<strong><?php esc_html_e( 'Status', 'doublescale' ); ?></strong><br>
				<?php echo esc_html( (string) ( $document['status'] ?? '' ) ); ?>
			</td>
		</tr>
	</table>

	<?php if ( ! empty( $line_items ) ) : ?>
		<table class="items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Description', 'doublescale' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'doublescale' ); ?></th>
					<th><?php esc_html_e( 'Rate', 'doublescale' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'doublescale' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $line_items as $item ) : ?>
					<?php if ( ! is_array( $item ) ) { continue; } ?>
					<tr>
						<td><?php echo esc_html( (string) ( $item['description'] ?? $item['name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['qty'] ?? 1 ) ); ?></td>
						<td><?php echo esc_html( $format_money( (float) ( $item['rate'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( $format_money( (float) ( $item['amount'] ?? 0 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<table class="totals">
		<tr>
			<td class="label"><?php esc_html_e( 'Subtotal', 'doublescale' ); ?></td>
			<td class="value"><?php echo esc_html( $format_money( (float) ( $document['subtotal'] ?? 0 ) ) ); ?></td>
		</tr>
		<tr>
			<td class="label"><?php esc_html_e( 'Tax', 'doublescale' ); ?></td>
			<td class="value"><?php echo esc_html( $format_money( (float) ( $document['total_tax'] ?? 0 ) ) ); ?></td>
		</tr>
		<tr>
			<td class="label"><strong><?php esc_html_e( 'Credit Total', 'doublescale' ); ?></strong></td>
			<td class="value"><strong><?php echo esc_html( $format_money( $total ) ); ?></strong></td>
		</tr>
		<tr>
			<td class="label"><?php esc_html_e( 'Remaining Credit', 'doublescale' ); ?></td>
			<td class="value"><?php echo esc_html( $format_money( $remaining ) ); ?></td>
		</tr>
	</table>

	<?php if ( ! empty( $document['terms'] ) ) : ?>
		<div style="margin-top:24px;">
			<strong><?php esc_html_e( 'Terms', 'doublescale' ); ?></strong>
			<p class="muted"><?php echo esc_html( (string) $document['terms'] ); ?></p>
		</div>
	<?php endif; ?>
</body>
</html>
