<?php
/**
 * BizCity_Commerce_Action_MCP_Service — order / inventory tools of the one MCP standard (PHASE-0.88 L1-9, lane CL-B):
 * order.create, inventory.reserve, inventory.release, inventory.check.
 *
 * Q88-2 (fixture inventory.reserve.json): no reservation table. A hold = a WooCommerce `pending` order (created by the
 * CRM order adapter when a customer is named, else wc_create_order) + Woo's own
 * Automattic\WooCommerce\Checkout\Helpers\ReserveStock (table wc_reserved_stock; Woo's cron cancels unpaid pending orders
 * after woocommerce_hold_stock_minutes). Orders made here carry order meta `_bizcity_mcp_hold`; inventory.release only
 * touches those. Missing ReserveStock ⇒ MCP_INVENTORY_RESERVE_UNAVAILABLE, never a silent success.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-10-01 (PHASE-0.88 CL-B)
 */

defined( 'ABSPATH' ) || exit;

// [2026-10-01 Claude Opus 5.5] PHASE-0.88 L1-9 — new file, order + inventory tools on BizCity_MCP_Tool_Registry.
final class BizCity_Commerce_Action_MCP_Service {

	const META_HOLD    = '_bizcity_mcp_hold';
	const META_HOLD_BY = '_bizcity_mcp_hold_by';
	const MAX_LINES    = 50;

	/** Woo's internal class (namespace may move between Woo versions — always class_exists first). Test seam. */
	public static $reserve_class = 'Automattic\\WooCommerce\\Checkout\\Helpers\\ReserveStock';

