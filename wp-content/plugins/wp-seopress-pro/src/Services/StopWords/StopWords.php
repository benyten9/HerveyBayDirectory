<?php

namespace SEOPressPro\Services\StopWords;

class StopWords {

	protected $languages;

	protected $words;

	public function __construct( $languages = array() ) {
		$this->setLanguages( $languages );
	}

	public function setLanguages( $languages = array() ) {
		$this->languages = $languages;
		$this->setStopWords();
		return $this;
	}

	public function setStopWords() {
		$finder      = new FinderStopWords();
		$this->words = array();

		foreach ( $this->languages as $key => $value ) {
			$this->words = array_merge( $this->words, $finder->find( $value ) );
		}
	}

	public function clean( $message ) {
		$iterable = preg_split( '/\s+/', $message );
		foreach ( $iterable as $pos => $item ) {
			if ( in_array( mb_strtolower( $item ), $this->words ) || strlen( trim( $item ) ) === 0 ) {
				unset( $iterable[ $pos ] );
			}
		}

		return implode( ' ', $iterable );
	}
}
