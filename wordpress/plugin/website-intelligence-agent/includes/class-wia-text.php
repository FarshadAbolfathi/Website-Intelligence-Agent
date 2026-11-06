<?php
/**
 * Language handling for fa/ar/en. Behaviour is pinned to the Python engine by shared/normalization-vectors.json.
 * This class has no WordPress dependencies, so its tests can run on plain PHP.
 */
if ( ! defined( 'ABSPATH' ) && ! defined( 'WIA_TEXT_STANDALONE' ) ) {
	exit;
}

class WIA_Text {
	const FA_SUFFIXES = array( 'هایی', 'هایش', 'های', 'ترین', 'ها', 'تر' );
	const AR_PREFIXES = array( 'وال' => 4, 'بال' => 4, 'فال' => 4, 'کال' => 4, 'لل' => 4, 'ال' => 3 );

	private static $language = null;
	private static $stop     = null;
	private static $soft     = null;

	public static function data() {
		if ( null === self::$language ) {
			self::$language = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/language.json' ), true );
		}
		return self::$language;
	}

	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	public static function normalize( $text ) {
		$s = (string) $text;
		if ( '' === $s ) {
			return '';
		}
		// Combining harakat, hamza and madda above letters are removed first, so decomposed spellings match too.
		$s = preg_replace( '/[\x{064B}-\x{0655}\x{0670}]/u', '', $s );
		$s = strtr(
			$s,
			array(
				'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه',
				'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				"\u{200C}" => ' ', "\u{200D}" => '', "\u{200E}" => '', "\u{200F}" => '', "\u{202A}" => '', "\u{202B}" => '',
				"\u{202C}" => '', "\u{00A0}" => ' ', 'ـ' => '',
			)
		);
		$s = preg_replace( '/(?<=\d)[,٬،](?=\d{3}(?:\D|$))/u', '', $s );
		$s = self::lower( $s );
		$s = preg_replace( '/[^\p{L}\p{N}\p{M}\s.\-+]/u', ' ', $s );
		$s = preg_replace( '/(?<!\d)[.\-+]|[.\-+](?!\d)/u', ' ', $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	private static function len( $s ) {
		return preg_match_all( '/./us', $s );
	}

	private static function starts( $s, $p ) {
		return 0 === strpos( $s, $p );
	}

	private static function ends( $s, $p ) {
		return strlen( $s ) >= strlen( $p ) && substr( $s, -strlen( $p ) ) === $p;
	}

	public static function stem( $word ) {
		if ( preg_match( '/^[a-z0-9.+\-]+$/', $word ) ) {
			$l = strlen( $word );
			if ( $l > 4 && self::ends( $word, 'ies' ) ) {
				return substr( $word, 0, -3 ) . 'y';
			}
			if ( $l > 3 && self::ends( $word, 's' ) && ! self::ends( $word, 'ss' ) ) {
				return substr( $word, 0, -1 );
			}
			return $word;
		}
		foreach ( self::AR_PREFIXES as $prefix => $min_rest ) {
			if ( self::starts( $word, $prefix ) && self::len( $word ) - self::len( $prefix ) >= $min_rest ) {
				$word = substr( $word, strlen( $prefix ) );
				break;
			}
		}
		foreach ( self::FA_SUFFIXES as $suffix ) {
			if ( self::ends( $word, $suffix ) && self::len( $word ) - self::len( $suffix ) >= 3 ) {
				return substr( $word, 0, -strlen( $suffix ) );
			}
		}
		return $word;
	}

	public static function stopwords() {
		if ( null === self::$stop ) {
			self::$stop = array_flip( self::data()['stopwords'] );
		}
		return self::$stop;
	}

	/**
	 * Soft words for this question. «كيف» (Arabic "how") and «کیف» (Persian "bag") are the same string after
	 * normalisation, so such words are soft only when the question itself is Arabic.
	 */
	public static function soft_stems( $text = '' ) {
		if ( null === self::$soft ) {
			self::$soft = array();
			foreach ( self::data()['soft_words'] as $w ) {
				self::$soft[ self::stem( $w ) ] = 1;
			}
		}
		if ( '' !== $text && 'ar' === self::detect_language( $text, 'fa' ) ) {
			$out = self::$soft;
			foreach ( self::data()['arabic_function_words']['words'] as $w ) {
				$out[ self::stem( $w ) ] = 1;
			}
			return $out;
		}
		return self::$soft;
	}

	private static $synonyms = null;

	/** @return string[] the token and every word that means the same (shared/language.json synonyms) */
	public static function synonyms_of( $token ) {
		if ( null === self::$synonyms ) {
			self::$synonyms = array();
			foreach ( self::data()['synonyms']['groups'] as $group ) {
				$stems = array();
				foreach ( $group as $w ) {
					$stems[] = self::stem( self::normalize( $w ) );
				}
				foreach ( $stems as $s ) {
					self::$synonyms[ $s ] = array_values( array_unique( array_merge( isset( self::$synonyms[ $s ] ) ? self::$synonyms[ $s ] : array(), $stems ) ) );
				}
			}
		}
		return isset( self::$synonyms[ $token ] ) ? self::$synonyms[ $token ] : array( $token );
	}

	/**
	 * «سفارشم» -> «سفارش»: forms without a colloquial or possessive ending, longest ending first. $unknown adds the
	 * endings tried only for words the site does not contain («تولیدی» -> «تولید»).
	 */
	public static function suffix_variants( $token, $unknown = false ) {
		if ( preg_match( '/^[a-z0-9.+\-]+$/', $token ) ) {
			return array();
		}
		$v   = self::data()['word_variants'];
		$out = array();
		foreach ( $unknown ? array_merge( $v['suffixes'], $v['unknown_only_suffixes'] ) : $v['suffixes'] as $s ) {
			if ( self::ends( $token, $s ) && self::len( $token ) - self::len( $s ) >= $v['min_rest'] ) {
				$out[] = substr( $token, 0, -strlen( $s ) );
			}
		}
		if ( $unknown ) {
			// «تشحنون» -> «شحن»: an Arabic present-tense verb tried as its root.
			$verb = $v['arabic_verb'];
			foreach ( $verb['suffixes'] as $s ) {
				if ( in_array( mb_substr( $token, 0, 1 ), $verb['prefixes'], true ) && self::ends( $token, $s ) && self::len( $token ) - 1 - self::len( $s ) >= $verb['min_rest'] ) {
					$out[] = mb_substr( $token, 1, self::len( $token ) - 1 - self::len( $s ) );
				}
			}
		}
		return $out;
	}

	/** @return string[] indexed words that count as this question word: synonyms and forms without a colloquial ending */
	public static function match_group( $token ) {
		$out = self::synonyms_of( $token );
		foreach ( self::suffix_variants( $token ) as $variant ) {
			$out = array_merge( $out, self::synonyms_of( $variant ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** «میاد», «نمیشه»: a verb the site does not contain says nothing about the subject. */
	public static function looks_like_verb( $token ) {
		$v = self::data()['word_variants'];
		if ( preg_match( '/^[a-z0-9.+\-]+$/', $token ) || self::len( $token ) < 3 || self::len( $token ) > $v['verb_max'] ) {
			return false;
		}
		foreach ( $v['verb_prefixes'] as $p ) {
			if ( self::starts( $token, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] the words of a question that name its subject */
	public static function subject_tokens( $text ) {
		$soft = self::soft_stems( $text );
		return array_values( array_filter( self::tokenize( $text ), function ( $w ) use ( $soft ) { return ! isset( $soft[ $w ] ); } ) );
	}

	/**
	 * A framing word, also with a colloquial ending: «قیمتش» frames a question as «قیمت» does. Used only to decide
	 * whether a question refers back to the conversation. Search keeps «لازمه» and «مناسبه» as words that can match.
	 */
	public static function is_soft( $token, $soft ) {
		if ( isset( $soft[ $token ] ) ) {
			return true;
		}
		foreach ( self::suffix_variants( $token ) as $v ) {
			if ( isset( $soft[ $v ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string[] normalised, stemmed search tokens */
	public static function tokenize( $text ) {
		$out  = array();
		$stop = self::stopwords();
		preg_match_all( '/[\p{L}\p{N}\p{M}.+\-]+/u', self::normalize( $text ), $m );
		foreach ( $m[0] as $raw ) {
			if ( self::len( $raw ) < 2 && ! ctype_digit( $raw ) ) {
				continue;
			}
			if ( isset( $stop[ $raw ] ) ) {
				continue;
			}
			$stem = self::stem( $raw );
			if ( isset( $stop[ $stem ] ) ) {
				continue;
			}
			$out[] = $stem;
		}
		return $out;
	}

	/** Arabic attaches ل ب و ف ک to words. Returns the bare form, or '' when not applicable. */
	public static function clitic_variant( $token ) {
		if ( self::len( $token ) >= 5 && preg_match( '/^[لبوفک]/u', $token ) && ! preg_match( '/^[a-z0-9.+\-]+$/', $token ) ) {
			return preg_replace( '/^./u', '', $token );
		}
		return '';
	}

	/** @return array term => weighted count, for one chunk */
	public static function term_counts( $heading, $text, $boost = '', $language = '' ) {
		$counts = array();
		$body   = self::tokenize( $text );
		foreach ( $body as $t ) {
			$counts[ $t ] = ( isset( $counts[ $t ] ) ? $counts[ $t ] : 0 ) + 1;
		}
		if ( 'ar' === $language ) {
			foreach ( $body as $t ) {
				$v = self::clitic_variant( $t );
				if ( '' !== $v ) {
					$counts[ $v ] = ( isset( $counts[ $v ] ) ? $counts[ $v ] : 0 ) + 1;
				}
			}
		}
		foreach ( self::tokenize( $heading . ' ' . $boost ) as $t ) {
			$counts[ $t ] = ( isset( $counts[ $t ] ) ? $counts[ $t ] : 0 ) + 4;
		}
		$n = count( $body );
		for ( $i = 0; $i + 1 < $n; $i++ ) {
			$a = $body[ $i ];
			$b = $body[ $i + 1 ];
			if ( self::len( $a ) <= 4 && self::len( $b ) <= 4 && ! ctype_digit( $a ) && ! ctype_digit( $b ) ) {
				$j            = $a . $b;
				$counts[ $j ] = ( isset( $counts[ $j ] ) ? $counts[ $j ] : 0 ) + 1;
			}
		}
		return $counts;
	}

	public static function query_terms( $query ) {
		$tokens = array_values( array_unique( self::tokenize( $query ) ) );
		$out    = $tokens;
		$n      = count( $tokens );
		for ( $i = 0; $i + 1 < $n; $i++ ) {
			if ( self::len( $tokens[ $i ] ) <= 4 && self::len( $tokens[ $i + 1 ] ) <= 4 && ! ctype_digit( $tokens[ $i ] ) && ! ctype_digit( $tokens[ $i + 1 ] ) ) {
				$j = $tokens[ $i ] . $tokens[ $i + 1 ];
				if ( ! in_array( $j, $out, true ) ) {
					$out[] = $j;
				}
			}
		}
		return $out;
	}

	public static function detect_language( $text, $default = 'en' ) {
		if ( '' === trim( (string) $text ) ) {
			return $default;
		}
		$arabic = preg_match_all( '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text );
		$latin  = preg_match_all( '/[A-Za-z]/', $text );
		if ( 0 === $arabic && 0 === $latin ) {
			return $default;
		}
		$d        = self::data()['detect'];
		$fa_words = array_flip( $d['fa_words'] );
		$ar_words = array_flip( $d['ar_words'] );
		preg_match_all( '/[\x{0600}-\x{06FF}]+/u', str_replace( "\u{200C}", ' ', $text ), $m );
		if ( $latin > $arabic ) {
			// «international shipping دارید؟»: English terms in a Persian sentence. The language of the grammar words
			// decides, not the number of letters; "Is there گارانتی?" stays English.
			$grammar = self::stopwords() + array_flip( self::data()['soft_words'] );
			$en      = 0;
			$rtl     = 0;
			preg_match_all( '/[A-Za-z]+/', $text, $lm );
			foreach ( $lm[0] as $w ) {
				$en += isset( $grammar[ strtolower( $w ) ] ) ? 1 : 0;
			}
			foreach ( $m[0] as $w ) {
				$rtl += ( isset( $fa_words[ $w ] ) || isset( $ar_words[ $w ] ) || isset( $grammar[ self::normalize( $w ) ] ) ) ? 1 : 0;
			}
			if ( $rtl <= $en ) {
				return 'en';
			}
		}
		$fa = 0.0;
		$ar = 0.0;
		foreach ( $m[0] as $w ) {
			if ( isset( $fa_words[ $w ] ) ) {
				$fa += 2;
			}
			if ( isset( $ar_words[ $w ] ) ) {
				$ar += 2;
			}
			if ( self::starts( $w, 'ال' ) && self::len( $w ) > 3 ) {
				$ar += 1;
			}
		}
		$fa += 1.5 * preg_match_all( '/[پچژگ]/u', $text ) + 0.25 * preg_match_all( '/[یک]/u', $text );
		$ar += 1.0 * preg_match_all( '/[ةىيكأإؤ]/u', $text );
		if ( $fa === $ar ) {
			return in_array( $default, array( 'fa', 'ar' ), true ) ? $default : 'fa';
		}
		return $fa > $ar ? 'fa' : 'ar';
	}

	public static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?؟…。])\s+|\n+/u', (string) $text );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}

	public static function looks_like_injection( $text ) {
		$re = '/' . str_replace( '/', '\/', implode( '|', self::data()['injection_patterns'] ) ) . '/iu';
		return (bool) ( preg_match( $re, self::normalize( $text ) ) || preg_match( $re, self::lower( (string) $text ) ) );
	}

	public static function probes_for_secrets( $text ) {
		return (bool) preg_match( '/' . str_replace( '/', '\/', self::data()['secret_pattern'] ) . '/iu', self::normalize( $text ) );
	}

	public static function intent( $name, $text ) {
		$pattern = self::data()['intents'][ $name ];
		return (bool) preg_match( '/' . str_replace( '/', '\/', $pattern ) . '/iu', $text );
	}

	/** Drop instruction-like sentences from site content. @return array [ cleaned text, dropped count ] */
	public static function strip_injection( $text ) {
		$lines   = array();
		$dropped = 0;
		foreach ( explode( "\n", (string) $text ) as $line ) {
			$kept = array();
			foreach ( self::sentences( $line ) as $sentence ) {
				if ( self::looks_like_injection( $sentence ) ) {
					$dropped++;
				} else {
					$kept[] = $sentence;
				}
			}
			if ( $kept ) {
				$lines[] = implode( ' ', $kept );
			}
		}
		return array( implode( "\n", $lines ), $dropped );
	}

	public static function edit_within_one( $a, $b ) {
		if ( $a === $b ) {
			return true;
		}
		$ca = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
		$cb = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
		if ( abs( count( $ca ) - count( $cb ) ) > 1 ) {
			return false;
		}
		if ( count( $ca ) > count( $cb ) ) {
			list( $ca, $cb ) = array( $cb, $ca );
		}
		$i = 0;
		$j = 0;
		$e = 0;
		while ( $i < count( $ca ) && $j < count( $cb ) ) {
			if ( $ca[ $i ] === $cb[ $j ] ) {
				$i++;
				$j++;
				continue;
			}
			if ( ++$e > 1 ) {
				return false;
			}
			if ( count( $ca ) === count( $cb ) ) {
				$i++;
			}
			$j++;
		}
		return $e + ( count( $cb ) - $j ) + ( count( $ca ) - $i ) <= 1;
	}
}
