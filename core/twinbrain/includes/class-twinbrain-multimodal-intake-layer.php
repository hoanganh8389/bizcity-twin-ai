<?php
/**
 * TwinBrain Multimodal Intake Layer.
 *
 * Builds a compact attachment/vision/file context before Notebook retrieval and
 * final compose. This layer is fail-open: if the hub vision endpoint is not
 * available, it emits degraded evidence and never pretends the file was read.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Multimodal_Intake_Layer {

	const MAX_TEXT_FILE_BYTES = 1048576;
	const MAX_CONTEXT_CHARS   = 12000;

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * @param string        $trace_id Turn trace ID.
	 * @param string        $prompt Original prompt.
	 * @param array         $opts Runtime opts, including attachments[].
	 * @param callable|null $emit Optional fn($event_type, $payload).
	 * @return array Runtime opts enriched with multimodal_* keys.
	 */
	public function collect( $trace_id, $prompt, array $opts, $emit = null ) {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — default TwinBrain attachment intake before Notebook/RAG.
		$t0          = microtime( true );
		$attachments = $this->normalize_attachments( isset( $opts['attachments'] ) && is_array( $opts['attachments'] ) ? $opts['attachments'] : array() );
		if ( empty( $attachments ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — recover media manifest from in-band TwinWeb prompt context when attachment_ids are lost in transit/history.
			$attachments = $this->parse_prompt_attachment_context( (string) $prompt );
		}
		$counts      = $this->count_by_kind( $attachments );

		$this->emit( $emit, 'attachment_manifest_ready', array(
			'trace_id'         => (string) $trace_id,
			'attachment_count' => (int) count( $attachments ),
			'image_count'      => (int) $counts['image'],
			'document_count'   => (int) $counts['document'],
			'audio_count'      => (int) $counts['audio'],
			'ms'               => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		) );

		$pack = array(
			'schema'          => 'bizcity.twinbrain.multimodal_ingest.v1',
			'prompt'          => (string) $prompt,
			'attachments'     => $attachments,
			'vision'          => array(),
			'ocr'             => array(),
			'file_text'       => array(),
			'audio_transcript'=> array(),
			'entities'        => array(),
			'query_expansion' => array(),
			'confidence'      => 'low',
			'degraded'        => false,
			'reason_bucket'   => '',
			'intent'          => 'answer',
		);

		if ( empty( $attachments ) ) {
			$opts['multimodal_ingest_pack'] = $pack;
			return $opts;
		}

		$this->emit( $emit, 'multimodal_ingest_started', array(
			'trace_id'         => (string) $trace_id,
			'attachment_count' => (int) count( $attachments ),
			'image_count'      => (int) $counts['image'],
			'document_count'   => (int) $counts['document'],
			'audio_count'      => (int) $counts['audio'],
		) );

		$images    = $this->filter_by_kind( $attachments, 'image' );
		$documents = $this->filter_by_kind( $attachments, 'document' );
		$audio     = $this->filter_by_kind( $attachments, 'audio' );

		if ( ! empty( $images ) ) {
			$this->emit( $emit, 'vision_analysis_started', array(
				'trace_id'    => (string) $trace_id,
				'image_count' => count( $images ),
			) );

			$vision_t0 = microtime( true );
			$vision    = $this->analyze_images( $images, (string) $prompt, $opts );
			$vision_ms = (int) ( ( microtime( true ) - $vision_t0 ) * 1000 );
			$pack['vision'][] = $vision;

			if ( ! empty( $vision['success'] ) ) {
				$pack['confidence'] = (string) ( $vision['confidence'] ?? 'medium' );
				$pack['entities'] = array_values( array_unique( array_merge( $pack['entities'], (array) ( $vision['entities'] ?? array() ) ) ) );
				$pack['ocr'] = array_values( array_unique( array_merge( $pack['ocr'], (array) ( $vision['ocr_text'] ?? array() ) ) ) );
				$this->emit( $emit, 'vision_analysis_done', array(
					'trace_id'     => (string) $trace_id,
					'summary'      => (string) ( $vision['summary'] ?? '' ),
					'entity_count' => count( (array) ( $vision['entities'] ?? array() ) ),
					'ocr_count'    => count( (array) ( $vision['ocr_text'] ?? array() ) ),
					'confidence'   => (string) ( $vision['confidence'] ?? 'medium' ),
					'degraded'     => false,
					'ms'           => $vision_ms,
				) );
			} else {
				$pack['degraded']      = true;
				$pack['reason_bucket'] = (string) ( $vision['reason_bucket'] ?? 'vision_unavailable' );
				$this->emit( $emit, 'vision_analysis_degraded', array(
					'trace_id'      => (string) $trace_id,
					'degraded'      => true,
					'reason_bucket' => (string) $pack['reason_bucket'],
					'ms'            => $vision_ms,
				) );
			}
		}

		if ( ! empty( $documents ) ) {
			$this->emit( $emit, 'file_extract_started', array(
				'trace_id'       => (string) $trace_id,
				'document_count' => count( $documents ),
			) );
			$file_t0 = microtime( true );
			$file_result = $this->extract_documents( $documents );
			$file_ms = (int) ( ( microtime( true ) - $file_t0 ) * 1000 );
			$pack['file_text'] = (array) ( $file_result['segments'] ?? array() );
			if ( ! empty( $file_result['success'] ) ) {
				foreach ( $pack['file_text'] as $segment ) {
					if ( is_array( $segment ) && ! empty( $segment['text'] ) ) {
						$pack['query_expansion'][] = mb_substr( trim( (string) $segment['text'] ), 0, 240 );
					}
				}
				$this->emit( $emit, 'file_extract_done', array(
					'trace_id'   => (string) $trace_id,
					'file_count' => count( $documents ),
					'char_count' => (int) ( $file_result['total_chars'] ?? 0 ),
					'page_count' => 0,
					'degraded'   => false,
					'ms'         => $file_ms,
				) );
			} else {
				$pack['degraded'] = true;
				if ( '' === (string) $pack['reason_bucket'] ) {
					$pack['reason_bucket'] = (string) ( $file_result['reason_bucket'] ?? 'file_extract_unsupported' );
				}
				$this->emit( $emit, 'file_extract_degraded', array(
					'trace_id'      => (string) $trace_id,
					'degraded'      => true,
					'reason_bucket' => (string) ( $file_result['reason_bucket'] ?? 'file_extract_unsupported' ),
					'file_count'    => count( $documents ),
					'ms'            => $file_ms,
				) );
			}
		}

		if ( ! empty( $audio ) ) {
			$audio_t0 = microtime( true );
			$audio_result = $this->transcribe_audio( $audio );
			$audio_ms = (int) ( ( microtime( true ) - $audio_t0 ) * 1000 );
			$pack['audio_transcript'] = (array) ( $audio_result['segments'] ?? array() );
			if ( ! empty( $audio_result['success'] ) ) {
				foreach ( $pack['audio_transcript'] as $segment ) {
					if ( is_array( $segment ) && ! empty( $segment['text'] ) ) {
						$pack['query_expansion'][] = mb_substr( trim( (string) $segment['text'] ), 0, 240 );
					}
				}
				$this->emit( $emit, 'audio_transcript_ready', array(
					'trace_id'   => (string) $trace_id,
					'audio_count'=> count( $audio ),
					'char_count' => (int) ( $audio_result['total_chars'] ?? 0 ),
					'ms'         => $audio_ms,
				) );
			} else {
				$pack['degraded'] = true;
				if ( '' === (string) $pack['reason_bucket'] ) {
					$pack['reason_bucket'] = (string) ( $audio_result['reason_bucket'] ?? 'audio_transcribe_failed' );
				}
				$this->emit( $emit, 'multimodal_ingest_degraded', array(
					'trace_id'      => (string) $trace_id,
					'degraded'      => true,
					'reason_bucket' => (string) ( $audio_result['reason_bucket'] ?? 'audio_transcribe_failed' ),
					'ms'            => $audio_ms,
				) );
			}
		}

		$filename_terms = $this->terms_from_filenames( $attachments );
		$pack['query_expansion'] = array_values( array_unique( array_filter( array_merge(
			(array) $pack['entities'],
			(array) $pack['ocr'],
			$filename_terms
		) ) ) );

		if ( ! empty( $pack['query_expansion'] ) ) {
			$this->emit( $emit, 'multimodal_entities_ready', array(
				'trace_id'     => (string) $trace_id,
				'entity_count' => count( (array) $pack['entities'] ),
				'layout_count' => $this->count_nested_values( $pack['vision'], 'layout' ),
				'style_count'  => $this->count_nested_values( $pack['vision'], 'style' ),
				'object_count' => $this->count_nested_values( $pack['vision'], 'objects' ),
			) );
		}

		$intent = $this->detect_intent( (string) $prompt, $attachments );
		$pack['intent'] = $intent;
		$this->emit( $emit, 'intent_detected', array(
			'trace_id' => (string) $trace_id,
			'intent'   => $intent,
		) );

		$this->emit( $emit, empty( $pack['degraded'] ) ? 'multimodal_ingest_done' : 'multimodal_ingest_degraded', array(
			'trace_id'      => (string) $trace_id,
			'degraded'      => ! empty( $pack['degraded'] ),
			'reason_bucket' => (string) $pack['reason_bucket'],
			'ms'            => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		) );

		$opts['multimodal_ingest_pack'] = $pack;
		$opts['multimodal_context_md']  = $this->render_context_md( $pack );
		$opts['multimodal_intent']      = $intent;
		$opts['multimodal_enriched_query'] = trim( (string) $prompt . "\n" . implode( "\n", array_slice( (array) $pack['query_expansion'], 0, 20 ) ) );

		return $opts;
	}

	private function emit( $emit, $event_type, array $payload ) {
		if ( is_callable( $emit ) ) {
			call_user_func( $emit, (string) $event_type, $payload );
		}
	}

	private function normalize_attachments( array $attachments ) {
		$out = array();
		foreach ( $attachments as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$mime = sanitize_text_field( (string) ( $attachment['mime_type'] ?? '' ) );
			$url  = esc_url_raw( (string) ( $attachment['url'] ?? '' ) );
			$out[] = array(
				'id'        => (int) ( $attachment['id'] ?? 0 ),
				'filename'  => sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) ),
				'mime_type' => $mime,
				'size'      => (int) ( $attachment['size'] ?? 0 ),
				'url'       => $url,
				'kind'      => $this->kind_from_mime( $mime ),
			);
		}
		return $out;
	}

	private function parse_prompt_attachment_context( $prompt ) {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — parse lines generated by TwinWeb with_attachment_prompt_context().
		$out = array();
		if ( ! preg_match( '/\[TWIN_GPT_ATTACHMENTS\]([\s\S]*?)\[\/TWIN_GPT_ATTACHMENTS\]/', (string) $prompt, $match ) ) {
			return $out;
		}
		$lines = preg_split( '/\r?\n/', (string) $match[1] );
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( ! preg_match( '/^\d+\.\s*(.*?)\s*\|\s*(image|audio|file|document)\s*\|\s*([^|]*)\s*\|\s*(\d+)\s*bytes\s*\|\s*(https?:\/\/\S+)$/i', $line, $parts ) ) {
				continue;
			}
			$mime = sanitize_text_field( trim( (string) $parts[3] ) );
			$out[] = array(
				'id'        => 0,
				'filename'  => sanitize_file_name( trim( (string) $parts[1] ) ),
				'mime_type' => $mime,
				'size'      => (int) $parts[4],
				'url'       => esc_url_raw( (string) $parts[5] ),
				'kind'      => $this->kind_from_mime( $mime ),
			);
		}
		return $out;
	}

	private function kind_from_mime( $mime ) {
		$mime = strtolower( (string) $mime );
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		return 'document';
	}

	private function count_by_kind( array $attachments ) {
		$counts = array( 'image' => 0, 'document' => 0, 'audio' => 0 );
		foreach ( $attachments as $attachment ) {
			$kind = (string) ( $attachment['kind'] ?? 'document' );
			if ( isset( $counts[ $kind ] ) ) {
				$counts[ $kind ]++;
			}
		}
		return $counts;
	}

	private function filter_by_kind( array $attachments, $kind ) {
		return array_values( array_filter( $attachments, static function ( $attachment ) use ( $kind ) {
			return is_array( $attachment ) && (string) ( $attachment['kind'] ?? '' ) === (string) $kind;
		} ) );
	}

	private function analyze_images( array $images, $prompt, array $opts ) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( 'success' => false, '_degraded' => true, 'reason_bucket' => 'llm_client_missing' );
		}

		$media = array();
		foreach ( $images as $image ) {
			if ( ! empty( $image['url'] ) ) {
				$media[] = $image;
			}
		}
		if ( empty( $media ) ) {
			return array( 'success' => false, '_degraded' => true, 'reason_bucket' => 'attachment_url_missing' );
		}

		return BizCity_LLM_Client::instance()->analyze_media( $media, (string) $prompt, array(
			'purpose' => 'vision',
			'timeout' => isset( $opts['vision_timeout'] ) ? (int) $opts['vision_timeout'] : 45,
		) );
	}

	private function extract_documents( array $documents ) {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — MVP reads text-like attachments for real; unsupported binary docs stay degraded.
		$segments = array();
		$errors   = array();
		$total    = 0;
		foreach ( $documents as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$mime = strtolower( (string) ( $doc['mime_type'] ?? '' ) );
			if ( ! $this->is_text_like_mime( $mime, (string) ( $doc['filename'] ?? '' ) ) ) {
				$errors[] = array( 'id' => (int) ( $doc['id'] ?? 0 ), 'reason' => 'unsupported_mime', 'mime' => $mime );
				continue;
			}
			$url = esc_url_raw( (string) ( $doc['url'] ?? '' ) );
			if ( '' === $url ) {
				$errors[] = array( 'id' => (int) ( $doc['id'] ?? 0 ), 'reason' => 'url_missing' );
				continue;
			}
			$size = (int) ( $doc['size'] ?? 0 );
			if ( $size > self::MAX_TEXT_FILE_BYTES ) {
				$errors[] = array( 'id' => (int) ( $doc['id'] ?? 0 ), 'reason' => 'file_too_large', 'size' => $size );
				continue;
			}
			$res = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2 ) );
			if ( is_wp_error( $res ) ) {
				$errors[] = array( 'id' => (int) ( $doc['id'] ?? 0 ), 'reason' => $res->get_error_code() );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			if ( $code < 200 || $code >= 300 || '' === trim( $body ) ) {
				$errors[] = array( 'id' => (int) ( $doc['id'] ?? 0 ), 'reason' => 'download_failed', 'http_code' => $code );
				continue;
			}
			$text = wp_strip_all_tags( $body );
			$text = preg_replace( '/\s+/', ' ', $text );
			$text = trim( (string) $text );
			if ( '' === $text ) {
				$errors[] = array( 'id' => (int) ( $doc['id'] ?? 0 ), 'reason' => 'empty_text' );
				continue;
			}
			$text = mb_substr( $text, 0, self::MAX_CONTEXT_CHARS );
			$total += mb_strlen( $text );
			$segments[] = array(
				'attachment_id' => (int) ( $doc['id'] ?? 0 ),
				'filename'      => (string) ( $doc['filename'] ?? '' ),
				'mime_type'     => $mime,
				'text'          => $text,
				'char_count'    => mb_strlen( $text ),
			);
		}

		return array(
			'success'       => ! empty( $segments ),
			'segments'      => $segments,
			'total_chars'   => $total,
			'errors'        => $errors,
			'reason_bucket' => empty( $segments ) ? ( ! empty( $errors[0]['reason'] ) ? (string) $errors[0]['reason'] : 'file_extract_unsupported' ) : '',
		);
	}

	private function transcribe_audio( array $audio_items ) {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — use Branch 02 transcribe wrapper for real audio intake.
		$this->ensure_branch02_clients_loaded();
		if ( ! class_exists( 'BizCity_AV_Transcribe_Client' ) ) {
			return array( 'success' => false, 'segments' => array(), 'reason_bucket' => 'av_transcribe_client_missing' );
		}

		$segments = array();
		$errors   = array();
		$total    = 0;
		foreach ( $audio_items as $audio ) {
			if ( ! is_array( $audio ) || empty( $audio['url'] ) ) {
				continue;
			}
			$out = BizCity_AV_Transcribe_Client::instance()->transcribe(
				esc_url_raw( (string) $audio['url'] ),
				'audio',
				array(
					'mime'        => (string) ( $audio['mime_type'] ?? '' ),
					'lang'        => 'vi',
					'prompt_hint' => 'TwinBrain user attachment; transcribe accurately for answering the current user prompt.',
					'plugin_name' => 'twinbrain/multimodal-intake',
				)
			);
			if ( is_wp_error( $out ) ) {
				$errors[] = array( 'id' => (int) ( $audio['id'] ?? 0 ), 'reason' => $out->get_error_code(), 'message' => $out->get_error_message() );
				continue;
			}
			$text = trim( (string) ( $out['text'] ?? '' ) );
			if ( '' === $text ) {
				$errors[] = array( 'id' => (int) ( $audio['id'] ?? 0 ), 'reason' => 'empty_transcript' );
				continue;
			}
			$text = mb_substr( $text, 0, self::MAX_CONTEXT_CHARS );
			$total += mb_strlen( $text );
			$segments[] = array(
				'attachment_id' => (int) ( $audio['id'] ?? 0 ),
				'filename'      => (string) ( $audio['filename'] ?? '' ),
				'text'          => $text,
				'char_count'    => mb_strlen( $text ),
				'meta'          => is_array( $out['meta'] ?? null ) ? $out['meta'] : array(),
			);
		}

		return array(
			'success'       => ! empty( $segments ),
			'segments'      => $segments,
			'total_chars'   => $total,
			'errors'        => $errors,
			'reason_bucket' => empty( $segments ) ? ( ! empty( $errors[0]['reason'] ) ? (string) $errors[0]['reason'] : 'audio_transcribe_failed' ) : '',
		);
	}

	private function is_text_like_mime( $mime, $filename ) {
		$mime = strtolower( (string) $mime );
		if ( 0 === strpos( $mime, 'text/' ) ) {
			return true;
		}
		if ( in_array( $mime, array( 'application/json', 'application/xml', 'application/csv', 'text/csv' ), true ) ) {
			return true;
		}
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( 'txt', 'md', 'csv', 'json', 'xml', 'html', 'htm' ), true );
	}

	private function ensure_branch02_clients_loaded() {
		if ( class_exists( 'BizCity_AV_Transcribe_Client' ) ) {
			return;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$client = $root . 'core/knowledge/kg-hub/includes/clients/class-av-transcribe-client.php';
		if ( is_readable( $client ) ) {
			require_once $client;
		}
	}

	private function terms_from_filenames( array $attachments ) {
		$terms = array();
		foreach ( $attachments as $attachment ) {
			$name = sanitize_file_name( (string) ( $attachment['filename'] ?? '' ) );
			$name = preg_replace( '/\.[a-z0-9]{1,8}$/i', '', $name );
			$name = str_replace( array( '-', '_' ), ' ', (string) $name );
			$name = trim( preg_replace( '/\s+/', ' ', $name ) );
			if ( $name !== '' ) {
				$terms[] = $name;
			}
		}
		return array_values( array_unique( $terms ) );
	}

	private function count_nested_values( array $rows, $key ) {
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row[ $key ] ) ) {
				continue;
			}
			$count += is_array( $row[ $key ] ) ? count( $row[ $key ] ) : 1;
		}
		return $count;
	}

	private function detect_intent( $prompt, array $attachments ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $prompt, 'UTF-8' ) : strtolower( (string) $prompt );
		if ( preg_match( '/(tạo|tao|generate|vẽ|ve|thiết kế|thiet ke).{0,30}(ảnh|anh|image|poster|logo|banner)/u', $text ) ) {
			return 'generate_image';
		}
		if ( ! empty( $attachments ) && preg_match( '/(ảnh gì|anh gi|file này|file nay|phân tích|phan tich|nhận diện|nhan dien|đọc file|doc file)/u', $text ) ) {
			return 'analyze_file';
		}
		return ! empty( $attachments ) ? 'answer_with_attachments' : 'answer';
	}

	private function render_context_md( array $pack ) {
		$lines = array();
		$lines[] = '## MULTIMODAL INTAKE CONTEXT';
		$lines[] = '- schema: `' . (string) $pack['schema'] . '`';
		$lines[] = '- intent: `' . (string) $pack['intent'] . '`';
		$lines[] = '- degraded: `' . ( ! empty( $pack['degraded'] ) ? 'true' : 'false' ) . '`' . ( ! empty( $pack['reason_bucket'] ) ? '; reason: `' . (string) $pack['reason_bucket'] . '`' : '' );
		$lines[] = '';
		$lines[] = '### Attachments';
		foreach ( (array) $pack['attachments'] as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$lines[] = '- #' . (int) ( $attachment['id'] ?? 0 ) . ' `' . (string) ( $attachment['filename'] ?? '' ) . '` · ' . (string) ( $attachment['kind'] ?? '' ) . ' · ' . (string) ( $attachment['mime_type'] ?? '' ) . ' · ' . (int) ( $attachment['size'] ?? 0 ) . ' bytes · ' . (string) ( $attachment['url'] ?? '' );
		}
		foreach ( (array) $pack['vision'] as $vision ) {
			if ( ! is_array( $vision ) ) {
				continue;
			}
			$summary = trim( (string) ( $vision['summary'] ?? '' ) );
			if ( $summary !== '' ) {
				$lines[] = '';
				$lines[] = '### Vision Summary';
				$lines[] = mb_substr( $summary, 0, 1200 );
			}
		}
		foreach ( (array) $pack['file_text'] as $segment ) {
			if ( is_array( $segment ) && ! empty( $segment['text'] ) ) {
				$lines[] = '';
				$lines[] = '### File Extract: ' . (string) ( $segment['filename'] ?? '' );
				$lines[] = mb_substr( (string) $segment['text'], 0, 2500 );
			}
		}
		foreach ( (array) $pack['audio_transcript'] as $segment ) {
			if ( is_array( $segment ) && ! empty( $segment['text'] ) ) {
				$lines[] = '';
				$lines[] = '### Audio Transcript: ' . (string) ( $segment['filename'] ?? '' );
				$lines[] = mb_substr( (string) $segment['text'], 0, 2500 );
			}
		}
		if ( ! empty( $pack['query_expansion'] ) ) {
			$lines[] = '';
			$lines[] = '### Query Expansion';
			$lines[] = implode( ', ', array_slice( (array) $pack['query_expansion'], 0, 20 ) );
		}
		return implode( "\n", $lines );
	}
}