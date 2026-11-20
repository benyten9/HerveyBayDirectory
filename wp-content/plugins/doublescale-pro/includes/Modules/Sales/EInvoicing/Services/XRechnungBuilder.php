<?php
/**
 * Serialise a mapped invoice as XRechnung 3.0 UBL 2.1 XML.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMElement;

/**
 * XRechnungBuilder service.
 *
 * Pure serialisation of the array produced by {@see XRechnungInvoiceMapper}.
 * Element order follows the UBL 2.1 XSD sequences — UBL is order-sensitive,
 * so an element appended out of sequence fails schema validation even when
 * every value is right.
 *
 * Every value goes through a text node: line items are stored unsanitised, so
 * a description such as `<b>&"` must be escaped here and nowhere else.
 */
final class XRechnungBuilder {

	public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
	public const PROFILE_ID       = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

	private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	private const NS_CAC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
	private const NS_CBC     = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

	/**
	 * @var DOMDocument
	 */
	private $doc;

	/**
	 * @var string
	 */
	private $currency = 'EUR';

	/**
	 * @param array<string, mixed> $mapped    Mapped invoice.
	 * @param string               $type_code UNTDID 1001 code: 380 invoice, 381 credit note.
	 * @return string XML document.
	 */
	public static function build( array $mapped, string $type_code = '380' ): string {
		return ( new self() )->render( $mapped, $type_code );
	}

