<?php
/**
 * The answer pipeline, in the same order as the Python engine. Without a language model it finds and quotes the site's
 * own text: it does not write new sentences, and it says so when the site has no answer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Chat {
	private static $messages = null;

	public static function msg( $lang, $key, $vars = array() ) {
		if ( null === self::$messages ) {
			self::$messages = json_decode( (string) file_get_contents( WIA_DIR . 'shared/chat-messages.json' ), true );
		}
		$text = isset( self::$messages['text'][ $lang ][ $key ] ) ? self::$messages['text'][ $lang ][ $key ] : self::$messages['text']['en'][ $key ];
		foreach ( $vars as $k => $v ) {
			$text = str_replace( '{' . $k . '}', $v, $text );
		}
		return $text;
	}

	private static function currencies() {
		self::msg( 'en', 'greeting' );
		return self::$messages['currency'];
	}

	/** Ordinary dialogue replies, decided before any search. */
	const SMALLTALK = array( 'farewell' => 'farewell', 'howareyou' => 'howareyou', 'identity' => 'identity' );

	/**
	 * One question, without conversation state beyond the subject under discussion. converse() adds the dialogue:
	 * rejections, misses counted toward a handoff, and learning.
	 *
	 * @return array text, language, mode, sources[url,title,checked_at], stale, context_doc, suggestions[], actions[label,url], alts[], learn
	 */
	public static function answer( $question, $context_doc = 0, $previous_question = '', $split = true ) {
		$question = trim( mb_substr( (string) $question, 0, 1000 ) );
		$default  = WIA_DB::default_language();
		$lang     = WIA_Text::detect_language( $question, $default );
		$reply    = function ( $mode, $text, $sources = array(), $doc = 0, $suggestions = array() ) use ( $lang, $context_doc ) {
			return self::shape( array( 'text' => $text, 'language' => $lang, 'mode' => $mode, 'sources' => $sources, 'context_doc' => $doc ? $doc : $context_doc, 'suggestions' => $suggestions ) );
		};
		$normalized = WIA_Text::normalize( $question );
		if ( '' === $question || WIA_Text::intent( 'greeting', $question ) ) {
			return $reply( 'smalltalk', self::msg( $lang, 'greeting' ), array(), 0, self::starters( $lang ) );
		}
		if ( WIA_Text::intent( 'thanks', $question ) ) {
			return $reply( 'smalltalk', self::msg( $lang, 'thanks' ) );
		}
		foreach ( self::SMALLTALK as $intent => $key ) {
			if ( WIA_Text::intent( $intent, $normalized ) ) {
				return $reply( 'smalltalk', self::msg( $lang, $key ), array(), 0, 'farewell' === $intent ? array() : self::starters( $lang ) );
			}
		}
		if ( WIA_Text::looks_like_injection( $question ) || WIA_Text::probes_for_secrets( $question ) ) {
			return $reply( 'guard', self::msg( $lang, 'guard' ) );
		}

		$faq = self::match_faq( $question, $lang );
		if ( $faq ) {
			return $reply( 'faq', $faq['answer'] );
		}
		if ( WIA_Text::intent( 'contact', $normalized ) ) {
			$contact = self::contact( $lang );
			// «آدرستون کجاست؟»: the contact page may hold the very detail asked for, so quote it with the buttons.
			$hits = WIA_Knowledge::search( $question, 5, $cinfo );
			if ( $hits && self::best_coverage( $hits ) >= WIA_Knowledge::MIN_COVERAGE && 'contact' === $hits[0]['content_type'] ) {
				$answer = self::extractive( self::match_query( $question, $cinfo ), $hits, $lang );
				if ( $contact ) {
					$answer['actions'] = $contact['actions'];
				}
				return $answer;
			}
			if ( $contact ) {
				return $contact;
			}
		}

		if ( WIA_Text::intent( 'catalog', $normalized ) || WIA_Text::intent( 'catalog', $question ) ) {
			$list = WIA_Catalog::listing();
			if ( $list ) {
				$sources = array();
				$lines   = array( self::msg( $lang, 'products_head' ) );
				foreach ( $list as $item ) {
					$lines[]   = '• ' . $item['name'];
					$sources[] = array( 'url' => $item['url'], 'title' => $item['name'], 'checked_at' => gmdate( 'c' ) );
				}
				return $reply( 'structured', implode( "\n", $lines ), $sources, 0, array_slice( array_column( $list, 'name' ), 0, 4 ) );
			}
		}

		if ( $split && ! WIA_Text::intent( 'compare', $normalized ) ) {
			$combined = self::multi_part( $question, $lang, $context_doc, $previous_question );
			if ( $combined ) {
				return $combined;
			}
		}

		$wants_price = WIA_Text::intent( 'price', $normalized );
		$wants_stock = WIA_Text::intent( 'stock', $normalized );
		$query       = self::resolve_followup( $question, $normalized, $context_doc, $previous_question );
		$hits        = WIA_Knowledge::search( $query, 8, $info );
		if ( $query === $question && ! $info['unknown'] && ( $context_doc || '' !== $previous_question ) && self::best_coverage( $hits ) < WIA_Knowledge::MIN_COVERAGE ) {
			// Not answerable alone, so it may rely on the conversation. A word the site has never seen cannot be
			// supplied by the conversation: adding the previous subject would only dilute it.
			$expanded = trim( self::context_subject( $context_doc, $previous_question ) . ' ' . $question );
			$retry    = WIA_Knowledge::search( $expanded, 8, $retry_info );
			if ( self::best_coverage( $retry ) >= WIA_Knowledge::MIN_COVERAGE ) {
				$query = $expanded;
				$hits  = $retry;
				$info  = $retry_info;
			}
		}
		// «گارانتی داره؟» after a product looks like a follow-up, but it names its own subject. When the question alone
		// is answered confidently and the product page says nothing about that subject, the question stands alone.
		if ( $query !== $question && $hits ) {
			$alone = WIA_Knowledge::search( $question, 8, $alone_info );
			$soft  = WIA_Text::soft_stems( $question );
			// «قیمتش» refers back like «قیمت».
			$own   = array_values( array_filter( $alone_info['subject'], function ( $w ) use ( $soft ) { return ! WIA_Text::is_soft( $w, $soft ); } ) );
			if ( self::best_coverage( $alone ) >= WIA_Knowledge::MIN_COVERAGE && $own && ! $alone_info['unknown'] && array_diff( $own, $hits[0]['matched'] ) ) {
				$query = $question;
				$hits  = $alone;
				$info  = $alone_info;
			}
		}
		$hits = self::prefer_language( $hits, $lang );
		// Each product holds only part of a comparison's words, so the gate would refuse it. Requiring two
		// products that are really named in the question is the stricter check here.
		if ( $hits && WIA_Text::intent( 'compare', $normalized ) ) {
			$compared = self::compare( $hits, $query, $lang );
			if ( $compared ) {
				return $compared;
			}
		}
		$learned = false;
		if ( self::best_coverage( $hits ) < WIA_Knowledge::MIN_COVERAGE ) {
			// A phrase visitors taught the assistant: they asked it, then settled on this page.
			$alias = WIA_Learn::alias_hits( $question, $info );
			if ( $alias ) {
				$hits    = $alias;
				$learned = true;
			} else {
				// «Is the Cold Brew kit in stock?»: «kit» is unknown, but the product is named without doubt.
				if ( $hits && ( $wants_price || $wants_stock ) ) {
					$named = self::pick_product( $hits, $query );
					$fact  = $named ? self::product_fact( array( $named ), $query, $lang, $wants_price, $wants_stock, $context_doc ) : null;
					if ( $fact ) {
						$missing = self::display_words( $question, $info['unknown'] );
						if ( $missing ) {
							$fact['text'] = self::msg( $lang, 'partial_quote', array( 'missing' => self::join_words( $missing, $lang ) ) ) . "\n" . $fact['text'];
							$fact['mode'] = 'partial';
						}
						return $fact;
					}
				}
				return self::miss( $question, $lang, $hits, $info, $context_doc );
			}
		}
		if ( $wants_price || $wants_stock ) {
			$choice = self::product_choice( $hits, $query, $question, $info, $lang, true );
			if ( $choice ) {
				return $choice;
			}
			$fact = self::product_fact( $hits, $query, $lang, $wants_price, $wants_stock, $context_doc );
			if ( $fact ) {
				return $fact;
			}
		}
		if ( 'product' === $hits[0]['content_type'] ) {
			$choice = self::product_choice( $hits, $query, $question, $info, $lang, false );
			if ( $choice ) {
				return $choice;
			}
		}
		$answer            = self::extractive( self::match_query( $query, $info ), $hits, $lang );
		$answer['learned'] = $learned;
		return $answer;
	}

	/** The query plus the indexed words that matched it («آدرستون» -> «ادرس»), so the right sentence is quoted. */
	private static function match_query( $query, $info ) {
		return trim( $query . ' ' . implode( ' ', isset( $info['match'] ) ? (array) $info['match'] : array() ) );
	}

	/** Every reply has the same keys, so the REST layer and the widget never meet a missing one. */
	private static function shape( $answer ) {
		foreach ( array( 'suggestions', 'alts' ) as $key ) {
			if ( isset( $answer[ $key ] ) ) {
				$answer[ $key ] = array_values( array_unique( array_map( 'strval', (array) $answer[ $key ] ) ) );
			}
		}
		return array_merge(
			array( 'text' => '', 'language' => 'en', 'mode' => '', 'sources' => array(), 'stale' => false, 'context_doc' => 0, 'suggestions' => array(), 'actions' => array(), 'alts' => array(), 'learn' => null, 'learned' => false, 'gaps' => array() ),
			$answer
		);
	}

	/**
	 * The dialogue around answer(): «نه، این نبود» brings back the alternatives, «بله» accepts a single offer,
	 * repeated misses end with the owner's contact details, and a visitor's final choice after a miss is learned.
	 */
	public static function converse( $question, &$state ) {
		$question   = trim( mb_substr( (string) $question, 0, 1000 ) );
		$normalized = WIA_Text::normalize( $question );
		$lang       = WIA_Text::detect_language( $question, WIA_DB::default_language() );
		$answer     = null;
		if ( WIA_Text::intent( 'reject', $normalized ) && ! self::carries_question( $normalized ) ) {
			$alts   = isset( $state['alts'] ) ? array_values( (array) $state['alts'] ) : array();
			$answer = self::shape(
				array(
					'text'        => self::msg( $lang, $alts ? 'rephrase' : 'rephrase_none' ),
					'language'    => $lang,
					'mode'        => 'rephrase',
					'suggestions' => $alts ? $alts : self::starters( $lang ),
					'context_doc' => 0,
				)
			);
			WIA_Learn::count( 'rejected' );
		} elseif ( WIA_Text::intent( 'affirm', $normalized ) ) {
			if ( ! empty( $state['offer'] ) ) {
				$question = (string) $state['offer'];
			} else {
				$answer = self::shape( array( 'text' => self::msg( $lang, 'ack' ), 'language' => $lang, 'mode' => 'smalltalk', 'context_doc' => isset( $state['doc'] ) ? (int) $state['doc'] : 0 ) );
			}
		}
		if ( null === $answer ) {
			$answer = self::answer( $question, isset( $state['doc'] ) ? (int) $state['doc'] : 0, isset( $state['q'] ) ? (string) $state['q'] : '' );
		}

		WIA_Learn::after_turn( $state, $question, $answer );

		$missed        = in_array( $answer['mode'], array( 'not_found', 'rephrase' ), true );
		$state['miss'] = $missed ? ( isset( $state['miss'] ) ? (int) $state['miss'] : 0 ) + 1 : ( 'smalltalk' === $answer['mode'] && isset( $state['miss'] ) ? (int) $state['miss'] : 0 );
		if ( $missed && $state['miss'] >= 2 ) {
			$contact = self::contact( $lang );
			if ( $contact ) {
				$answer['text']    .= "\n\n" . self::msg( $lang, 'handoff' ) . "\n" . $contact['text'];
				$answer['actions']  = $contact['actions'];
				$answer['mode']     = 'handoff';
				$state['miss']      = 0;
			}
		}
		$state['doc'] = (int) $answer['context_doc'];
		if ( ! in_array( $answer['mode'], array( 'smalltalk', 'guard', 'rephrase', 'contact', 'handoff' ), true ) ) {
			$state['q'] = mb_substr( $question, 0, 200 );
		}
		$state['alts']  = array_slice( (array) $answer['alts'], 0, 4 );
		$state['offer'] = 1 === count( $answer['suggestions'] ) ? (string) $answer['suggestions'][0] : '';
		WIA_Learn::count( 'handoff' === $answer['mode'] ? 'not_found' : $answer['mode'] );
		WIA_Learn::record_gaps( $answer['gaps'] );
		return $answer;
	}

	/** «نه، قیمت کیف مدرسه چنده؟» rejects and asks again in one message: that question must be answered. */
	private static function carries_question( $normalized ) {
		$pattern = '/' . str_replace( '/', '\/', WIA_Text::data()['intents']['reject'] ) . '/iu';
		$rest    = trim( $normalized );
		// «نه، این نبود» is two rejections in a row.
		for ( $i = 0; $i < 4 && preg_match( $pattern, $rest ); $i++ ) {
			$rest = trim( (string) preg_replace( $pattern, '', $rest, 1 ) );
		}
		return (bool) WIA_Text::subject_tokens( $rest );
	}

	/**
	 * Suggested first questions: the owner's own, otherwise common business questions this site really answers,
	 * then its main categories. Cached until the next scan or settings change, because every page carries them.
	 */
	public static function starters( $lang ) {
		$key    = 'wia_starters_' . $lang;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$settings = WIA_DB::settings();
		$own      = isset( $settings['starters'][ $lang ] ) ? (string) $settings['starters'][ $lang ] : '';
		$list     = array_values( array_filter( array_map( 'trim', explode( "\n", $own ) ), 'strlen' ) );
		if ( ! $list ) {
			self::msg( $lang, 'greeting' );
			$common = isset( self::$messages['common_questions'][ $lang ] ) ? self::$messages['common_questions'][ $lang ] : array();
			foreach ( $common as $q ) {
				// Offer only what gets a real answer: a button that leads to «not found» would be worse than none.
				if ( count( $list ) < 4 && in_array( self::answer( $q )['mode'], array( 'faq', 'extractive', 'structured' ), true ) ) {
					$list[] = $q;
				}
			}
			$list = array_slice( array_values( array_unique( array_merge( $list, self::topics( 3 ) ) ) ), 0, 5 );
		}
		if ( self::has_contact() ) {
			$list[] = self::msg( $lang, 'contact_chip' );
		}
		$list = array_slice( $list, 0, 6 );
		set_transient( $key, $list, 12 * HOUR_IN_SECONDS );
		return $list;
	}

	public static function forget_starters() {
		foreach ( array( 'fa', 'ar', 'en' ) as $l ) {
			delete_transient( 'wia_starters_' . $l );
		}
	}

	/** Main topics of this site: product categories, then post categories, then pages. */
	public static function topics( $limit = 4 ) {
		$out = array();
		foreach ( array( 'product_cat', 'category' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => $limit + 2, 'exclude' => array( (int) get_option( 'default_category' ), (int) get_option( 'default_product_cat' ) ) ) );
			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$out[] = wp_strip_all_tags( html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) );
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		if ( count( $out ) < 2 ) {
			global $wpdb;
			$pages = $wpdb->get_col( $wpdb->prepare( 'SELECT title FROM ' . WIA_DB::t( 'documents' ) . " WHERE status = 'active' AND content_type IN ('page','product','post') ORDER BY content_type = 'product' DESC, id LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB
			$out   = array_merge( $out, array_map( 'strval', (array) $pages ) );
		}
		return array_slice( array_values( array_unique( array_filter( $out, 'strlen' ) ) ), 0, $limit );
	}

	/** The words of the question, as the visitor wrote them, whose stems are in $stems. */
	private static function display_words( $question, $stems ) {
		$want = array_flip( (array) $stems );
		$out  = array();
		foreach ( preg_split( '/[\s،,؛;:!?؟.«»"()]+/u', (string) $question, -1, PREG_SPLIT_NO_EMPTY ) as $raw ) {
			foreach ( WIA_Text::tokenize( $raw ) as $token ) {
				if ( isset( $want[ $token ] ) ) {
					$out[] = $raw;
					break;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function join_words( $words, $lang ) {
		return implode( 'en' === $lang ? ', ' : '، ', $words );
	}

	/**
	 * No confident answer. Instead of a dead end, say exactly which words the site does not know, offer what it
	 * does have, and remember the unknown words so a visitor's eventual choice can teach the assistant.
	 */
	private static function miss( $question, $lang, $hits, $info, $context_doc ) {
		$unknown = self::display_words( $question, $info['unknown'] );
		$known   = self::display_words( $question, array_diff( $info['subject'], $info['unknown'] ) );
		$related = array();
		foreach ( $hits as $hit ) {
			if ( $hit['matched'] && ! isset( $related[ $hit['doc_id'] ] ) && count( $related ) < 4 ) {
				$related[ $hit['doc_id'] ] = $hit;
			}
		}
		$titles = array_values( array_unique( array_map( function ( $h ) { return wp_strip_all_tags( $h['title'] ); }, $related ) ) );
		$first  = $related ? reset( $related ) : null;
		$known_stems = array_values( array_diff( $info['subject'], $info['unknown'] ) );
		// «به شیراز هم ارسال دارید؟»: the shipping page answers everything but «شیراز». Quote it, and say what
		// was not found, rather than only listing links.
		if ( $first && $unknown && $known_stems && count( $info['unknown'] ) < count( $info['subject'] ) && count( array_intersect( $known_stems, $first['matched'] ) ) >= 0.6 * count( $known_stems ) ) {
			$quote = self::extractive( self::match_query( implode( ' ', $known_stems ), $info ), array( $first ), $lang );
			$more  = array_values( array_diff( $titles, array( wp_strip_all_tags( $first['title'] ) ) ) );
			return self::shape(
				array(
					'text'        => self::msg( $lang, 'partial_quote', array( 'missing' => self::join_words( $unknown, $lang ) ) ) . "\n" . $quote['text'],
					'language'    => $lang,
					'mode'        => 'partial',
					'sources'     => $quote['sources'],
					'context_doc' => (int) $first['doc_id'],
					'suggestions' => $more,
					'alts'        => $more,
					'learn'       => array( 'words' => array_values( $info['unknown'] ), 'docs' => array_map( 'intval', array_keys( $related ) ) ),
					'gaps'        => $unknown,
				)
			);
		}
		if ( $related && $unknown && $known ) {
			$text = self::msg( $lang, 'partial', array( 'missing' => self::join_words( $unknown, $lang ), 'found' => self::join_words( $known, $lang ) ) );
		} elseif ( $related ) {
			$text = self::msg( $lang, 'near' );
		} else {
			$settings = WIA_DB::settings();
			$custom   = isset( $settings['not_found'][ $lang ] ) ? trim( (string) $settings['not_found'][ $lang ] ) : '';
			$text     = '' !== $custom ? $custom : ( $unknown ? self::msg( $lang, 'not_found_word', array( 'words' => self::join_words( $unknown, $lang ) ) ) : self::msg( $lang, 'not_found' ) );
			$titles   = self::topics( 4 );
			if ( $titles ) {
				$text .= "\n" . self::msg( $lang, 'topics_head' );
			}
		}
		$words = $info['unknown'] ? $info['unknown'] : $info['subject'];
		return self::shape(
			array(
				'text'        => $text,
				'language'    => $lang,
				'mode'        => 'not_found',
				'sources'     => array_values( array_map( array( __CLASS__, 'source' ), $related ) ),
				'context_doc' => $context_doc,
				'suggestions' => $titles,
				'alts'        => $titles,
				'learn'       => $words ? array( 'words' => array_values( $words ), 'docs' => array_map( 'intval', array_keys( $related ) ) ) : null,
				'gaps'        => $unknown,
			)
		);
	}

	/**
	 * «کوله پشتی» matches several products and names none of them fully. Listing them, with live prices, and
	 * asking which one is better than quoting whichever ranked first.
	 */
	private static function product_choice( $hits, $query, $question, $info, $lang, $with_price ) {
		$q        = array_unique( WIA_Text::tokenize( $query ) );
		$products = array();
		$best     = 0.0;
		foreach ( $hits as $hit ) {
			$name = array_unique( WIA_Text::tokenize( $hit['title'] ) );
			if ( 'product' !== $hit['content_type'] || ! $name || isset( $products[ $hit['doc_id'] ] ) || $hit['coverage'] < WIA_Knowledge::MIN_COVERAGE ) {
				continue;
			}
			$ratio = count( array_intersect( $name, $q ) ) / count( $name );
			$best  = max( $best, $ratio );
			$products[ $hit['doc_id'] ] = $hit;
		}
		if ( count( $products ) < 2 || $best >= 1.0 ) {
			return null;
		}
		$lines   = array( self::msg( $lang, 'clarify_many', array( 'topic' => self::join_words( self::display_words( $question, $info['subject'] ), $lang ) ) ) );
		$names   = array();
		$sources = array();
		foreach ( array_slice( $products, 0, 5 ) as $hit ) {
			$live = WIA_Catalog::live( (int) $hit['source_id'] );
			if ( ! $live || in_array( $live['name'], $names, true ) ) {
				continue;
			}
			$price     = '' !== (string) $live['price'] ? trim( WIA_Catalog::number( $live['price'], $lang ) . ' ' . WIA_Catalog::currency( $lang, self::currencies() ) ) : '';
			$lines[]   = '• ' . $live['name'] . ( $with_price && '' !== $price ? ': ' . $price : '' );
			$names[]   = $live['name'];
			$sources[] = array( 'url' => $live['url'], 'title' => $live['name'], 'checked_at' => gmdate( 'c' ) );
		}
		if ( count( $names ) < 2 ) {
			return null;
		}
		return self::shape( array( 'text' => implode( "\n", $lines ), 'language' => $lang, 'mode' => 'clarify', 'sources' => $sources, 'suggestions' => array_slice( $names, 0, 4 ), 'alts' => array_slice( $names, 0, 4 ) ) );
	}

	/** Contact details the owner entered, or the site's contact page. Null when there are none. */
	public static function contact( $lang ) {
		$c       = WIA_DB::settings()['contact'];
		$lines   = array();
		$actions = array();
		$phone   = trim( (string) $c['phone'] );
		if ( '' !== $phone ) {
			$lines[]   = self::msg( $lang, 'contact_phone', array( 'v' => $phone ) );
			$actions[] = array( 'label' => $phone, 'url' => 'tel:' . preg_replace( '/[^\d+]/', '', WIA_Text::normalize( $phone ) ) );
		}
		if ( is_email( $c['email'] ) ) {
			$lines[]   = self::msg( $lang, 'contact_email', array( 'v' => $c['email'] ) );
			$actions[] = array( 'label' => $c['email'], 'url' => 'mailto:' . $c['email'] );
		}
		$wa = preg_replace( '/\D/', '', WIA_Text::normalize( (string) $c['whatsapp'] ) );
		if ( strlen( $wa ) >= 8 ) {
			$actions[] = array( 'label' => 'WhatsApp', 'url' => 'https://wa.me/' . $wa );
		}
		$tg = preg_replace( '/[^A-Za-z0-9_]/', '', (string) preg_replace( '#^(?:https?://)?(?:t\.me/|@)#i', '', trim( (string) $c['telegram'] ) ) );
		if ( strlen( $tg ) >= 4 ) {
			$actions[] = array( 'label' => 'Telegram', 'url' => 'https://t.me/' . $tg );
		}
		$page = '' !== (string) $c['page'] ? array( 'url' => (string) $c['page'], 'title' => self::msg( $lang, 'contact_page' ) ) : self::contact_page();
		if ( $page ) {
			$actions[] = array( 'label' => $page['title'], 'url' => $page['url'] );
		}
		if ( '' !== trim( (string) $c['hours'] ) ) {
			$lines[] = self::msg( $lang, 'contact_hours', array( 'v' => trim( (string) $c['hours'] ) ) );
		}
		if ( ! $actions ) {
			return null;
		}
		return self::shape( array( 'text' => trim( self::msg( $lang, 'contact_head' ) . "\n" . implode( "\n", $lines ) ), 'language' => $lang, 'mode' => 'contact', 'actions' => $actions ) );
	}

	public static function has_contact() {
		$c = WIA_DB::settings()['contact'];
		return '' !== trim( $c['phone'] . $c['email'] . $c['whatsapp'] . $c['telegram'] . $c['page'] ) || (bool) self::contact_page();
	}

	/** A published page whose title says it is the contact page. */
	private static function contact_page() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT url, title FROM ' . WIA_DB::t( 'documents' ) . " WHERE status = 'active' AND content_type IN ('contact','page') AND (title LIKE '%تماس%' OR title LIKE '%ارتباط با%' OR title LIKE '%contact%' OR title LIKE '%اتصل%' OR title LIKE '%تواصل%') ORDER BY CHAR_LENGTH(title) LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? array( 'url' => $row['url'], 'title' => wp_strip_all_tags( $row['title'] ) ) : null;
	}

	private static function not_found( $lang, $context_doc = 0 ) {
		$settings = WIA_DB::settings();
		$custom   = isset( $settings['not_found'][ $lang ] ) ? trim( (string) $settings['not_found'][ $lang ] ) : '';
		return self::shape( array( 'text' => '' !== $custom ? $custom : self::msg( $lang, 'not_found' ), 'language' => $lang, 'mode' => 'not_found', 'context_doc' => $context_doc ) );
	}

	public static function source( $hit ) {
		return array( 'url' => $hit['url'], 'title' => $hit['title'], 'checked_at' => mysql2date( 'c', $hit['crawl_date'], false ) );
	}

	private static function match_faq( $question, $lang ) {
		global $wpdb;
		$tokens     = array_unique( WIA_Text::tokenize( $question ) );
		$normalized = ' ' . WIA_Text::normalize( $question ) . ' ';
		if ( ! $tokens ) {
			return null;
		}
		$asked = array();
		foreach ( $tokens as $w ) {
			foreach ( array_merge( WIA_Text::match_group( $w ), WIA_Text::suffix_variants( $w, true ) ) as $v ) {
				$asked[ $v ] = true;
			}
		}
		$best       = null;
		$best_score = 0.0;
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . WIA_DB::t( 'faqs' ) . ' ORDER BY id', ARRAY_A ) as $faq ) { // phpcs:ignore WordPress.DB
			if ( '' !== $faq['language'] && $faq['language'] !== $lang ) {
				continue;
			}
			foreach ( preg_split( '/[،,\n]/u', (string) $faq['keywords'] ) as $keyword ) {
				$keyword = trim( $keyword );
				if ( '' !== $keyword && false !== strpos( $normalized, ' ' . WIA_Text::normalize( $keyword ) . ' ' ) ) {
					return $faq;
				}
				// «دانشجوها» matches the keyword «دانشجو»: keywords are compared as stems, not only as text.
				$words = WIA_Text::tokenize( $keyword );
				$all   = (bool) $words;
				foreach ( $words as $w ) {
					$all = $all && (bool) array_intersect_key( array_flip( WIA_Text::match_group( $w ) ), $asked );
				}
				if ( $all ) {
					return $faq;
				}
			}
			$candidate = array_unique( WIA_Text::tokenize( $faq['question'] ) );
			if ( ! $candidate ) {
				continue;
			}
			$overlap = count( array_intersect( $tokens, $candidate ) ) / count( array_unique( array_merge( $tokens, $candidate ) ) );
			if ( $overlap > $best_score ) {
				$best       = $faq;
				$best_score = $overlap;
			}
		}
		return $best_score >= 0.6 ? $best : null;
	}

	private static function context_subject( $context_doc, $previous_question ) {
		if ( $context_doc ) {
			global $wpdb;
			$title = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . WIA_DB::t( 'documents' ) . " WHERE id = %d AND status = 'active'", $context_doc ) ); // phpcs:ignore WordPress.DB
			if ( '' !== $title ) {
				return $title;
			}
		}
		return (string) $previous_question;
	}

	/** «موجوده؟» names no subject and refers back. «شرایط ارسال چیست؟» has its own subject and must stand alone. */
	private static function is_followup( $question, $normalized ) {
		$subject = WIA_Text::subject_tokens( $question );
		return ! $subject || ( count( $subject ) <= 1 && WIA_Text::intent( 'followup', $normalized ) );
	}

	private static function resolve_followup( $question, $normalized, $context_doc, $previous_question ) {
		if ( ( $context_doc || '' !== $previous_question ) && self::is_followup( $question, $normalized ) ) {
			return trim( self::context_subject( $context_doc, $previous_question ) . ' ' . $question );
		}
		return $question;
	}

	private static function best_coverage( $hits ) {
		$best = 0.0;
		foreach ( $hits as $hit ) {
			$best = max( $best, (float) $hit['coverage'] );
		}
		return $best;
	}

	/** Only a product whose name is really in the question may answer. A nearby product must not answer for another. */
	private static function pick_product( $hits, $query ) {
		$q          = array_unique( WIA_Text::tokenize( $query ) );
		$best       = null;
		$best_ratio = 0.0;
		foreach ( $hits as $hit ) {
			if ( 'product' !== $hit['content_type'] ) {
				continue;
			}
			$name = array_unique( WIA_Text::tokenize( $hit['title'] ) );
			if ( ! $name ) {
				continue;
			}
			$ratio = count( array_intersect( $name, $q ) ) / count( $name );
			if ( $ratio > $best_ratio ) {
				$best       = $hit;
				$best_ratio = $ratio;
			}
		}
		if ( $best_ratio >= 0.5 ) {
			return $best;
		}
		$named = self::distinctive_products( $query );
		if ( $named ) {
			$top     = max( $named );
			$winners = array_keys( $named, $top, true );
			if ( 1 === count( $winners ) ) {
				foreach ( $hits as $hit ) {
					if ( (int) $hit['doc_id'] === (int) $winners[0] && 'product' === $hit['content_type'] ) {
						return $hit;
					}
				}
			}
		}
		return null;
	}

	/**
	 * Products named by a word only their own name contains: «روبوستا» names «دانه قهوه روبوستا» although «دانه» and
	 * «قهوه» are in other names too. @return array doc id => number of such words in the question
	 */
	private static function distinctive_products( $query ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT id, title FROM ' . WIA_DB::t( 'documents' ) . " WHERE status = 'active' AND content_type = 'product' LIMIT 2000", ARRAY_A ); // phpcs:ignore WordPress.DB
		$names = array();
		$df    = array();
		foreach ( $rows as $row ) {
			$names[ (int) $row['id'] ] = array_unique( WIA_Text::tokenize( $row['title'] ) );
			foreach ( $names[ (int) $row['id'] ] as $token ) {
				$df[ $token ] = isset( $df[ $token ] ) ? $df[ $token ] + 1 : 1;
			}
		}
		$asked = array();
		foreach ( WIA_Text::tokenize( $query ) as $w ) {
			foreach ( WIA_Text::match_group( $w ) as $g ) {
				$asked[ $g ] = true;
			}
		}
		$out = array();
		foreach ( $names as $id => $tokens ) {
			$own = 0;
			foreach ( $tokens as $token ) {
				$own += ( 1 === $df[ $token ] && ! preg_match( '/^\d+$/', $token ) && isset( $asked[ $token ] ) ) ? 1 : 0;
			}
			if ( $own ) {
				$out[ $id ] = $own;
			}
		}
		return $out;
	}

	/**
	 * «ضمانت دستگاه اسپرسو چقدره؟» names a product but asks about its warranty: «چقدره» is not always a price. A known
	 * word outside the product name means the question is about something else.
	 */
	private static function asks_beyond( $hit, $query ) {
		$name = array_flip( array_unique( WIA_Text::tokenize( $hit['title'] ) ) );
		WIA_Knowledge::search( $query, 1, $qinfo );
		$soft = WIA_Text::soft_stems( $query );
		foreach ( WIA_Text::subject_tokens( $query ) as $w ) {
			if ( in_array( $w, $qinfo['unknown'], true ) || WIA_Text::is_soft( $w, $soft ) ) {
				continue;
			}
			if ( ! array_intersect_key( array_flip( WIA_Text::match_group( $w ) ), $name ) ) {
				return true;
			}
		}
		return false;
	}

	/** An Arabic question answered from the Arabic page when one covers it as well as the top page does. */
	private static function prefer_language( $hits, $lang ) {
		if ( ! $hits || '' === (string) $hits[0]['language'] || $hits[0]['language'] === $lang ) {
			return $hits;
		}
		foreach ( $hits as $i => $hit ) {
			if ( $i > 0 && $hit['language'] === $lang && (float) $hit['coverage'] >= (float) $hits[0]['coverage'] - 0.05 ) {
				array_splice( $hits, $i, 1 );
				array_unshift( $hits, $hit );
				break;
			}
		}
		return $hits;
	}

	/**
	 * Two questions in one message, each answered from its own page. Only when one page does not already cover the
	 * whole message («اشتراک چقدر تخفیف داره و میشه لغوش کرد؟» is one page).
	 */
	private static function multi_part( $question, $lang, $context_doc, $previous_question ) {
		$parts = array();
		foreach ( preg_split( '/\s+(?:و|and|also|plus|همچنین|ضمنا)\s+|[؟?]+\s+(?=\S)/iu', $question ) as $part ) {
			// Not trim(): it works on bytes, and «؟» shares its first byte with «د».
			$part = (string) preg_replace( '/^[\s،,؟?]+|[\s،,؟?]+$/u', '', $part );
			if ( '' !== $part && WIA_Text::subject_tokens( $part ) ) {
				$parts[] = $part;
			}
		}
		if ( count( $parts ) < 2 ) {
			return null;
		}
		$whole   = WIA_Knowledge::search( $question, 5, $winfo );
		$subject = array_diff( $winfo['subject'], $winfo['unknown'] );
		if ( $whole && self::best_coverage( $whole ) >= WIA_Knowledge::MIN_COVERAGE && ! array_diff( $subject, $whole[0]['matched'] ) ) {
			return null;
		}
		$answers = array();
		$seen    = array();
		$good    = 0;
		foreach ( $parts as $part ) {
			$a         = self::answer( $part, $context_doc, $previous_question, false );
			$answers[] = $a;
			if ( in_array( $a['mode'], array( 'faq', 'extractive', 'structured' ), true ) ) {
				$key = $a['sources'] ? $a['sources'][0]['url'] : $a['text'];
				if ( isset( $seen[ $key ] ) ) {
					return null;
				}
				$seen[ $key ] = true;
				$good++;
			}
		}
		if ( ! $good ) {
			return null;
		}
		$lines   = array();
		$sources = array();
		$modes   = array();
		$doc     = $context_doc;
		foreach ( $answers as $i => $a ) {
			if ( in_array( $a['mode'], array( 'faq', 'extractive', 'structured' ), true ) ) {
				$lines[]  = $a['text'];
				$sources  = array_merge( $sources, $a['sources'] );
				$modes[]  = $a['mode'];
				$doc      = $a['context_doc'] ? $a['context_doc'] : $doc;
			} else {
				$words   = self::display_words( $parts[ $i ], WIA_Text::subject_tokens( $parts[ $i ] ) );
				$lines[] = self::msg( $lang, 'not_found_word', array( 'words' => self::join_words( $words ? $words : array( $parts[ $i ] ), $lang ) ) );
			}
		}
		$modes = array_values( array_unique( $modes ) );
		return self::shape( array( 'text' => implode( "\n\n", $lines ), 'language' => $lang, 'mode' => 1 === count( $modes ) ? $modes[0] : 'extractive', 'sources' => $sources, 'context_doc' => (int) $doc ) );
	}

	private static function product_fact( $hits, $query, $lang, $wants_price, $wants_stock, $context_doc ) {
		$hit = self::pick_product( $hits, $query );
		if ( ! $hit && $context_doc ) {
			foreach ( $hits as $h ) {
				if ( (int) $h['doc_id'] === (int) $context_doc && 'product' === $h['content_type'] ) {
					$hit = $h;
					break;
				}
			}
		}
		if ( ! $hit || self::asks_beyond( $hit, $query ) ) {
			return null;
		}
		$live = WIA_Catalog::live( (int) $hit['source_id'] );
		if ( ! $live ) {
			return null;
		}
		$lines = array();
		if ( $wants_price ) {
			if ( '' !== (string) $live['price'] ) {
				$lines[] = self::msg( $lang, 'price', array( 'name' => $live['name'], 'price' => trim( WIA_Catalog::number( $live['price'], $lang ) . ' ' . WIA_Catalog::currency( $lang, self::currencies() ) ) ) );
			} else {
				$lines[] = self::msg( $lang, 'no_price', array( 'name' => $live['name'] ) );
			}
		}
		if ( $wants_stock ) {
			$key     = array( 'in_stock' => 'stock_in', 'out_of_stock' => 'stock_out', 'preorder' => 'stock_pre' );
			$lines[] = self::msg( $lang, isset( $key[ $live['availability'] ] ) ? $key[ $live['availability'] ] : 'stock_unknown', array( 'name' => $live['name'] ) );
		}
		return self::shape(
			array(
				'text'        => implode( "\n", $lines ),
				'language'    => $lang,
				'mode'        => 'structured',
				'sources'     => array( array( 'url' => $live['url'], 'title' => $live['name'], 'checked_at' => gmdate( 'c' ) ) ),
				'context_doc' => (int) $hit['doc_id'],
			)
		);
	}

	private static function compare( $hits, $query, $lang ) {
		$q        = array_unique( WIA_Text::tokenize( $query ) );
		$named    = self::distinctive_products( $query );
		$products = array();
		foreach ( $hits as $hit ) {
			$name = array_unique( WIA_Text::tokenize( $hit['title'] ) );
			if ( 'product' === $hit['content_type'] && $name && ( count( array_intersect( $name, $q ) ) / count( $name ) >= 0.5 || isset( $named[ (int) $hit['doc_id'] ] ) ) && ! isset( $products[ $hit['doc_id'] ] ) ) {
				$products[ $hit['doc_id'] ] = $hit;
			}
		}
		if ( count( $products ) < 2 ) {
			return null;
		}
		$pair = array_slice( array_values( $products ), 0, 2 );
		$live = array( WIA_Catalog::live( (int) $pair[0]['source_id'] ), WIA_Catalog::live( (int) $pair[1]['source_id'] ) );
		if ( ! $live[0] || ! $live[1] ) {
			return null;
		}
		$lines   = array( self::msg( $lang, 'compare_head', array( 'a' => $live[0]['name'], 'b' => $live[1]['name'] ) ) );
		$sources = array();
		foreach ( array( 0, 1 ) as $i ) {
			$first     = WIA_Text::sentences( $pair[ $i ]['body'] );
			$price     = '' !== (string) $live[ $i ]['price'] ? trim( WIA_Catalog::number( $live[ $i ]['price'], $lang ) . ' ' . WIA_Catalog::currency( $lang, self::currencies() ) ) : '-';
			$lines[]   = '• ' . $live[ $i ]['name'] . ': ' . $price . ( $first ? ' - ' . $first[0] : '' );
			$sources[] = array( 'url' => $live[ $i ]['url'], 'title' => $live[ $i ]['name'], 'checked_at' => gmdate( 'c' ) );
		}
		return self::shape( array( 'text' => implode( "\n", $lines ), 'language' => $lang, 'mode' => 'structured', 'sources' => $sources ) );
	}

	private static function extractive( $query, $hits, $lang ) {
		$best   = $hits[0];
		$tokens = array_unique( WIA_Text::tokenize( $query ) );
		$scored = array();
		foreach ( WIA_Text::sentences( $best['body'] ) as $i => $sentence ) {
			$scored[] = array( count( array_intersect( $tokens, WIA_Text::tokenize( $sentence ) ) ), -$i, $sentence );
		}
		usort( $scored, function ( $a, $b ) { return $b[0] <=> $a[0] ?: $b[1] <=> $a[1]; } );
		$picked = array_slice( $scored, 0, 2 );
		usort( $picked, function ( $a, $b ) { return $b[1] <=> $a[1]; } );
		$quote = implode( ' ', array_column( $picked, 2 ) );
		$quote = '' !== $quote ? $quote : mb_substr( $best['body'], 0, 300 );
		$head  = self::msg( $lang, 'from_source', array( 'title' => $best['title'] ) );
		if ( '' !== $best['language'] && $best['language'] !== $lang ) {
			$head = self::msg( $lang, 'other_lang' ) . "\n" . $head;
		}
		$text = $head . "\n" . $quote;
		if ( 'product' === $best['content_type'] ) {
			$live = WIA_Catalog::live( (int) $best['source_id'] );
			if ( $live && '' !== (string) $live['price'] ) {
				$text .= "\n" . self::msg( $lang, 'price_line', array( 'price' => trim( WIA_Catalog::number( $live['price'], $lang ) . ' ' . WIA_Catalog::currency( $lang, self::currencies() ) ) ) );
			}
		}
		// Other pages that also answer well, offered as next steps.
		$related = array();
		foreach ( $hits as $hit ) {
			$title = wp_strip_all_tags( $hit['title'] );
			if ( (int) $hit['doc_id'] !== (int) $best['doc_id'] && $hit['coverage'] >= WIA_Knowledge::MIN_COVERAGE && ! in_array( $title, $related, true ) && count( $related ) < 3 ) {
				$related[] = $title;
			}
		}
		return self::shape( array( 'text' => $text, 'language' => $lang, 'mode' => 'extractive', 'sources' => array( self::source( $best ) ), 'context_doc' => (int) $best['doc_id'], 'suggestions' => $related, 'alts' => $related ) );
	}

	/**
	 * Optional: forward the question to the owner's own self-hosted engine. Off by default.
	 * On any failure, the site answers locally instead of showing an error.
	 */
	public static function ask_engine( $question, $conversation ) {
		$settings = WIA_DB::settings();
		if ( empty( $settings['engine_enabled'] ) || '' === $settings['engine_url'] || '' === $settings['engine_key'] ) {
			return null;
		}
		$response = wp_safe_remote_post(
			trailingslashit( $settings['engine_url'] ) . 'public/v1/chat',
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'site_key' => $settings['engine_key'], 'message' => $question, 'conversation_id' => $conversation ? $conversation : null ) ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['answer'] ) ? $data : null;
	}

	/* ---------- conversation store ---------- */

	public static function load_conversation( $id ) {
		global $wpdb;
		if ( '' === (string) $id || ! preg_match( '/^[A-Za-z0-9_\-]{10,40}$/', $id ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WIA_DB::t( 'conversations' ) . ' WHERE id = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['state'] = json_decode( (string) $row['state'], true ) ?: array();
		return $row;
	}

	public static function save_turn( $id, $state, $question, $answer ) {
		global $wpdb;
		$settings = WIA_DB::settings();
		$now      = current_time( 'mysql', true );
		$table    = WIA_DB::t( 'conversations' );
		if ( '' === (string) $id ) {
			$id = wp_generate_password( 32, false, false );
			$wpdb->insert( $table, array( 'id' => $id, 'state' => wp_json_encode( $state ), 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->update( $table, array( 'state' => wp_json_encode( $state ), 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! empty( $settings['log_messages'] ) ) {
			$messages = WIA_DB::t( 'messages' );
			$wpdb->insert( $messages, array( 'conversation_id' => $id, 'role' => 'user', 'body' => $question, 'mode' => $answer['mode'], 'created_at' => $now ) ); // phpcs:ignore WordPress.DB
			$wpdb->insert( $messages, array( 'conversation_id' => $id, 'role' => 'assistant', 'body' => $answer['text'], 'mode' => $answer['mode'], 'created_at' => $now ) ); // phpcs:ignore WordPress.DB
		}
		return $id;
	}

	public static function purge_old() {
		global $wpdb;
		$settings = WIA_DB::settings();
		$days     = max( 1, (int) $settings['retention_days'] );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$conv     = WIA_DB::t( 'conversations' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . WIA_DB::t( 'messages' ) . " WHERE conversation_id IN (SELECT id FROM {$conv} WHERE updated_at < %s)", $cutoff ) ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$conv} WHERE updated_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}
