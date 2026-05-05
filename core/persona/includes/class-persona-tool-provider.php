<?php
/**
 * Bizcity Twin AI — Persona Tool Provider (abstract contract).
 *
 * PHASE-0.18 Wave 0.18.0 — bridge between Notebook persona × smart tools ×
 * personal sources. Concrete providers (built-in `scholar`, `tax-stub`, or
 * 3rd-party plugin like `bizcoach-map`) MUST extend this class and register
 * themselves via the `bizcity_persona_tool_providers` filter.
 *
 * Read together with: PHASE-0-RULE-PERSONA-PROVIDER.md (R-PP-1..R-PP-8).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.3.3
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @license    GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Persona_Tool_Provider' ) ) {
    return;
}

/**
 * Abstract Persona Tool Provider.
 *
 * Lifecycle: instantiated once per request by `BizCity_Persona_Registry` after
 * the `bizcity_persona_tool_providers` filter chain runs. Providers MUST stay
 * stateless across requests (lean on WP options / transients for persistence).
 */
abstract class BizCity_Persona_Tool_Provider {

    /**
     * Stable slug used to address this provider. Regex enforced by registry.
     *
     * @return string Lowercase slug, [a-z][a-z0-9_-]{2,39}.
     */
    abstract public function id(): string;

    /**
     * Human-friendly label shown in admin / persona switcher.
     */
    abstract public function label(): string;

    /**
     * Provider semver. Used by registry metadata + back-compat checks (R-PP-8).
     */
    public function version(): string {
        return '1.0.0';
    }

    /**
     * Tools this provider exposes. See R-PP-5 for the row contract.
     *
     * @return array<int,array<string,mixed>>
     */
    abstract public function get_tool_definitions(): array;

    /**
     * Smart source chips rendered in Notebook detail (UI affordances).
     *
     * Each chip: [ 'tool' => '<tool_name>', 'icon' => '🔮', 'label' => '…',
     *              'requires_user_data' => [ 'dob' ] ?? [] ].
     *
     * @return array<int,array<string,mixed>>
     */
    abstract public function get_smart_source_chips(): array;

    /**
     * Source kinds (kg_sources.source_type values) this provider OWNS.
     * Built-in reserved kinds MUST NOT be returned (R-PP-3).
     *
     * @return string[]
     */
    abstract public function get_source_kinds(): array;

    /**
     * Convert a stored artifact row into Passage[] for the embedder.
     *
     * Passage shape:
     *   [ 'title' => string, 'body' => string,
     *     'metadata' => array, 'citation_anchor' => ?string ]
     *
     * @param string $kind     One of self::get_source_kinds().
     * @param array  $artifact Raw artifact (as produced by the tool callback or
     *                         retrieved from kg_sources.content JSON).
     *
     * @return array<int,array<string,mixed>>
     */
    abstract public function render_to_passages( string $kind, array $artifact ): array;

    /**
     * Optional: short context block injected at priority 22 of the
     * `bizcity_chat_system_prompt` chain. Bounded ≤ 600 tokens (R-PP-6).
     * Registry will catch exceptions and truncate.
     *
     * @param int   $user_id      Current user.
     * @param int   $character_id Active character row id.
     * @param array $ctx          Free-form runtime hints (notebook_id, intent…).
     *
     * @return string Plain text. Empty string = "no enrichment".
     */
    public function enrich_system_prompt( int $user_id, int $character_id, array $ctx ): string {
        return '';
    }

    /**
     * Optional: hook fired after a personal artifact is ingested. Providers
     * may use it to update their own side-table (e.g. mark `bccm_astro` row
     * linked) WITHOUT mutating kg_sources directly (R-PP-4).
     *
     * @param int    $source_id kg_sources.id of the freshly-created row.
     * @param string $kind      Source kind that was ingested.
     * @param array  $artifact  Artifact payload.
     */
    public function on_artifact_created( int $source_id, string $kind, array $artifact ): void {
        // No-op by default.
    }

    /**
     * Optional: render a citation token `[persona:<kind>#<source_id>]` into a
     * structured payload for the resolver drawer. Default returns minimal info;
     * providers SHOULD override to produce a useful summary.
     *
     * @param int $source_id kg_sources.id.
     *
     * @return array{title:string,summary:string,actions:array}
     */
    public function resolve_citation( int $source_id ): array {
        return [
            'title'   => sprintf( '%s #%d', $this->label(), $source_id ),
            'summary' => '',
            'actions' => [],
        ];
    }

    /**
     * Optional: declare Guru Research Studio capability.
     *
     * Returning `null` (default) = provider does NOT enable research dialog
     * for characters bound to it. Returning an array enables the dialog with
     * the supplied configuration.
     *
     * Capability shape (PHASE-0.18.1 §7.B.2):
     *   [
     *     'enabled'            => bool,
     *     'modes'              => string[],   // subset of ['fast','deep']
     *     'allowed_tools'      => string[],   // subset of ['search','extract','crawl']
     *     'rate_limit_per_day' => int,        // turns/day per character
     *     'starter_queries'    => string[],   // suggested queries (≤6)
     *     'topic_tags'         => string[],   // pre-tag for new sessions
     *     'ui_label'           => string,     // override default "🔬 Nghiên cứu sâu"
     *   ]
     *
     * Hook `bizcity_research_capability_for_character` (priority 10, 2 args:
     * `$capability, $character_id`) fires AFTER this method so admins can
     * tweak capability per-character.
     *
     * @return array|null
     */
    public function get_research_capability(): ?array {
        return null;
    }
}