	public static function init() {
		add_action( 'bizcity_mcp_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$order_input = array( 'type' => 'object', 'required' => array( 'items' ), 'properties' => array(
			'contact_ref'   => array( 'type' => 'string' ),
			'items'         => array( 'type' => 'array', 'minItems' => 1, 'items' => array( 'type' => 'object', 'required' => array( 'product_id', 'qty' ), 'properties' => array( 'product_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'qty' => array( 'type' => 'integer', 'minimum' => 1 ) ) ) ),
			'hold_minutes'  => array( 'type' => 'integer', 'minimum' => 5, 'maximum' => 1440 ),
			'note'          => array( 'type' => 'string' ),
			'confirm_token' => array( 'type' => 'string' ),
		) );
		$order_output = BizCity_MCP_Tool_Registry::envelope_schema( array(
			'status'          => array( 'type' => 'string', 'enum' => array( 'needs_confirmation', 'done' ) ),
			'preview'         => array( 'type' => 'object' ),
			'confirm_token'   => array( 'type' => 'string' ),
			'expires_at'      => array( 'type' => 'string' ),
			'order_id'        => array( 'type' => 'integer' ),
			'reserved'        => array( 'type' => 'array' ),
			'hold_expires_at' => array( 'type' => 'string' ),
		) );

		// @mcp bizcity-mcp-standard@1 tool order.create
		BizCity_MCP_Tool_Registry::register( 'order.create', array(
			'title'          => 'Tạo đơn nháp (giữ hàng)',
			'description'    => 'Tạo đơn WooCommerce trạng thái chờ (pending) và giữ hàng trong hold_minutes. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý.',
			'input_schema'   => $order_input,
			'output_schema'  => $order_output,
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'order.write',
			'handler'        => array( __CLASS__, 'create_order' ),
			'preview'        => array( __CLASS__, 'preview_order' ),
			'mode'           => 'orders',
			'scopes'         => array( 'order.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'order_create',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool inventory.reserve
		BizCity_MCP_Tool_Registry::register( 'inventory.reserve', array(
			'title'          => 'Giữ hàng',
			'description'    => 'Giữ số lượng sản phẩm trong hold_minutes (mặc định 30, tối đa theo thiết lập giữ hàng của WooCommerce) bằng một đơn nháp; không bắt buộc có khách. Lần gọi đầu chỉ trả bản xem trước + confirm_token; gọi lại cùng tham số kèm confirm_token sau khi người dùng đồng ý. Nhả hàng sớm bằng inventory.release.',
			'input_schema'   => $order_input,
			'output_schema'  => $order_output,
			'read_only'      => false,
			'destructive'    => false,
			'idempotent'     => false,
			'required_scope' => 'inventory.write',
			'handler'        => array( __CLASS__, 'reserve' ),
			'preview'        => array( __CLASS__, 'preview_reserve' ),
			'mode'           => 'orders',
			'scopes'         => array( 'inventory.write' ),
			'confirm'        => 'always',
			'llm_alias'      => 'inventory_reserve',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool inventory.release
		BizCity_MCP_Tool_Registry::register( 'inventory.release', array(
			'title'          => 'Nhả hàng đang giữ',
			'description'    => 'Nhả hàng của một đơn giữ hàng do Agent tạo (order.create / inventory.reserve) và huỷ đơn nháp đó. Không dùng được cho đơn khách đặt thật.',
			'input_schema'   => array( 'type' => 'object', 'required' => array( 'order_id' ), 'properties' => array( 'order_id' => array( 'type' => 'integer', 'minimum' => 1 ) ) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array( 'order_id' => array( 'type' => 'integer' ), 'released' => array( 'type' => 'array' ), 'order_status' => array( 'type' => 'string' ) ), array( 'order_id', 'released' ) ),
			'read_only'      => false,
			'destructive'    => true,
			'idempotent'     => true,
			'required_scope' => 'inventory.write',
			'handler'        => array( __CLASS__, 'release' ),
			'mode'           => 'orders',
			'scopes'         => array( 'inventory.write' ),
			'confirm'        => 'never',
			'llm_alias'      => 'inventory_release',
			'fallback_pack'  => null,
			'since'          => '0.88.3',
		) );

		// @mcp bizcity-mcp-standard@1 tool inventory.check
		BizCity_MCP_Tool_Registry::register( 'inventory.check', array(
			'title'          => 'Kiểm tra tồn kho',
			'description'    => 'Tồn kho theo sản phẩm: tồn, đang giữ cho đơn chờ, còn bán được (available = tồn − đang giữ), trạng thái; kèm danh sách sắp hết / hết hàng. Không có product_ids thì xem các sản phẩm đang bán (tối đa limit).',
			'input_schema'   => array( 'type' => 'object', 'properties' => array(
				'product_ids'         => array( 'type' => 'array', 'maxItems' => self::MAX_LINES, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'limit'               => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'low_stock_threshold' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 5 ),
			) ),
			'output_schema'  => BizCity_MCP_Tool_Registry::envelope_schema( array( 'items' => array( 'type' => 'array' ), 'low_stock' => array( 'type' => 'array' ), 'out_of_stock' => array( 'type' => 'array' ), 'reserve_supported' => array( 'type' => 'boolean' ) ), array( 'items', 'low_stock', 'out_of_stock' ) ),
			'read_only'      => true,
			'idempotent'     => true,
			'required_scope' => 'business.read',
			'handler'        => array( __CLASS__, 'check' ),
			'mode'           => 'stock',
			'scopes'         => array( 'business.read' ),
			'confirm'        => 'never',
			'llm_alias'      => 'biz_stock',
			'fallback_pack'  => 'stock',
			'since'          => '0.88.2',
		) );
	}

	/* ── order.create / inventory.reserve ────────────────────────── */

	public static function preview_order( array $args, array $ctx ) {
		return self::preview( $args, $ctx, 'Tạo đơn nháp' );
	}

	public static function preview_reserve( array $args, array $ctx ) {
		return self::preview( $args, $ctx, 'Giữ hàng' );
	}

	public static function create_order( array $args, array $ctx ) {
		return self::commit( $args, $ctx );
	}

	/** Thin alias of order.create (contact optional there too): same plan, same draft order + Woo hold. */
	public static function reserve( array $args, array $ctx ) {
		return self::commit( $args, $ctx );
	}

	private static function preview( array $args, array $ctx, $verb ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args, $verb ) {
			$plan = self::plan( $args, $uid );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$parts = array();
			foreach ( $plan['lines'] as $l ) {
				$parts[] = $l['qty'] . ' × ' . $l['name'] . ' (' . self::money( $l['unit_price'], $plan['currency'] ) . ')';
			}
			$who = $plan['contact'] ? ' cho ' . $plan['contact']['name'] : '';
			return array(
				'summary'      => $verb . $who . ': ' . implode( ', ', $parts ) . ' = ' . self::money( $plan['total'], $plan['currency'] ) . ', giữ hàng ' . $plan['hold_minutes'] . ' phút.',
				'lines'        => array_map( static function ( $l ) { unset( $l['product'] ); return $l; }, $plan['lines'] ),
				'total'        => $plan['total'],
				'currency'     => $plan['currency'],
				'hold_minutes' => $plan['hold_minutes'],
			);
		} );
	}

	private static function commit( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			$plan = self::plan( $args, $uid ); // availability re-checked at commit time
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$order = self::new_order( $plan );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$expires = time() + $plan['hold_minutes'] * 60;
			$order->update_meta_data( self::META_HOLD, 1 );
			$order->update_meta_data( self::META_HOLD_BY, (int) $uid );
			$order->save();
			$class = self::$reserve_class;
			$rs    = new $class();
			try {
				$rs->reserve_stock_for_order( $order, $plan['hold_minutes'] );
			} catch ( \Exception $e ) {
				// Someone else took the stock between plan and reserve: undo the draft, say so.
				$rs->release_stock_for_order( $order );
				$order->update_status( 'cancelled', 'Không giữ được hàng (Agent MCP).' );
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_NOT_ENOUGH, 'Không đủ hàng để giữ (vừa có đơn khác giữ). Kiểm tra lại tồn kho.', 409 );
			}
			$reserved = array();
			foreach ( $plan['lines'] as $l ) {
				$reserved[] = array( 'product_id' => $l['product_id'], 'qty' => $l['qty'] );
			}
			return array(
				'status'          => 'done',
				'order_id'        => (int) $order->get_id(),
				'reserved'        => $reserved,
				'hold_expires_at' => BizCity_MCP_Action_Support::iso( $expires ),
				'total'           => $plan['total'],
				'currency'        => $plan['currency'],
			);
		} );
	}

	/**
	 * Validate + price + availability, side-effect free (preview and commit share it).
	 *
	 * @return array|WP_Error {lines[], total, currency, hold_minutes, contact|null, note}
	 */
	private static function plan( array $args, $uid ) {
		if ( ! BizCity_MCP_Action_Support::can_sell( $uid ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền tạo đơn / giữ hàng.', 403 );
		}
		$rs = self::reserve_stock();
		if ( null === $rs || ! function_exists( 'wc_get_product' ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'Cửa hàng chưa bật giữ hàng của WooCommerce.', 503 );
		}
		$qty_by = array();
		foreach ( array_slice( (array) ( $args['items'] ?? array() ), 0, self::MAX_LINES + 1 ) as $row ) {
			$pid = (int) ( is_array( $row ) ? ( $row['product_id'] ?? 0 ) : 0 );
			$qty = (int) ( is_array( $row ) ? ( $row['qty'] ?? 0 ) : 0 );
			if ( $pid <= 0 || $qty <= 0 ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Mỗi dòng cần product_id và qty ≥ 1.', 422 );
			}
			$qty_by[ $pid ] = ( $qty_by[ $pid ] ?? 0 ) + $qty;
		}
		if ( ! $qty_by || count( $qty_by ) > self::MAX_LINES ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'Cần từ 1 đến 50 sản phẩm.', 422 );
		}
		$contact = null;
		$ref     = trim( (string) ( $args['contact_ref'] ?? '' ) );
		if ( '' !== $ref ) {
			$cid = BizCity_MCP_Action_Support::contact_id( $ref );
			$contact = $cid > 0 ? BizCity_MCP_Action_Support::contact_access( $cid, false, $uid ) : BizCity_MCP_Action_Support::error( BizCity_MCP_Error::QUERY_INVALID, 'contact_ref không hợp lệ (ví dụ "crm:731").', 422 );
			if ( is_wp_error( $contact ) ) {
				return $contact;
			}
		}
		$lines = array();
		$total = 0.0;
		$short = array();
		foreach ( $qty_by as $pid => $qty ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy sản phẩm #' . $pid . '.', 404 );
			}
			$row = self::stock_row( $product, $rs );
			if ( 'outofstock' === $row['stock_status'] || ( null !== $row['available'] && $qty > $row['available'] ) ) {
				$short[] = array( 'product_id' => $pid, 'name' => $row['name'], 'requested' => $qty, 'available' => null === $row['available'] ? 0 : $row['available'] );
				continue;
			}
			$unit    = self::num( $product->get_price() );
			$total  += $unit * $qty;
			$lines[] = array( 'product_id' => $pid, 'name' => $row['name'], 'qty' => $qty, 'unit_price' => $unit, 'line_total' => self::num( $unit * $qty ), 'product' => $product );
		}
		if ( $short ) {
			$s = $short[0];
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_NOT_ENOUGH, 'Không đủ hàng: ' . $s['name'] . ' còn ' . $s['available'] . ', cần ' . $s['requested'] . '.', 409, array( 'short' => $short ) );
		}
		return array(
			'lines'        => $lines,
			'total'        => self::num( $total ),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'VND',
			'hold_minutes' => self::hold_minutes( $args['hold_minutes'] ?? 0 ),
			'contact'      => $contact,
			'note'         => trim( sanitize_textarea_field( (string) ( $args['note'] ?? '' ) ) ),
		);
	}

