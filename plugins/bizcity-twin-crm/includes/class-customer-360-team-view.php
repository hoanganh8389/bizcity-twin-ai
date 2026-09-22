<?php
/**
 * BizCity CRM — B2 serializer for `customer-360-team-view@1.1.0` (PHASE-0.50 C-02, master roadmap M3-01).
 *
 * `GET /crm-contacts/{id}/team-360` composes a flat read model (owner, conversations, touches, orders…)
 * that the `/crm/` SPA already consumes. This class is the ONE place that turns that read model into the
 * public contract envelope — field whitelist, masking, out-of-team grouping, coverage — so the contract
 * never drifts with whatever the composer happens to return. The member (C) projection lives in TwinWeb
 * and must never call this class (R-LM-4 / R-LM-6: two server-shaped projections, no shared serializer).
 *
 * PII decision (master roadmap §10 #4, 2026-09-18): on B2 a leader who has the customer in scope may see
 * the full phone/email (`contact.phone`, `contact.email`, added in 1.1.0 as optional fields). Channel
 * accounts still expose only `account_key` + `phone_masked`, and nothing here is ever logged.
 *
 * Pure: no DB, no WordPress calls besides the optional Staff_Policy role lookup.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.50 2026-09-18
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Customer_360_Team_View', false ) ) {
	return;
}

final class BizCity_CRM_Customer_360_Team_View {

	const CONTRACT = 'customer-360-team-view';
	const VERSION  = '1.1.0';
	const SURFACE  = 'B2_ADMIN_CRM';

	const MAX_CONVERSATIONS = 20;
	const MAX_ORDERS        = 50;
	const OTHER_STAFF_LABEL = 'Nhân viên khác';

	const ATTRIBUTIONS = array( 'assignee_at_create', 'creator_fallback', 'backfill_event', 'backfill_current' );

	/**
	 * @param array      $view        Flat read model from the team-360 composer.
	 * @param int        $actor_id    Leader asking.
	 * @param int        $blog_id     Current site.
	 * @param string     $as_of       ISO-8601 timestamp.
	 * @param array|null $visible_ids Staff the actor may see by name (Staff_Policy::visible_user_ids); null = everyone (admin).
	 * @param array      $roles       Optional user_id => team_role map (avoids a policy lookup in tests).
	 */
	public static function to_contract( array $view, int $actor_id, int $blog_id, string $as_of, ?array $visible_ids = null, array $roles = array() ): array {
		$contact = (array) ( $view['contact'] ?? array() );
		$owner   = is_array( $view['owner'] ?? null ) ? $view['owner'] : null;

		$conversations = array();
		foreach ( array_slice( (array) ( $view['conversations'] ?? array() ), 0, self::MAX_CONVERSATIONS ) as $c ) {
			$shaped = self::conversation( (array) $c );
			if ( null !== $shaped ) { $conversations[] = $shaped; }
		}

		$orders_available = ! empty( $view['orders_available'] );
		$orders = array();
		foreach ( array_slice( (array) ( $view['orders'] ?? array() ), 0, self::MAX_ORDERS ) as $o ) {
			$shaped = self::order( (array) $o );
			if ( null !== $shaped ) { $orders[] = $shaped; }
		}

		$degraded = array( 'journey.assignment_history' ); // Q2: owner history A→B not composed yet.
		if ( ! $orders_available ) { $degraded[] = 'orders.woo'; }
		$truncated = count( (array) ( $view['conversations'] ?? array() ) ) > self::MAX_CONVERSATIONS
			|| count( (array) ( $view['orders'] ?? array() ) ) > self::MAX_ORDERS;

		$subject_id = $owner ? (int) ( $owner['user_id'] ?? 0 ) : 0;
		if ( $subject_id <= 0 ) { $subject_id = $actor_id; }
		$subject_name = $owner && $subject_id === (int) ( $owner['user_id'] ?? 0 ) ? (string) ( $owner['display_name'] ?? '' ) : '';
		if ( '' === $subject_name ) { $subject_name = '#' . $subject_id; }

		$marketing = (array) ( $view['marketing'] ?? array() );
		$labels = array();
		foreach ( (array) ( $marketing['tags'] ?? $marketing['labels'] ?? array() ) as $tag ) {
			if ( is_scalar( $tag ) && '' !== trim( (string) $tag ) ) { $labels[] = self::cut( (string) $tag, 80 ); }
		}

		$conflicts = (array) ( $view['identity_conflicts'] ?? array() );
		$identity = array( 'open' => max( 0, (int) ( $conflicts['open'] ?? 0 ) ) );
		if ( '' !== (string) ( $conflicts['review_url'] ?? '' ) ) { $identity['review_url'] = self::cut( (string) $conflicts['review_url'], 500 ); }

		$can = (array) ( $view['can'] ?? array() );

		return array(
			'contract'  => self::CONTRACT,
			'version'   => self::VERSION,
			'surface'   => self::SURFACE,
			'principal' => array( 'blog_id' => max( 1, $blog_id ), 'actor_user_id' => max( 1, $actor_id ) ),
			'subject'   => array( 'user_id' => max( 1, $subject_id ), 'display_name' => self::cut( $subject_name, 120 ), 'team_role' => self::role( $subject_id, $roles ) ),
			'as_of'     => $as_of,
			// Never "complete" until the owner history (Q2) is composed — `degraded_sources` always names it.
			'coverage'  => array( 'complete' => false, 'degraded_sources' => $degraded, 'truncated' => $truncated ),
			'data'      => array(
				'contact'            => self::contact( $contact ),
				'owner'              => $owner ? array(
					'user_id'         => (int) ( $owner['user_id'] ?? 0 ),
					'display_name'    => (string) ( $owner['display_name'] ?? '' ),
					'conversation_id' => (int) ( $owner['conversation_id'] ?? 0 ),
					'inbox_id'        => (int) ( $owner['inbox_id'] ?? 0 ),
				) : null,
				'conversations'      => $conversations,
				'touched_by'         => self::touched_by( (array) ( $view['touched_by'] ?? array() ), $visible_ids ),
				'automated_replies'  => max( 0, (int) ( $view['automated_replies'] ?? 0 ) ),
				'orders'             => array( 'available' => $orders_available, 'items' => $orders ),
				'marketing'          => array(
					'acquisition_source' => '' !== (string) ( $marketing['acquisition_source'] ?? '' ) ? self::cut( (string) $marketing['acquisition_source'], 190 ) : null,
					'first_seen_at'      => self::nullable_string( $marketing['first_seen_at'] ?? null ),
					'labels'             => $labels,
				),
				'open_tasks'         => max( 0, (int) ( $view['open_tasks'] ?? 0 ) ),
				'identity_conflicts' => $identity,
				'can'                => array(
					'view_owner_workspace' => ! empty( $can['view_owner_workspace'] ),
					'assign_task'          => ! empty( $can['assign_task'] ),
				),
			),
		);
	}

	public static function mask_phone( string $phone ): ?string {
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		$len = strlen( $digits );
		if ( 0 === $len ) { return null; }
		return $len > 4 ? substr( $digits, 0, 2 ) . str_repeat( '*', max( 2, $len - 4 ) ) . substr( $digits, -2 ) : str_repeat( '*', $len );
	}

	private static function contact( array $c ): array {
		$id = (int) ( $c['id'] ?? $c['contact_id'] ?? 0 );
		$name = trim( (string) ( $c['name'] ?? $c['display_name'] ?? '' ) );
		$phone = trim( (string) ( $c['phone'] ?? '' ) );
		$email = trim( (string) ( $c['email'] ?? '' ) );
		$out = array(
			'contact_id'   => max( 1, $id ),
			'display_name' => self::cut( '' !== $name ? $name : 'Khách #' . $id, 190 ),
			'phone_masked' => '' !== $phone ? self::mask_phone( $phone ) : null,
			'created_at'   => self::nullable_string( $c['created_at'] ?? null ),
		);
		if ( '' !== $phone ) { $out['phone'] = self::cut( $phone, 32 ); }
		if ( '' !== $email ) { $out['email'] = self::cut( $email, 190 ); }
		return $out;
	}

	private static function conversation( array $c ): ?array {
		$id = (int) ( $c['conversation_id'] ?? 0 );
		$inbox = (int) ( $c['inbox_id'] ?? 0 );
		if ( $id <= 0 || $inbox <= 0 ) { return null; }
		$channel = strtolower( (string) ( $c['channel_type'] ?? '' ) );
		$status = strtolower( (string) ( $c['status'] ?? '' ) );
		$out = array(
			'conversation_id'  => $id,
			'inbox_id'         => $inbox,
			'inbox_name'       => self::cut( (string) ( $c['inbox_name'] ?? '' ), 190 ),
			'channel_type'     => preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $channel ) ? $channel : 'unknown',
			'status'           => preg_match( '/^[a-z_]{2,20}$/', $status ) ? $status : 'unknown',
			'assignee'         => self::user_ref( $c['assignee'] ?? null ),
			'last_activity_at' => self::nullable_string( $c['last_activity_at'] ?? null ),
			'created_at'       => self::nullable_string( $c['created_at'] ?? null ),
			'session_state'    => self::nullable_string( $c['session_state'] ?? null ),
			'phone_owner'      => self::user_ref( $c['phone_owner'] ?? null ),
		);
		$key = strtolower( (string) ( $c['account_key'] ?? '' ) );
		if ( preg_match( '/^[a-f0-9]{16,64}$/', $key ) ) { $out['account_key'] = $key; }
		if ( array_key_exists( 'phone_masked', $c ) ) { $out['phone_masked'] = self::nullable_string( $c['phone_masked'] ); }
		return $out;
	}

	/**
	 * Staff outside the actor's visible roster collapse into one "Nhân viên khác" row (user_id null):
	 * a lead sees that someone else touched the customer, not who.
	 */
	private static function touched_by( array $rows, ?array $visible_ids ): array {
		$visible = null === $visible_ids ? null : array_flip( array_map( 'intval', $visible_ids ) );
		$out = array();
		$other = null;
		foreach ( $rows as $t ) {
			$t = (array) $t;
			$uid = (int) ( $t['user_id'] ?? 0 );
			$replies = (int) ( $t['replies'] ?? 0 );
			$first = (string) ( $t['first_at'] ?? '' );
			$last = (string) ( $t['last_at'] ?? '' );
			if ( $replies < 1 || strlen( $first ) < 10 || strlen( $last ) < 10 ) { continue; }
			if ( $uid > 0 && ( null === $visible || isset( $visible[ $uid ] ) ) ) {
				$row = array(
					'user_id'      => $uid,
					'display_name' => self::cut( (string) ( $t['display_name'] ?? '#' . $uid ), 120 ),
					'replies'      => $replies,
					'first_at'     => $first,
					'last_at'      => $last,
				);
				$inbox_ids = array_values( array_filter( array_map( 'intval', (array) ( $t['inbox_ids'] ?? array() ) ), static function ( $id ) { return $id > 0; } ) );
				if ( $inbox_ids ) { $row['inbox_ids'] = $inbox_ids; }
				$out[] = $row;
				continue;
			}
			if ( null === $other ) {
				$other = array( 'user_id' => null, 'display_name' => self::OTHER_STAFF_LABEL, 'replies' => 0, 'first_at' => $first, 'last_at' => $last );
			}
			$other['replies'] += $replies;
			if ( strcmp( $first, $other['first_at'] ) < 0 ) { $other['first_at'] = $first; }
			if ( strcmp( $last, $other['last_at'] ) > 0 ) { $other['last_at'] = $last; }
		}
		if ( null !== $other ) { $out[] = $other; }
		return $out;
	}

	private static function order( array $o ): ?array {
		$id = (int) ( $o['order_id'] ?? 0 );
		if ( $id <= 0 ) { return null; }
		$status = strtolower( (string) ( $o['status'] ?? '' ) );
		$currency = strtoupper( (string) ( $o['currency'] ?? '' ) );
		$attribution = (string) ( $o['attribution'] ?? '' );
		$conv = (int) ( $o['conversation_id'] ?? 0 );
		return array(
			'order_id'        => $id,
			'number'          => self::cut( (string) ( $o['number'] ?? $id ), 40 ),
			'status'          => preg_match( '/^[a-z0-9_-]{2,32}$/', $status ) ? $status : 'unknown',
			'total'           => max( 0.0, (float) ( $o['total'] ?? 0 ) ),
			'currency'        => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'VND',
			'paid'            => ! empty( $o['paid'] ),
			'created_at'      => self::nullable_string( $o['created_at'] ?? null ),
			'assignee'        => self::user_ref( $o['assignee'] ?? null ),
			'attribution'     => in_array( $attribution, self::ATTRIBUTIONS, true ) ? $attribution : null,
			'conversation_id' => $conv > 0 ? $conv : null,
		);
	}

	private static function user_ref( $ref ): ?array {
		if ( ! is_array( $ref ) || (int) ( $ref['user_id'] ?? 0 ) <= 0 ) { return null; }
		return array( 'user_id' => (int) $ref['user_id'], 'display_name' => (string) ( $ref['display_name'] ?? '' ) );
	}

	private static function role( int $user_id, array $roles ): string {
		$role = $roles[ $user_id ] ?? ( class_exists( 'BizCity_CRM_Staff_Policy' ) ? BizCity_CRM_Staff_Policy::role( $user_id ) : 'none' );
		return in_array( $role, array( 'admin', 'supervisor', 'lead', 'agent', 'none' ), true ) ? $role : 'none';
	}

	private static function nullable_string( $value ): ?string {
		return null === $value || '' === (string) $value ? null : (string) $value;
	}

	private static function cut( string $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
