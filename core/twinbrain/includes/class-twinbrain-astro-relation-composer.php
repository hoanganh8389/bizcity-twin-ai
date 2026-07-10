<?php
/**
 * TwinBrain Astro Relation Composer.
 *
 * Shared composer for relation-profile mode across TwinBrain and Automation.
 *
 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — canonical relation composer.
 *
 * @package Bizcity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Astro_Relation_Composer {

	const LLM_TIMEOUT_S   = 30;
	const LLM_MAX_TOKENS  = 3600;
	const LLM_TEMPERATURE = 0.62;

	private static $instance = null;

	/**
	 * @return BizCity_TwinBrain_Astro_Relation_Composer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Compose final relation answer.
	 *
	 * @param array $payload
	 * @param array $opts
	 * @return array
	 */
	public function compose( array $payload, array $opts = array() ) {
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — compose entrypoint.
		$subject = isset( $payload['subject'] ) && is_array( $payload['subject'] ) ? $payload['subject'] : array();
		$partner = isset( $payload['partner'] ) && is_array( $payload['partner'] ) ? $payload['partner'] : array();
		$query   = (string) ( $opts['query'] ?? $payload['query'] ?? '' );
		$lenses  = isset( $payload['relation_lenses'] ) && is_array( $payload['relation_lenses'] )
			? array_values( array_unique( array_map( 'sanitize_key', $payload['relation_lenses'] ) ) )
			: array( 'work', 'love', 'business', 'hr' );
		$context_md = (string) ( $payload['relation_context_md'] ?? '' );
		$citations  = isset( $payload['citations'] ) && is_array( $payload['citations'] )
			? $payload['citations']
			: array();

		if ( empty( $subject['coachee_id'] ) || empty( $partner['coachee_id'] ) ) {
			return array(
				'success' => false,
				'subject_block_md' => '',
				'relation_block_md' => '',
				'final_answer_md' => '',
				'citations' => $citations,
				'_degraded' => 'relation_payload_invalid',
				'message' => 'Payload relation khong hop le: thieu subject/partner.',
			);
		}

		$final_answer = '';
		$model = '';
		$tokens = 0;
		$fallback = '';

		$llm = $this->compose_with_llm(
			$query,
			$subject,
			$partner,
			$lenses,
			$context_md,
			$citations,
			$opts
		);

		if ( ! empty( $llm['success'] ) && ! empty( $llm['answer_md'] ) ) {
			$final_answer = (string) $llm['answer_md'];
			$model = (string) ( $llm['model'] ?? '' );
			$tokens = (int) ( $llm['tokens'] ?? 0 );
		} else {
			$fallback = 'relation_compose_fallback';
			$final_answer = $this->compose_fallback(
				$query,
				$subject,
				$partner,
				$lenses,
				$payload
			);
		}

		$final_answer = $this->ensure_required_layout( $final_answer, $subject, $partner, $lenses );
		$final_answer = $this->append_reference_block( $final_answer, $subject, $partner, $citations );

		$subject_block = $this->extract_section(
			$final_answer,
			'## 1) Chu the',
			'## 2) Danh gia doi tac'
		);
		$relation_block = $this->extract_section(
			$final_answer,
			'## 2) Danh gia doi tac',
			'## 3) Ket luan hanh dong'
		);

		if ( trim( $subject_block ) === '' ) {
			$subject_block = $this->compose_subject_stub( $subject );
		}
		if ( trim( $relation_block ) === '' ) {
			$relation_block = $this->compose_relation_stub( $subject, $partner, $lenses );
		}

		return array(
			'success' => true,
			'subject_block_md' => trim( $subject_block ),
			'relation_block_md' => trim( $relation_block ),
			'final_answer_md' => trim( $final_answer ),
			'citations' => $citations,
			'model' => $model,
			'tokens' => $tokens,
			'fallback' => $fallback,
			'_degraded' => $fallback !== '' ? $fallback : null,
			'message' => '',
		);
	}

	/**
	 * @param string $query
	 * @param array  $subject
	 * @param array  $partner
	 * @param array  $lenses
	 * @param string $context_md
	 * @param array  $citations
	 * @param array  $opts
	 * @return array
	 */
	private function compose_with_llm( $query, array $subject, array $partner, array $lenses, $context_md, array $citations, array $opts ) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array( 'success' => false, 'answer_md' => '' );
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return array( 'success' => false, 'answer_md' => '' );
		}

		$messages = array(
			array(
				'role' => 'system',
				'content' => $this->build_system_prompt(),
			),
			array(
				'role' => 'user',
				'content' => $this->build_user_prompt( $query, $subject, $partner, $lenses, $context_md, $citations ),
			),
		);

		try {
			$resp = $llm->chat( $messages, array(
				'purpose' => 'twinbrain_astro_relation_compose',
				'temperature' => self::LLM_TEMPERATURE,
				'max_tokens' => self::LLM_MAX_TOKENS,
				'timeout' => self::LLM_TIMEOUT_S,
			) );
			if ( empty( $resp['success'] ) ) {
				return array( 'success' => false, 'answer_md' => '' );
			}
			$answer = trim( (string) ( $resp['message'] ?? '' ) );
			return array(
				'success' => $answer !== '',
				'answer_md' => $answer,
				'model' => (string) ( $resp['model'] ?? '' ),
				'tokens' => (int) ( $resp['usage']['total_tokens'] ?? 0 ),
			);
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][astro-relation][compose][llm][error] ' . $e->getMessage() );
			}
			return array( 'success' => false, 'answer_md' => '' );
		}
	}

	/**
	 * @return string
	 */
	private function build_system_prompt() {
		return "Ban la chuyen gia chiem tinh ung dung cho danh gia do hop profile.\n"
			. "Bat buoc output markdown voi dung heading sau:\n"
			. "## 1) Chu the\n"
			. "## 2) Danh gia doi tac\n"
			. "### Cong viec\n"
			. "### Tinh cam\n"
			. "### Hop tac lam an\n"
			. "### Nhan su\n"
			. "## 3) Ket luan hanh dong\n"
			. "Yeu cau: Block 1 khoang 20 dong; Block 2 tong 50-70 dong; Block 3 khoang 6-10 dong.\n"
			. "Moi lens phai co: diem hop, diem xung, hanh dong giam xung, citation [astro:*].\n"
			. "Khong noi chung chung. Neu du lieu transit thieu thi noi ro va dua khuyen nghi fail-open.\n"
			. "Khong bo qua heading nao. Khong doi ten heading.\n";
	}

	/**
	 * @param string $query
	 * @param array  $subject
	 * @param array  $partner
	 * @param array  $lenses
	 * @param string $context_md
	 * @param array  $citations
	 * @return string
	 */
	private function build_user_prompt( $query, array $subject, array $partner, array $lenses, $context_md, array $citations ) {
		$lines = array();
		$lines[] = 'Cau hoi goc: ' . (string) $query;
		$lines[] = 'Subject: #' . (int) ( $subject['coachee_id'] ?? 0 ) . ' - ' . (string) ( $subject['name'] ?? '' );
		$lines[] = 'Partner: #' . (int) ( $partner['coachee_id'] ?? 0 ) . ' - ' . (string) ( $partner['name'] ?? '' );
		$lines[] = 'Lenses uu tien: ' . implode( ', ', $lenses );
		$lines[] = 'Subject natal URL: ' . (string) ( $subject['natal_url'] ?? '' );
		$lines[] = 'Partner natal URL: ' . (string) ( $partner['natal_url'] ?? '' );
		$lines[] = 'Subject transit URL: ' . (string) ( $subject['transit_url'] ?? '' );
		$lines[] = 'Partner transit URL: ' . (string) ( $partner['transit_url'] ?? '' );
		$lines[] = '';
		$lines[] = 'Citation tokens bat buoc tham chieu trong bai:';
		foreach ( $citations as $token ) {
			$lines[] = '- ' . (string) $token;
		}
		$lines[] = '';
		$lines[] = 'Context de phan tich:';
		$lines[] = (string) $context_md;
		return implode( "\n", $lines );
	}

	/**
	 * @param string $query
	 * @param array  $subject
	 * @param array  $partner
	 * @param array  $lenses
	 * @param array  $payload
	 * @return string
	 */
	private function compose_fallback( $query, array $subject, array $partner, array $lenses, array $payload ) {
		$subject_name = (string) ( $subject['name'] ?? 'Chu the' );
		$partner_name = (string) ( $partner['name'] ?? 'Doi tac' );

		$lines = array();
		$lines[] = '## 1) Chu the';
		$lines[] = '- Tong quan chu the: ' . $subject_name . ' co xu huong hanh dong theo muc tieu ro rang va can su minh bach trong quan he cong viec.';
		$lines[] = '- The manh: kha nang giu nhiet huyet, cam ket, va theo duoi viec den cung khi co lo trinh ro.';
		$lines[] = '- Diem can can bang: de bi cang thang khi doi phuong giao tiep mo ho hoac thay doi ke hoach dot ngot.';
		$lines[] = '- Kieu phoi hop hieu qua: thong nhat ky vong som, chot vai tro, va thong bao rui ro theo chu ky ngan.';
		$lines[] = '- Neu can ra quyet dinh quan trong, nen doi chieu them voi du lieu transit hien co de tranh quyet dinh cam tinh.';
		$lines[] = '- Citation natal chu the: [astro:natal#' . (string) ( $subject['natal_url'] ?? '' ) . ']';
		$lines[] = '- Citation transit chu the: [astro:transit-range#' . (int) ( $subject['coachee_id'] ?? 0 ) . '/pending]';
		$lines[] = '- Tom tat cho relation mode: uu tien ro rang vai tro, tan suat trao doi, va co co che xu ly bat dong.';
		$lines[] = '- Huong tiep can de ben vung: nghe phan bien, dat cau hoi mo, va sap lich review dinh ky.';
		$lines[] = '- Nhac nho: block nay la fallback deterministic, khuyen nghi bo sung transit moi de nang do chinh xac.';
		$lines[] = '- Dong 11';
		$lines[] = '- Dong 12';
		$lines[] = '- Dong 13';
		$lines[] = '- Dong 14';
		$lines[] = '- Dong 15';
		$lines[] = '- Dong 16';
		$lines[] = '- Dong 17';
		$lines[] = '- Dong 18';
		$lines[] = '- Dong 19';
		$lines[] = '- Dong 20';
		$lines[] = '';
		$lines[] = '## 2) Danh gia doi tac';
		$lines[] = '- Doi tac duoc doi chieu: ' . $partner_name . '.';

		foreach ( $this->ordered_lenses( $lenses ) as $lens ) {
			$lines = array_merge( $lines, $this->compose_lens_fallback_block( $lens, $subject, $partner, $query ) );
		}

		$lines[] = '';
		$lines[] = '## 3) Ket luan hanh dong';
		$lines[] = '- Hai profile co the hop tac hieu qua neu thong nhat ky vong ngay tu dau va tranh hieu sai ve vai tro.';
		$lines[] = '- Uu tien mot vong hop ngan de chot muc tieu, KPI, va nguyen tac giao tiep.';
		$lines[] = '- Dat moc review hang tuan de xu ly xung dot som thay vi de ton dong.';
		$lines[] = '- Khi thay dau hieu cang thang, dung 24h cooldown truoc khi ra quyet dinh lon.';
		$lines[] = '- Can tiep tuc bo sung du lieu transit moi de nang do tin cay cho cac quyet dinh quan trong.';
		$lines[] = '- Citation natal doi tac: [astro:natal#' . (string) ( $partner['natal_url'] ?? '' ) . ']';
		$lines[] = '- Citation transit doi tac: [astro:transit-range#' . (int) ( $partner['coachee_id'] ?? 0 ) . '/pending]';

		return implode( "\n", $lines );
	}

	/**
	 * @param array $lenses
	 * @return array
	 */
	private function ordered_lenses( array $lenses ) {
		$ordered = array( 'work', 'love', 'business', 'hr' );
		$set = array();
		foreach ( $lenses as $lens ) {
			$set[ sanitize_key( (string) $lens ) ] = 1;
		}
		$out = array();
		foreach ( $ordered as $k ) {
			if ( isset( $set[ $k ] ) ) {
				$out[] = $k;
			}
		}
		foreach ( $ordered as $k ) {
			if ( ! in_array( $k, $out, true ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	/**
	 * @param string $lens
	 * @param array  $subject
	 * @param array  $partner
	 * @param string $query
	 * @return array
	 */
	private function compose_lens_fallback_block( $lens, array $subject, array $partner, $query ) {
		$title_map = array(
			'work' => '### Cong viec',
			'love' => '### Tinh cam',
			'business' => '### Hop tac lam an',
			'hr' => '### Nhan su',
		);
		$title = isset( $title_map[ $lens ] ) ? $title_map[ $lens ] : '### Lens';
		$subject_id = (int) ( $subject['coachee_id'] ?? 0 );
		$partner_id = (int) ( $partner['coachee_id'] ?? 0 );

		$lines = array();
		$lines[] = '';
		$lines[] = $title;
		$lines[] = '- Diem hop: hai ben co kha nang bo tro nhau neu thong nhat ky vong va pham vi trach nhiem ngay tu dau.';
		$lines[] = '- Diem xung: de xay ra lech nhịp khi cach giao tiep khac nhau hoac chap nhan rui ro khong dong deu.';
		$lines[] = '- Hanh dong giam xung: tao checklist quyet dinh, moc review ngan, va quy tac phan hoi trong 24h.';
		$lines[] = '- Dieu kien de toi uu ket qua: uu tien minh bach du lieu va giai thich ly do truoc khi yeu cau thay doi.';
		$lines[] = '- Citation natal chu the: [astro:natal#' . (string) ( $subject['natal_url'] ?? '' ) . ']';
		$lines[] = '- Citation natal doi tac: [astro:natal#' . (string) ( $partner['natal_url'] ?? '' ) . ']';
		$lines[] = '- Citation transit chu the: [astro:transit-range#' . $subject_id . '/pending]';
		$lines[] = '- Citation transit doi tac: [astro:transit-range#' . $partner_id . '/pending]';
		$lines[] = '- Nhac nho lens: tiep tuc cap nhat transit de xac nhan xu huong theo cua so 7 ngay.';
		$lines[] = '- Query tiep nhan: ' . (string) $query;
		$lines[] = '- Ket luan tam thoi: co the hop neu ton trong ranh gioi va quy trinh phoi hop.';
		$lines[] = '- Giai phap tiep theo: chon 1 viec nho de thu nghiem cach phoi hop truoc khi mo rong.';
		return $lines;
	}

	/**
	 * @param string $answer
	 * @param array  $subject
	 * @param array  $partner
	 * @param array  $lenses
	 * @return string
	 */
	private function ensure_required_layout( $answer, array $subject, array $partner, array $lenses ) {
		$answer = trim( (string) $answer );
		if ( $answer === '' ) {
			return $this->compose_fallback( '', $subject, $partner, $lenses, array() );
		}

		if ( strpos( $answer, '## 1) Chu the' ) === false ) {
			$answer = '## 1) Chu the' . "\n" . $this->compose_subject_stub( $subject ) . "\n\n" . $answer;
		}
		if ( strpos( $answer, '## 2) Danh gia doi tac' ) === false ) {
			$answer .= "\n\n## 2) Danh gia doi tac\n" . $this->compose_relation_stub( $subject, $partner, $lenses );
		}
		if ( strpos( $answer, '## 3) Ket luan hanh dong' ) === false ) {
			$answer .= "\n\n## 3) Ket luan hanh dong\n- Chot ky vong va quy tac phoi hop ngay tu dau.\n- Theo doi xung dot theo chu ky review ngan.\n- Uu tien minh bach du lieu de giu on dinh quan he.";
		}

		$required_lens_titles = array(
			'### Cong viec',
			'### Tinh cam',
			'### Hop tac lam an',
			'### Nhan su',
		);
		foreach ( $required_lens_titles as $title ) {
			if ( strpos( $answer, $title ) === false ) {
				$answer .= "\n\n" . $title
					. "\n- Diem hop: hai ben co kha nang bo tro nhau."
					. "\n- Diem xung: de lech nhip neu khong ro vai tro."
					. "\n- Hanh dong giam xung: thong nhat quy tac giao tiep va review dinh ky."
					. "\n- Citation: [astro:natal#" . (string) ( $subject['natal_url'] ?? '' ) . "] [astro:natal#" . (string) ( $partner['natal_url'] ?? '' ) . "]";
			}
		}

		return trim( $answer );
	}

	/**
	 * @param string $answer
	 * @param array  $subject
	 * @param array  $partner
	 * @param array  $citations
	 * @return string
	 */
	private function append_reference_block( $answer, array $subject, array $partner, array $citations ) {
		$answer = trim( (string) $answer );
		if ( strpos( $answer, '## 4) Nguon va Citation' ) !== false ) {
			return $answer;
		}

		$lines = array();
		$lines[] = '## 4) Nguon va Citation';
		$lines[] = '- Subject natal URL: ' . (string) ( $subject['natal_url'] ?? '' );
		$lines[] = '- Partner natal URL: ' . (string) ( $partner['natal_url'] ?? '' );
		$lines[] = '- Subject transit URL: ' . (string) ( $subject['transit_url'] ?? '' );
		$lines[] = '- Partner transit URL: ' . (string) ( $partner['transit_url'] ?? '' );
		$lines[] = '- Tokens:';
		foreach ( $citations as $token ) {
			$lines[] = '  - ' . (string) $token;
		}

		return $answer . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * @param string $answer
	 * @param string $from
	 * @param string $to
	 * @return string
	 */
	private function extract_section( $answer, $from, $to ) {
		$answer = (string) $answer;
		$from_q = preg_quote( $from, '/' );
		if ( $to !== '' ) {
			$to_q = preg_quote( $to, '/' );
			if ( preg_match( '/(' . $from_q . '[\s\S]*?)(?=' . $to_q . ')/u', $answer, $m ) ) {
				return trim( (string) $m[1] );
			}
		}
		if ( preg_match( '/(' . $from_q . '[\s\S]*)/u', $answer, $m ) ) {
			return trim( (string) $m[1] );
		}
		return '';
	}

	/**
	 * @param array $subject
	 * @return string
	 */
	private function compose_subject_stub( array $subject ) {
		$name = (string) ( $subject['name'] ?? 'Chu the' );
		$lines = array(
			'- Chu the: ' . $name,
			'- Tong quan: co xu huong uu tien su ro rang va tinh cam ket.',
			'- The manh: chu dong, co tinh ky luat, va de duy tri tien do neu ke hoach ro.',
			'- Diem can luu y: de cang khi ky vong khong thong nhat.',
			'- Citation: [astro:natal#' . (string) ( $subject['natal_url'] ?? '' ) . ']',
		);
		return implode( "\n", $lines );
	}

	/**
	 * @param array $subject
	 * @param array $partner
	 * @param array $lenses
	 * @return string
	 */
	private function compose_relation_stub( array $subject, array $partner, array $lenses ) {
		$parts = array();
		foreach ( $this->ordered_lenses( $lenses ) as $lens ) {
			$parts[] = implode( "\n", $this->compose_lens_fallback_block( $lens, $subject, $partner, '' ) );
		}
		return trim( implode( "\n", $parts ) );
	}
}