	/** [5, woocommerce_hold_stock_minutes or 60]; default 30 (or the cap when lower). */
	public static function hold_minutes( $asked ) {
		$cap = (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		$cap = max( 5, $cap > 0 ? $cap : 60 );
		$asked = (int) $asked;
		return max( 5, min( $cap, $asked > 0 ? $asked : 30 ) );
	}

	/** Pending draft: CRM order adapter when a customer is named (pipeline attribution), else wc_create_order. */
	private static function new_order( array $plan ) {
		$c = $plan['contact'];
		$adapter = $c && class_exists( 'BizCity_CRM_Order_Adapter_Registry' ) ? BizCity_CRM_Order_Adapter_Registry::default_adapter() : null;
		try {
			if ( $adapter ) {
				$res = $adapter->create_order( array(
					'conversation_id' => (int) $c['conversation_id'],
					'contact'         => array( 'id' => (int) $c['contact_id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'wp_user_id' => (int) $c['wp_user_id'] ),
					'items'           => array_map( static function ( $l ) { return array( 'product_id' => $l['product_id'], 'qty' => $l['qty'] ); }, $plan['lines'] ),
					'note'            => $plan['note'],
				) );
				$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) ( $res['order_id'] ?? 0 ) ) : null;
			} elseif ( function_exists( 'wc_create_order' ) ) {
				$order = wc_create_order( array( 'status' => 'pending', 'created_via' => 'bizcity-mcp', 'customer_id' => $c ? (int) $c['wp_user_id'] : 0 ) );
				if ( is_wp_error( $order ) ) {
					return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không tạo được đơn nháp.', 500 );
				}
				foreach ( $plan['lines'] as $l ) {
					$order->add_product( $l['product'], $l['qty'] );
				}
				if ( $c ) {
					$order->set_billing_first_name( (string) $c['name'] );
					if ( '' !== $c['phone'] ) {
						$order->set_billing_phone( (string) $c['phone'] );
					}
					$order->update_meta_data( '_bizcity_crm_contact_id', (int) $c['contact_id'] );
				}
				if ( '' !== $plan['note'] ) {
					$order->add_order_note( 'Ghi chú: ' . $plan['note'] );
				}
				$order->add_order_note( 'Đơn nháp giữ hàng tạo qua Agent (MCP).' );
				$order->calculate_totals();
			} else {
				$order = null;
			}
		} catch ( \Exception $e ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INTERNAL_ERROR, 'Không tạo được đơn nháp.', 500 );
		}
		if ( ! $order || ! is_object( $order ) ) {
			return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'WooCommerce chưa sẵn sàng để tạo đơn.', 503 );
		}
		return $order;
	}

	/* ── inventory.release ───────────────────────────────────────── */

	public static function release( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! BizCity_MCP_Action_Support::can_sell( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền nhả hàng.', 403 );
			}
			$rs = self::reserve_stock();
			if ( null === $rs || ! function_exists( 'wc_get_order' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'Cửa hàng chưa bật giữ hàng của WooCommerce.', 503 );
			}
			$order_id = (int) ( $args['order_id'] ?? 0 );
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
			if ( ! $order || ! is_object( $order ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::NOT_FOUND, 'Không tìm thấy đơn #' . $order_id . '.', 404 );
			}
			if ( ! $order->get_meta( self::META_HOLD ) || 'pending' !== (string) $order->get_status() ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Chỉ nhả được đơn giữ hàng do Agent tạo và còn đang chờ.', 403 );
			}
			$by = (int) $order->get_meta( self::META_HOLD_BY );
			if ( $by !== (int) $uid && BizCity_MCP_Action_Support::crm_rank( $uid ) < 3 && ! BizCity_MCP_Action_Support::is_admin( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Đơn giữ hàng này do người khác tạo.', 403 );
			}
			$released = array();
			foreach ( (array) $order->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
					$released[] = array( 'product_id' => (int) $item->get_product_id(), 'qty' => (int) $item->get_quantity() );
				}
			}
			$rs->release_stock_for_order( $order );
			$order->update_status( 'cancelled', 'Nhả hàng giữ qua Agent (MCP).' );
			return array( 'status' => 'done', 'order_id' => $order_id, 'released' => $released, 'order_status' => (string) $order->get_status() );
		} );
	}

	/* ── inventory.check ─────────────────────────────────────────── */

	public static function check( array $args, array $ctx ) {
		return BizCity_MCP_Action_Support::run_as( $ctx, static function ( $uid ) use ( $args ) {
			if ( ! BizCity_MCP_Action_Support::can_sell( $uid ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::SCOPE_DENIED, 'Bạn chưa có quyền xem tồn kho.', 403 );
			}
			if ( ! function_exists( 'wc_get_product' ) ) {
				return BizCity_MCP_Action_Support::error( BizCity_MCP_Error::INVENTORY_RESERVE_UNAVAILABLE, 'WooCommerce chưa bật.', 503 );
			}
			$rs        = self::reserve_stock();
			$threshold = max( 0, (int) ( $args['low_stock_threshold'] ?? 5 ) );
			$ids       = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['product_ids'] ?? array() ) ) ) ) ), 0, self::MAX_LINES );
			if ( $ids ) {
				$products = array_filter( array_map( 'wc_get_product', $ids ) );
			} else {
				$limit    = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
				$products = function_exists( 'wc_get_products' ) ? (array) wc_get_products( array( 'limit' => $limit, 'status' => 'publish', 'return' => 'objects' ) ) : array();
			}
			$items = array();
			$low   = array();
			$out   = array();
			foreach ( $products as $p ) {
				if ( ! is_object( $p ) ) {
					continue;
				}
				$row     = self::stock_row( $p, $rs );
				$items[] = $row;
				$short   = array( 'product_id' => $row['product_id'], 'name' => $row['name'], 'available' => $row['available'] );
				if ( 'outofstock' === $row['stock_status'] || ( null !== $row['available'] && $row['available'] <= 0 ) ) {
					$out[] = $short;
				} elseif ( null !== $row['available'] && $row['available'] <= $threshold ) {
					$low[] = $short;
				}
			}
			return array( 'items' => $items, 'low_stock' => $low, 'out_of_stock' => $out, 'reserve_supported' => null !== $rs );
		} );
	}

	/* ── helpers ─────────────────────────────────────────────────── */

	/** @return object|null Woo ReserveStock, or null when this Woo has no stock reservation. */
	private static function reserve_stock() {
		$class = self::$reserve_class;
		return class_exists( $class ) ? new $class() : null;
	}

	/** stock = Woo stock quantity (null when not managed), reserved = held by pending orders, available = stock − reserved. */
	private static function stock_row( $product, $rs ) {
		$managed  = method_exists( $product, 'managing_stock' ) && $product->managing_stock() && ! ( method_exists( $product, 'backorders_allowed' ) && $product->backorders_allowed() );
		$stock    = $managed ? (int) $product->get_stock_quantity() : null;
		$reserved = $managed && $rs ? (int) $rs->get_reserved_stock( $product ) : 0;
		return array(
			'product_id'   => (int) $product->get_id(),
			'name'         => (string) $product->get_name(),
			'stock'        => $stock,
			'reserved'     => $reserved,
			'available'    => $managed ? max( 0, $stock - $reserved ) : null,
			'stock_status' => (string) $product->get_stock_status(),
		);
	}

	/** Whole amounts as integers (225000, not 225000.0) — the shape of fixture confirm.flow.json. */
	private static function num( $v ) {
		$f = (float) $v;
		return floor( $f ) == $f && abs( $f ) < PHP_INT_MAX ? (int) $f : $f;
	}

	private static function money( $v, $currency ) {
		$v = (float) $v;
		$s = number_format( $v, ( floor( $v ) == $v ) ? 0 : 2, ',', '.' );
		return 'VND' === $currency ? $s . 'đ' : $s . ' ' . $currency;
	}
}

BizCity_Commerce_Action_MCP_Service::init();
