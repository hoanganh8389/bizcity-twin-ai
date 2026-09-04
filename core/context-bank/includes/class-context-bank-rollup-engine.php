<?php
/**
 * Deterministic, side-effect-free Context Bank rollup reducer.
 *
 * Durable workers, leases and checkpoints remain owned by the later CB5
 * worker slice. This class only reduces bounded metadata evidence.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Rollup_Engine', false ) ) {
	return;
}

final class BizCity_Context_Bank_Rollup_Engine {

	const VERSION = '1.0.0';

	/**
	 * Reduce bounded evidence according to a registered rollup definition.
	 *
	 * @param string              $rollup_id Registered rollup identifier.
	 * @param array<int,array>   $records Metadata-only evidence rows.
	 * @param array<string,mixed> $dimensions Canonical dimension values.
	 * @return array<string,mixed>
	 */
	public static function reduce( $rollup_id, array $records, array $dimensions = array() ) {
		// [2026-09-01 Johnny Chu] PHASE-CB5.1 — reduce only registry-approved bounded metadata evidence with a stable output hash.
		$rollup_id = sanitize_key( (string) $rollup_id );
		if ( ! class_exists( 'BizCity_Context_Bank_Rollup_Registry' ) ) {
			return self::failure( 'rollup_registry_unavailable' );
		}
		$definition = BizCity_Context_Bank_Rollup_Registry::get( $rollup_id );
		if ( ! is_array( $definition ) || (string) ( $definition['status'] ?? 'active' ) === 'retire_only' ) {
			return self::failure( 'rollup_not_registered' );
		}
		$dimension_validation = self::validate_dimensions( $rollup_id, $dimensions );
		if ( empty( $dimension_validation['ok'] ) ) {
			return self::failure( (string) ( $dimension_validation['reason'] ?? 'rollup_dimensions_invalid' ) );
		}
		$record_dimension_validation = self::validate_record_dimensions( $rollup_id, $records, $dimensions );
		if ( empty( $record_dimension_validation['ok'] ) ) {
			return self::failure( (string) ( $record_dimension_validation['reason'] ?? 'rollup_record_dimension_mismatch' ) );
		}
		$normalized = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || (string) ( $record['record_id'] ?? '' ) === '' ) {
				continue;
			}
			if ( $rollup_id === 'customer_product_affinity' && in_array( sanitize_key( (string) ( $record['traffic_type'] ?? '' ) ), array( 'bot', 'internal', 'admin' ), true ) ) {
				continue;
			}
			if ( (string) ( $record['lifecycle_status'] ?? 'active' ) === 'deleted' || (string) ( $record['operation'] ?? 'upsert' ) === 'delete' ) {
				continue;
			}
			if ( $rollup_id === 'conversation_state' && strpos( sanitize_key( (string) ( $record['event_type'] ?? '' ) ), 'delivery' ) !== false ) {
				continue;
			}
			$metadata = self::metadata_record( $record );
			if ( ! empty( $metadata ) ) {
				$normalized[] = $metadata;
			}
		}
		usort( $normalized, function ( $left, $right ) {
			$time_compare = strcmp( (string) ( $left['occurred_at'] ?? '' ), (string) ( $right['occurred_at'] ?? '' ) );
			return $time_compare !== 0 ? $time_compare : strcmp( (string) $left['record_id'], (string) $right['record_id'] );
		} );
		// [2026-09-03 Johnny Chu] PHASE-CB5.1 - deduplicate after canonical ordering so replay output is independent of input arrival order.
		$deduplicated = array();
		$seen_events = array();
		foreach ( $normalized as $record ) {
			$event_uuid = (string) ( $record['event_uuid'] ?? '' );
			if ( $event_uuid !== '' ) {
				if ( isset( $seen_events[ $event_uuid ] ) ) {
					continue;
				}
				$seen_events[ $event_uuid ] = true;
			}
			$deduplicated[] = $record;
		}
		$normalized = $deduplicated;
		$state = self::reduce_state( $rollup_id, $normalized );
		$evidence_refs = array();
		$sample_limit = 25;
		foreach ( $normalized as $record ) {
			if ( count( $evidence_refs ) >= $sample_limit ) {
				break;
			}
			$evidence_refs[] = array(
				'record_id' => (string) $record['record_id'],
				'source_contract_id' => (string) ( $record['source_contract_id'] ?? '' ),
				'source_record_id' => (string) ( $record['source_record_id'] ?? '' ),
				'event_uuid' => (string) ( $record['event_uuid'] ?? '' ),
				'content_hash' => (string) ( $record['content_hash'] ?? '' ),
				'superseded_record_id' => (string) ( $record['superseded_record_id'] ?? '' ),
			);
		}
		$output = array(
			'contract' => 'context-rollup-result',
			'version' => self::VERSION,
			'rollup_id' => $rollup_id,
			'rollup_version' => (string) ( $definition['version'] ?? '1.0.0' ),
			'dimensions' => self::canonicalize( $dimensions ),
			'state' => self::canonicalize( $state ),
			'input_count' => count( $normalized ),
			'evidence_refs' => $evidence_refs,
			'deterministic' => true,
		);
		$output['output_hash'] = hash( 'sha256', wp_json_encode( self::canonicalize( $output ), JSON_UNESCAPED_SLASHES ) );
		return array( 'ok' => true, 'result' => $output );
	}

	/**
	 * Validate the canonical dimension tuple before reducing evidence.
	 *
	 * @param string              $rollup_id Registered rollup identifier.
	 * @param array<string,mixed> $dimensions Canonical dimension values.
	 * @return array<string,mixed>
	 */
	public static function validate_dimensions( $rollup_id, array $dimensions = array() ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — keep direct reducer calls aligned with each rollup family's declared identity/entity tuple.
		$rollup_id = sanitize_key( (string) $rollup_id );
		$required = array(
			'conversation_state' => array( 'conversation' ),
			'customer_product_affinity' => array( 'identity', 'product' ),
			'sku_inventory' => array( 'sku', 'warehouse' ),
			'order_lifecycle' => array( 'order' ),
		);
		if ( ! isset( $required[ $rollup_id ] ) ) {
			return array( 'ok' => false, 'reason' => 'rollup_not_registered' );
		}
		foreach ( $required[ $rollup_id ] as $dimension ) {
			$value = sanitize_text_field( (string) ( $dimensions[ $dimension ] ?? '' ) );
			if ( $value === '' ) {
				return array( 'ok' => false, 'reason' => 'rollup_dimension_' . $dimension . '_required' );
			}
		}
		return array( 'ok' => true );
	}

	/**
	 * Refuse source rows that explicitly belong to another rollup dimension.
	 *
	 * @param string              $rollup_id Registered rollup identifier.
	 * @param array<int,array>    $records Metadata-only evidence rows.
	 * @param array<string,mixed> $dimensions Canonical dimension values.
	 * @return array<string,mixed>
	 */
	public static function validate_record_dimensions( $rollup_id, array $records = array(), array $dimensions = array() ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — reject source rows from a different identity, product, warehouse or order before derived state is built.
		$rollup_id = sanitize_key( (string) $rollup_id );
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( $rollup_id === 'customer_product_affinity' && in_array( sanitize_key( (string) ( $record['traffic_type'] ?? '' ) ), array( 'bot', 'internal', 'admin' ), true ) ) {
				continue;
			}
			if ( $rollup_id === 'conversation_state' && (int) ( $record['conversation_id'] ?? 0 ) > 0 && (string) ( $dimensions['conversation'] ?? '' ) !== (string) $record['conversation_id'] ) {
				return array( 'ok' => false, 'reason' => 'rollup_conversation_dimension_mismatch' );
			}
			if ( $rollup_id === 'order_lifecycle' && (int) ( $record['order_id'] ?? 0 ) > 0 && (string) ( $dimensions['order'] ?? '' ) !== (string) $record['order_id'] ) {
				return array( 'ok' => false, 'reason' => 'rollup_order_dimension_mismatch' );
			}
			if ( $rollup_id === 'sku_inventory' ) {
				if ( (string) ( $record['sku'] ?? '' ) !== '' && (string) ( $dimensions['sku'] ?? '' ) !== (string) $record['sku'] ) {
					return array( 'ok' => false, 'reason' => 'rollup_sku_dimension_mismatch' );
				}
				if ( (int) ( $record['warehouse_id'] ?? 0 ) > 0 && (string) ( $dimensions['warehouse'] ?? '' ) !== (string) $record['warehouse_id'] ) {
					return array( 'ok' => false, 'reason' => 'rollup_warehouse_dimension_mismatch' );
				}
			}
			if ( $rollup_id === 'customer_product_affinity' ) {
				$customer_user_id = (int) ( $record['customer_user_id'] ?? 0 );
				$identity_uuid = sanitize_text_field( (string) ( $record['identity_uuid'] ?? '' ) );
				$identity_key = $customer_user_id > 0 ? 'user:' . $customer_user_id : $identity_uuid;
				$dimension_identity = sanitize_text_field( (string) ( $dimensions['identity'] ?? '' ) );
				$identity_matches = $dimension_identity === '' || $dimension_identity === $identity_key || ( $customer_user_id <= 0 && $dimension_identity === $identity_uuid );
				if ( $identity_key !== '' && ! $identity_matches ) {
					return array( 'ok' => false, 'reason' => 'rollup_identity_dimension_mismatch' );
				}
				$product_id = (int) ( $record['product_id'] ?? 0 );
				$sku = sanitize_text_field( (string) ( $record['sku'] ?? '' ) );
				$product_key = $product_id > 0 ? (string) $product_id : $sku;
				if ( $product_key !== '' && (string) ( $dimensions['product'] ?? '' ) !== '' && (string) ( $dimensions['product'] ?? '' ) !== $product_key ) {
					return array( 'ok' => false, 'reason' => 'rollup_product_dimension_mismatch' );
				}
			}
		}
		return array( 'ok' => true );
	}

	private static function metadata_record( array $record ) {
		// [2026-09-01 Johnny Chu] PHASE-CB5.1 — retain only bounded dimensions/status/provenance fields; never reduce payload bodies.
		$fields = array( 'record_id', 'source_contract_id', 'source_record_id', 'event_uuid', 'content_hash', 'occurred_at', 'event_type', 'direction', 'channel', 'status', 'outcome', 'order_status', 'payment_state', 'fulfillment_state', 'shipment_state', 'refund_state', 'on_hand', 'reserved', 'available', 'inbound', 'stock_status', 'source_version', 'product_id', 'sku', 'warehouse_id', 'order_id', 'customer_user_id', 'conversation_id', 'contact_id', 'identity_uuid', 'traffic_type', 'last_intent', 'superseded_record_id' );
		$out = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $record ) && is_scalar( $record[ $field ] ) ) {
				$out[ $field ] = $record[ $field ];
			}
		}
		return $out;
	}

	private static function reduce_state( $rollup_id, array $records ) {
		$state = array( 'first_message_at' => '', 'last_message_at' => '', 'message_count' => 0, 'inbound_count' => 0, 'outbound_count' => 0, 'channels' => array(), 'last_intent' => '', 'status' => '', 'conversation_status' => 'open', 'reopen_count' => 0, 'last_outcome' => '', 'validity_version' => '' );
		if ( $rollup_id === 'customer_product_affinity' ) {
			$state = array( 'views_30d' => 0, 'clicks_30d' => 0, 'messages_30d' => 0, 'orders_30d' => 0, 'score' => 0, 'score_version' => 'affinity_v1', 'identity_kind' => '', 'identity_key' => '', 'product_id' => 0, 'sku' => '', 'status' => '', 'error_buckets' => array() );
		} elseif ( $rollup_id === 'sku_inventory' ) {
			$state = array( 'on_hand' => null, 'reserved' => null, 'available' => null, 'inbound' => null, 'stock_status' => '', 'source_version' => '', 'sku' => '', 'warehouse_id' => 0, 'status' => '', 'error_buckets' => array(), 'invalid_quantity_fields' => array(), 'correction_count' => 0, 'superseded_record_ids' => array() );
		} elseif ( $rollup_id === 'order_lifecycle' ) {
			$state = array( 'payment_state' => '', 'fulfillment_state' => '', 'shipment_state' => '', 'refund_state' => '', 'last_status' => '', 'related_conversations' => array(), 'related_contacts' => array(), 'correction_count' => 0, 'superseded_record_ids' => array() );
		}
		foreach ( $records as $record ) {
			$event_type = sanitize_key( (string) ( $record['event_type'] ?? '' ) );
			if ( $rollup_id === 'conversation_state' ) {
				if ( strpos( $event_type, 'delivery' ) !== false ) {
					continue;
				}
				$state['message_count']++;
				if ( (string) ( $record['direction'] ?? '' ) === 'inbound' || strpos( $event_type, 'received' ) !== false ) {
					$state['inbound_count']++;
				}
				if ( (string) ( $record['direction'] ?? '' ) === 'outbound' || strpos( $event_type, 'sent' ) !== false ) {
					$state['outbound_count']++;
				}
				$occurred_at = (string) ( $record['occurred_at'] ?? '' );
				if ( $state['first_message_at'] === '' ) {
					$state['first_message_at'] = $occurred_at;
				}
				$state['last_message_at'] = $occurred_at;
				$state['status'] = (string) ( $record['status'] ?? $record['order_status'] ?? $state['status'] );
				$record_status = sanitize_key( (string) ( $record['status'] ?? '' ) );
				if ( strpos( $event_type, 'resolved' ) !== false || $record_status === 'resolved' ) {
					$state['conversation_status'] = 'resolved';
				} elseif ( strpos( $event_type, 'reopened' ) !== false || strpos( $event_type, 'reopen' ) !== false || $record_status === 'open' ) {
					if ( $state['conversation_status'] === 'resolved' ) {
						$state['reopen_count']++;
					}
					$state['conversation_status'] = 'open';
				}
				if ( array_key_exists( 'outcome', $record ) ) {
					$state['last_outcome'] = sanitize_text_field( (string) $record['outcome'] );
				}
				$channel = sanitize_key( (string) ( $record['channel'] ?? '' ) );
				if ( $channel !== '' && ! in_array( $channel, $state['channels'], true ) ) {
					$state['channels'][] = $channel;
				}
				$state['last_intent'] = (string) ( $record['last_intent'] ?? $state['last_intent'] );
			} elseif ( $rollup_id === 'customer_product_affinity' ) {
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5.3 — retain explicit customer/anonymous identity and deterministic affinity version in the rollup state.
				if ( strpos( $event_type, 'view' ) !== false ) { $state['views_30d']++; }
				if ( strpos( $event_type, 'click' ) !== false ) { $state['clicks_30d']++; }
				if ( strpos( $event_type, 'message' ) !== false ) { $state['messages_30d']++; }
				if ( strpos( $event_type, 'order' ) !== false ) { $state['orders_30d']++; }
				$state['score'] = $state['views_30d'] + ( 2 * $state['clicks_30d'] ) + ( 3 * $state['messages_30d'] ) + ( 5 * $state['orders_30d'] );
				$customer_user_id = (int) ( $record['customer_user_id'] ?? 0 );
				$identity_uuid = sanitize_text_field( (string) ( $record['identity_uuid'] ?? '' ) );
				$incoming_identity_kind = $customer_user_id > 0 ? 'customer' : ( $identity_uuid !== '' ? 'anonymous' : 'unresolved' );
				$incoming_identity_key = $customer_user_id > 0 ? 'user:' . $customer_user_id : ( $identity_uuid !== '' ? 'identity:' . $identity_uuid : '' );
				if ( $state['identity_kind'] === '' ) {
					$state['identity_kind'] = $incoming_identity_kind;
					$state['identity_key'] = $incoming_identity_key;
				} elseif ( $state['identity_kind'] !== $incoming_identity_kind || $state['identity_key'] !== $incoming_identity_key ) {
					$state['status'] = 'identity_conflict';
					$state['error_buckets'][] = 'identity_conflict';
				}
				$incoming_product_id = (int) ( $record['product_id'] ?? 0 );
				if ( $incoming_product_id > 0 && $state['product_id'] === 0 ) {
					$state['product_id'] = $incoming_product_id;
				} elseif ( $incoming_product_id > 0 && $state['product_id'] !== $incoming_product_id ) {
					$state['status'] = 'product_conflict';
					$state['error_buckets'][] = 'product_conflict';
				}
				$incoming_sku = sanitize_text_field( (string) ( $record['sku'] ?? '' ) );
				if ( $incoming_sku !== '' && $state['sku'] === '' ) {
					$state['sku'] = $incoming_sku;
				} elseif ( $incoming_sku !== '' && $state['sku'] !== $incoming_sku ) {
					$state['status'] = 'product_conflict';
					$state['error_buckets'][] = 'product_conflict';
				}
			} elseif ( $rollup_id === 'sku_inventory' ) {
				// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5.4 — classify negative and non-numeric quantities without authorizing inventory mutations.
				$invalid_quantity_fields = array();
				foreach ( array( 'on_hand', 'reserved', 'available', 'inbound' ) as $field ) {
					if ( ! array_key_exists( $field, $record ) ) {
						continue;
					}
					$value = $record[ $field ];
					if ( ! is_numeric( $value ) || (float) $value < 0 ) {
						$invalid_quantity_fields[] = $field;
						continue;
					}
					$state[ $field ] = (float) $value;
				}
				if ( array_key_exists( 'stock_status', $record ) ) {
					$state['stock_status'] = sanitize_key( (string) $record['stock_status'] );
				}
				if ( array_key_exists( 'source_version', $record ) ) {
					$state['source_version'] = sanitize_text_field( (string) $record['source_version'] );
				}
				$incoming_sku = sanitize_text_field( (string) ( $record['sku'] ?? '' ) );
				$incoming_warehouse_id = (int) ( $record['warehouse_id'] ?? 0 );
				if ( $incoming_sku !== '' && $state['sku'] === '' ) {
					$state['sku'] = $incoming_sku;
				} elseif ( $incoming_sku !== '' && $state['sku'] !== $incoming_sku ) {
					$state['status'] = 'warehouse_conflict';
					$state['error_buckets'][] = 'warehouse_conflict';
				}
				if ( $incoming_warehouse_id > 0 && $state['warehouse_id'] === 0 ) {
					$state['warehouse_id'] = $incoming_warehouse_id;
				} elseif ( $incoming_warehouse_id > 0 && $state['warehouse_id'] !== $incoming_warehouse_id ) {
					$state['status'] = 'warehouse_conflict';
					$state['error_buckets'][] = 'warehouse_conflict';
				}
				if ( ! empty( $invalid_quantity_fields ) ) {
					$state['status'] = 'invalid_quantity';
					$state['error_buckets'][] = 'invalid_quantity';
					$state['invalid_quantity_fields'] = array_values( array_unique( array_merge( $state['invalid_quantity_fields'], $invalid_quantity_fields ) ) );
				}
				$superseded_record_id = sanitize_text_field( (string) ( $record['superseded_record_id'] ?? '' ) );
				if ( $superseded_record_id !== '' ) {
					$state['correction_count']++;
					if ( ! in_array( $superseded_record_id, $state['superseded_record_ids'], true ) ) {
						$state['superseded_record_ids'][] = $superseded_record_id;
					}
				}
			} elseif ( $rollup_id === 'order_lifecycle' ) {
				foreach ( array( 'payment_state', 'fulfillment_state', 'shipment_state', 'refund_state' ) as $field ) {
					if ( array_key_exists( $field, $record ) ) { $state[ $field ] = (string) $record[ $field ]; }
				}
				$state['last_status'] = (string) ( $record['status'] ?? $record['order_status'] ?? $state['last_status'] );
				$conversation_id = (int) ( $record['conversation_id'] ?? 0 );
				if ( $conversation_id > 0 && ! in_array( $conversation_id, $state['related_conversations'], true ) ) {
					$state['related_conversations'][] = $conversation_id;
				}
				$contact_id = (int) ( $record['contact_id'] ?? 0 );
				if ( $contact_id > 0 && ! in_array( $contact_id, $state['related_contacts'], true ) ) {
					$state['related_contacts'][] = $contact_id;
				}
				$superseded_record_id = sanitize_text_field( (string) ( $record['superseded_record_id'] ?? '' ) );
				if ( $superseded_record_id !== '' ) {
					$state['correction_count']++;
					if ( ! in_array( $superseded_record_id, $state['superseded_record_ids'], true ) ) {
						$state['superseded_record_ids'][] = $superseded_record_id;
					}
				}
			}
		}
		if ( $rollup_id === 'conversation_state' ) {
			$state['validity_version'] = substr( hash( 'sha256', wp_json_encode( array( $state['conversation_status'], $state['reopen_count'], $state['first_message_at'], $state['last_message_at'], $state['last_outcome'] ), JSON_UNESCAPED_SLASHES ) ), 0, 16 );
		}
		return $state;
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			return array_map( array( __CLASS__, 'canonicalize' ), $value );
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}

	private static function failure( $reason ) {
		return array( 'ok' => false, 'reason' => sanitize_key( (string) $reason ) );
	}
}