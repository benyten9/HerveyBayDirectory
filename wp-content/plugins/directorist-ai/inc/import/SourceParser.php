<?php
/**
 * Bounded data-only source adapters.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,Generic.CodeAnalysis.AssignmentInCondition -- Native DOM/ZipArchive property names and bounded CSV streaming.
// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** No parser executes source formulas, markup, entities or instructions. */
final class SourceParser {
	/** Parse a documented dialect; record paths are JSON pointers. */
	public function parse( string $bytes, string $format, string $records_path = '' ): array {
		if ( '' === $bytes || strlen( $bytes ) > Limits::SOURCE_BYTES ) {
			throw new ContractException( 'source_size', 'Use a nonempty source of at most 10 MiB.' );
		}
		$format = strtolower( $format );
		if ( ! in_array( $format, array( 'csv', 'xlsx', 'json', 'xml', 'html' ), true ) ) {
			throw new ContractException( 'unsupported_source', 'Use CSV, XLSX, JSON, XML or a public HTML page. PDF, DOCX and scanned files need a separate adapter.' );
		}
		$hash         = hash( 'sha256', $bytes );
		$source_bytes = strlen( $bytes );
		if ( 'xlsx' !== $format && ( str_starts_with( $bytes, '%PDF-' ) || str_starts_with( $bytes, "PK\x03\x04" ) || str_starts_with( $bytes, "\x89PNG" ) || str_starts_with( $bytes, "\xFF\xD8" ) ) ) {
			throw new ContractException( 'unsupported_source', 'The source signature is a document, archive or image. Use an explicit supported export.' ); }
		if ( 'xlsx' !== $format ) {
			$bytes = preg_replace( '/^\xEF\xBB\xBF/', '', $bytes );
			if ( ! mb_check_encoding( $bytes, 'UTF-8' ) || str_contains( $bytes, "\0" ) ) {
				throw new ContractException( 'source_encoding', 'Save the source as UTF-8 without binary data.' );
			}
		}
		$rows = $this->{$format}( $bytes, $records_path );
		if ( ! $rows || count( $rows ) > Limits::RECORDS ) {
			throw new ContractException( 'source_records', 'The source must contain 1–10,000 records. Split larger sources explicitly.' );
		}
		$records = array();
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row['data'] ) || ! $row['data'] || self::is_list( $row['data'] ) ) {
				throw new ContractException( 'source_shape', 'Each record must have named properties.' );
			}
			$this->check_value( $row['data'], 0 );
			$records[] = array(
				'id'         => hash( 'sha256', $hash . ':' . $format . ':' . $records_path . ':' . $index ),
				'ordinal'    => $index,
				'data'       => $row['data'],
				'provenance' => $row['provenance'],
			);
		}
		return array(
			'revision'     => Limits::REVISION,
			'format'       => $format,
			'source_hash'  => $hash,
			'source_bytes' => $source_bytes,
			'parser_hash'  => hash_file( 'sha256', __FILE__ ),
			'records_path' => $records_path,
			'records'      => $records,
		);
	}

	public static function is_list( array $value ): bool {
		return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ); }

	private function check_value( $value, int $depth ): void {
		if ( $depth > 16 || ( is_array( $value ) && count( $value ) > Limits::COLUMNS ) || ( is_string( $value ) && strlen( $value ) > Limits::CELL_BYTES ) ) {
			throw new ContractException( 'source_bounds', 'A source record exceeds the nesting, column or 64 KiB cell limit.' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( strlen( (string) $key ) > 256 ) {
					throw new ContractException( 'source_bounds', 'A source property name exceeds 256 bytes.' );
				}
				$this->check_value( $child, $depth + 1 );
			}
		}
	}

	private function csv( string $bytes ): array {
		// fgetcsv accepts an unterminated quote; reject that ambiguous dialect first.
		$quoted = false;
		for ( $i = 0, $length = strlen( $bytes ); $i < $length; ++$i ) {
			if ( '"' === $bytes[ $i ] ) {
				if ( $quoted && $i + 1 < $length && '"' === $bytes[ $i + 1 ] ) {
					++$i;
				} else {
					$quoted = ! $quoted; }
			}
		}
		if ( $quoted ) {
			throw new ContractException( 'source_csv', 'CSV contains an unterminated quoted value.' ); }
		$stream = fopen( 'php://temp', 'w+' );
		fwrite( $stream, $bytes );
		rewind( $stream );
		try {
			$headers = fgetcsv( $stream, 0, ',', '"', '' );
			$this->headers( $headers );
			$rows            = array();
			$line            = 1 + substr_count( substr( $bytes, 0, ftell( $stream ) ), "\n" );
			$previous_offset = ftell( $stream );
			while ( false !== ( $values = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
				$end            = ftell( $stream );
				$physical_lines = substr_count( substr( $bytes, $previous_offset, $end - $previous_offset ), "\n" );
				if ( array( null ) === $values ) {
					$line           += $physical_lines;
					$previous_offset = $end;
					continue; }
				if ( count( $values ) !== count( $headers ) ) {
					throw new ContractException( 'source_csv', 'CSV row ' . $line . ' has a different number of columns.' ); }
				$rows[] = array(
					'data'       => array_combine( $headers, $values ),
					'provenance' => array(
						'row'          => $line,
						'byte_start'   => $previous_offset,
						'byte_end'     => $end,
						'offset_basis' => 'UTF-8 without BOM',
					),
				);
				if ( count( $rows ) > Limits::RECORDS ) {
					throw new ContractException( 'source_records', 'Split sources exceeding 10,000 records.' ); }
				$line           += $physical_lines;
				$previous_offset = $end;
			}
			return $rows;
		} finally {
			fclose( $stream ); }
	}

	private function headers( $headers ): void {
		if ( ! is_array( $headers ) || ! $headers || count( $headers ) > Limits::COLUMNS || count( array_unique( $headers ) ) !== count( $headers ) || in_array( '', $headers, true ) || in_array( null, $headers, true ) ) {
			throw new ContractException( 'source_headers', 'Use 1–200 unique nonempty column names in the first row.' );
		}
	}

	/** Resolve a property without expression evaluation or wildcard expansion. */
	public static function pointer( $value, string $path ) {
		if ( '' === $path ) {
			return $value; }
		if ( '/' !== $path[0] || strlen( $path ) > 1024 ) {
			throw new ContractException( 'source_path', 'Use a bounded JSON pointer beginning with /.' ); }
		foreach ( explode( '/', substr( $path, 1 ) ) as $part ) {
			$part = str_replace( array( '~1', '~0' ), array( '/', '~' ), $part );
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return null; }
			$value = $value[ $part ];
		}
		return $value;
	}

	private function json( string $bytes, string $path ): array {
		try {
			( new JsonDecoder( Limits::SOURCE_BYTES, 20 ) )->decode( $bytes );
			$data = json_decode( $bytes, true, 20, JSON_THROW_ON_ERROR ); } catch ( \Throwable $error ) {
			throw new ContractException( 'source_json', 'JSON must contain valid UTF-8 JSON with bounded nesting.' ); }
			$data = self::pointer( $data, $path );
			if ( ! is_array( $data ) || ! self::is_list( $data ) ) {
				throw new ContractException( 'source_json', 'Select a JSON array of named records using the records path.' ); }
			return array_map(
				static fn( $row, $index ) => array(
					'data'       => $row,
					'provenance' => array( 'pointer' => $path . '/' . $index ),
				),
				$data,
				array_keys( $data )
			);
	}

	private function document( string $bytes, bool $html = false ): \DOMDocument {
		if ( ! class_exists( '\DOMDocument' ) ) {
			throw new ContractException( 'unsupported_dependency', 'Enable PHP DOM/XML to parse this source.' ); }
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $bytes ) && ! $html ) {
			throw new ContractException( 'source_xml', 'XML DTDs and external entities are unsupported.' ); }
		$prior = libxml_use_internal_errors( true );
		try {
			$doc                     = new \DOMDocument();
			$doc->resolveExternals   = false;
			$doc->substituteEntities = false;
			$ok                      = $html ? $doc->loadHTML( '<?xml encoding="UTF-8">' . $bytes, LIBXML_NONET ) : $doc->loadXML( $bytes, LIBXML_NONET );
			if ( ! $ok || ( ! $html && libxml_get_errors() ) ) {
				throw new ContractException( 'source_xml', 'The XML source is malformed.' ); }
			return $doc;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prior ); }
	}

	private function xml_value( \DOMElement $node, int $depth = 0 ) {
		if ( $depth > 16 ) {
			throw new ContractException( 'source_bounds', 'XML nesting exceeds 16 levels.' ); }
		$result    = array();
		$has_child = false;
		$has_text  = false;
		foreach ( $node->attributes as $attribute ) {
			$result[ '@' . $attribute->name ] = $attribute->value; }
		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				if ( trim( $child->textContent ) !== '' ) {
					$has_text = true; }
				continue; }
			$has_child = true;
			$value     = $this->xml_value( $child, $depth + 1 );
			if ( array_key_exists( $child->nodeName, $result ) ) {
				if ( ! is_array( $result[ $child->nodeName ] ) || ! self::is_list( $result[ $child->nodeName ] ) ) {
					$result[ $child->nodeName ] = array( $result[ $child->nodeName ] ); }
				$result[ $child->nodeName ][] = $value;
			} else {
				$result[ $child->nodeName ] = $value; }
		}
		if ( $result && ( ! $has_child || $has_text ) && '' !== trim( $node->textContent ) ) {
			$result['#text'] = trim( $node->textContent ); }
		return $result ? $result : trim( $node->textContent );
	}

	private function xml( string $bytes, string $path ): array {
		$doc  = $this->document( $bytes );
		$rows = array();
		$name = null;
		if ( '' !== $path ) {
			throw new ContractException( 'source_xml', 'XML accepts a root containing homogeneous record elements. Convert other dialects to JSON or CSV.' ); }
		foreach ( $doc->documentElement->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue; }
			if ( null !== $name && $child->nodeName !== $name ) {
				throw new ContractException( 'source_xml', 'XML root children must all be the same record type.' ); }
			$name   = $child->nodeName;
			$rows[] = array(
				'data'       => $this->xml_value( $child ),
				'provenance' => array(
					'element' => $name,
					'index'   => count( $rows ) + 1,
					'line'    => $child->getLineNo(),
				),
			);
		}
		return $rows;
	}

	private function html( string $bytes ): array {
		$doc   = $this->document( $bytes, true );
		$xpath = new \DOMXPath( $doc );
		foreach ( $xpath->query( '//script|//style|//noscript|//template|//*[@hidden]|//*[@aria-hidden="true"]' ) as $node ) {
			$node->parentNode->removeChild( $node ); }
		$tables = $xpath->query( '//table' );
		if ( $tables->length > 1 ) {
			throw new ContractException( 'source_html', 'The page has multiple tables. Export the intended table as CSV.' ); }
		if ( 1 === $tables->length ) {
			$rows    = array();
			$headers = null;
			foreach ( $xpath->query( './/tr', $tables->item( 0 ) ) as $tr ) {
				$values = array();
				foreach ( $xpath->query( './th|./td', $tr ) as $cell ) {
					if ( (int) $cell->getAttribute( 'rowspan' ) > 1 || (int) $cell->getAttribute( 'colspan' ) > 1 ) {
						throw new ContractException( 'source_html', 'Merged HTML table cells need a CSV export.' ); }
					$values[] = trim( $cell->textContent );
				}
				if ( null === $headers ) {
					$this->headers( $values );
					$headers = $values;
					continue; }
				if ( count( $headers ) !== count( $values ) ) {
					throw new ContractException( 'source_html', 'HTML table rows have inconsistent columns.' ); }
				$rows[] = array(
					'data'       => array_combine( $headers, $values ),
					'provenance' => array(
						'table' => 1,
						'row'   => count( $rows ) + 2,
					),
				);
			}
			return $rows;
		}
		$title = $xpath->query( '//title' )->item( 0 );
		$body  = $xpath->query( '//body' )->item( 0 );
		$text  = trim( preg_replace( '/\s+/u', ' ', $body ? $body->textContent : '' ) );
		if ( '' === $text ) {
			throw new ContractException( 'source_html', 'No readable HTML content was found. JavaScript-only or authenticated pages need a file export.' ); }
		return array(
			array(
				'data'       => array(
					'title' => $title ? trim( $title->textContent ) : '',
					'text'  => $text,
				),
				'provenance' => array( 'section' => 'visible_body' ),
			),
		);
	}

	private function xlsx( string $bytes ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new ContractException( 'unsupported_dependency', 'Enable PHP ZipArchive to read XLSX.' ); }
		$file = tempnam( sys_get_temp_dir(), 'dai-xlsx-' );
		chmod( $file, 0600 );
		file_put_contents( $file, $bytes );
		$zip    = new \ZipArchive();
		$opened = false;
		try {
			if ( true !== $zip->open( $file ) ) {
				throw new ContractException( 'source_xlsx', 'XLSX must be a valid unencrypted Office workbook.' ); }
			$opened = true;
			$total  = 0;
			if ( $zip->numFiles > 1000 ) {
				throw new ContractException( 'source_bounds', 'XLSX archive contains too many entries.' ); }
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat   = $zip->statIndex( $i );
				$total += $stat['size'];
				if ( str_contains( $stat['name'], '..' ) || str_starts_with( $stat['name'], '/' ) || ! empty( $stat['encryption_method'] ) || $total > Limits::EXPANDED_BYTES ) {
					throw new ContractException( 'source_bounds', 'XLSX is encrypted, unsafe, or expands beyond 20 MiB.' ); }
			}
			$read     = function ( string $name, bool $optional = false ) use ( $zip ) {
				$content = $zip->getFromName( $name );
				if ( false === $content ) {
					if ( $optional ) {
						return null;
					} throw new ContractException( 'source_xlsx', 'XLSX is missing ' . $name . '.' ); }
				return $this->document( $content );
			};
			$workbook = $read( 'xl/workbook.xml' );
			$sheets   = $workbook->getElementsByTagName( 'sheet' );
			if ( 1 !== $sheets->length ) {
				throw new ContractException( 'source_xlsx', 'Use a workbook with exactly one data sheet.' ); }
			$relationship = $sheets->item( 0 )->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
			$rels         = $read( 'xl/_rels/workbook.xml.rels' );
			$target       = '';
			foreach ( $rels->getElementsByTagName( 'Relationship' ) as $rel ) {
				if ( $rel->getAttribute( 'Id' ) === $relationship && 'External' !== $rel->getAttribute( 'TargetMode' ) ) {
					$target = $rel->getAttribute( 'Target' ); }
			}
			if ( ! preg_match( '#^(?:/xl/|)?worksheets/[a-zA-Z0-9_.-]+\.xml$#D', $target ) ) {
				throw new ContractException( 'source_xlsx', 'Unsupported worksheet relationship.' ); }
			$sheet   = $read( str_starts_with( $target, '/xl/' ) ? substr( $target, 1 ) : 'xl/' . $target );
			$strings = array();
			$shared  = $read( 'xl/sharedStrings.xml', true );
			if ( $shared ) {
				foreach ( $shared->getElementsByTagName( 'si' ) as $si ) {
					$strings[] = $si->textContent; }
			}
			$date_styles = array();
			$styles      = $read( 'xl/styles.xml', true );
			if ( $styles ) {
				$formats = array();
				foreach ( $styles->getElementsByTagName( 'numFmt' ) as $fmt ) {
					$formats[ (int) $fmt->getAttribute( 'numFmtId' ) ] = $fmt->getAttribute( 'formatCode' ); }
				$xfs = $styles->getElementsByTagName( 'cellXfs' )->item( 0 );
				if ( $xfs ) {
					$index = 0;
					foreach ( $xfs->childNodes as $xf ) {
						if ( ! $xf instanceof \DOMElement ) {
							continue;
						} $id                    = (int) $xf->getAttribute( 'numFmtId' );
						$date_styles[ $index++ ] = in_array( $id, array( 14, 15, 16, 17, 22 ), true ) || ( isset( $formats[ $id ] ) && preg_match( '/[yd]/i', preg_replace( '/"[^"]*"|\[[^\]]*\]/', '', $formats[ $id ] ) ) ); }
				}
			}
			$props    = $workbook->getElementsByTagName( 'workbookPr' )->item( 0 );
			$date1904 = $props && in_array( $props->getAttribute( 'date1904' ), array( 'true', '1' ), true );
			$rows     = array();
			$headers  = null;
			foreach ( $sheet->getElementsByTagName( 'row' ) as $row ) {
				$values = array();
				foreach ( $row->getElementsByTagName( 'c' ) as $cell ) {
					if ( $cell->getElementsByTagName( 'f' )->length ) {
						throw new ContractException( 'source_xlsx', 'Export calculated formulas as values before import.' ); }
					if ( ! preg_match( '/^([A-Z]+)[1-9][0-9]*$/D', $cell->getAttribute( 'r' ), $match ) ) {
						throw new ContractException( 'source_xlsx', 'Unsupported cell reference.' ); }
					$column = 0;
					foreach ( str_split( $match[1] ) as $letter ) {
						$column = $column * 26 + ord( $letter ) - 64; }
					if ( $column > Limits::COLUMNS ) {
						throw new ContractException( 'source_bounds', 'XLSX exceeds 200 columns.' ); }
					$type  = $cell->getAttribute( 't' );
					$node  = $cell->getElementsByTagName( 'v' )->item( 0 );
					$value = $node ? $node->textContent : '';
					if ( 's' === $type ) {
						if ( ! isset( $strings[ (int) $value ] ) ) {
							throw new ContractException( 'source_xlsx', 'Invalid shared string reference.' );
						} $value = $strings[ (int) $value ]; } elseif ( 'inlineStr' === $type ) {
						$node  = $cell->getElementsByTagName( 'is' )->item( 0 );
						$value = $node ? $node->textContent : ''; } elseif ( 'b' === $type ) {
							if ( ! in_array( $value, array( '0', '1' ), true ) ) {
								throw new ContractException( 'source_xlsx', 'Invalid boolean cell.' );
							} $value = '1' === $value; } elseif ( '' === $type || 'n' === $type ) {
							if ( '' !== $value && ! is_numeric( $value ) ) {
								throw new ContractException( 'source_xlsx', 'Invalid numeric cell.' ); }
							if ( '' !== $value ) {
								$value = (float) $value;
								if ( ! is_finite( $value ) ) {
														throw new ContractException( 'source_xlsx', 'Nonfinite numeric cell.' );
								} if ( ! empty( $date_styles[ (int) $cell->getAttribute( 's' ) ] ) ) {
									if ( ! $date1904 && ( $value < 1 || floor( $value ) === 60.0 ) ) {
										throw new ContractException( 'source_xlsx', 'This Excel 1900 date is ambiguous. Export an exact ISO date.' ); }
									$epoch  = $date1904 ? 24107 : ( $value < 60 ? 25568 : 25569 );
									$format = floor( $value ) === $value ? 'Y-m-d' : 'Y-m-d\\TH:i:s';
									$value  = gmdate( $format, (int) round( ( $value - $epoch ) * 86400 ) ); }
							}
							} elseif ( ! in_array( $type, array( 'str', 'd' ), true ) ) {
								throw new ContractException( 'source_xlsx', 'Unsupported or error cell type.' ); }
							$values[ $column - 1 ] = $value;
				}
				if ( ! $values ) {
					continue; }
				$width = null === $headers ? max( array_keys( $values ) ) + 1 : count( $headers );
				if ( max( array_keys( $values ) ) >= $width ) {
					throw new ContractException( 'source_xlsx', 'A row extends beyond its headers.' ); }
				$values = array_replace( array_fill( 0, $width, '' ), $values );
				if ( null === $headers ) {
					$headers = array_map( 'strval', $values );
					$this->headers( $headers );
					continue; }
				$rows[] = array(
					'data'       => array_combine( $headers, $values ),
					'provenance' => array(
						'sheet' => $sheets->item( 0 )->getAttribute( 'name' ),
						'row'   => (int) $row->getAttribute( 'r' ),
					),
				);
				if ( count( $rows ) > Limits::RECORDS ) {
					throw new ContractException( 'source_records', 'Split sources exceeding 10,000 records.' ); }
			}
			return $rows;
		} finally {
			if ( $opened ) {
				$zip->close();
			} unlink( $file ); }
	}
}