	/**
	 * @param array<string, mixed> $m         Mapped invoice.
	 * @param string               $type_code Type code.
	 * @return string
	 */
	private function render( array $m, string $type_code ): string {
		$this->doc               = new DOMDocument( '1.0', 'UTF-8' );
		$this->doc->formatOutput = true;
		$this->currency          = (string) $m['currency'];

		$root = $this->doc->createElementNS( self::NS_INVOICE, 'Invoice' );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC );
		$root->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC );
		$this->doc->appendChild( $root );

		$this->cbc( $root, 'CustomizationID', self::CUSTOMIZATION_ID );
		$this->cbc( $root, 'ProfileID', self::PROFILE_ID );
		$this->cbc( $root, 'ID', $m['number'] );
		$this->cbc( $root, 'IssueDate', $m['issue_date'] );
		if ( ! empty( $m['due_date'] ) ) {
			$this->cbc( $root, 'DueDate', $m['due_date'] );
		}
		$this->cbc( $root, 'InvoiceTypeCode', $type_code );
		foreach ( $m['notes'] as $note ) {
			$this->cbc( $root, 'Note', $note );
		}
		$this->cbc( $root, 'DocumentCurrencyCode', $this->currency );
		$this->cbc( $root, 'BuyerReference', $m['buyer_reference'] );
		if ( ! empty( $m['invoice_period'] ) ) {
			$period = $this->cac( $root, 'InvoicePeriod' );
			$this->cbc( $period, 'StartDate', $m['invoice_period']['start'] );
			$this->cbc( $period, 'EndDate', $m['invoice_period']['end'] );
		}

		$this->seller( $root, $m['seller'] );
		$this->buyer( $root, $m['buyer'] );
		if ( ! empty( $m['delivery_date'] ) ) {
			$this->cbc( $this->cac( $root, 'Delivery' ), 'ActualDeliveryDate', $m['delivery_date'] );
		}
		$this->payment_means( $root, $m['payment'] );

		if ( '' !== (string) $m['payment_terms'] ) {
			$terms = $this->cac( $root, 'PaymentTerms' );
			$this->cbc( $terms, 'Note', $m['payment_terms'] );
		}

		foreach ( $m['allowances'] as $allowance ) {
			$node = $this->cac( $root, 'AllowanceCharge' );
			$this->cbc( $node, 'ChargeIndicator', 'false' );
			$this->cbc( $node, 'AllowanceChargeReasonCode', $allowance['reason_code'] );
			$this->cbc( $node, 'AllowanceChargeReason', $allowance['reason'] );
			$this->cbc( $node, 'MultiplierFactorNumeric', self::decimal( (float) $allowance['percent'], 2 ) );
			$this->amount( $node, 'Amount', (float) $allowance['amount'] );
			$this->amount( $node, 'BaseAmount', (float) $allowance['base_amount'] );
			$this->tax_category( $node, 'TaxCategory', (string) $allowance['category'], (float) $allowance['rate'] );
		}

		$tax_total = $this->cac( $root, 'TaxTotal' );
		$this->amount( $tax_total, 'TaxAmount', (float) $m['totals']['tax_total'] );
		foreach ( $m['tax_subtotals'] as $subtotal ) {
			$node = $this->cac( $tax_total, 'TaxSubtotal' );
			$this->amount( $node, 'TaxableAmount', (float) $subtotal['taxable'] );
			$this->amount( $node, 'TaxAmount', (float) $subtotal['tax'] );
			$this->tax_category(
				$node,
				'TaxCategory',
				(string) $subtotal['category'],
				(float) $subtotal['rate'],
				$subtotal['exemption_code'] ?? null,
				$subtotal['exemption_reason'] ?? null
			);
		}

		$totals   = $m['totals'];
		$monetary = $this->cac( $root, 'LegalMonetaryTotal' );
		$this->amount( $monetary, 'LineExtensionAmount', (float) $totals['line_extension'] );
		$this->amount( $monetary, 'TaxExclusiveAmount', (float) $totals['tax_exclusive'] );
		$this->amount( $monetary, 'TaxInclusiveAmount', (float) $totals['tax_inclusive'] );
		if ( 0.0 !== (float) $totals['allowance_total'] ) {
			$this->amount( $monetary, 'AllowanceTotalAmount', (float) $totals['allowance_total'] );
		}
		if ( 0.0 !== (float) $totals['prepaid'] ) {
			$this->amount( $monetary, 'PrepaidAmount', (float) $totals['prepaid'] );
		}
		if ( 0.0 !== (float) $totals['rounding'] ) {
			$this->amount( $monetary, 'PayableRoundingAmount', (float) $totals['rounding'] );
		}
		$this->amount( $monetary, 'PayableAmount', (float) $totals['payable'] );

		foreach ( $m['lines'] as $line ) {
			$this->line( $root, $line );
		}

		return (string) $this->doc->saveXML();
	}

	/**
	 * @param DOMElement           $root   Root.
	 * @param array<string, mixed> $seller Seller.
	 * @return void
	 */
	private function seller( DOMElement $root, array $seller ): void {
		$party = $this->cac( $this->cac( $root, 'AccountingSupplierParty' ), 'Party' );

		$this->cbc( $party, 'EndpointID', $seller['email'], array( 'schemeID' => 'EM' ) );
		$this->address( $party, $seller );

		if ( ! empty( $seller['vat_id'] ) ) {
			$this->party_tax_scheme( $party, (string) $seller['vat_id'], 'VAT' );
		}
		if ( ! empty( $seller['tax_number'] ) ) {
			$this->party_tax_scheme( $party, (string) $seller['tax_number'], 'FC' );
		}

		$legal = $this->cac( $party, 'PartyLegalEntity' );
		$this->cbc( $legal, 'RegistrationName', $seller['name'] );
		if ( '' !== (string) $seller['registration_number'] ) {
			$this->cbc( $legal, 'CompanyID', $seller['registration_number'] );
		}

		$contact = $this->cac( $party, 'Contact' );
		$this->cbc( $contact, 'Name', $seller['contact_name'] );
		$this->cbc( $contact, 'Telephone', $seller['contact_phone'] );
		$this->cbc( $contact, 'ElectronicMail', $seller['email'] );
	}

	/**
	 * @param DOMElement           $root  Root.
	 * @param array<string, mixed> $buyer Buyer.
	 * @return void
	 */
	private function buyer( DOMElement $root, array $buyer ): void {
		$party = $this->cac( $this->cac( $root, 'AccountingCustomerParty' ), 'Party' );

		$this->cbc( $party, 'EndpointID', $buyer['email'], array( 'schemeID' => 'EM' ) );
		$this->address( $party, $buyer );

		if ( ! empty( $buyer['vat_id'] ) ) {
			$this->party_tax_scheme( $party, (string) $buyer['vat_id'], 'VAT' );
		}

		$legal = $this->cac( $party, 'PartyLegalEntity' );
		$this->cbc( $legal, 'RegistrationName', $buyer['name'] );
		if ( '' !== (string) $buyer['registration_number'] ) {
			$this->cbc( $legal, 'CompanyID', $buyer['registration_number'] );
		}
	}

	/**
	 * @param DOMElement           $party Party.
	 * @param array<string, mixed> $data  Address fields.
	 * @return void
	 */
	private function address( DOMElement $party, array $data ): void {
		$address = $this->cac( $party, 'PostalAddress' );
		if ( '' !== (string) ( $data['street'] ?? '' ) ) {
			$this->cbc( $address, 'StreetName', $data['street'] );
		}
		if ( '' !== (string) ( $data['additional_street'] ?? '' ) ) {
			$this->cbc( $address, 'AdditionalStreetName', $data['additional_street'] );
		}
		$this->cbc( $address, 'CityName', $data['city'] );
		$this->cbc( $address, 'PostalZone', $data['postcode'] );
		if ( '' !== (string) ( $data['state'] ?? '' ) ) {
			$this->cbc( $address, 'CountrySubentity', $data['state'] );
		}
		$country = $this->cac( $address, 'Country' );
		$this->cbc( $country, 'IdentificationCode', $data['country'] );
	}

	/**
	 * @param DOMElement $party      Party.
	 * @param string     $company_id Identifier.
	 * @param string     $scheme     VAT (BT-31/48) or FC (BT-32).
	 * @return void
	 */
	private function party_tax_scheme( DOMElement $party, string $company_id, string $scheme ): void {
		$node = $this->cac( $party, 'PartyTaxScheme' );
		$this->cbc( $node, 'CompanyID', $company_id );
		$this->cbc( $this->cac( $node, 'TaxScheme' ), 'ID', $scheme );
	}

	/**
	 * @param DOMElement           $root    Root.
	 * @param array<string, mixed> $payment Payment.
	 * @return void
	 */
	private function payment_means( DOMElement $root, array $payment ): void {
		$means = $this->cac( $root, 'PaymentMeans' );
		$this->cbc( $means, 'PaymentMeansCode', $payment['means_code'] );
		$this->cbc( $means, 'PaymentID', $payment['payment_id'] );

		$account = $this->cac( $means, 'PayeeFinancialAccount' );
		$this->cbc( $account, 'ID', $payment['iban'] );
		if ( '' !== (string) $payment['account_name'] ) {
			$this->cbc( $account, 'Name', $payment['account_name'] );
		}
		if ( '' !== (string) $payment['bic'] ) {
			$this->cbc( $this->cac( $account, 'FinancialInstitutionBranch' ), 'ID', $payment['bic'] );
		}
	}

	/**
	 * @param DOMElement           $root Root.
	 * @param array<string, mixed> $line Line.
	 * @return void
	 */
	private function line( DOMElement $root, array $line ): void {
		$node = $this->cac( $root, 'InvoiceLine' );
		$this->cbc( $node, 'ID', $line['id'] );
		$this->cbc( $node, 'InvoicedQuantity', self::decimal( (float) $line['quantity'], 4 ), array( 'unitCode' => $line['unit'] ) );
		$this->amount( $node, 'LineExtensionAmount', (float) $line['line_extension'] );

		if ( ! empty( $line['adjustment'] ) ) {
			$adjustment = $line['adjustment'];
			$charge     = $this->cac( $node, 'AllowanceCharge' );
			$this->cbc( $charge, 'ChargeIndicator', $adjustment['charge'] ? 'true' : 'false' );
			$this->cbc( $charge, 'AllowanceChargeReasonCode', $adjustment['reason_code'] );
			$this->cbc( $charge, 'AllowanceChargeReason', $adjustment['reason'] );
			// No BaseAmount: with one, PEPPOL-EN16931-R042 also demands a
			// percentage, and a per-line discount is only known as an amount.
			$this->amount( $charge, 'Amount', (float) $adjustment['amount'] );
		}

		$item = $this->cac( $node, 'Item' );
		if ( '' !== (string) $line['description'] ) {
			$this->cbc( $item, 'Description', $line['description'] );
		}
		$this->cbc( $item, 'Name', $line['name'] );
		$this->tax_category( $item, 'ClassifiedTaxCategory', (string) $line['category'], (float) $line['rate'] );

		$price = $this->cac( $node, 'Price' );
		$this->cbc( $price, 'PriceAmount', self::decimal( (float) $line['price'], 6 ), array( 'currencyID' => $this->currency ) );
	}

	/**
	 * @param DOMElement  $parent Parent.
	 * @param string      $name   TaxCategory or ClassifiedTaxCategory.
	 * @param string      $id     VAT category code.
	 * @param float       $rate   Rate in percent.
	 * @param string|null $code   Exemption reason code (BT-121).
	 * @param string|null $reason Exemption reason text (BT-120).
	 * @return void
	 */
	private function tax_category( DOMElement $parent, string $name, string $id, float $rate, ?string $code = null, ?string $reason = null ): void {
		$node = $this->cac( $parent, $name );
		$this->cbc( $node, 'ID', $id );
		$this->cbc( $node, 'Percent', self::decimal( $rate, 2 ) );
		if ( null !== $code && '' !== $code ) {
			$this->cbc( $node, 'TaxExemptionReasonCode', $code );
		}
		if ( null !== $reason && '' !== $reason ) {
			$this->cbc( $node, 'TaxExemptionReason', $reason );
		}
		$this->cbc( $this->cac( $node, 'TaxScheme' ), 'ID', 'VAT' );
	}

	/**
	 * @param DOMElement $parent Parent.
	 * @param string     $name   Element name.
	 * @param float      $value  Amount.
	 * @return void
	 */
	private function amount( DOMElement $parent, string $name, float $value ): void {
		$this->cbc( $parent, $name, number_format( $value, 2, '.', '' ), array( 'currencyID' => $this->currency ) );
	}

	/**
	 * @param DOMElement $parent Parent.
	 * @param string     $name   Element name.
	 * @return DOMElement
	 */
	private function cac( DOMElement $parent, string $name ): DOMElement {
		$node = $this->doc->createElementNS( self::NS_CAC, 'cac:' . $name );
		$parent->appendChild( $node );

		return $node;
	}

	/**
	 * @param DOMElement            $parent     Parent.
	 * @param string                $name       Element name.
	 * @param mixed                 $value      Text value.
	 * @param array<string, string> $attributes Attributes.
	 * @return DOMElement
	 */
	private function cbc( DOMElement $parent, string $name, $value, array $attributes = array() ): DOMElement {
		$node = $this->doc->createElementNS( self::NS_CBC, 'cbc:' . $name );
		$node->appendChild( $this->doc->createTextNode( self::clean( (string) $value ) ) );
		foreach ( $attributes as $attribute => $attribute_value ) {
			$node->setAttribute( $attribute, $attribute_value );
		}
		$parent->appendChild( $node );

		return $node;
	}

	/**
	 * Drop characters XML 1.0 cannot carry at all (text nodes escape the rest).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function clean( string $value ): string {
		return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
	}

	/**
	 * Fixed-point decimal with trailing zeros trimmed (keeps at least 2 places
	 * for amounts; quantities/prices may be whole numbers).
	 *
	 * @param float $value    Value.
	 * @param int   $decimals Max decimals.
	 * @return string
	 */
	private static function decimal( float $value, int $decimals ): string {
		$text = number_format( $value, $decimals, '.', '' );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}

		return '-0' === $text ? '0' : $text;
	}
}
