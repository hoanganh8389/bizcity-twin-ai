<?php
/**
 * Research Ingest Service — turn selected source URLs into knowledge.
 *
 * For both scopes the workflow is:
 *   1. Tavily extract on the URL → raw content
 *   2. Insert/Upsert row in bizcity_research_ingests (dedupe via url_hash)
 *   3. If KG_Source_Service is loaded for the matching scope, hand off
 *      via BizCity_KG::ingest() with a 'note' payload — that path drives
 *      embed pipeline → kg_passages → retrieval ready.
 *   4. If KG hub is unavailable, the ingest row alone is the storage
 *      (queryable via REST + listed in Studio UI).
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Ingest_Service {

    /**
     * @return array { ingested:int, duplicate:int, failed:int, items:[{url, status, kg_source_id?, error?}] }
     */
    public static function ingest_urls( int $session_id, int $turn_id, array $urls ): array {
        $session = BizCity_Research_Store::get_session( $session_id );
        if ( ! $session ) {
            return [ 'ingested' => 0, 'duplicate' => 0, 'failed' => count( $urls ), 'items' => [] ];
        }

        $items     = [];
        $ingested  = 0;
        $duplicate = 0;
        $failed    = 0;

        foreach ( $urls as $url ) {
            $url = (string) $url;
            if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                $failed++;
                $items[] = [ 'url' => $url, 'status' => 'invalid' ];
                continue;
            }
            $r = self::ingest_one( $session, $turn_id, $url );
            if ( $r['status'] === 'ok' )         $ingested++;
            elseif ( $r['status'] === 'dup' )    $duplicate++;
            else                                  $failed++;
            $items[] = $r;
        }

        // Bump session counter
        if ( $ingested > 0 ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare(
                'UPDATE ' . BizCity_Research_DB::table_sessions()
                . ' SET total_ingested = total_ingested + %d, updated_at = %s WHERE id = %d',
                $ingested, current_time( 'mysql' ), $session_id
            ) );
        }

        BizCity_Research_Event_Emitter::emit( 'research_ingest_completed', [
            'session_id'      => $session_id,
            'turn_id'         => $turn_id,
            'ingested_count'  => $ingested,
            'duplicate_count' => $duplicate,
            'failed_count'    => $failed,
        ] );

        return [
            'ingested'  => $ingested,
            'duplicate' => $duplicate,
            'failed'    => $failed,
            'items'     => $items,
        ];
    }

    private static function ingest_one( array $session, int $turn_id, string $url ): array {
        global $wpdb;
        $tbl  = BizCity_Research_DB::table_ingests();
        $hash = BizCity_Research_Store::url_hash( $url );

        // Dedupe per scope
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$tbl} WHERE scope_type=%s AND scope_id=%d AND url_hash=%s",
            $session['scope_type'], $session['scope_id'], $hash
        ) );
        if ( $exists > 0 ) {
            // If row exists but was previously detached, re-attach it.
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d", $exists ), ARRAY_A );
            if ( $row && in_array( (string) $row['ingest_status'], [ 'detached' ], true ) ) {
                // Trigger re-ingest into KG.
                $kg_source_id = self::handoff_to_kg(
                    $session,
                    $turn_id,
                    $url,
                    (string) $row['title'],
                    (string) $row['content_md'],
                    (string) $row['favicon']
                );
                $wpdb->update( $tbl, [
                    'kg_source_id'  => $kg_source_id,
                    'ingest_status' => $kg_source_id ? 'kg_attached' : 'stored',
                ], [ 'id' => $exists ] );
                return [
                    'url'          => $url,
                    'status'       => 'ok',
                    'ingest_id'    => $exists,
                    'kg_source_id' => $kg_source_id,
                    'reattached'   => true,
                ];
            }
            return [ 'url' => $url, 'status' => 'dup', 'ingest_id' => $exists, 'kg_source_id' => $row ? (int) $row['kg_source_id'] : null ];
        }

        // Look up source list metadata from the turn for nice title/favicon.
        $turn  = BizCity_Research_Store::get_turn( $turn_id );
        $title = $url;
        $favicon = '';
        if ( $turn && is_array( $turn['source_urls'] ) ) {
            foreach ( $turn['source_urls'] as $s ) {
                if ( ! empty( $s['url'] ) && (string) $s['url'] === $url ) {
                    $title   = (string) ( $s['title'] ?? $url );
                    $favicon = (string) ( $s['favicon'] ?? '' );
                    break;
                }
            }
        }

        // Gateway extract → grab content (NEVER call Tavily directly — see R-GW-1).
        $content_md = '';
        if ( class_exists( 'BizCity_Search_Client' ) ) {
            $client = BizCity_Search_Client::instance();
            if ( $client->is_ready() ) {
                $ext = $client->extract( [ $url ] );
                if ( ! is_wp_error( $ext ) && ! empty( $ext[0]['raw_content'] ) ) {
                    $content_md = (string) $ext[0]['raw_content'];
                    if ( empty( $title ) || $title === $url ) {
                        $title = (string) ( $ext[0]['title'] ?? $url );
                    }
                }
            }
        }

        $now = current_time( 'mysql' );
        $wpdb->insert( $tbl, [
            'session_id'    => (int) $session['id'],
            'turn_id'       => (int) $turn_id,
            'scope_type'    => (string) $session['scope_type'],
            'scope_id'      => (int) $session['scope_id'],
            'source_url'    => mb_substr( $url, 0, 1000 ),
            'url_hash'      => $hash,
            'title'         => mb_substr( $title, 0, 500 ),
            'favicon'       => mb_substr( $favicon, 0, 500 ),
            'content_md'    => $content_md,
            'kg_source_id'  => null,
            'ingest_status' => 'stored',
            'created_at'    => $now,
        ] );
        $ingest_id = (int) $wpdb->insert_id;

        // Best-effort handoff to KG Hub (handles both character + notebook scope).
        $kg_source_id = self::handoff_to_kg( $session, $turn_id, $url, $title, $content_md, $favicon );
        if ( $kg_source_id ) {
            $wpdb->update( $tbl, [
                'kg_source_id'  => $kg_source_id,
                'ingest_status' => 'kg_attached',
            ], [ 'id' => $ingest_id ] );
        }

        BizCity_Research_Event_Emitter::emit( 'guru_knowledge_ingested', [
            'scope_type'   => (string) $session['scope_type'],
            'scope_id'     => (int) $session['scope_id'],
            'session_id'   => (int) $session['id'],
            'turn_id'      => (int) $turn_id,
            'kg_source_id' => $kg_source_id,
            'source'       => 'research_studio',
        ] );

        return [
            'url'          => $url,
            'status'       => 'ok',
            'ingest_id'    => $ingest_id,
            'kg_source_id' => $kg_source_id,
        ];
    }

    /**
     * Map session scope → KG scope tuple.
     * - scope=character → plugin=knowledge,  scope_id=character_id
     * - scope=notebook  → plugin=twinchat,   scope_id=notebook_id
     * - scope=user      → null (user-scope research is history-only, no KG handoff)
     */
    private static function kg_scope_for_session( array $session ): ?array {
        $type = (string) $session['scope_type'];
        $sid  = (int) $session['scope_id'];
        if ( $sid <= 0 ) return null;
        if ( $type === 'character' ) return [ 'plugin' => 'knowledge', 'scope_id' => $sid ];
        if ( $type === 'notebook'  ) return [ 'plugin' => 'twinchat',  'scope_id' => $sid ];
        return null;
    }

    /**
     * Push a single source row into KG Hub, return kg_sources.id or null.
     */
    private static function handoff_to_kg( array $session, int $turn_id, string $url, string $title, string $content_md, string $favicon ): ?int {
        $scope = self::kg_scope_for_session( $session );
        if ( ! $scope || ! class_exists( 'BizCity_KG' ) ) {
            return null;
        }
        $kg = BizCity_KG::ingest( $scope, [
            'type'     => 'note',
            'title'    => $title,
            'url'      => $url,
            'content'  => $content_md ?: $title,
            'metadata' => [
                'origin'              => 'research_studio',
                'research_session_id' => (int) $session['id'],
                'research_turn_id'    => (int) $turn_id,
                'agent_mode'          => (string) $session['agent_mode'],
                'favicon'             => $favicon,
            ],
        ] );
        if ( is_array( $kg ) && ! empty( $kg['source_id'] ) ) {
            return (int) $kg['source_id'];
        }
        return null;
    }

    /**
     * Attach the synthesized research report itself as a KG source. Uses a
     * synthetic URL `bizcity-research://turn/{id}` so it dedupes per turn and
     * never collides with a real web URL. Stores the cleaned markdown body
     * directly (no Tavily extract). Used by REST POST /turns/{id}/source/attach-report.
     *
     * @return array { url, status, ingest_id?, kg_source_id?, error? }
     */
    public static function attach_report( int $turn_id, string $scope_type = '', int $scope_id = 0 ): array {
        $turn = BizCity_Research_Store::get_turn( $turn_id );
        if ( ! $turn ) {
            return [ 'status' => 'fail', 'error' => 'turn_not_found' ];
        }
        $session = BizCity_Research_Store::get_session( (int) $turn['session_id'] );
        if ( ! $session ) {
            return [ 'status' => 'fail', 'error' => 'session_not_found' ];
        }
        // NOTE: shape_turn() returns the column as 'agent_answer_md', not 'report_md'.
        // 'report_md' key never exists in the return array → was always empty → empty_report error.
        $report_md = trim( (string) ( $turn['agent_answer_md'] ?? $turn['report_md'] ?? '' ) );
        if ( $report_md === '' ) {
            return [ 'status' => 'fail', 'error' => 'empty_report' ];
        }
        // Caller may override target scope (notebook can attach into its own scope).
        if ( $scope_type !== '' && $scope_id > 0 ) {
            $session['scope_type'] = BizCity_Research_Store::sanitize_scope( $scope_type );
            $session['scope_id']   = $scope_id;
        }

        $synthetic_url = 'bizcity-research://turn/' . $turn_id;
        $title         = (string) ( $session['title'] ?? '' );
        if ( $title === '' ) {
            $title = (string) ( $turn['user_query'] ?? 'Báo cáo nghiên cứu' );
        }
        $title = '🔬 ' . mb_substr( $title, 0, 200 );

        global $wpdb;
        $tbl  = BizCity_Research_DB::table_ingests();
        $hash = BizCity_Research_Store::url_hash( $synthetic_url );

        // Dedupe: if already attached return existing row.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE scope_type=%s AND scope_id=%d AND url_hash=%s",
            $session['scope_type'], $session['scope_id'], $hash
        ), ARRAY_A );

        if ( $existing && (string) $existing['ingest_status'] !== 'detached' ) {
            return [
                'url'          => $synthetic_url,
                'status'       => 'dup',
                'ingest_id'    => (int) $existing['id'],
                'kg_source_id' => $existing['kg_source_id'] ? (int) $existing['kg_source_id'] : null,
            ];
        }

        $kg_source_id = self::handoff_to_kg( $session, $turn_id, $synthetic_url, $title, $report_md, '' );

        if ( $existing ) {
            $wpdb->update( $tbl, [
                'title'         => mb_substr( $title, 0, 500 ),
                'content_md'    => $report_md,
                'kg_source_id'  => $kg_source_id,
                'ingest_status' => $kg_source_id ? 'kg_attached' : 'stored',
            ], [ 'id' => (int) $existing['id'] ] );
            $ingest_id = (int) $existing['id'];
        } else {
            $wpdb->insert( $tbl, [
                'session_id'    => (int) $session['id'],
                'turn_id'       => (int) $turn_id,
                'scope_type'    => (string) $session['scope_type'],
                'scope_id'      => (int) $session['scope_id'],
                'source_url'    => mb_substr( $synthetic_url, 0, 1000 ),
                'url_hash'      => $hash,
                'title'         => mb_substr( $title, 0, 500 ),
                'favicon'       => '',
                'content_md'    => $report_md,
                'kg_source_id'  => $kg_source_id,
                'ingest_status' => $kg_source_id ? 'kg_attached' : 'stored',
                'created_at'    => current_time( 'mysql' ),
            ] );
            $ingest_id = (int) $wpdb->insert_id;
        }

        BizCity_Research_Event_Emitter::emit( 'research_report_attached', [
            'turn_id'      => $turn_id,
            'session_id'   => (int) $session['id'],
            'scope_type'   => (string) $session['scope_type'],
            'scope_id'     => (int) $session['scope_id'],
            'kg_source_id' => $kg_source_id,
        ] );

        return [
            'url'          => $synthetic_url,
            'status'       => 'ok',
            'ingest_id'    => $ingest_id,
            'kg_source_id' => $kg_source_id,
        ];
    }

    /* ────────────── Wave 0.18.1.6 — Sources Sync ────────────── */

    /**
     * Attach (ingest or re-ingest) a single URL from a research turn into the
     * scoped KG source list. Used by REST POST /turns/{id}/source/attach.
     *
     * @return array { url, status, ingest_id?, kg_source_id?, error? }
     */
    public static function attach_url( int $turn_id, string $url, string $scope_type = '', int $scope_id = 0 ): array {
        $turn = BizCity_Research_Store::get_turn( $turn_id );
        if ( ! $turn ) {
            return [ 'url' => $url, 'status' => 'fail', 'error' => 'turn_not_found' ];
        }
        $session = BizCity_Research_Store::get_session( (int) $turn['session_id'] );
        if ( ! $session ) {
            return [ 'url' => $url, 'status' => 'fail', 'error' => 'session_not_found' ];
        }
        // Allow caller to override the target scope (notebook may attach into
        // its own scope even when the session is character-scoped, etc.).
        if ( $scope_type !== '' && $scope_id > 0 ) {
            $session['scope_type'] = BizCity_Research_Store::sanitize_scope( $scope_type );
            $session['scope_id']   = $scope_id;
        }
        $r = self::ingest_one( $session, $turn_id, $url );

        if ( in_array( $r['status'], [ 'ok', 'dup' ], true ) ) {
            BizCity_Research_Event_Emitter::emit( 'research_source_attached', [
                'turn_id'      => $turn_id,
                'session_id'   => (int) $session['id'],
                'url'          => $url,
                'scope_type'   => (string) $session['scope_type'],
                'scope_id'     => (int) $session['scope_id'],
                'kg_source_id' => $r['kg_source_id'] ?? null,
            ] );
        }
        return $r;
    }

    /**
     * Detach a single URL from the scoped KG source list. Marks the ingest
     * row as `detached` (preserves history) and removes the kg_source +
     * passages via BizCity_KG::delete_source().
     *
     * @return array { url, status, deleted, kg_source_id? }
     */
    public static function detach_url( int $turn_id, string $url, string $scope_type, int $scope_id ): array {
        global $wpdb;
        $tbl = BizCity_Research_DB::table_ingests();
        $hash = BizCity_Research_Store::url_hash( $url );
        $scope_type = BizCity_Research_Store::sanitize_scope( $scope_type );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$tbl} WHERE scope_type=%s AND scope_id=%d AND url_hash=%s",
            $scope_type, $scope_id, $hash
        ), ARRAY_A );

        if ( ! $row ) {
            return [ 'url' => $url, 'status' => 'not_found', 'deleted' => false ];
        }

        $deleted_kg   = false;
        $kg_source_id = (int) $row['kg_source_id'];
        if ( $kg_source_id > 0 && class_exists( 'BizCity_KG' ) ) {
            $session = BizCity_Research_Store::get_session( (int) $row['session_id'] );
            $scope   = $session ? self::kg_scope_for_session( array_merge( $session, [
                'scope_type' => $scope_type, 'scope_id' => $scope_id,
            ] ) ) : null;
            if ( $scope ) {
                $del = BizCity_KG::delete_source( $scope, $kg_source_id );
                $deleted_kg = ! is_wp_error( $del ) && (bool) $del;
            }
        }

        $wpdb->update( $tbl, [
            'kg_source_id'  => null,
            'ingest_status' => 'detached',
        ], [ 'id' => (int) $row['id'] ] );

        BizCity_Research_Event_Emitter::emit( 'research_source_detached', [
            'turn_id'      => $turn_id,
            'session_id'   => (int) $row['session_id'],
            'url'          => $url,
            'scope_type'   => $scope_type,
            'scope_id'     => $scope_id,
            'kg_source_id' => $kg_source_id ?: null,
            'deleted_kg'   => $deleted_kg,
        ] );

        return [
            'url'          => $url,
            'status'       => 'ok',
            'deleted'      => $deleted_kg,
            'kg_source_id' => $kg_source_id ?: null,
        ];
    }

    /**
     * List sources of a research turn enriched with their current attach
     * state for a target scope.
     *
     * State values:
     *   - 'discovered': URL surfaced in turn but not yet attached to scope
     *   - 'attached'  : URL is in scope's KG (kg_source_id present, status=kg_attached)
     *   - 'detached'  : URL was previously attached but removed (history kept)
     *
     * @return array<int,array{url:string,title:string,favicon:string,score:float,state:string,kg_source_id:?int,ingest_id:?int}>
     */
    public static function list_for_turn_with_state( int $turn_id, string $scope_type, int $scope_id ): array {
        $turn = BizCity_Research_Store::get_turn( $turn_id );
        if ( ! $turn ) return [];

        $sources    = is_array( $turn['source_urls'] ) ? $turn['source_urls'] : [];
        $scope_type = BizCity_Research_Store::sanitize_scope( $scope_type );

        global $wpdb;
        $tbl = BizCity_Research_DB::table_ingests();

        // Index existing ingests by url_hash for the requested scope.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url_hash, source_url, title, favicon, kg_source_id, ingest_status FROM {$tbl} WHERE scope_type=%s AND scope_id=%d",
            $scope_type, $scope_id
        ), ARRAY_A );
        $ingest_index = [];
        foreach ( ( $rows ?: [] ) as $r ) {
            $ingest_index[ (string) $r['url_hash'] ] = $r;
        }

        // Collect ingest rows created specifically for this turn (manually attached).
        $turn_ingest_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url_hash, source_url, title, favicon, kg_source_id, ingest_status FROM {$tbl} WHERE turn_id=%d",
            $turn_id
        ), ARRAY_A );
        $turn_ingest_by_hash = [];
        foreach ( ( $turn_ingest_rows ?: [] ) as $r ) {
            $turn_ingest_by_hash[ (string) $r['url_hash'] ] = $r;
        }

        $seen = [];
        $out  = [];

        // 1) Agent-discovered sources (from turn.source_urls JSON).
        foreach ( $sources as $s ) {
            $url = (string) ( $s['url'] ?? '' );
            if ( $url === '' ) continue;
            $hash    = BizCity_Research_Store::url_hash( $url );
            $seen[ $hash ] = true;
            $ingest  = $ingest_index[ $hash ] ?? null;
            $state   = 'discovered';
            $kg_sid  = null;
            $ing_id  = null;
            if ( $ingest ) {
                $ing_id = (int) $ingest['id'];
                $kg_sid = $ingest['kg_source_id'] ? (int) $ingest['kg_source_id'] : null;
                $st     = (string) $ingest['ingest_status'];
                if ( $st === 'detached' ) {
                    $state = 'detached';
                } elseif ( $kg_sid || in_array( $st, [ 'kg_attached', 'stored' ], true ) ) {
                    $state = 'attached';
                }
            }
            $out[] = [
                'url'          => $url,
                'title'        => (string) ( $s['title'] ?? $url ),
                'favicon'      => (string) ( $s['favicon'] ?? '' ),
                'score'        => isset( $s['score'] ) ? (float) $s['score'] : 0.0,
                'tool_type'    => (string) ( $s['tool_type'] ?? 'search' ),
                'state'        => $state,
                'kg_source_id' => $kg_sid,
                'ingest_id'    => $ing_id,
            ];
        }

        // 2) Manually attached URLs for this turn not already in source_urls.
        foreach ( $turn_ingest_by_hash as $hash => $r ) {
            if ( isset( $seen[ $hash ] ) ) continue; // already handled above
            $st = (string) $r['ingest_status'];
            if ( $st === 'detached' ) continue; // hidden after manual detach
            $kg_sid = $r['kg_source_id'] ? (int) $r['kg_source_id'] : null;
            $state  = ( $kg_sid || in_array( $st, [ 'kg_attached', 'stored' ], true ) )
                ? 'attached' : 'discovered';
            $out[] = [
                'url'          => (string) $r['source_url'],
                'title'        => (string) ( $r['title'] ?: $r['source_url'] ),
                'favicon'      => (string) ( $r['favicon'] ?? '' ),
                'score'        => 0.0,
                'tool_type'    => 'manual',
                'state'        => $state,
                'kg_source_id' => $kg_sid,
                'ingest_id'    => (int) $r['id'],
            ];
        }

        return $out;
    }

    public static function list_for_scope( string $scope_type, int $scope_id, int $limit = 100 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . BizCity_Research_DB::table_ingests()
            . ' WHERE scope_type=%s AND scope_id=%d ORDER BY created_at DESC LIMIT %d',
            $scope_type, $scope_id, $limit
        ), ARRAY_A );
        return $rows ?: [];
    }
}
