<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicTokinizer {
	protected $name;
	protected $mergeableRanks;
	protected $decodeMergeableRanks;
	protected $pattenRegex;
	protected $specialRegex;
	protected $specialTokens;
	protected $decodeSpecialTokens;

	public function __construct( string $name, &$mergeableRanks, string $pattenRegex, array $specialTokens = [], ?int $explicitNVocab = null ) {
		$this->name = $name;
		$this->mergeableRanks = $mergeableRanks;
		$this->pattenRegex = $pattenRegex . 'u'; // u for unicode
		$this->specialTokens = $specialTokens;

		$maxTokenValue = 0;

		$escapeToken = [];
		$this->decodeSpecialTokens = [];
		foreach ($this->specialTokens as $token => $rank) {
			$escapeToken[] = str_replace('|', '\|', $token);
			$this->decodeSpecialTokens[$rank] = $token;

			if ($rank > $maxTokenValue) {
				$maxTokenValue = $rank;
			}
		}
		$this->specialRegex = '/' . implode('|', $escapeToken) . '/u';

		// for decode
		$this->decodeMergeableRanks = [];
		foreach ($this->mergeableRanks as $token => $rank) {
			$this->decodeMergeableRanks[$rank] = $token;

			if ($rank > $maxTokenValue) {
				$maxTokenValue = $rank;
			}
		}

		if (count($this->mergeableRanks) !== count($this->decodeMergeableRanks)) {
			throw new InvalidArgumentException('Encoder and decoder must be of equal length; maybe you had duplicate token indices in your encoder?');
		}

		if (!is_null($explicitNVocab)) {
			if (count($this->mergeableRanks) + count($this->specialTokens) !== $explicitNVocab) {
				throw new InvalidArgumentException("explicitNVocab check failed: total token count mismatch");
			}

			if ($maxTokenValue !== $explicitNVocab - 1) {
				throw new InvalidArgumentException("explicitNVocab check failed: Max token({$maxTokenValue}) !== {$explicitNVocab} - 1");
			}
		}
	}

	public function getName() {
		return $this->name;
	}

	public function getSpecialTokensSet() {
		return array_keys($this->specialTokens);
	}

	/**
	 * Encodes a string into tokens, ignoring special tokens.
	 * This is equivalent to `encode($text, disallowedSpecial: [])` (but slightly faster).
	 *
	 * @param string $text
	 * @return int[]
	 */
	public function encodeOrdinary( string $text ): array {
		$result = array();
		preg_match_all($this->pattenRegex, $text, $matches);
		foreach ($matches[0] as $match) {
			$token = $this->mergeableRanks[$match] ?? null;
			if (!is_null($token)) {
				$result[] = $token;
			} else {
				$resultList = $this->bytePairEncode($match, $this->mergeableRanks);
				foreach ($resultList as $item) {
					$result[] = $item;
				}
			}
		}

		return $result;
	}

	public function encode( string $text, $allowedSpecial = [], $disallowedSpecial = 'all' ): array {
		if ($allowedSpecial === 'all') {
			$allowedSpecial = $this->getSpecialTokensSet();
		}
		if ($disallowedSpecial === 'all') {
			$disallowedSpecial = array_diff($this->getSpecialTokensSet(), $allowedSpecial);
		}
		if (count($disallowedSpecial) > 0) {
			$escapeToken = [];
			foreach ($disallowedSpecial as $token) {
				$escapeToken[] = str_replace('|', '\|', $token);
			}
			$disallowedSpecialRegex = '/' . implode('|', $escapeToken) . '/u';

			preg_match_all($disallowedSpecialRegex, $text, $matches);
			if (count($matches[0]) > 0) {
				$token = $matches[0][0];
				throw new ValueError(
					"Encountered text corresponding to disallowed special token '{$token}'.\n" .
					"If you want this text to be encoded as a special token, " .
					"pass it to `allowedSpecial`, e.g. `allowedSpecial: ['{$token}', ...]`.\n" .
					"If you want this text to be encoded as normal text, disable the check for this token " .
					"by passing `disallowedSpecial: array_diff(\$enc->getSpecialTokensSet(), ['{$token}']))`.\n" .
					"To disable this check for all special tokens, pass `disallowedSpecial: []`.\n"
				);
			}
		}

		$result = array();
		$start = 0;
		while (true) {
			$hasNextSpecial = false;
			$nextSpecial = null;

			$startFind = $start;
			while (true) {
				// Find the next allowed special token, if any
				preg_match($this->specialRegex, $text, $matches, PREG_OFFSET_CAPTURE, $startFind);
				if (count($matches) > 0) {
					if (in_array($matches[0][0], $allowedSpecial, true)) {
						$hasNextSpecial = true;
						$nextSpecial = $matches[0][0];
						break;
					}

					$startFind = $matches[0][1] + 1;
				} else {
					break;
				}
			}
			if ($hasNextSpecial) {
				$end = $matches[0][1];
			} else {
				$end = strlen($text);
			}

			// Okay, here we go, compare this logic to _encode_ordinary_native
			preg_match_all($this->pattenRegex, substr($text, $start, $end - $start), $matches);
			foreach ($matches[0] as $match) {
				$token = $this->mergeableRanks[$match] ?? null;
				if (!is_null($token)) {
					$result[] = $token;
				} else {
					$resultList = $this->bytePairEncode($match, $this->mergeableRanks);
					foreach ($resultList as $item) {
						$result[] = $item;
					}
				}
			}

			if ($hasNextSpecial) {
				$token = $this->specialTokens[$nextSpecial];
				$result[] = $token;
				$start = $end + strlen($nextSpecial);
			} else {
				break;
			}
		}

		return $result;
	}

	protected function bytePairEncode( string $piece, $ranks ): array {
		// This is a vector of (start, rank).
		// The rank is of the byte pair starting at position start.
		// The rank of the last item in the vector is not a valid value.
		$parts = array();
		for ($i = 0; $i < strlen($piece) + 1; $i++) {
			$parts[] = [$i, PHP_INT_MAX];
		}

		$getRank = function (&$parts, int $startIdx, int $skip) use (&$ranks, &$piece) {
			if (($startIdx + $skip + 2) < count($parts)) {
				$subStart = $parts[$startIdx][0];
				$subEnd = $parts[$startIdx + $skip + 2][0];

				return $ranks[substr($piece, $subStart, $subEnd - $subStart)] ?? null;
			} else {
				return null;
			}
		};

		// We look up the ranks once in the beginning and iteratively update
		// them during each merge, which reduces the number of rank lookups.
		for ($i = 0; $i < count($parts) - 2; $i++) {
			$rank = $getRank($parts, $i, 0);
			if (!is_null($rank)) {
				if ($rank === PHP_INT_MAX) {
					throw new Exception();
				}
				$parts[$i][1] = $rank;
			}
		}

		// If you have n parts and m merges, this does O(mn) work.
		// We could do something with a heap and do O(m log n) work.
		// It is important to consider that n is often small (<100), and as such
		// the cache-locality benefits outweigh the algorithmic complexity downsides
		// of the `parts` vector data structure above.

		// Note that we hash bytes, not token pairs. As long as we train BPE the way we
		// currently do, this is equivalent. An easy way to break this would be to decouple
		// merge priority from token index or to prevent specific token merges.
		while (true) {
			if (count($parts) == 1) {
				break;
			}

			// PHP_INT_MAX is a sentinel rank value allowing us to
			// take the min more quickly
			$minRank = [PHP_INT_MAX, 0];
			for ($i = 0; $i < count($parts) - 1; $i++) {
				if ($parts[$i][1] < $minRank[0]) {
					$minRank = [$parts[$i][1], $i];
				}
			}

			if ($minRank[0] !== PHP_INT_MAX) {
				$i = $minRank[1];

				// NOTE: We are about to remove parts[i + 1]. We do not do it
				// yet because there are cache-locality benefits to updating
				// parts[i] and parts[i-1] before removing, which could thrash
				// the cache. Thus, we update the rank calculation by skipping over
				// parts[i + 1], by invoking `get_rank!` with `skip = 1`.
				// NOTE 2: NOTE is for rust. PHP is not verify this strategy yet.
				$newValue = $getRank($parts, $i, 1);
				$parts[$i][1] = is_null($newValue) ? PHP_INT_MAX : $newValue;
				if ($i > 0) {
					$newValue = $getRank($parts, $i - 1, 1);
					$parts[$i - 1][1] = is_null($newValue) ? PHP_INT_MAX : $newValue;
				}

				array_splice($parts, $i + 1, 1, null);
			} else {
				break;
			}
		}

		$out = array();
		for ($i = 0; $i < count($parts) - 1; $i++) {
			$start = $parts[$i][0];
			$end = $parts[$i + 1][0];

			$out[] = $ranks[substr($piece, $start, $end - $start)];
		}

		return $out;
	}

	public function decode( array $tokens ): string {
		$result = '';
		foreach ($tokens as $token) {
			$out = $this->decodeMergeableRanks[$token] ?? null;
			if (is_null($out)) {
				$out = $this->decodeSpecialTokens[$token];
			}

			$result .= $out;
		}

		return $result;
	}
}
