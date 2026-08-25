<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Server-side HTML export from SiteConfig JSON.
 * Produces standalone HTML with inlined theme CSS, Tailwind CDN, and Google Fonts.
 * Ported from OpenPage src/lib/export-html.ts (simplified).
 */
class BZPB_Export {

	/**
	 * Render SiteConfig for embedding inside a WP page.
	 * Uses CSS isolation to prevent theme (Flatsome, etc.) from overriding styles.
	 * Returns HTML fragment (no <!DOCTYPE>, no <html>/<body>).
	 */
	public static function render_for_wp_page( array $config ): string {
		return self::render_page_css( $config ) . self::render_page_body( $config, function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0 );
	}

	/**
	 * Render floating admin debug toolbar for published BZPB pages.
	 * Only injected when current_user_can('manage_options').
	 *
	 * [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — admin debug toolbar
	 *
	 * @param  array $config  SiteConfig array.
	 * @param  int   $post_id WP post ID.
	 * @return string HTML + JS for the toolbar.
	 */
	private static function render_admin_debug_toolbar( array $config, int $post_id ): string {
		$edit_url    = esc_url( admin_url( 'admin.php?page=bizcity-pagebuilder&project_id=' . $post_id ) );
		$config_json = wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		$config_b64  = base64_encode( $config_json );
		$block_count = count( $config['blocks'] ?? [] );
		$page_name   = esc_js( $config['name'] ?? 'bzpb-config' );

		// Build block list for debug panel
		$block_rows = '';
		foreach ( ( $config['blocks'] ?? [] ) as $b ) {
			$bid   = esc_html( $b['id'] ?? '?' );
			$btype = esc_html( $b['type'] ?? '?' );
			$hidden = ! empty( $b['hidden'] ) ? ' (hidden)' : '';
			$block_rows .= '<tr><td style="padding:3px 8px;border-bottom:1px solid #2d2d2d;font-size:11px;color:#a0a0a0;">' . $bid . '</td><td style="padding:3px 8px;border-bottom:1px solid #2d2d2d;font-size:11px;color:#e0e0e0;">' . $btype . $hidden . '</td></tr>';
		}

		return <<<HTML
<!-- BZPB Admin Toolbar — only rendered for manage_options users -->
<div id="bzpb-admin-bar" style="position:fixed;bottom:0;left:0;right:0;z-index:99999;background:#1a1a1a;border-top:2px solid #3b82f6;display:flex;align-items:center;gap:0;font-family:system-ui,sans-serif;font-size:12px;">
  <span style="padding:6px 12px;color:#60a5fa;font-weight:700;border-right:1px solid #2d2d2d;white-space:nowrap;">⚡ BZPB #{$post_id}</span>
  <span style="padding:6px 10px;color:#a0a0a0;border-right:1px solid #2d2d2d;white-space:nowrap;">{$block_count} blocks</span>
  <button onclick="bzpbAdminToggleDebug()" style="padding:6px 12px;background:none;border:none;border-right:1px solid #2d2d2d;color:#d1d5db;cursor:pointer;white-space:nowrap;">🐛 Debug</button>
  <button onclick="bzpbAdminCopyJson()" style="padding:6px 12px;background:none;border:none;border-right:1px solid #2d2d2d;color:#d1d5db;cursor:pointer;white-space:nowrap;">📋 Copy JSON</button>
  <button onclick="bzpbAdminExportJson()" style="padding:6px 12px;background:none;border:none;border-right:1px solid #2d2d2d;color:#d1d5db;cursor:pointer;white-space:nowrap;">⬇️ Export JSON</button>
  <a href="{$edit_url}" style="padding:6px 12px;color:#60a5fa;text-decoration:none;border-right:1px solid #2d2d2d;white-space:nowrap;">✏️ Sửa trong Builder</a>
  <button onclick="document.getElementById('bzpb-admin-bar').style.display='none'" style="margin-left:auto;padding:6px 12px;background:none;border:none;color:#6b7280;cursor:pointer;">✕</button>
</div>

<!-- BZPB Debug Panel -->
<div id="bzpb-debug-panel" style="display:none;position:fixed;bottom:42px;left:0;right:0;z-index:99998;background:#111;border-top:1px solid #2d2d2d;max-height:50vh;overflow:auto;font-family:monospace;">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:1px solid #2d2d2d;">
    <strong style="color:#60a5fa;font-size:12px;">Site Config — {$page_name}</strong>
    <div style="display:flex;gap:8px;">
      <button onclick="bzpbAdminToggleJsonView()" style="padding:3px 10px;font-size:11px;background:#1e3a5f;border:none;color:#60a5fa;border-radius:4px;cursor:pointer;">{ } JSON</button>
      <button onclick="bzpbAdminToggleBlockList()" style="padding:3px 10px;font-size:11px;background:#27272a;border:none;color:#a0a0a0;border-radius:4px;cursor:pointer;">☰ Blocks</button>
    </div>
  </div>
  <div id="bzpb-debug-json" style="display:block;padding:12px;overflow:auto;max-height:calc(50vh - 50px);">
    <pre style="margin:0;font-size:11px;color:#a1a1aa;white-space:pre-wrap;word-break:break-all;" id="bzpb-json-pre"></pre>
  </div>
  <div id="bzpb-debug-blocks" style="display:none;padding:8px 12px;overflow:auto;max-height:calc(50vh - 50px);">
    <table style="border-collapse:collapse;width:100%;">
      <thead><tr><th style="padding:4px 8px;text-align:left;font-size:11px;color:#6b7280;">ID</th><th style="padding:4px 8px;text-align:left;font-size:11px;color:#6b7280;">Type</th></tr></thead>
      <tbody>{$block_rows}</tbody>
    </table>
  </div>
</div>

<script>
(function(){
  var _bzpbJson = null;
  function getJson(){
    if(!_bzpbJson){ try{ _bzpbJson=atob('{$config_b64}'); }catch(e){ _bzpbJson='{}'; } }
    return _bzpbJson;
  }
  window.bzpbAdminToggleDebug=function(){
    var p=document.getElementById('bzpb-debug-panel');
    if(p.style.display==='none'){
      p.style.display='block';
      document.getElementById('bzpb-json-pre').textContent=getJson();
    }else{p.style.display='none';}
  };
  window.bzpbAdminToggleJsonView=function(){
    document.getElementById('bzpb-debug-json').style.display='block';
    document.getElementById('bzpb-debug-blocks').style.display='none';
  };
  window.bzpbAdminToggleBlockList=function(){
    document.getElementById('bzpb-debug-json').style.display='none';
    document.getElementById('bzpb-debug-blocks').style.display='block';
  };
  window.bzpbAdminCopyJson=function(){
    var j=getJson();
    if(navigator.clipboard){navigator.clipboard.writeText(j).then(function(){alert('✅ Đã copy JSON vào clipboard!');}).catch(function(){prompt('JSON:',j);});}
    else{prompt('JSON:',j);}
  };
  window.bzpbAdminExportJson=function(){
    var j=getJson();
    var b=new Blob([j],{type:'application/json'});
    var a=document.createElement('a');
    a.href=URL.createObjectURL(b);
    a.download='bzpb-config-{$post_id}.json';
    document.body.appendChild(a);a.click();document.body.removeChild(a);
  };
  // Offset page body so toolbar doesn't cover content
  document.documentElement.style.paddingBottom='40px';
})();
</script>
HTML;
	}

	/**
	 * Return only the <link> + <style> tags for embedding in <head>.
	 * Call from template-canvas.php before any HTML is output.
	 */
	public static function render_page_css( array $config ): string {
		$theme     = $config['theme'] ?? [];
		$css_vars  = self::theme_to_css_vars( $theme );
		$fonts_url = self::google_fonts_url( $theme );

		return <<<HTML
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="{$fonts_url}" rel="stylesheet">
<style>
.bzpb-page { {$css_vars} }
.bzpb-page,
.bzpb-page *,
.bzpb-page *::before,
.bzpb-page *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  border: 0;
  font-size: 100%;
  font: inherit;
  vertical-align: baseline;
}
.bzpb-page {
  all: initial;
  display: block;
  font-family: var(--font-sans), system-ui, sans-serif;
  background: var(--bg0);
  color: var(--text0);
  line-height: 1.6;
  -webkit-text-size-adjust: 100%;
  width: 100%;
  max-width: 100vw;
  overflow-x: hidden;
}
.bzpb-page h1, .bzpb-page h2, .bzpb-page h3, .bzpb-page h4, .bzpb-page h5, .bzpb-page h6 {
  font-family: var(--font-display), system-ui, sans-serif;
  font-weight: 700;
  line-height: 1.2;
}
.bzpb-page h1 { font-size: clamp(2rem, 5vw, 3.5rem); }
.bzpb-page h2 { font-size: 2rem; }
.bzpb-page h3 { font-size: 1.25rem; }
.bzpb-page p { margin: 0; }
.bzpb-page a { color: inherit; text-decoration: none; }
.bzpb-page img { max-width: 100%; height: auto; display: block; }
.bzpb-page ul, .bzpb-page ol { list-style: none; padding: 0; margin: 0; }
.bzpb-page input, .bzpb-page textarea, .bzpb-page button, .bzpb-page select {
  font-family: inherit; font-size: inherit; color: inherit;
}
.bzpb-page .accent { color: var(--accent); }
.bzpb-page .btn-primary {
  background: var(--accent);
  color: var(--bg0);
  padding: 12px 24px;
  border-radius: var(--radius);
  text-decoration: none;
  display: inline-block;
  font-weight: 600;
  transition: opacity .2s;
  cursor: pointer;
}
.bzpb-page .btn-primary:hover { opacity: .9; }
.bzpb-page .btn-secondary {
  border: 1px solid var(--border);
  color: var(--text0);
  padding: 12px 24px;
  border-radius: var(--radius);
  text-decoration: none;
  display: inline-block;
  font-weight: 500;
}
.bzpb-page .card {
  background: var(--bg1);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
}
.bzpb-page .section { padding: 80px 24px; max-width: 1200px; margin: 0 auto; }
.bzpb-page .section-sm { padding: 60px 24px; max-width: 1200px; margin: 0 auto; }
.bzpb-page details summary { cursor: pointer; list-style: none; }
.bzpb-page details summary::-webkit-details-marker { display: none; }
</style>
HTML;
	}

	/**
	 * Return only the body HTML (<div class="bzpb-page">…</div> + JS).
	 * Call from template-canvas.php inside <body>.
	 */
	public static function render_page_body( array $config, $post_id = 0 ): string {
		$theme  = $config['theme'] ?? [];
		$blocks = $config['blocks'] ?? [];

		$body_html = '';
		self::$rendered_anchor_types = []; // reset per render pass
		$profile_block = null;
		$lead_block = null;
		$profile_index = -1;
		$first_lead_block = null;
		foreach ( $blocks as $block_index => $candidate ) {
			if ( ! empty( $candidate['hidden'] ) ) { continue; }
			if ( 'profile-card' === (string) ( $candidate['type'] ?? '' ) && null === $profile_block ) { $profile_block = $candidate; $profile_index = (int) $block_index; }
			if ( 'lead-form' === (string) ( $candidate['type'] ?? '' ) ) {
				if ( null === $first_lead_block ) { $first_lead_block = $candidate; }
				if ( null === $lead_block && $profile_index >= 0 && (int) $block_index > $profile_index ) { $lead_block = $candidate; }
			}
		}
		if ( null === $lead_block ) { $lead_block = $first_lead_block; }
		$profile_tabs = is_array( $profile_block ) && is_array( $lead_block );
		$profile_tabs_rendered = false;
		$lead_block_key = $profile_tabs ? (string) ( $lead_block['id'] ?? wp_json_encode( $lead_block ) ) : '';
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['hidden'] ) ) continue;
			if ( $profile_tabs && 'lead-form' === (string) ( $block['type'] ?? '' ) && $lead_block_key === (string) ( $block['id'] ?? wp_json_encode( $block ) ) ) { continue; }
			if ( $profile_tabs && 'profile-card' === (string) ( $block['type'] ?? '' ) && ! $profile_tabs_rendered ) {
				// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — public Profile communication tabs: Twin Brain chat and CRM lead form share one surface.
				$body_html .= self::render_profile_public_tabs( $profile_block, $lead_block, $theme, $post_id );
				$profile_tabs_rendered = true;
				continue;
			}
			$body_html .= self::render_block( $block, $theme );
		}

		$contact_js = self::contact_form_js();
		$nav_scroll = self::nav_scroll_js();
		$portfolio_layout_css = '';
		$portfolio_page_class = 'bzpb-page';
		$portfolio_props = is_array( $profile_block['props'] ?? null ) ? $profile_block['props'] : array();
		if ( 'vcard_portfolio' === sanitize_key( (string) ( $portfolio_props['profileStyle'] ?? '' ) ) ) {
			// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — compose the ported desktop sidebar/main layout from existing Page Builder output; collapse to one column on mobile.
			$portfolio_layout_css = '<style>.bzpb-page.bzp-vcard-portfolio-page{display:grid;grid-template-columns:minmax(240px,280px) minmax(0,1fr);gap:24px;max-width:1180px;margin:0 auto;padding:24px}.bzpb-page.bzp-vcard-portfolio-page>nav{grid-column:1/-1}.bzpb-page.bzp-vcard-portfolio-page>.bzp-profile-public-tabs{grid-column:1;position:sticky;top:16px;align-self:start;max-width:none;padding:0}.bzpb-page.bzp-vcard-portfolio-page>div[id]{grid-column:2;min-width:0}.bzpb-page.bzp-vcard-portfolio-page>.section{grid-column:2;min-width:0}@media(max-width:760px){.bzpb-page.bzp-vcard-portfolio-page{display:block;padding:12px}.bzpb-page.bzp-vcard-portfolio-page>.bzp-profile-public-tabs{position:relative;top:auto;margin-bottom:18px}.bzpb-page.bzp-vcard-portfolio-page>div[id],.bzpb-page.bzp-vcard-portfolio-page>.section{margin-bottom:18px}}</style>';
			$portfolio_page_class = 'bzpb-page bzp-vcard-portfolio-page';
		}

		return <<<HTML
{$portfolio_layout_css}<div class="{$portfolio_page_class}">
{$body_html}
</div>
{$contact_js}
{$nav_scroll}
HTML;
	}

	private static function render_profile_public_tabs( array $profile_block, array $lead_block, array $theme, $post_id = 0 ): string {
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — keep Profile content, Twin Brain, and CF7 lead capture in a single public tab contract.
		$lead_props = is_array( $lead_block['props'] ?? null ) ? $lead_block['props'] : array();
		$profile_props = is_array( $profile_block['props'] ?? null ) ? $profile_block['props'] : array();
		$profile_props['profileCardId'] = self::resolve_profile_card_id( $profile_props, $post_id );
		$profile_block['props'] = $profile_props;
		$profile_html = self::render_block( $profile_block, $theme );
		$lead_props['profileCardId'] = absint( $profile_props['profileCardId'] ?? 0 );
		$lead_block['props'] = $lead_props;
		$lead_html = self::render_block( $lead_block, $theme );
		if ( 'vcard_portfolio' === sanitize_key( (string) ( $profile_props['profileStyle'] ?? '' ) ) ) {
			// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — scope the source vCard dark/yellow treatment to the shared public Profile surface.
			$profile_html = '<style>.bzp-profile-public-tabs .bzp-profile-card{background:#1e1e1f;color:#f5f5f7;border-color:#3d3d40;box-shadow:0 18px 50px rgba(0,0,0,.35);font-family:Poppins,ui-sans-serif,system-ui,sans-serif}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-body{background:#1e1e1f}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-role,.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-bio{color:#aaaab2}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-row{background:#29292b;border-color:#3d3d40;color:#f5f5f7}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-row-label{color:#aaaab2}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-social{background:#29292b;border-color:#3d3d40;color:#f1c75b}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-twin-intro{background:#29292b!important;border-color:#f1c75b!important;color:#f5f5f7!important}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-twin-intro span,.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-twin-intro small{color:#aaaab2!important}.bzp-profile-public-tabs .bzp-profile-card .bzp-profile-capability{background:#3a3018!important;border-color:#f1c75b!important;color:#f1c75b!important}.bzp-profile-public-tabs .bzp-profile-tablist{background:#1e1e1f;border-color:#3d3d40}.bzp-profile-public-tabs .bzp-profile-tab{color:#aaaab2}.bzp-profile-public-tabs .bzp-profile-tab:hover{background:#29292b;color:#f5f5f7}.bzp-profile-public-tabs .bzp-profile-tab.is-active{background:#f1c75b;color:#1e1e1f}</style>' . $profile_html;
		}
		return '<style>.bzp-profile-public-tabs{max-width:720px;margin:0 auto;padding:0 16px 40px;color:#172033;font-family:Inter,ui-sans-serif,system-ui,sans-serif}.bzp-profile-tablist{display:flex;gap:6px;margin:0 auto 14px;padding:5px;border:1px solid #dce2eb;border-radius:14px;background:#eef3fb}.bzp-profile-tab{flex:1;min-height:46px;border:0;border-radius:10px;background:transparent;color:#68758b;font:600 14px/1.2 inherit;cursor:pointer}.bzp-profile-tab:hover{background:#fff;color:#172033}.bzp-profile-tab.is-active{background:#172033;color:#fff;box-shadow:0 5px 14px rgba(23,32,51,.14)}.bzp-profile-tab:focus-visible{outline:3px solid #2dd4bf;outline-offset:2px}.bzp-profile-tabpanel[hidden]{display:none}.bzp-profile-tabpanel>.section{max-width:none;padding:0}@media (max-width:600px){.bzp-profile-public-tabs{padding-left:10px;padding-right:10px}.bzp-profile-tab{font-size:13px}}</style>'
			. '<section class="bzp-profile-public-tabs" data-profile-public-tabs="1" aria-label="Kết nối với Profile">'
			. '<div class="bzp-profile-tablist" role="tablist" aria-label="Kết nối với Profile">'
			. '<button type="button" class="bzp-profile-tab is-active" id="bzp-profile-tab-chat" role="tab" aria-selected="true" aria-controls="bzp-profile-panel-chat" data-profile-tab="chat">Chat với tôi</button>'
			. '<button type="button" class="bzp-profile-tab" id="bzp-profile-tab-contact" role="tab" aria-selected="false" aria-controls="bzp-profile-panel-contact" data-profile-tab="contact">Để lại thông tin</button>'
			. '</div>'
			. '<div class="bzp-profile-tabpanel is-active" id="bzp-profile-panel-chat" role="tabpanel" aria-labelledby="bzp-profile-tab-chat" data-profile-tab-panel="chat">' . $profile_html . '</div>'
			. '<div class="bzp-profile-tabpanel" id="bzp-profile-panel-contact" role="tabpanel" aria-labelledby="bzp-profile-tab-contact" data-profile-tab-panel="contact" hidden>' . $lead_html . '</div>'
			. '</section>'
			. '<script>(function(){function init(){document.querySelectorAll("[data-profile-public-tabs]").forEach(function(root){if(root.__bzpTabs)return;root.__bzpTabs=true;var tabs=root.querySelectorAll("[data-profile-tab]");var panels=root.querySelectorAll("[data-profile-tab-panel]");function select(name){tabs.forEach(function(tab){var active=tab.getAttribute("data-profile-tab")===name;tab.classList.toggle("is-active",active);tab.setAttribute("aria-selected",active?"true":"false");});panels.forEach(function(panel){var active=panel.getAttribute("data-profile-tab-panel")===name;panel.classList.toggle("is-active",active);panel.hidden=!active;});}tabs.forEach(function(tab){tab.addEventListener("click",function(){select(tab.getAttribute("data-profile-tab")||"chat");});tab.addEventListener("keydown",function(event){if(event.key!=="ArrowRight"&&event.key!=="ArrowLeft")return;event.preventDefault();var index=Array.prototype.indexOf.call(tabs,tab);var next=event.key==="ArrowRight"?(index+1)%tabs.length:(index+tabs.length-1)%tabs.length;tabs[next].focus();select(tabs[next].getAttribute("data-profile-tab")||"chat");});});});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init,{once:true});}else{init();}})();</script>';
	}

	private static function resolve_profile_card_id( array $props, $post_id = 0 ) {
		$card_id = absint( $props['profileCardId'] ?? 0 );
		if ( $card_id > 0 ) { return $card_id; }
		$post_id = (int) $post_id > 0 ? (int) $post_id : ( function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0 );
		if ( $post_id <= 0 || ! function_exists( 'bizcity_tbl_exists' ) ) { return 0; }
		static $resolved = array();
		$cache_key = (int) get_current_blog_id() . ':' . $post_id;
		if ( array_key_exists( $cache_key, $resolved ) ) { return $resolved[ $cache_key ]; }
		global $wpdb;
		$projects_table = $wpdb->prefix . 'bzpb_projects';
		$cards_table    = $wpdb->prefix . 'bizcity_personal_profile_cards';
		if ( ! bizcity_tbl_exists( $projects_table ) || ! bizcity_tbl_exists( $cards_table ) ) {
			$resolved[ $cache_key ] = 0;
			return 0;
		}
		$project_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $projects_table . '` WHERE published_page_id = %d LIMIT 1', $post_id ) );
		$card_id = $project_id > 0 ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $cards_table . '` WHERE bzpb_project_id = %d AND status <> %s ORDER BY updated_at DESC, id DESC LIMIT 1', $project_id, 'archived' ) ) : 0;
		$resolved[ $cache_key ] = $card_id;
		return $card_id;
	}

	/**
	 * Render a full SiteConfig to standalone HTML string.
	 */
	/**
	 * JS for AJAX contact form submission — injected once per rendered page.
	 */
	private static function contact_form_js(): string {
		return <<<'SCRIPT'
<script>
(function(){
  function initBzpbForms(){
    document.querySelectorAll('[data-bzpb-contact]').forEach(function(form){
      if(form.__bzpbInit)return;
      form.__bzpbInit=true;
      form.addEventListener('submit',function(e){
        e.preventDefault();
        var btn=form.querySelector('[data-bzpb-submit]');
        var orig=btn?btn.textContent:'';
        if(btn){btn.disabled=true;btn.textContent='Đang gửi…';}
        var fd=new FormData(form);
        fd.append('action','bzpb_submit_contact');
        var cfg=window.bzpbContactConfig||{};
        if(cfg.nonce)fd.append('nonce',cfg.nonce);
        var url=cfg.ajaxUrl||'/wp-admin/admin-ajax.php';
        fetch(url,{method:'POST',credentials:'same-origin',body:fd})
          .then(function(r){return r.json();})
          .then(function(res){
            if(res.success){
			  form.dispatchEvent(new CustomEvent('bzpbcontactsuccess',{bubbles:true,detail:{form:form}}));form.innerHTML='<p style="text-align:center;padding:32px 16px;color:var(--accent,#22c55e);font-size:1.1rem;">'+(res.data&&res.data.message?res.data.message:'Cảm ơn bạn đã liên hệ! Chúng tôi sẽ phản hồi sớm nhất.')+'</p>';
            }else{
              if(btn){btn.disabled=false;btn.textContent=orig;}
              alert(res.data&&res.data.message?res.data.message:'Có lỗi xảy ra. Vui lòng thử lại.');
            }
          })
          .catch(function(){
            if(btn){btn.disabled=false;btn.textContent=orig;}
            alert('Không thể kết nối. Vui lòng thử lại sau.');
          });
      });
    });
  }
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',initBzpbForms);
  }else{
    initBzpbForms();
  }
})();
</script>
SCRIPT;
	}

	/**
	 * Pre-render a complete HTML page for a WP canvas page.
	 * Called inside the template_include filter (where WP query state is reliable).
	 * Result is stored in $GLOBALS and echoed by template-canvas.php.
	 * Handles both standalone <!DOCTYPE> custom-html blocks and normal block configs.
	 *
	 * @param array $config  Decoded SiteConfig array.
	 * @param int   $post_id WordPress post ID.
	 * @return string        Full HTML document string.
	 */
	public static function render_canvas_page( array $config, int $post_id ): string {
		// ── Standalone full-HTML detection ──────────────────────────────────────
		// If a custom-html block contains a full <!DOCTYPE html> document, serve it
		// directly (inject nonce only).
		foreach ( ( $config['blocks'] ?? [] ) as $block ) {
			if ( ! empty( $block['hidden'] ) ) continue;
			if ( ( $block['type'] ?? '' ) !== 'custom-html' ) continue;
			$raw_html = isset( $block['props']['html'] ) ? trim( (string) $block['props']['html'] ) : '';
			if ( strlen( $raw_html ) < 50 ) continue;
			if ( stripos( $raw_html, '<!DOCTYPE' ) === 0 || preg_match( '/^<html[\s>]/i', $raw_html ) ) {
				$nonce_script = '<script>window.bzpbContactConfig=' . wp_json_encode( [
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'bzpb-contact-submit' ),
				] ) . ';</script>';
				// Inject before </head> using substr (avoids preg_replace PCRE size limits).
				$head_close = stripos( $raw_html, '</head>' );
				if ( $head_close !== false ) {
					return substr( $raw_html, 0, $head_close ) . $nonce_script . substr( $raw_html, $head_close );
				}
				return $nonce_script . $raw_html;
			}
		}

		// ── Normal block rendering ───────────────────────────────────────────────
		$post      = get_post( $post_id );
		$site_name = (string) get_bloginfo( 'name' );
		$title     = esc_html( $post ? $post->post_title . ' — ' . $site_name : $site_name );
		$charset   = esc_attr( (string) ( get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		$lang      = esc_attr( (string) ( get_bloginfo( 'language' ) ?: 'vi' ) );
		$body_bg   = esc_attr( (string) ( $config['theme']['bg0'] ?? '#ffffff' ) );

		$contact_cfg = wp_json_encode( [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bzpb-contact-submit' ),
		] );

		$css  = self::render_page_css( $config );
		$body = self::render_page_body( $config, $post_id );

		// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — admin debug toolbar
		// Only inject if current user is admin AND owns this post
		$admin_toolbar = '';
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$admin_toolbar = self::render_admin_debug_toolbar( $config, $post_id );
		}

		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — canvas pages never call
		// wp_head/wp_footer so CF7 JS is never enqueued. If any lead-form block
		// uses a real CF7 form, inject CF7 script + wpcf7 config manually.
		$cf7_scripts = '';
		$has_cf7 = false;
		foreach ( $config['blocks'] ?? array() as $_blk ) {
			if ( ( $_blk['type'] ?? '' ) === 'lead-form' && ! empty( $_blk['props']['cf7FormId'] ) ) {
				$has_cf7 = true;
				break;
			}
		}
		if ( $has_cf7 && class_exists( 'WPCF7' ) ) {
			// Get the registered CF7 script src (set by CF7 itself at plugins_loaded)
			$cf7_src = '';
			$cf7_handle = 'contact-form-7';
			if ( wp_script_is( $cf7_handle, 'registered' ) ) {
				$cf7_src = wp_scripts()->registered[ $cf7_handle ]->src ?? '';
			} elseif ( defined( 'WPCF7_PLUGIN' ) ) {
				// Fallback: construct URL from plugin path
				$cf7_src = plugin_dir_url( WPCF7_PLUGIN ) . 'includes/js/index.js';
			}
			if ( $cf7_src ) {
				$wpcf7_obj = wp_json_encode( array(
					'apiSettings' => array(
						'root'      => rest_url(),
						'namespace' => 'contact-form-7/v1',
					),
					'cached'   => false,
					'jqueryUi' => false,
				) );
				// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — anti-double-click: disable submit
				// button immediately on form submit; re-enable on CF7 ajax error/invalid events.
				$anti_dbl = '<script>document.addEventListener("DOMContentLoaded",function(){';
				$anti_dbl .= 'function bzpbGuard(f){';
				$anti_dbl .= 'f.addEventListener("submit",function(){';
				$anti_dbl .= 'var b=f.querySelector("input[type=submit],button[type=submit]");';
				$anti_dbl .= 'if(b){b.disabled=true;b.dataset.bzpbPending="1";}';
				$anti_dbl .= 'setTimeout(function(){if(b&&b.dataset.bzpbPending)b.disabled=false;},30000);';
				$anti_dbl .= '});}';
				$anti_dbl .= 'document.querySelectorAll(".wpcf7-form").forEach(bzpbGuard);';
				$anti_dbl .= '["wpcf7invalid","wpcf7mailfailed","wpcf7spam"].forEach(function(ev){';
				$anti_dbl .= 'document.addEventListener(ev,function(e){';
				$anti_dbl .= 'var b=(e.detail&&e.detail.contactForm)?e.detail.contactForm.querySelector("input[type=submit],button[type=submit]"):null;';
				$anti_dbl .= 'if(b){b.disabled=false;delete b.dataset.bzpbPending;}';
				$anti_dbl .= '});});';
				$anti_dbl .= '});</script>';
				// [2026-07-02 Johnny Chu] PHASE-0.46 M1 — Source tracker for canvas page.
				// wp_footer() never fires (template exits early) so inject inline here.
				$bz_src_script  = '<script id="bz-src-tracker">(function(){';
				$bz_src_script .= 'var SK="bz_src",PF="_bz_src";';
				$bz_src_script .= 'function gP(k){try{return new URL(location.href).searchParams.get(k)||"";}catch(e){return "";}}';
				$bz_src_script .= 'var ex;try{ex=JSON.parse(sessionStorage.getItem(SK)||"null");}catch(e){ex=null;}';
				$bz_src_script .= 'if(!ex){var s={utm_source:gP("utm_source"),utm_medium:gP("utm_medium"),utm_campaign:gP("utm_campaign"),utm_content:gP("utm_content"),utm_term:gP("utm_term"),';
				$bz_src_script .= 'referrer:(function(){try{var r=document.referrer;return r?new URL(r).origin:"";}catch(e){return "";}}()),landing_url:location.href.split("?")[0].substring(0,200)};';
				$bz_src_script .= 'try{sessionStorage.setItem(SK,JSON.stringify(s));}catch(e){}}';
				$bz_src_script .= 'function inj(f){if(!f||f.querySelector("input[name=\'"+PF+"\']"))return;';
				$bz_src_script .= 'try{var h=document.createElement("input");h.type="hidden";h.name=PF;h.value=sessionStorage.getItem(SK)||"";f.appendChild(h);}catch(e){}}';
				$bz_src_script .= 'document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".wpcf7-form,form[data-bz-track]").forEach(inj);});';
				$bz_src_script .= '})();</script>';
				$cf7_scripts = '<script>var wpcf7=' . $wpcf7_obj . ';</script>'
					. '<script src="' . esc_url( $cf7_src ) . '" defer></script>'
					. $anti_dbl
					. $bz_src_script;
			}
			// [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 4 — the
			// wpcf7mailsent → fbq/gtag/dataLayer pixel-fire script is normally
			// injected via wp_footer(), which canvas pages never trigger.
			if ( class_exists( 'BizCity_CF7_Tracking_Frontend' ) ) {
				$cf7_scripts .= BizCity_CF7_Tracking_Frontend::render_tracking_script();
			}
		}

		// [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 3 — canvas pages never
		// call wp_head()/wp_footer(), so GA4/FB Pixel/GTM/TikTok/custom head-body from
		// Settings must be injected inline here rather than relying on those hooks.
		$settings            = is_array( $config['settings'] ?? null ) ? $config['settings'] : array();
		$tracking_head       = self::render_tracking_head_scripts( $settings );
		$tracking_body_open  = self::render_tracking_body_scripts( $settings );

		return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
<meta charset="{$charset}">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<script>window.bzpbContactConfig={$contact_cfg};</script>
{$css}
{$tracking_head}
</head>
<body style="margin:0;padding:0;background:{$body_bg};">
{$tracking_body_open}
{$body}
{$admin_toolbar}
{$cf7_scripts}
</body>
</html>
HTML;
	}

	/**
	 * Build GA4 / FB Pixel / GTM head / TikTok / custom head HTML tags for the
	 * live canvas page (which never fires wp_head()).
	 *
	 * [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 3
	 *
	 * @param array $settings SiteConfig['settings'] (gaId/fbPixelId/gtmId/tiktokPixelId/customHeadHtml).
	 * @return string HTML to inject before </head>.
	 */
	private static function render_tracking_head_scripts( array $settings ): string {
		$out = '';

		$ga_id = trim( (string) ( $settings['gaId'] ?? '' ) );
		if ( $ga_id && preg_match( '/^G-[A-Z0-9]+$/i', $ga_id ) ) {
			$ga_id_js = wp_json_encode( $ga_id );
			$out     .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $ga_id ) . '"></script>';
			$out     .= '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config",' . $ga_id_js . ');</script>';
		}

		$fb_pixel_id = trim( (string) ( $settings['fbPixelId'] ?? '' ) );
		if ( $fb_pixel_id && preg_match( '/^[0-9]{10,20}$/', $fb_pixel_id ) ) {
			$fb_pixel_js = wp_json_encode( $fb_pixel_id );
			$out .= '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};'
				. 'if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;'
				. 't.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script",'
				. '"https://connect.facebook.net/en_US/fbevents.js");fbq("init",' . $fb_pixel_js . ');fbq("track","PageView");</script>';
		}

		$gtm_id = trim( (string) ( $settings['gtmId'] ?? '' ) );
		if ( $gtm_id && preg_match( '/^GTM-[A-Z0-9]{4,8}$/i', $gtm_id ) ) {
			$gtm_id_js = wp_json_encode( $gtm_id );
			$out .= '<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
				. 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;'
				. 'j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);'
				. '})(window,document,"script","dataLayer",' . $gtm_id_js . ');</script>';
		}

		$tiktok_id = trim( (string) ( $settings['tiktokPixelId'] ?? '' ) );
		if ( $tiktok_id && preg_match( '/^[A-Z0-9]{10,30}$/i', $tiktok_id ) ) {
			$tiktok_id_js = wp_json_encode( $tiktok_id );
			$out .= '<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie"];'
				. 'ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};'
				. 'for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);'
				. 'ttq.load=function(e,n){var i="https://analytics.tiktok.com/i18n/pixel/events.js";ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};'
				. 'var o=document.createElement("script");o.type="text/javascript";o.async=!0;o.src=i+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(o,a)};'
				. 'ttq.load(' . $tiktok_id_js . ');ttq.page();}(window,document,"ttq");</script>';
		}

		// [2026-08-21 Johnny Chu] PHASE-PB-TRACKING — customHeadHtml is owner-authored
		// (Page Builder Settings tab), not visitor-submitted input; same trust level as custom-html block.
		$custom_head = trim( (string) ( $settings['customHeadHtml'] ?? '' ) );
		if ( $custom_head ) {
			$out .= $custom_head;
		}

		return $out;
	}

	/**
	 * Build GTM <noscript> body tag + custom body HTML for the live canvas page
	 * (which never fires wp_body_open()/wp_footer()).
	 *
	 * [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 3
	 *
	 * @param array $settings SiteConfig['settings'].
	 * @return string HTML to inject right after <body>.
	 */
	private static function render_tracking_body_scripts( array $settings ): string {
		$out = '';

		$gtm_id = trim( (string) ( $settings['gtmId'] ?? '' ) );
		if ( $gtm_id && preg_match( '/^GTM-[A-Z0-9]{4,8}$/i', $gtm_id ) ) {
			$out .= '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $gtm_id )
				. '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
		}

		$custom_body = trim( (string) ( $settings['customBodyHtml'] ?? '' ) );
		if ( $custom_body ) {
			$out .= $custom_body;
		}

		return $out;
	}

	/**
	 * Render a full SiteConfig to standalone HTML string.
	 */
	public static function render_site_config( array $config ): string {
		$name   = esc_html( $config['name'] ?? 'Website' );
		$theme  = $config['theme'] ?? [];
		$blocks = $config['blocks'] ?? [];

		$css_vars  = self::theme_to_css_vars( $theme );
		$fonts_url = self::google_fonts_url( $theme );
		$body_html = '';

		self::$rendered_anchor_types = []; // reset per render pass
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['hidden'] ) ) continue; // skip hidden blocks
			$body_html .= self::render_block( $block, $theme );
		}

		$nav_scroll = self::nav_scroll_js();

		return <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$name}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="{$fonts_url}" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root { {$css_vars} }
body {
  font-family: var(--font-sans), system-ui, sans-serif;
  background: var(--bg0);
  color: var(--text0);
  margin: 0;
  line-height: 1.6;
}
h1, h2, h3, h4, h5, h6 { font-family: var(--font-display), system-ui, sans-serif; }
.accent { color: var(--accent); }
.btn-primary {
  background: var(--accent);
  color: var(--bg0);
  padding: 12px 24px;
  border-radius: var(--radius);
  text-decoration: none;
  display: inline-block;
  font-weight: 600;
  transition: opacity .2s;
}
.btn-primary:hover { opacity: .9; }
.btn-secondary {
  border: 1px solid var(--border);
  color: var(--text0);
  padding: 12px 24px;
  border-radius: var(--radius);
  text-decoration: none;
  display: inline-block;
  font-weight: 500;
}
.card {
  background: var(--bg1);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 24px;
}
.section { padding: 80px 24px; max-width: 1200px; margin: 0 auto; }
.section-sm { padding: 60px 24px; max-width: 1200px; margin: 0 auto; }
details summary { cursor: pointer; list-style: none; }
details summary::-webkit-details-marker { display: none; }
</style>
</head>
<body>
{$body_html}
{$nav_scroll}
</body>
</html>
HTML;
	}

	/**
	 * Inline JS: maps navbar links with href="#" to section anchors by text keyword,
	 * then smooth-scrolls to the matched section on click.
	 */
	private static function nav_scroll_js(): string {
		return <<<'SCRIPT'
<script>
(function(){
  document.documentElement.style.scrollBehavior='smooth';
  var kw={
    hero:['trang ch\u1ee7','home','ch\xednh','gi\u1edbi thi\u1ec7u'],
    features:['t\xednh n\u0103ng','feature','th\u1ef1c \u0111\u01a1n','menu','d\u1ecbch v\u1ee5','service','s\u1ea3n ph\u1ea9m','product'],
    pricing:['gi\xe1','pricing','g\xf3i','plan','chi ph\xed'],
    testimonials:['\u0111\xe1nh gi\xe1','review','testimonial','nh\u1eadn x\xe9t','ph\u1ea3n h\u1ed3i'],
    stats:['s\u1ed1 li\u1ec7u','stat','th\xe0nh t\xedch','k\u1ebft qu\u1ea3','con s\u1ed1'],
    team:['\u0111\u1ed9i ng\u0169','team','th\xe0nh vi\xean','v\u1ec1 ch\xfang t\xf4i','about'],
    faq:['c\xe2u h\u1ecfi','faq','h\u1ecfi \u0111\xe1p','th\u01b0\u1eddng g\u1eb7p'],
    contact:['li\xean h\u1ec7','contact','\u0111\u1eb7t b\xe0n','k\u1ebft n\u1ed1i'],
    newsletter:['\u0111\u0103ng k\xfd','newsletter','subscribe','nh\u1eadn tin'],
    gallery:['th\u01b0 vi\u1ec7n','gallery','\u1ea3nh','h\xecnh'],
    video:['video','xem'],
    content:['n\u1ed9i dung','blog','b\xe0i vi\u1ebft','tin t\u1ee9c'],
    cta:['b\u1eaft \u0111\u1ea7u','mua ngay','order']
  };
  function norm(s){return s.toLowerCase().trim();}
  function findId(txt){
    var t=norm(txt);
    for(var tp in kw){
      // Find first element whose id starts with "type-" (e.g. features-1, hero-1)
      var el=document.querySelector('[id^="'+tp+'-"]');
      if(!el)continue;
      var arr=kw[tp];
      for(var i=0;i<arr.length;i++){
        if(t.indexOf(arr[i])!==-1||arr[i].indexOf(t)!==-1)return el.id;
      }
    }
    return null;
  }
  document.querySelectorAll('nav a[href="#"]').forEach(function(a){
    var id=findId(a.textContent||'');
    if(id)a.href='#'+id;
  });
  document.addEventListener('click',function(e){
    var a=e.target.closest('a[href^="#"]');
    if(!a)return;
    var href=a.getAttribute('href');
    var id=href.slice(1);
    if(!id){e.preventDefault();return;}
    var el=document.getElementById(id);
    if(el){e.preventDefault();el.scrollIntoView({behavior:'smooth',block:'start'});}
  });
})();
</script>
SCRIPT;
	}

	private static function theme_to_css_vars( array $theme ): string {
		$defaults = [
			'bg0'           => '#ffffff',
			'bg1'           => '#f4f4f5',
			'bg2'           => '#e4e4e7',
			'text0'         => '#09090b',
			'text1'         => '#71717a',
			'accent'        => '#2563eb',
			'accentDim'     => '#93c5fd',
			// ThemeConfig stores border as 'borderDefault' — support both keys for backwards-compat
			'borderDefault' => '#e4e4e7',
			'borderSubtle'  => '#f4f4f5',
			'fontSans'      => 'Inter',
			'fontDisplay'   => 'Inter',
			'fontMono'      => 'JetBrains Mono',
			'radius'        => 8,
			'radiusLg'      => 16,
		];
		$t = array_merge( $defaults, $theme );

		// Resolve --border: prefer 'borderDefault' (ThemeConfig key), fall back to legacy 'border' key
		$border = $t['borderDefault'] ?? $t['border'] ?? '#e4e4e7';

		return sprintf(
			'--bg0: %s; --bg1: %s; --bg2: %s; --text0: %s; --text1: %s; --accent: %s; --accent-dim: %s; --border: %s; --border-subtle: %s; --font-sans: "%s"; --font-display: "%s"; --font-mono: "%s"; --radius: %dpx; --radius-lg: %dpx;',
			esc_attr( $t['bg0'] ),
			esc_attr( $t['bg1'] ),
			esc_attr( $t['bg2'] ),
			esc_attr( $t['text0'] ),
			esc_attr( $t['text1'] ),
			esc_attr( $t['accent'] ),
			esc_attr( $t['accentDim'] ),
			esc_attr( $border ),
			esc_attr( $t['borderSubtle'] ),
			esc_attr( $t['fontSans'] ),
			esc_attr( $t['fontDisplay'] ),
			esc_attr( $t['fontMono'] ),
			(int) $t['radius'],
			(int) $t['radiusLg']
		);
	}

	private static function google_fonts_url( array $theme ): string {
		$fonts = array_unique( array_filter( [
			$theme['fontSans'] ?? 'Inter',
			$theme['fontDisplay'] ?? 'Inter',
		] ) );

		$families = [];
		foreach ( $fonts as $f ) {
			$families[] = 'family=' . urlencode( $f ) . ':wght@400;500;600;700';
		}

		return 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap';
	}

	/* ═══════════════════════════════════════════════
	   BLOCK RENDERERS
	   ═══════════════════════════════════════════════ */

	/** Tracks which block types have already received an anchor id in the current render pass. */
	private static $rendered_anchor_types = [];

	private static function render_block( array $block, array $theme ): string {
		$type  = $block['type'] ?? '';
		$props = $block['props'] ?? [];

		$html = '';
		switch ( $type ) {
			case 'navbar':       $html = self::render_navbar( $props ); break;
			case 'hero':         $html = self::render_hero( $props, $block['variant'] ?? 'centered' ); break;
			case 'features':     $html = self::render_features( $props, $block['variant'] ?? 'grid' ); break;
			case 'pricing':      $html = self::render_pricing( $props, $block['variant'] ?? 'simple' ); break;
			case 'cta':          $html = self::render_cta( $props ); break;
			case 'footer':       $html = self::render_footer( $props, $block['variant'] ?? 'simple' ); break;
			case 'testimonials': $html = self::render_testimonials( $props ); break;
			case 'stats':        $html = self::render_stats( $props, $block['variant'] ?? 'default' ); break;
			case 'faq':          $html = self::render_faq( $props ); break;
			case 'team':         $html = self::render_team( $props ); break;
			case 'contact':      $html = self::render_contact( $props ); break;
			case 'newsletter':   $html = self::render_newsletter( $props ); break;
			// [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — lead-form block PHP render
			case 'lead-form':    $html = self::render_lead_form( $props ); break;
			// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — additive Profile card renderer.
			case 'profile-card': $html = self::render_profile_card( $props ); break;
			case 'logocloud':    $html = self::render_logocloud( $props ); break;
			case 'content':      $html = self::render_content( $props ); break;
			case 'image':        $html = self::render_image( $props, $block['variant'] ?? 'hero-image' ); break;
			case 'video':        $html = self::render_video( $props ); break;
			case 'gallery':      $html = self::render_gallery( $props, $block['variant'] ?? 'grid' ); break;
			case 'divider':      $html = self::render_divider( $props, $block['variant'] ?? 'line' ); break;
			case 'banner':       $html = self::render_banner( $props ); break;
			// [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 5 — safe allowlisted shortcode block.
			case 'shortcode':    $html = self::render_shortcode_block( $props ); break;
			case 'custom-html':
				/*
				 * [2026-08-24 Johnny Chu] HOTFIX — ignore an obsolete chat assignment that does not belong to the generic custom HTML renderer.
				// Hybrid mode: raw pixel-perfect HTML from screenshot Pass 1
		$chat_script = '<script>(function(){var script=document.currentScript;function boot(){var root=script&&script.parentNode;var card=' . (int) $card_id . ',ctxUrl=' . wp_json_encode( $context_url ) . ',chatUrl=' . wp_json_encode( $chat_url ) . ';if(!root||!card||!ctxUrl||!chatUrl)return;root.querySelectorAll(".bzp-profile-chat-mount[data-channel-code=webchat]:not([data-profile-react=1])").forEach(function(mount){var presentation=mount.getAttribute("data-presentation");if(presentation!=="profile_float"&&presentation!=="profile_embed")return;var state={context:null,session:"",busy:false};var shell=document.createElement("div");shell.className="bzp-profile-chat-shell "+presentation;shell.innerHTML="<button type=button class=bzp-profile-chat-launch>Chat với tôi</button><div class=bzp-profile-chat-panel><div class=bzp-profile-chat-head><strong>Trợ lý Profile</strong><button type=button class=bzp-profile-chat-close aria-label=Đóng>×</button></div><div class=bzp-profile-chat-messages></div><form class=bzp-profile-chat-form><input class=bzp-profile-chat-input placeholder=Nhập tin nhắn... autocomplete=off><button type=submit>Gửi</button></form></div>";mount.appendChild(shell);var panel=shell.querySelector(".bzp-profile-chat-panel"),launch=shell.querySelector(".bzp-profile-chat-launch"),close=shell.querySelector(".bzp-profile-chat-close"),messages=shell.querySelector(".bzp-profile-chat-messages"),form=shell.querySelector(".bzp-profile-chat-form"),input=shell.querySelector(".bzp-profile-chat-input");if(presentation==="profile_embed"){launch.style.display="none";panel.classList.add("is-open");}function add(text,kind){var row=document.createElement("div");row.className="bzp-profile-chat-msg "+kind;row.textContent=text;messages.appendChild(row);messages.scrollTop=messages.scrollHeight;}function open(){panel.classList.add("is-open");if(!state.context){fetch(ctxUrl+"?channel_code=webchat&presentation="+encodeURIComponent(presentation)).then(function(r){return r.json()}).then(function(d){if(!d.success)throw new Error(d.message||"Không thể mở chat");state.context=d.context;}).catch(function(e){add(e.message||"Không thể mở chat","error");});}input.focus();}launch.addEventListener("click",open);close.addEventListener("click",function(){panel.classList.remove("is-open")});form.addEventListener("submit",function(e){e.preventDefault();var text=input.value.trim();if(!text||state.busy)return;state.busy=true;input.value="";add(text,"user");fetch(chatUrl,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({card_id:card,context_token:state.context&&state.context.context_token||"",channel_code:"webchat",presentation:presentation,message:text,session_id:state.session||""})}).then(function(r){return r.json()}).then(function(d){if(!d.success)throw new Error(d.message||"Quản gia chưa thể trả lời");state.session=d.session_id||state.session;add(d.answer||"","bot")}).catch(function(e){add(e.message||"Quản gia chưa thể trả lời","error")}).finally(function(){state.busy=false})});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot,{once:true});}else{boot();}})();</script>';
				*/
				if ( empty( $raw ) ) return '';
				// Safety net: if this is a full HTML document embedded in a multi-block page
				// (the canvas template should have caught standalone pages earlier),
				// extract only the <body> content + any <style>/<script> from <head>
				// to prevent nested <html> documents from breaking the layout.
				if ( stripos( $raw, '<!DOCTYPE' ) === 0 || preg_match( '/^<html[\s>]/i', $raw ) ) {
					$extracted = '';
					// Pull <style> and non-CDN <script> tags from <head>
					if ( preg_match( '#<head[^>]*>(.*?)</head>#is', $raw, $head_m ) ) {
						preg_match_all( '#<style[^>]*>.*?</style>#is', $head_m[1], $sm );
						$extracted .= implode( "\n", $sm[0] );
						// Include non-CDN script tags (inline scripts only, not cdn.tailwindcss.com etc.)
						preg_match_all( '#<script(?![^>]*src)[^>]*>.*?</script>#is', $head_m[1], $scm );
						$extracted .= implode( "\n", $scm[0] );
					}
					// Pull body content
					if ( preg_match( '#<body[^>]*>(.*?)</body>#is', $raw, $body_m ) ) {
						$extracted .= $body_m[1];
					} else {
						// No body tags — strip html/head wrapper with regex
						$extracted = preg_replace( '#^.*?</head>#is', '', $raw );
						$extracted = preg_replace( '#</?html[^>]*>#i', '', $extracted );
					}
					return $extracted ?: $raw;
				}
				// Return immediately — no anchor wrapping for raw HTML blocks
				return $raw;
			default:             return '<!-- unknown block: ' . esc_html( $type ) . ' -->';
		}

		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PORTFOLIO — use each block ID as its anchor so repeated content/gallery sections remain reachable from the ported portfolio navbar.
		$no_anchor = [ 'navbar', 'footer', 'divider', 'banner' ];
		$block_id = sanitize_title( (string) ( $block['id'] ?? '' ) );
		if ( ! in_array( $type, $no_anchor, true ) && '' !== $block_id ) {
			$html = '<div id="' . esc_attr( $block_id ) . '">' . $html . '</div>';
		}

		return $html;
	}

	private static function render_profile_card( array $p ): string {
		// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — render a clean profile surface without legacy /gpt/ chrome.
		// [2026-08-23 Johnny Chu] PHASE-TBP-6.4 — repair legacy published pages that lack the card registry ID in SiteConfig.
		$name       = esc_html( (string) ( $p['name'] ?? '' ) );
		$job_title  = esc_html( (string) ( $p['jobTitle'] ?? '' ) );
		$company    = esc_html( (string) ( $p['company'] ?? '' ) );
		$bio        = esc_html( (string) ( $p['bio'] ?? '' ) );
		$avatar     = esc_url( (string) ( $p['avatarUrl'] ?? '' ) );
		$cover      = esc_url( (string) ( $p['coverUrl'] ?? '' ) );
		$logo       = esc_url( (string) ( $p['logoUrl'] ?? '' ) );
		$card_id    = self::resolve_profile_card_id( $p );
		$profile_style = sanitize_key( (string) ( $p['profileStyle'] ?? '' ) );
		$twin_enabled = ! array_key_exists( 'twinBrainEnabled', $p ) || ! empty( $p['twinBrainEnabled'] );
		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-UX — keep legacy stored greeting text aligned with the public Quản gia brand.
		$twin_greeting_raw = (string) ( $p['twinBrainGreeting'] ?? 'Xin chào, tôi là quản gia của bạn. Tôi có thể giúp gì hôm nay?' );
		$twin_greeting_raw = str_replace( array( 'Twin của bạn', 'Twin của tôi' ), array( 'quản gia của bạn', 'quản gia của tôi' ), $twin_greeting_raw );
		$twin_greeting = esc_html( $twin_greeting_raw );
		$twin_questions = array();
		foreach ( is_array( $p['twinBrainSuggestedQuestions'] ?? null ) ? $p['twinBrainSuggestedQuestions'] : array() as $question ) {
			$question = esc_html( (string) $question );
			if ( '' !== $question ) { $twin_questions[] = $question; }
		}
		$capabilities_html = '';
		// [2026-08-23 Johnny Chu] PHASE-TBP-3 — published pages prefer the server-owned public snapshot; legacy cards fall back to approved props.
		$public_snapshot = is_array( $p['publicGraphSnapshot'] ?? null ) ? $p['publicGraphSnapshot'] : array();
		$public_snapshot_valid = (int) ( $public_snapshot['version'] ?? 0 ) === 1
			&& 'profile_public_capabilities' === (string) ( $public_snapshot['source'] ?? '' )
			&& is_array( $public_snapshot['capabilities'] ?? null );
		$public_capabilities = $public_snapshot_valid ? $public_snapshot['capabilities'] : ( is_array( $p['publicCapabilities'] ?? null ) ? $p['publicCapabilities'] : array() );
		$public_capabilities = array_slice( $public_capabilities, 0, 5 );
		if ( $public_snapshot_valid && empty( $public_capabilities ) && is_array( $public_snapshot['graph']['nodes'] ?? null ) ) {
			$public_capabilities = $public_snapshot['graph']['nodes'];
		}
		foreach ( $public_capabilities as $capability ) {
			$capability_label = esc_html( (string) ( $capability['label'] ?? '' ) );
			$capability_category = esc_html( (string) ( $capability['category'] ?? 'expertise' ) );
			if ( '' !== $capability_label ) {
				$capabilities_html .= '<span class="bzp-profile-capability" title="' . $capability_category . '" style="display:inline-flex;align-items:center;padding:7px 11px;border:1px solid #b8e4df;border-radius:999px;background:#f0fbfa;color:#176b69;font-size:12px;font-weight:600">' . $capability_label . '</span>';
			}
		}
		$hero_style = sanitize_key( (string) ( $p['heroStyle'] ?? 'brain' ) );
		$hero_style = in_array( $hero_style, array( 'brain', 'photo' ), true ) ? $hero_style : 'brain';
		$brain_color = sanitize_hex_color( (string) ( $p['brainAccentColor'] ?? '#2dd4bf' ) ) ?: '#2dd4bf';
		$style      = $cover ? ' style="background-image:url(' . esc_url( $cover ) . ')"' : '';
		$logo_html  = $logo ? '<img class="bzp-profile-logo" src="' . $logo . '" alt="Logo thương hiệu" style="position:absolute;top:16px;right:16px;z-index:2;max-width:120px;max-height:54px;object-fit:contain;border-radius:10px;background:rgba(255,255,255,.9);padding:6px;box-sizing:border-box" onerror="this.style.display=\'none\'">' : '';
		$brain_html = '<div class="bzp-profile-brain-visual" aria-hidden="true"><span class="bzp-profile-brain-core">TWIN<br>BRAIN</span><span class="bzp-profile-brain-node node-a"></span><span class="bzp-profile-brain-node node-b"></span><span class="bzp-profile-brain-node node-c"></span><span class="bzp-profile-brain-node node-d"></span><span class="bzp-profile-brain-node node-e"></span><span class="bzp-profile-brain-node node-f"></span></div>';
		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-UX — render a stable profile icon when an avatar is missing or its URL fails.
		$avatar_icon = '<svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.8-3.4 3.1-5.2 7-5.2s6.2 1.8 7 5.2"></path></svg>';
		$avatar_html = $avatar
			? '<img class="bzp-profile-avatar" src="' . $avatar . '" alt="' . $name . '" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'"><span class="bzp-profile-avatar bzp-profile-avatar-fallback" aria-hidden="true" style="display:none;align-items:center;justify-content:center;background:#e7eef5;color:#607086">' . $avatar_icon . '</span>'
			: '<span class="bzp-profile-avatar bzp-profile-avatar-fallback" aria-hidden="true" style="display:flex;align-items:center;justify-content:center;background:#e7eef5;color:#607086">' . $avatar_icon . '</span>';
		$public_graph = is_array( $public_snapshot['graph'] ?? null ) ? $public_snapshot['graph'] : array();
		$public_graph = array( 'nodes' => array_slice( is_array( $public_graph['nodes'] ?? null ) ? $public_graph['nodes'] : array(), 0, 24 ), 'edges' => array_slice( is_array( $public_graph['edges'] ?? null ) ? $public_graph['edges'] : array(), 0, 48 ) );
		if ( empty( $public_graph['nodes'] ) && ! empty( $public_capabilities ) ) {
			foreach ( array_slice( $public_capabilities, 0, 24 ) as $capability ) {
				if ( ! is_array( $capability ) || '' === trim( (string) ( $capability['label'] ?? '' ) ) ) { continue; }
				$public_graph['nodes'][] = array( 'id' => sanitize_key( (string) ( $capability['id'] ?? $capability['label'] ) ), 'label' => sanitize_text_field( (string) $capability['label'] ), 'category' => sanitize_text_field( (string) ( $capability['category'] ?? 'expertise' ) ), 'weight' => max( 1, min( 10, (int) ( $capability['weight'] ?? 5 ) ) ) );
			}
		}
		$profile_react_available = defined( 'BIZCITY_PERSONAL_DIR' ) && is_readable( BIZCITY_PERSONAL_DIR . 'ui/dist/assets/profile-public.js' );
		$hero_graph_root = $profile_react_available && ! empty( $public_graph['nodes'] )
			? '<div data-bizcity-profile-hero-graph="1" data-graph="' . esc_attr( wp_json_encode( $public_graph ) ) . '" data-accent="' . esc_attr( $brain_color ) . '"></div>'
			: '';
		// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-REACT — replace the cover visual with a public-safe interactive graph when a published snapshot exists.
		$hero_html  = $hero_graph_root
			? '<div class="bzp-profile-brain" data-profile-brain="1" data-brain-accent="' . esc_attr( $brain_color ) . '">' . $hero_graph_root . $logo_html . '</div>'
			: ( 'photo' === $hero_style
			? '<div class="bzp-profile-cover" style="position:relative;' . ( $cover ? 'background-image:url(' . esc_url( $cover ) . ');' : '' ) . '">' . $logo_html . '</div>'
			: '<div class="bzp-profile-brain" data-profile-brain="1" data-brain-accent="' . esc_attr( $brain_color ) . '">' . $brain_html . $logo_html . '<canvas aria-hidden="true"></canvas></div>' );
		$contact_html = '';
		foreach ( is_array( $p['contactFields'] ?? null ) ? $p['contactFields'] : array() as $field ) {
			$type  = sanitize_key( (string) ( $field['type'] ?? '' ) );
			$label = esc_html( (string) ( $field['label'] ?? $type ) );
			$value = (string) ( $field['value'] ?? '' );
			$href  = '';
			if ( 'email' === $type && is_email( $value ) ) {
				$href = 'mailto:' . sanitize_email( $value );
			} elseif ( 'phone' === $type ) {
				$href = 'tel:' . preg_replace( '/[^0-9+]/', '', $value );
			} elseif ( in_array( $type, array( 'website', 'link' ), true ) ) {
				$href = esc_url( $value );
			}
			$display = esc_html( $value );
			$event_type = 'website' === $type || 'link' === $type ? 'click_link' : ( 'email' === $type ? 'click_email' : ( 'phone' === $type ? 'click_phone' : 'click_map' ) );
			if ( '' !== $href ) {
				$contact_html .= '<a class="bzp-profile-row" href="' . esc_url( $href ) . '" data-profile-event="' . esc_attr( $event_type ) . '"><span class="bzp-profile-row-label">' . $label . '</span><strong>' . $display . '</strong></a>';
			} else {
				$contact_html .= '<div class="bzp-profile-row"><span class="bzp-profile-row-label">' . $label . '</span><strong>' . $display . '</strong></div>';
			}
		}

		$social_html = '';
		foreach ( is_array( $p['socialLinks'] ?? null ) ? $p['socialLinks'] : array() as $social ) {
			$url      = esc_url( (string) ( $social['url'] ?? '' ) );
			$platform = esc_html( (string) ( $social['platform'] ?? 'social' ) );
			if ( '' !== $url ) {
				$social_html .= '<a class="bzp-profile-social" href="' . $url . '" target="_blank" rel="noopener" data-profile-event="click_social" data-social="' . esc_attr( strtolower( $platform ) ) . '">' . $platform . '</a>';
			}
		}
		$messaging_html = '';
		foreach ( is_array( $p['messagingLinks'] ?? null ) ? $p['messagingLinks'] : array() as $message_link ) {
			$platform = sanitize_key( (string) ( $message_link['platform'] ?? '' ) );
			$value = trim( (string) ( $message_link['value'] ?? '' ) );
			if ( '' === $value || ! in_array( $platform, array( 'whatsapp', 'discord', 'skype', 'telegram', 'zalo' ), true ) ) { continue; }
			$href = '';
			if ( preg_match( '/^https?:\/\//i', $value ) ) {
				$href = esc_url( $value );
			} elseif ( in_array( $platform, array( 'whatsapp', 'zalo' ), true ) ) {
				$digits = preg_replace( '/[^0-9+]/', '', $value );
				$href = 'whatsapp' === $platform ? 'https://wa.me/' . rawurlencode( ltrim( $digits, '+' ) ) : 'https://zalo.me/' . rawurlencode( ltrim( $digits, '+' ) );
			} elseif ( 'telegram' === $platform ) {
				$href = 'https://t.me/' . rawurlencode( ltrim( $value, '@' ) );
			} elseif ( 'skype' === $platform ) {
				$href = 'skype:' . rawurlencode( $value ) . '?chat';
			}
			if ( '' === $href ) { continue; }
			$messaging_html .= '<a class="bzp-profile-social" href="' . esc_url( $href ) . '" target="_blank" rel="noopener" data-profile-event="click_message_app" data-channel-code="' . esc_attr( $platform ) . '">' . esc_html( ucfirst( $platform ) ) . '</a>';
		}
		$social_html .= $messaging_html;
			$public_base = function_exists( 'rest_url' ) ? rest_url( 'bizcity-profile/v1/profile/cards/' . $card_id ) : '';
			$vcard_url = esc_url( untrailingslashit( $public_base ) . '/vcard.vcf' );
			$chat_url = function_exists( 'rest_url' ) ? rest_url( 'bizcity-profile/v1/profile/chat/turn' ) : '';
			$stream_url = function_exists( 'rest_url' ) ? rest_url( 'bizcity-twinweb/v1/chat/stream' ) : '';
			$context_url = function_exists( 'rest_url' ) ? rest_url( 'bizcity-profile/v1/profile/cards/' . $card_id . '/channel-context' ) : '';
			// [2026-08-24 Johnny Chu] PHASE-PROFILE-PUBLIC-REACT — expose only the compiled React chat module to public Profile pages.
			$profile_react_js = defined( 'BIZCITY_PERSONAL_URL' ) && defined( 'BIZCITY_PERSONAL_DIR' ) && is_readable( BIZCITY_PERSONAL_DIR . 'ui/dist/assets/profile-public.js' )
				? add_query_arg( 'ver', defined( 'BIZCITY_PERSONAL_VERSION' ) ? BIZCITY_PERSONAL_VERSION : '1.0.0', BIZCITY_PERSONAL_URL . 'ui/dist/assets/profile-public.js' )
				: '';
			$profile_react_assets = $profile_react_js ? '<script type="module" crossorigin="anonymous" src="' . esc_url( $profile_react_js ) . '"></script>' : '';
			$cta_html = '<div class="bzp-profile-ctas" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;">';
			if ( ! empty( $p['ctaSave'] ) && $card_id > 0 ) {
				$cta_html .= '<a href="' . $vcard_url . '" data-profile-event="save_contact" style="display:inline-flex;padding:10px 15px;border-radius:999px;background:#172033;color:#fff;font-weight:700;text-decoration:none;">Lưu danh bạ</a>';
			}
			if ( ! empty( $p['ctaShare'] ) && $card_id > 0 ) {
				$cta_html .= '<button type="button" data-profile-share="1" data-profile-event="share" style="display:inline-flex;padding:10px 15px;border:1px solid #dce2eb;border-radius:999px;background:#fff;color:#172033;font-weight:700;cursor:pointer;">Chia sẻ</button>';
			}
			$cta_html .= '</div><script>(function(){function bind(){document.querySelectorAll("[data-profile-share]").forEach(function(button){button.addEventListener("click",function(){if(navigator.share){navigator.share({title:document.title,url:location.href}).catch(function(){});}else if(navigator.clipboard){navigator.clipboard.writeText(location.href);} });});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",bind,{once:true});}else{bind();}})();</script>';
			$contact_html .= $cta_html;

		$chat_html = '';
		$entrypoints = is_array( $p['chatEntrypoints'] ?? null ) ? $p['chatEntrypoints'] : array();
		// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — legacy cards with no channel config receive the same default FloatChat as new templates.
		if ( empty( $entrypoints ) && $card_id > 0 ) {
			$entrypoints[] = array( 'channelCode' => 'webchat', 'enabled' => true, 'presentation' => 'profile_float', 'trackingTag' => '', 'fallbackUrl' => '' );
		}
		foreach ( $entrypoints as $entry ) {
			if ( empty( $entry['enabled'] ) ) { continue; }
			$channel      = sanitize_key( (string) ( $entry['channelCode'] ?? '' ) );
			$presentation = sanitize_key( (string) ( $entry['presentation'] ?? 'external' ) );
			if ( 'webchat' === $channel && $twin_enabled ) {
				$react_root = $profile_react_js
					? '<div data-bizcity-profile-chat-root="1" data-profile-card-id="' . $card_id . '" data-context-url="' . esc_attr( $context_url ) . '" data-chat-url="' . esc_attr( $chat_url ) . '" data-stream-url="' . esc_attr( $stream_url ) . '" data-presentation="' . esc_attr( $presentation ) . '"></div>'
					: '';
				$react_marker = $profile_react_js ? ' data-profile-react="1"' : '';
				$chat_html .= '<div class="bzp-profile-chat-mount"' . $react_marker . ' data-profile-card-id="' . $card_id . '" data-channel-code="webchat" data-presentation="' . esc_attr( $presentation ) . '">' . $react_root . '</div>';
				continue;
			}
			$href = esc_url( (string) ( $entry['fallbackUrl'] ?? '' ) );
			if ( 'twin_gpt' === $channel && '' === $href ) { $href = esc_url( home_url( '/gpt/' ) ); }
			if ( '' === $href ) { continue; }
			$label = 'messenger' === $channel ? 'Nhắn Messenger' : ( 'zalo_oa' === $channel ? 'Kết nối Zalo OA' : ( 'zalo_personal' === $channel ? 'Nhắn Zalo cá nhân' : 'Mở Twin GPT' ) );
			$tracking_tag = sanitize_key( (string) ( $entry['trackingTag'] ?? '' ) );
			$chat_html .= '<a class="bzp-profile-channel-link" href="' . $href . '" target="_blank" rel="noopener" data-profile-event="click_message_app" data-channel-code="' . esc_attr( $channel ) . '" data-tracking-tag="' . esc_attr( $tracking_tag ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( $twin_enabled && $chat_html && ! $profile_react_js ) {
			$question_html = '';
			foreach ( $twin_questions as $question ) {
				$question_html .= '<a href="#profile-twin-chat" style="display:inline-flex;margin:4px 4px 0 0;padding:7px 10px;border:1px solid #b8e4df;border-radius:999px;color:#176b69;background:#fff;text-decoration:none;font-size:12px">' . $question . '</a>';
			}
			$chat_html = '<div id="profile-twin-chat" class="bzp-profile-twin-intro" style="display:grid;gap:4px;margin-top:22px;padding:14px 16px;border:1px solid #b8e4df;border-radius:14px;background:#f0fbfa;color:#172033"><button type="button" class="bzp-profile-twin-open" data-profile-chat-open="1" style="border:0;padding:0;background:transparent;color:#172033;font:inherit;font-weight:700;text-align:left;cursor:pointer">Hỏi quản gia của tôi</button><span style="font-size:13px;color:#4b5870;line-height:1.5">' . $twin_greeting . '</span><small style="font-size:11px;color:#68758b">Quản gia AI công khai · Có thể chuyển sang người thật</small><span>' . $question_html . '</span></div>' . $chat_html;
		}
		$chat_prompt_script = '<script>(function(){function bind(){document.querySelectorAll("[data-profile-chat-open]").forEach(function(trigger){if(trigger.__bzpBound)return;trigger.__bzpBound=true;trigger.addEventListener("click",function(){var card=trigger.closest(".bzp-profile-card");var launch=card&&card.querySelector(".bzp-profile-chat-launch");if(launch){launch.click();}});});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",bind,{once:true});}else{bind();}})();</script>';
		$gift_html = '';
		$gift_wheel_id = absint( $p['giftWheelId'] ?? 0 );
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: resolve optional wheel rendering through the selected provider.
		$gift_wheel_provider = sanitize_key( (string) ( $p['giftWheelProvider'] ?? ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry' ) ? BizCity_Profile_Wheel_Provider_Registry::default_key() : '' ) ) );
		if ( $gift_wheel_id > 0 ) {
			$wheel_html = class_exists( 'BizCity_Profile_Wheel_Provider_Registry' )
				? BizCity_Profile_Wheel_Provider_Registry::render( $gift_wheel_provider, $gift_wheel_id )
				: '';
			if ( '' !== $wheel_html ) {
				$gift_html = '<div class="bzp-profile-gift-wheel" style="margin-top:22px;padding:16px;border:1px solid #fed7aa;border-radius:14px;background:#fff7ed"><strong style="display:block;margin-bottom:6px">Nhận một món quà</strong><span style="display:block;margin-bottom:12px;color:#9a3412;font-size:13px">Chơi nhanh để nhận ưu đãi hoặc tài liệu từ profile này.</span>' . $wheel_html . '</div>';
			} else {
				// [2026-08-24 Johnny Chu] PHASE-TBP-4 — keep the public page usable when the selected wheel provider is disabled or degraded.
				$gift_html = '<div class="bzp-profile-gift-wheel bzp-profile-gift-wheel-fallback" role="status" style="margin-top:22px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:14px;background:#f8fafc;color:#475569;font-size:13px">Quà tặng tạm thời chưa sẵn sàng. Bạn vẫn có thể xem profile và trò chuyện với quản gia.</div>';
			}
		}

		$css = '<style>.bzp-profile-card{max-width:680px;margin:0 auto;background:#fff;color:#172033;border:1px solid #e7eaf0;border-radius:24px;overflow:hidden;box-shadow:0 18px 50px rgba(23,32,51,.10);font-family:Inter,ui-sans-serif,system-ui,sans-serif}.bzp-profile-cover,.bzp-profile-brain{height:220px}.bzp-profile-cover{background:#dfe7f3 center/cover no-repeat}.bzp-profile-brain{position:relative;overflow:hidden;background:#07111e;cursor:grab}.bzp-profile-brain canvas{position:relative;z-index:1;display:block;width:100%;height:100%}.bzp-profile-brain-visual{position:absolute;inset:0;z-index:0;overflow:hidden;background:radial-gradient(circle at 52% 50%,rgba(28,100,126,.52),transparent 52%)}.bzp-profile-brain-core{position:absolute;left:50%;top:50%;display:flex;width:94px;height:94px;align-items:center;justify-content:center;transform:translate(-50%,-50%);border:1px solid rgba(170,242,247,.9);border-radius:50%;background:rgba(17,65,88,.92);box-shadow:0 0 0 12px rgba(45,212,191,.08),0 0 38px rgba(45,212,191,.75);color:#d9ffff;font-size:12px;font-weight:800;letter-spacing:.16em;line-height:1.35;text-align:center}.bzp-profile-brain-core:before,.bzp-profile-brain-core:after{position:absolute;inset:-18px;border:1px solid rgba(93,220,224,.28);border-radius:50%;content:""}.bzp-profile-brain-core:after{inset:-34px;border-color:rgba(93,220,224,.14)}.bzp-profile-brain-node{position:absolute;width:10px;height:10px;border:2px solid #d5ffff;border-radius:50%;background:#54d8dd;box-shadow:0 0 0 7px rgba(84,216,221,.12),0 0 18px rgba(84,216,221,.92)}.bzp-profile-brain-node:after{position:absolute;left:50%;top:50%;width:110px;height:1px;transform:rotate(var(--angle));transform-origin:left center;background:linear-gradient(90deg,rgba(125,233,235,.68),transparent);content:""}.bzp-profile-brain-node.node-a{left:18%;top:28%;--angle:25deg}.bzp-profile-brain-node.node-b{left:80%;top:22%;--angle:145deg}.bzp-profile-brain-node.node-c{left:12%;top:70%;--angle:-20deg}.bzp-profile-brain-node.node-d{left:84%;top:68%;--angle:198deg}.bzp-profile-brain-node.node-e{left:36%;top:15%;--angle:78deg}.bzp-profile-brain-node.node-f{left:65%;top:82%;--angle:238deg}.bzp-profile-body{position:relative;padding:72px 28px 28px}.bzp-profile-avatar{position:absolute;top:-44px;left:28px;width:92px;height:92px;border:5px solid #fff;border-radius:50%;object-fit:cover;background:#fff;box-shadow:0 8px 22px rgba(23,32,51,.18)}.bzp-profile-card h1{margin:0;font-size:30px;line-height:1.1;letter-spacing:0}.bzp-profile-role{margin:8px 0 0;color:#68758b;font-size:15px}.bzp-profile-bio{margin:20px 0;color:#4b5870;line-height:1.7}.bzp-profile-contacts{display:grid;gap:8px;margin-top:22px}.bzp-profile-row{display:flex;justify-content:space-between;gap:16px;padding:14px 16px;border:1px solid #edf0f5;border-radius:14px;color:inherit;text-decoration:none;background:#fbfcfe}.bzp-profile-row:hover{border-color:#b8c7e5;background:#f5f8ff}.bzp-profile-row-label{color:#7a879b;font-size:13px}.bzp-profile-row strong{font-size:14px;text-align:right;overflow-wrap:anywhere}.bzp-profile-socials{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}.bzp-profile-social{padding:9px 12px;border-radius:999px;background:#eef3fb;color:#35578f;font-size:13px;text-decoration:none}.bzp-profile-chat-entrypoints{display:grid;gap:10px;margin-top:22px}.bzp-profile-chat-mount{min-height:4px}.bzp-profile-channel-link{display:flex;align-items:center;justify-content:center;padding:13px 16px;border:1px solid #dce2eb;border-radius:14px;color:#172033;text-decoration:none;background:#fff;font-weight:700}.bzp-profile-channel-link:hover{border-color:#8bd5d8;background:#f3fbfb}@media (prefers-reduced-motion:reduce){.bzp-profile-brain canvas{opacity:.9}}</style>';
		$brain_script = 'brain' === $hero_style ? '<script>(function(){function mount(){document.querySelectorAll("[data-profile-brain]").forEach(function(hero){var canvas=hero.querySelector("canvas"),ctx=canvas&&canvas.getContext("2d");if(!ctx)return;var accent=hero.getAttribute("data-brain-accent")||"#2dd4bf";var reduced=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches;var scale=reduced?0.15:1;var nodes=[],width=0,height=0,drag=null,pointer={x:0,y:0,active:false};function resize(){var rect=hero.getBoundingClientRect(),ratio=Math.min(window.devicePixelRatio||1,2);width=Math.max(1,rect.width);height=Math.max(1,rect.height);canvas.width=width*ratio;canvas.height=height*ratio;ctx.setTransform(ratio,0,0,ratio,0,0);if(!nodes.length){for(var i=0;i<30;i++){nodes.push({x:Math.random()*width,y:Math.random()*height,vx:(Math.random()-0.5)*0.25,vy:(Math.random()-0.5)*0.25,r:1.6+Math.random()*3,phase:Math.random()*6.28});}}}function hitNode(px,py){var found=null,best=324;nodes.forEach(function(n){var dx=n.x-px,dy=n.y-py,d=dx*dx+dy*dy;if(d<best){best=d;found=n;}});return found;}function toLocal(evt){var rect=canvas.getBoundingClientRect();var t=evt.touches&&evt.touches[0];return {x:(t?t.clientX:evt.clientX)-rect.left,y:(t?t.clientY:evt.clientY)-rect.top};}function onDown(evt){var p=toLocal(evt);drag=hitNode(p.x,p.y);if(drag){hero.style.cursor="grabbing";evt.preventDefault();}}function onMove(evt){var p=toLocal(evt);pointer.x=p.x;pointer.y=p.y;pointer.active=true;if(drag){drag.x=p.x;drag.y=p.y;drag.vx=0;drag.vy=0;evt.preventDefault();}}function onUp(){drag=null;hero.style.cursor="grab";}function onLeave(){pointer.active=false;drag=null;hero.style.cursor="grab";}canvas.addEventListener("pointerdown",onDown);canvas.addEventListener("pointermove",onMove);window.addEventListener("pointerup",onUp);canvas.addEventListener("pointerleave",onLeave);var frame=0;function step(node){if(node===drag)return;node.x+=node.vx*scale;node.y+=node.vy*scale;if(pointer.active){var dx=pointer.x-node.x,dy=pointer.y-node.y,dist=Math.sqrt(dx*dx+dy*dy)||1;if(dist<140){node.vx+=(dx/dist)*0.012;node.vy+=(dy/dist)*0.012;}}node.vx+=Math.sin(frame*0.01+node.phase)*0.002;node.vy+=Math.cos(frame*0.011+node.phase)*0.002;node.vx*=0.985;node.vy*=0.985;if(node.x<0||node.x>width)node.vx*=-1;if(node.y<0||node.y>height)node.vy*=-1;node.x=Math.max(0,Math.min(width,node.x));node.y=Math.max(0,Math.min(height,node.y));}function draw(){ctx.clearRect(0,0,width,height);var cx=width*0.52,cy=height*0.5,glow=ctx.createRadialGradient(cx,cy,0,cx,cy,Math.max(width,height)*0.62);glow.addColorStop(0,"rgba(35,89,122,.42)");glow.addColorStop(1,"rgba(7,17,30,0)");ctx.fillStyle=glow;ctx.fillRect(0,0,width,height);nodes.forEach(step);nodes.forEach(function(a,i){nodes.slice(i+1).forEach(function(b){var dx=a.x-b.x,dy=a.y-b.y,d=Math.sqrt(dx*dx+dy*dy);if(d<112){ctx.strokeStyle="rgba(83,190,205,"+(0.24*(1-d/112))+")";ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(a.x,a.y);ctx.lineTo(b.x,b.y);ctx.stroke();}});});nodes.forEach(function(node){ctx.fillStyle=accent;ctx.globalAlpha=.3;ctx.beginPath();ctx.arc(node.x,node.y,node.r*3.4,0,Math.PI*2);ctx.fill();ctx.globalAlpha=.9;ctx.beginPath();ctx.arc(node.x,node.y,node.r,0,Math.PI*2);ctx.fill();});ctx.globalAlpha=1;frame++;requestAnimationFrame(draw);}resize();window.addEventListener("resize",resize,{passive:true});draw();});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",mount,{once:true});}else{mount();}})();</script>' : '';
		$track_url = function_exists( 'rest_url' ) ? esc_url( rest_url( 'bizcity-profile/v1/profile/track' ) ) : '';
		$track_script = '<script>(function(){var c=' . (int) $card_id . ',u=' . wp_json_encode( $track_url ) . ';if(!c||!u)return;function send(e,m){var b=JSON.stringify({card_id:c,event_type:e,meta:m||{}});if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:"application/json"}));}else{fetch(u,{method:"POST",headers:{"Content-Type":"application/json"},body:b,keepalive:true});}}document.addEventListener("DOMContentLoaded",function(){send("view");document.querySelectorAll("[data-profile-event]").forEach(function(el){el.addEventListener("click",function(){send(el.getAttribute("data-profile-event"),{social:el.getAttribute("data-social")||"",channel_code:el.getAttribute("data-channel-code")||"",tracking_tag:el.getAttribute("data-tracking-tag")||""});});});});})();</script>';
		// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — record share-cohort chat/contact milestones without storing visitor PII in the browser.
		$funnel_script = '<script>(function(){var c=' . (int) $card_id . ',u=' . wp_json_encode( $track_url ) . ',flag="__bzpProfileFunnelBound_"+c;if(!c||!u||window[flag])return;window[flag]=true;var sent={};function send(e){if(sent[e])return;sent[e]=true;var b=JSON.stringify({card_id:c,event_type:e,meta:{funnel:"share"}});if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:"application/json"}));}else{fetch(u,{method:"POST",headers:{"Content-Type":"application/json"},body:b,keepalive:true});}}document.addEventListener("click",function(event){var target=event.target&&event.target.closest?event.target.closest(".bzp-profile-chat-launch"):null;if(target)send("chat_open");});function contact(form){if(form&&form.closest&&form.closest("[data-bzpb-profile-card-id=\\""+c+"\\"]"))send("contact_submitted");}document.addEventListener("bzpbcontactsuccess",function(event){contact(event.detail&&event.detail.form);});document.addEventListener("wpcf7mailsent",function(event){contact(event.detail&&event.detail.contactForm);});function embedded(){var panel=document.querySelector("[data-profile-card-id=\\""+c+"\\"] .profile_embed .bzp-profile-chat-panel");if(panel)send("chat_open");}function afterDom(){setTimeout(embedded,0);}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",afterDom,{once:true});}else{afterDom();}})();</script>';
		$chat_url = function_exists( 'rest_url' ) ? esc_url( rest_url( 'bizcity-profile/v1/profile/chat/turn' ) ) : '';
		$context_url = function_exists( 'rest_url' ) ? esc_url( rest_url( 'bizcity-profile/v1/profile/cards/' . $card_id . '/channel-context' ) ) : '';
		$chat_script = '<script>(function(){var script=document.currentScript;function boot(){var root=script&&script.parentNode;var card=' . (int) $card_id . ',ctxUrl=' . wp_json_encode( $context_url ) . ',chatUrl=' . wp_json_encode( $chat_url ) . ';if(!root||!card||!ctxUrl||!chatUrl)return;root.querySelectorAll(".bzp-profile-chat-mount[data-channel-code=webchat]").forEach(function(mount){var presentation=mount.getAttribute("data-presentation");if(presentation!=="profile_float"&&presentation!=="profile_embed")return;var state={context:null,session:"",busy:false};var shell=document.createElement("div");shell.className="bzp-profile-chat-shell "+presentation;shell.innerHTML="<button type=button class=bzp-profile-chat-launch>Chat với tôi</button><div class=bzp-profile-chat-panel><div class=bzp-profile-chat-head><strong>Trợ lý Profile</strong><button type=button class=bzp-profile-chat-close aria-label=Đóng>×</button></div><div class=bzp-profile-chat-messages></div><form class=bzp-profile-chat-form><input class=bzp-profile-chat-input placeholder=Nhập tin nhắn... autocomplete=off><button type=submit>Gửi</button></form></div>";mount.appendChild(shell);var panel=shell.querySelector(".bzp-profile-chat-panel"),launch=shell.querySelector(".bzp-profile-chat-launch"),close=shell.querySelector(".bzp-profile-chat-close"),messages=shell.querySelector(".bzp-profile-chat-messages"),form=shell.querySelector(".bzp-profile-chat-form"),input=shell.querySelector(".bzp-profile-chat-input");if(presentation==="profile_embed"){launch.style.display="none";panel.classList.add("is-open");}function add(text,kind){var row=document.createElement("div");row.className="bzp-profile-chat-msg "+kind;row.textContent=text;messages.appendChild(row);messages.scrollTop=messages.scrollHeight;}function open(){panel.classList.add("is-open");if(!state.context){fetch(ctxUrl+"?channel_code=webchat&presentation="+encodeURIComponent(presentation)).then(function(r){return r.json()}).then(function(d){if(!d.success)throw new Error("context");state.context=d.context;add("Xin chào, tôi có thể hỗ trợ gì cho bạn?","bot");}).catch(function(){add("Kênh chat chưa sẵn sàng. Vui lòng thử lại sau.","error");});}input.focus();fetch(' . wp_json_encode( $track_url ) . ',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({card_id:card,event_type:"click_message_app",meta:{channel_code:"webchat",presentation:presentation,tracking_tag:(state.context&&state.context.tracking_tag)||""}})}).catch(function(){});}launch.addEventListener("click",open);close.addEventListener("click",function(){panel.classList.remove("is-open")});form.addEventListener("submit",function(e){e.preventDefault();var text=input.value.trim();if(!text||state.busy||!state.context)return;state.busy=true;input.value="";add(text,"user");fetch(chatUrl,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({card_id:card,channel_code:"webchat",presentation:presentation,context_token:state.context.context_token,session_id:state.session,message:text})}).then(function(r){return r.json()}).then(function(d){if(!d.success)throw new Error("chat");state.session=d.session_id||state.session;add(d.answer||"Tôi chưa có câu trả lời phù hợp.","bot");}).catch(function(){add("Không thể kết nối lúc này. Vui lòng thử lại.","error");}).finally(function(){state.busy=false;input.focus()});});if(presentation==="profile_embed")open();});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",boot,{once:true});}else{boot();}})();</script>';
		$chat_css = '<style>.bzp-profile-chat-shell{font-family:Inter,ui-sans-serif,system-ui,sans-serif}.bzp-profile-chat-launch{border:0;border-radius:999px;background:#172033;color:#fff;padding:12px 18px;font-weight:700;cursor:pointer;box-shadow:0 10px 24px rgba(23,32,51,.18)}.bzp-profile-chat-panel{display:none;width:min(360px,calc(100vw - 40px));background:#fff;border:1px solid #e7eaf0;border-radius:18px;box-shadow:0 18px 50px rgba(23,32,51,.18);overflow:hidden}.profile_float.bzp-profile-chat-shell{position:fixed;right:20px;bottom:20px;z-index:9999}.profile_float .bzp-profile-chat-panel{position:absolute;right:0;bottom:calc(100% + 12px)}.bzp-profile-chat-panel.is-open{display:block}.bzp-profile-chat-head{display:flex;justify-content:space-between;align-items:center;padding:13px 15px;border-bottom:1px solid #edf0f5;color:#172033}.bzp-profile-chat-close{border:0;background:transparent;font-size:22px;color:#68758b;cursor:pointer}.bzp-profile-chat-messages{height:260px;overflow:auto;padding:14px;background:#f7f9fc;display:grid;align-content:start;gap:8px}.bzp-profile-chat-msg{max-width:86%;padding:9px 11px;border-radius:12px;font-size:13px;line-height:1.5;white-space:pre-wrap}.bzp-profile-chat-msg.user{justify-self:end;background:#172033;color:#fff}.bzp-profile-chat-msg.bot{justify-self:start;background:#fff;color:#172033;border:1px solid #e7eaf0}.bzp-profile-chat-msg.error{justify-self:start;background:#fff1f2;color:#9f1239}.bzp-profile-chat-form{display:flex;gap:8px;padding:11px;border-top:1px solid #edf0f5}.bzp-profile-chat-input{min-width:0;flex:1;border:1px solid #dce2eb;border-radius:10px;padding:9px 10px;font:inherit;font-size:13px}.bzp-profile-chat-form button{border:0;border-radius:10px;background:#d59621;color:#172033;padding:0 12px;font-weight:700;cursor:pointer}.profile_embed .bzp-profile-chat-panel{width:100%;box-shadow:none}.profile_embed .bzp-profile-chat-messages{height:300px}</style>';
		// [2026-08-25 Johnny Chu] PHASE-PROFILE-QUICK-INTRO — separate public quick-intro chips with a stable responsive gap.
		return $profile_react_assets . $css . $brain_script . $chat_css . $track_script . $funnel_script . $chat_script . $chat_prompt_script . '<section class="bzp-profile-card" data-profile-card-id="' . $card_id . '">' . $hero_html . '<div class="bzp-profile-body">' . $avatar_html . '<h1>' . $name . '</h1><p class="bzp-profile-role">' . $job_title . ( $company ? ' · ' . $company : '' ) . '</p>' . ( $bio ? '<p class="bzp-profile-bio">' . $bio . '</p>' : '' ) . ( $capabilities_html ? '<div class="bzp-profile-capabilities" aria-label="5 điều giới thiệu nhanh" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:18px">' . $capabilities_html . '</div>' : '' ) . '<div class="bzp-profile-contacts">' . $contact_html . '</div>' . ( $social_html ? '<div class="bzp-profile-socials">' . $social_html . '</div>' : '' ) . ( $chat_html ? '<div class="bzp-profile-chat-entrypoints">' . $chat_html . '</div>' : '' ) . $gift_html . '</div></section>';
	}

	private static function render_navbar( array $p ): string {
		$logo  = esc_html( $p['logo'] ?? 'Site' );
		$cta   = esc_html( $p['ctaText'] ?? '' );
		$links = '';
		foreach ( ( $p['links'] ?? [] ) as $link ) {
			// Backward-compat: old format is string, new format is {label, href}
			if ( is_string( $link ) ) {
				$label = esc_html( $link );
				$href  = '#';
			} else {
				$label = esc_html( $link['label'] ?? '' );
				$raw_href = $link['href'] ?? '#';
				// Only use real URLs; fallback to '#' for auto-anchor resolution via JS
				$href = ( $raw_href && $raw_href !== '#' ) ? esc_url( $raw_href ) : '#';
			}
			$links .= '<a href="' . $href . '" style="color:var(--text1);text-decoration:none;margin:0 16px;">' . $label . '</a>';
		}
		$cta_html = $cta ? '<a href="#" class="btn-primary" style="font-size:14px;padding:8px 18px;">' . $cta . '</a>' : '';

		return <<<HTML
<nav style="background:var(--bg1);border-bottom:1px solid var(--border);padding:16px 24px;">
  <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;">
    <strong style="font-family:var(--font-display);font-size:20px;">{$logo}</strong>
    <div style="display:flex;align-items:center;">{$links}{$cta_html}</div>
  </div>
</nav>
HTML;
	}

	private static function render_hero( array $p, string $variant ): string {
		$badge    = ! empty( $p['badge'] ) ? '<span style="display:inline-block;background:var(--bg1);border:1px solid var(--border);padding:4px 12px;border-radius:999px;font-size:13px;color:var(--text1);margin-bottom:16px;">' . esc_html( $p['badge'] ) . '</span><br>' : '';
		$headline = esc_html( $p['headline'] ?? '' );
		$sub      = esc_html( $p['subheadline'] ?? '' );
		$primary  = ! empty( $p['primaryCta'] ) ? '<a href="#" class="btn-primary">' . esc_html( $p['primaryCta'] ) . '</a>' : '';
		$secondary = ! empty( $p['secondaryCta'] ) ? ' <a href="#" class="btn-secondary" style="margin-left:12px;">' . esc_html( $p['secondaryCta'] ) . '</a>' : '';

		return <<<HTML
<section class="section" style="text-align:center;padding-top:100px;padding-bottom:100px;">
  {$badge}
  <h1 style="font-size:clamp(2rem,5vw,3.5rem);font-weight:700;margin:0 0 16px;line-height:1.1;">{$headline}</h1>
  <p style="font-size:1.2rem;color:var(--text1);max-width:600px;margin:0 auto 32px;">{$sub}</p>
  <div>{$primary}{$secondary}</div>
</section>
HTML;
	}

	private static function render_features( array $p, string $variant ): string {
		$title    = esc_html( $p['title'] ?? '' );
		$subtitle = ! empty( $p['subtitle'] ) ? '<p style="color:var(--text1);font-size:1.1rem;margin-top:8px;">' . esc_html( $p['subtitle'] ) . '</p>' : '';
		if ( 'timeline' === $variant ) {
			$items = '';
			foreach ( array_slice( (array) ( $p['items'] ?? array() ), 0, 12 ) as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$item_title = esc_html( (string) ( $item['title'] ?? '' ) );
				$period = esc_html( (string) ( $item['period'] ?? '' ) );
				$description = esc_html( (string) ( $item['description'] ?? '' ) );
				if ( '' === $item_title && '' === $description ) { continue; }
				$items .= '<li style="position:relative;margin:0 0 24px;padding:0 0 0 24px;border-left:1px solid var(--accent);"><span style="position:absolute;left:-5px;top:3px;width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px var(--accentDim);"></span><h3 style="margin:0;font-size:16px;font-weight:600;color:var(--text0);">' . $item_title . '</h3>' . ( $period ? '<div style="margin-top:4px;color:var(--accent);font-size:12px;font-weight:600;">' . $period . '</div>' : '' ) . ( $description ? '<p style="margin:8px 0 0;color:var(--text1);font-size:13px;line-height:1.7;">' . $description . '</p>' : '' ) . '</li>';
			}
			return '<section class="section"><div style="text-align:center;margin-bottom:32px;"><h2 style="font-size:2rem;font-weight:700;margin:0;">' . $title . '</h2>' . $subtitle . '</div><ol style="max-width:720px;margin:0 auto;padding:8px 0 0 12px;list-style:none;">' . $items . '</ol></section>';
		}
		$items    = '';
		foreach ( ( $p['items'] ?? [] ) as $item ) {
			$icon = esc_html( $item['icon'] ?? '✦' );
			$t    = esc_html( $item['title'] ?? '' );
			$d    = esc_html( $item['description'] ?? '' );
			$items .= <<<ITEM
<div class="card">
  <div style="font-size:24px;margin-bottom:12px;color:var(--accent);">{$icon}</div>
  <h3 style="font-weight:600;margin:0 0 8px;">{$t}</h3>
  <p style="color:var(--text1);font-size:14px;margin:0;">{$d}</p>
</div>
ITEM;
		}

		$cols = $variant === 'list' ? '1' : '3';
		return <<<HTML
<section class="section">
  <div style="text-align:center;margin-bottom:48px;">
    <h2 style="font-size:2rem;font-weight:700;margin:0;">{$title}</h2>
    {$subtitle}
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:24px;">
    {$items}
  </div>
</section>
HTML;
	}

	private static function render_pricing( array $p, string $variant = 'simple' ): string {
		$title    = esc_html( $p['title'] ?? 'Pricing' );
		$subtitle = ! empty( $p['subtitle'] ) ? '<p style="color:var(--text1);font-size:15px;margin:8px 0 0;">' . esc_html( $p['subtitle'] ) . '</p>' : '';

		// Support both 'tiers' (new key) and 'plans' (legacy AI key)
		$tiers_raw = $p['tiers'] ?? $p['plans'] ?? [];

		// Parse features: can be array or comma-separated string
		$parse_features = static function( $features ): array {
			if ( is_array( $features ) ) return $features;
			if ( is_string( $features ) ) return array_values( array_filter( array_map( 'trim', explode( ',', $features ) ) ) );
			return [];
		};

		if ( $variant === 'comparison' && ! empty( $tiers_raw ) ) {
			// ── Comparison table ──────────────────────────────────────────────
			$all_features = [];
			foreach ( $tiers_raw as $tier ) {
				foreach ( $parse_features( $tier['features'] ?? [] ) as $f ) {
					if ( ! in_array( $f, $all_features, true ) ) $all_features[] = $f;
				}
			}

			// Header: name + price per tier
			$header_cells = '<th style="padding:12px 16px;color:var(--text1);font-weight:500;font-size:13px;">Feature</th>';
			foreach ( $tiers_raw as $tier ) {
				$is_featured = ! empty( $tier['featured'] ) || ! empty( $tier['highlighted'] );
				$name   = esc_html( $tier['name'] ?? '' );
				$price  = esc_html( $tier['price'] ?? '' );
				$period = ! empty( $tier['period'] ) ? '<div style="font-size:11px;color:var(--text1);font-weight:400;margin-top:2px;">' . esc_html( $tier['period'] ) . '</div>' : '';
				$color  = $is_featured ? 'color:var(--accent);' : 'color:var(--text0);';
				$header_cells .= '<th style="padding:12px 16px;text-align:center;font-weight:600;' . $color . '">'
					. $name
					. '<div style="font-size:1.5rem;font-weight:700;margin-top:4px;' . $color . '">' . $price . '</div>'
					. $period
					. '</th>';
			}

			// Body rows
			$body_rows = '';
			foreach ( $all_features as $i => $feature ) {
				$row_bg = ( $i % 2 === 0 ) ? '' : 'background:var(--bg1);';
				$cells  = '<td style="padding:10px 16px;color:var(--text1);font-size:13px;' . $row_bg . '">' . esc_html( $feature ) . '</td>';
				foreach ( $tiers_raw as $tier ) {
					$tier_features = $parse_features( $tier['features'] ?? [] );
					$has = in_array( $feature, $tier_features, true );
					$cells .= $has
						? '<td style="padding:10px 16px;text-align:center;color:var(--accent);font-size:15px;' . $row_bg . '">&#10003;</td>'
						: '<td style="padding:10px 16px;text-align:center;color:var(--text1);opacity:.4;' . $row_bg . '">&#8212;</td>';
				}
				$body_rows .= '<tr style="border-bottom:1px solid var(--border-subtle);">' . $cells . '</tr>';
			}

			return <<<HTML
<section class="section">
  <div style="text-align:center;margin-bottom:48px;">
    <h2 style="font-size:2rem;font-weight:700;margin:0;">{$title}</h2>
    {$subtitle}
  </div>
  <div style="overflow-x:auto;border-radius:var(--radius-lg);border:1px solid var(--border);">
    <table style="width:100%;border-collapse:collapse;text-align:left;">
      <thead>
        <tr style="border-bottom:2px solid var(--border);background:var(--bg1);">{$header_cells}</tr>
      </thead>
      <tbody>{$body_rows}</tbody>
    </table>
  </div>
</section>
HTML;
		}

		// ── Simple card layout ──────────────────────────────────────────────────
		$cards = '';
		foreach ( $tiers_raw as $tier ) {
			$is_featured  = ! empty( $tier['featured'] ) || ! empty( $tier['highlighted'] );
			$name         = esc_html( $tier['name'] ?? '' );
			$price        = esc_html( $tier['price'] ?? '' );
			$period       = esc_html( $tier['period'] ?? '' );
			$desc         = esc_html( $tier['description'] ?? '' );
			$cta          = esc_html( $tier['cta'] ?? 'Get Started' );

			$border_style = $is_featured
				? 'border:2px solid var(--accent);'
				: 'border:1px solid var(--border);';
			$btn_style = $is_featured
				? 'background:var(--accent);color:var(--bg0);border:none;'
				: 'background:transparent;color:var(--text0);border:1px solid var(--border);';
			$featured_badge = $is_featured
				? '<div style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);white-space:nowrap;padding:2px 12px;border-radius:999px;background:var(--accent);color:var(--bg0);font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;">&#11088; Recommended</div>'
				: '';
			$desc_html = $desc
				? '<p style="font-size:13px;color:var(--text1);margin:0 0 16px;min-height:2.4em;">' . $desc . '</p>'
				: '<p style="margin:0 0 16px;min-height:2.4em;"></p>';
			$period_html = $period
				? '<span style="font-size:13px;color:var(--text1);margin-left:2px;">' . $period . '</span>'
				: '';

			$features = '';
			foreach ( $parse_features( $tier['features'] ?? [] ) as $f ) {
				$features .= '<li style="display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--text1);padding:5px 0;list-style:none;">'
					. '<span style="color:var(--accent);flex-shrink:0;font-size:15px;line-height:1.2;">&#10003;</span>'
					. esc_html( $f )
					. '</li>';
			}

			$cards .= <<<CARD
<div style="position:relative;border-radius:var(--radius-lg);padding:24px;display:flex;flex-direction:column;background:var(--bg1);{$border_style}">
  {$featured_badge}
  <div style="margin-bottom:4px;font-size:15px;font-weight:600;color:var(--text0);">{$name}</div>
  {$desc_html}
  <div style="margin-bottom:20px;">
    <span style="font-size:2.2rem;font-weight:700;color:var(--text0);">{$price}</span>
    {$period_html}
  </div>
  <ul style="list-style:none;padding:0;margin:0 0 20px;flex:1;">{$features}</ul>
  <a href="#" style="display:block;text-align:center;padding:11px 0;border-radius:var(--radius);font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;transition:opacity .2s;{$btn_style}">{$cta}</a>
</div>
CARD;
		}

		return <<<HTML
<section class="section">
  <div style="text-align:center;margin-bottom:48px;">
    <h2 style="font-size:2rem;font-weight:700;margin:0;">{$title}</h2>
    {$subtitle}
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;max-width:980px;margin:0 auto;">
    {$cards}
  </div>
</section>
HTML;
	}

	private static function render_cta( array $p ): string {
		$title   = esc_html( $p['title'] ?? '' );
		$desc    = esc_html( $p['description'] ?? '' );
		$primary = ! empty( $p['primaryCta'] ) ? '<a href="#" class="btn-primary">' . esc_html( $p['primaryCta'] ) . '</a>' : '';
		$secondary = ! empty( $p['secondaryCta'] ) ? ' <a href="#" class="btn-secondary" style="margin-left:12px;">' . esc_html( $p['secondaryCta'] ) . '</a>' : '';

		return <<<HTML
<section class="section" style="text-align:center;background:var(--bg1);border-radius:var(--radius);margin:40px auto;padding:60px 24px;">
  <h2 style="font-size:2rem;font-weight:700;margin:0 0 12px;">{$title}</h2>
  <p style="color:var(--text1);margin:0 0 32px;max-width:500px;margin-left:auto;margin-right:auto;">{$desc}</p>
  <div>{$primary}{$secondary}</div>
</section>
HTML;
	}

	private static function render_footer( array $p, string $variant ): string {
		$copyright = esc_html( $p['copyright'] ?? '' );
		$logo      = ! empty( $p['logo'] ) ? '<strong style="font-family:var(--font-display);font-size:18px;">' . esc_html( $p['logo'] ) . '</strong>' : '';

		$columns = '';
		if ( $variant === 'multi-column' && ! empty( $p['columns'] ) ) {
			foreach ( $p['columns'] as $col ) {
				$title = esc_html( $col['title'] ?? '' );
				$links = '';
				foreach ( ( $col['links'] ?? [] ) as $link ) {
					$links .= '<a href="#" style="display:block;color:var(--text1);text-decoration:none;padding:4px 0;font-size:14px;">' . esc_html( $link ) . '</a>';
				}
				$columns .= '<div><h4 style="font-weight:600;margin:0 0 12px;font-size:14px;">' . $title . '</h4>' . $links . '</div>';
			}
		}

		return <<<HTML
<footer style="background:var(--bg1);border-top:1px solid var(--border);padding:48px 24px 24px;">
  <div style="max-width:1200px;margin:0 auto;">
    {$logo}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:32px;margin:24px 0;">
      {$columns}
    </div>
    <div style="border-top:1px solid var(--border);padding-top:24px;color:var(--text1);font-size:13px;text-align:center;">
      {$copyright}
    </div>
  </div>
</footer>
HTML;
	}

	private static function render_testimonials( array $p ): string {
		$title = ! empty( $p['title'] ) ? '<h2 style="font-size:2rem;font-weight:700;margin:0 0 48px;text-align:center;">' . esc_html( $p['title'] ) . '</h2>' : '';
		$items = '';
		foreach ( ( $p['items'] ?? [] ) as $item ) {
			$quote  = esc_html( $item['quote'] ?? '' );
			$author = esc_html( $item['author'] ?? '' );
			$role   = ! empty( $item['role'] ) ? '<span style="color:var(--text1);font-size:13px;"> — ' . esc_html( $item['role'] ) . '</span>' : '';
			$rating = '';
			if ( ! empty( $item['rating'] ) ) {
				$rating = '<div style="margin-bottom:8px;">' . str_repeat( '★', (int) $item['rating'] ) . '</div>';
			}
			$items .= <<<ITEM
<div class="card">
  {$rating}
  <p style="font-style:italic;margin:0 0 16px;">"{$quote}"</p>
  <strong>{$author}</strong>{$role}
</div>
ITEM;
		}

		return <<<HTML
<section class="section">
  {$title}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;">
    {$items}
  </div>
</section>
HTML;
	}

	private static function render_stats( array $p, string $variant = 'default' ): string {
		$title = ! empty( $p['title'] ) ? '<h2 style="font-size:2rem;font-weight:700;margin:0 0 48px;text-align:center;">' . esc_html( $p['title'] ) . '</h2>' : '';
		if ( 'progress' === $variant ) {
			$items = '';
			foreach ( array_slice( (array) ( $p['items'] ?? array() ), 0, 12 ) as $item ) {
				if ( ! is_array( $item ) ) { continue; }
				$label = esc_html( (string) ( $item['label'] ?? '' ) );
				$value = max( 0, min( 100, (int) ( $item['value'] ?? 0 ) ) );
				if ( '' === $label ) { continue; }
				$items .= '<div style="margin:0 0 18px;"><div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:7px;color:var(--text0);font-size:13px;font-weight:600;"><span>' . $label . '</span><span style="color:var(--accent);">' . $value . '%</span></div><div style="height:7px;border-radius:999px;background:var(--bg3,var(--border));overflow:hidden;"><div style="width:' . $value . '%;height:100%;border-radius:inherit;background:var(--accent);"></div></div></div>';
			}
			return '<section class="section"><div style="text-align:center;margin-bottom:32px;">' . $title . '</div><div style="max-width:720px;margin:0 auto;padding:20px 22px;background:var(--bg1);border:1px solid var(--border);border-radius:var(--radius);">' . $items . '</div></section>';
		}
		$items = '';
		foreach ( ( $p['items'] ?? [] ) as $item ) {
			$value  = esc_html( $item['value'] ?? '' );
			$label  = esc_html( $item['label'] ?? '' );
			$suffix = esc_html( $item['suffix'] ?? '' );
			$items .= '<div style="text-align:center;"><div style="font-size:2.5rem;font-weight:700;color:var(--accent);">' . $value . $suffix . '</div><div style="color:var(--text1);margin-top:8px;">' . $label . '</div></div>';
		}

		return <<<HTML
<section class="section" style="background:var(--bg1);border-radius:var(--radius);margin:40px auto;">
  {$title}
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:32px;">
    {$items}
  </div>
</section>
HTML;
	}

	private static function render_faq( array $p ): string {
		$title = ! empty( $p['title'] ) ? '<h2 style="font-size:2rem;font-weight:700;margin:0 0 48px;text-align:center;">' . esc_html( $p['title'] ) . '</h2>' : '';
		$items = '';
		foreach ( ( $p['items'] ?? [] ) as $item ) {
			$q = esc_html( $item['question'] ?? '' );
			$a = esc_html( $item['answer'] ?? '' );
			$items .= <<<ITEM
<details style="border:1px solid var(--border);border-radius:var(--radius);margin-bottom:8px;">
  <summary style="padding:16px;font-weight:600;display:flex;justify-content:space-between;align-items:center;">
    {$q} <span style="font-size:1.2em;">+</span>
  </summary>
  <div style="padding:0 16px 16px;color:var(--text1);">{$a}</div>
</details>
ITEM;
		}

		return '<section class="section">' . $title . $items . '</section>';
	}

	private static function render_team( array $p ): string {
		$title    = ! empty( $p['title'] ) ? '<h2 style="font-size:2rem;font-weight:700;margin:0;text-align:center;">' . esc_html( $p['title'] ) . '</h2>' : '';
		$subtitle = ! empty( $p['subtitle'] ) ? '<p style="color:var(--text1);text-align:center;margin-top:8px;">' . esc_html( $p['subtitle'] ) . '</p>' : '';
		$members  = '';
		foreach ( ( $p['members'] ?? [] ) as $m ) {
			$name = esc_html( $m['name'] ?? '' );
			$role = esc_html( $m['role'] ?? '' );
			$bio  = ! empty( $m['bio'] ) ? '<p style="color:var(--text1);font-size:13px;margin:8px 0 0;">' . esc_html( $m['bio'] ) . '</p>' : '';
			$members .= '<div class="card" style="text-align:center;"><div style="width:80px;height:80px;border-radius:50%;background:var(--accent);margin:0 auto 16px;opacity:.2;"></div><h4 style="margin:0;font-weight:600;">' . $name . '</h4><div style="color:var(--text1);font-size:14px;">' . $role . '</div>' . $bio . '</div>';
		}

		return <<<HTML
<section class="section">
  <div style="margin-bottom:48px;">{$title}{$subtitle}</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:24px;">
    {$members}
  </div>
</section>
HTML;
	}

	/**
	 * Render lead-form block on published pages.
	 * Option A: CF7 linked (cf7FormId > 0) → do_shortcode.
	 * Option B: native AJAX form → data-bzpb-contact handler.
	 *
	 * [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — PHP render for lead-form block
	 */
	private static function render_lead_form( array $p ): string {
		$title      = esc_html( $p['title'] ?? '' );
		$subtitle   = ! empty( $p['subtitle'] ) ? '<p style="color:var(--text1);margin:0 0 32px;">' . esc_html( $p['subtitle'] ) . '</p>' : '';
		$submit     = esc_html( $p['submitText'] ?? 'Gửi' );
		$cf7_id     = (int) ( $p['cf7FormId'] ?? 0 );
		$profile_card_id = absint( $p['profileCardId'] ?? 0 );
		$profile_card_attr = $profile_card_id > 0 ? ' data-bzpb-profile-card-id="' . $profile_card_id . '"' : '';
		$title_html = $title ? '<h2 style="font-size:1.75rem;font-weight:700;margin:0 0 8px;">' . $title . '</h2>' : '';

		// ── Option A: CF7 form linked + plugin active ─────────────────────
		// [2026-07-02 Johnny Chu] PHASE-PB-LEADFORM — canvas pages never call
		// wp_head()/wp_footer(), so CF7 CSS/JS are never enqueued. Inject a
		// <style> block here so CF7 form elements inherit the theme CSS variables.
		if ( $cf7_id > 0 && class_exists( 'WPCF7_ContactForm' ) ) {
			$form_html  = do_shortcode( '[contact-form-7 id="' . $cf7_id . '"]' );
			$cf7_styles = '
<style data-bzpb-cf7>
/* CF7 form — theme-aware override for canvas pages */
.wpcf7 label {
	display:block; font-size:14px; font-weight:500;
	color:var(--text0); margin-bottom:4px;
}
.wpcf7 .wpcf7-form-control-wrap {
	display:block; margin-bottom:16px;
}
.wpcf7 input[type=text],.wpcf7 input[type=email],.wpcf7 input[type=tel],
.wpcf7 input[type=url],.wpcf7 input[type=number],.wpcf7 input[type=date],
.wpcf7 textarea,.wpcf7 select {
	width:100%; padding:11px 12px;
	border:1px solid var(--border); border-radius:var(--radius);
	background:var(--bg0); color:var(--text0);
	font-size:14px; font-family:inherit; box-sizing:border-box;
	outline:none; transition:border-color .15s;
}
.wpcf7 input[type=text]:focus,.wpcf7 input[type=email]:focus,
.wpcf7 input[type=tel]:focus,.wpcf7 textarea:focus,.wpcf7 select:focus {
	border-color:var(--accent);
}
.wpcf7 textarea { resize:vertical; min-height:120px; }
.wpcf7 input[type=submit],.wpcf7 .wpcf7-submit {
	width:100%; padding:14px; border:none; cursor:pointer;
	background:var(--accent); color:#fff;
	border-radius:var(--radius); font-size:15px; font-weight:700;
	font-family:inherit; margin-top:4px; transition:opacity .15s;
}
.wpcf7 input[type=submit]:hover { opacity:.9; }
.wpcf7 input[type=submit]:disabled { opacity:.6; cursor:not-allowed; }
.wpcf7-response-output {
	margin:16px 0 0; padding:10px 14px;
	border-radius:var(--radius); font-size:13px;
}
.wpcf7-mail-sent-ok { background:#f0fdf4; border:1px solid #22c55e; color:#166534; }
.wpcf7-validation-errors,.wpcf7-mail-sent-ng,.wpcf7-spam-blocked {
	background:#fef2f2; border:1px solid #f87171; color:#991b1b;
}
.wpcf7-not-valid-tip { color:#ef4444; font-size:12px; display:block; margin-top:3px; }
.wpcf7 span.wpcf7-list-item { display:inline-flex; align-items:center; gap:6px; margin-right:12px; }
</style>';
			return $cf7_styles
				. '<section class="section"' . $profile_card_attr . ' style="text-align:center;padding:60px 24px;">'
				. $title_html . $subtitle
				. '<div style="max-width:540px;margin:0 auto;text-align:left;">' . $form_html . '</div>'
				. '</section>';
		}

		// ── Option B: native AJAX form ────────────────────────────────────
		$is         = 'width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg0);color:var(--text0);font-size:14px;font-family:inherit;box-sizing:border-box;';
		$fields_arr = isset( $p['fields'] ) && is_array( $p['fields'] ) ? $p['fields'] : [];
		$rows_html  = '';
		$fi         = 0;
		$count      = count( $fields_arr );
		while ( $fi < $count ) {
			$f     = $fields_arr[ $fi ];
			$width = isset( $f['width'] ) ? $f['width'] : 'full';
			if ( $width === 'half' && isset( $fields_arr[ $fi + 1 ] ) && ( isset( $fields_arr[ $fi + 1 ]['width'] ) ? $fields_arr[ $fi + 1 ]['width'] : '' ) === 'half' ) {
				$rows_html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
					. self::render_lead_form_field( $f, $is )
					. self::render_lead_form_field( $fields_arr[ $fi + 1 ], $is )
					. '</div>';
				$fi += 2;
			} else {
				$rows_html .= self::render_lead_form_field( $f, $is );
				$fi++;
			}
		}
		if ( ! $rows_html ) {
			$rows_html = '<input type="text" name="name" placeholder="Họ tên" style="' . $is . 'margin-bottom:12px;">'
				. '<input type="tel" name="phone" placeholder="Số điện thoại" style="' . $is . 'margin-bottom:12px;">'
				. '<input type="email" name="email" placeholder="Email" style="' . $is . 'margin-bottom:12px;">';
		}

		return <<<HTML
<section class="section"{$profile_card_attr} style="text-align:center;padding:60px 24px;">
  {$title_html}
  {$subtitle}
  <form data-bzpb-contact="1" style="max-width:540px;margin:0 auto;text-align:left;">
    {$rows_html}
    <button type="submit" data-bzpb-submit="1" class="btn-primary" style="width:100%;border:none;cursor:pointer;font-size:15px;font-weight:700;padding:14px;margin-top:4px;">{$submit}</button>
  </form>
</section>
HTML;
	}

	/**
	 * Render a single field for the lead-form block (PHP 7.4 compatible).
	 * [2026-06-27 Johnny Chu] PHASE-PB-LEADFORM — helper for render_lead_form
	 */
	private static function render_lead_form_field( array $f, string $is ): string {
		$label    = esc_html( isset( $f['label'] ) ? $f['label'] : '' );
		$name     = esc_attr( sanitize_key( isset( $f['name'] ) ? $f['name'] : 'field' ) );
		$type     = isset( $f['type'] ) ? $f['type'] : 'text';
		$ph       = esc_attr( isset( $f['placeholder'] ) ? $f['placeholder'] : '' );
		$req      = ! empty( $f['required'] ) ? ' required' : '';
		$req_mark = ! empty( $f['required'] ) ? ' <span style="color:var(--accent,#e11d48);">*</span>' : '';
		$wrap     = 'margin-bottom:14px;';
		$lbl      = '<label style="display:block;font-size:12px;font-weight:500;color:var(--text1);margin-bottom:5px;">' . $label . $req_mark . '</label>';

		switch ( $type ) {
			case 'textarea':
				return '<div style="' . $wrap . '">' . $lbl . '<textarea name="' . $name . '" placeholder="' . $ph . '" rows="4"' . $req . ' style="' . $is . 'resize:vertical;"></textarea></div>';
			case 'select':
				$opts_raw  = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : [];
				$opts_html = '<option value="" disabled selected>' . esc_html( $ph ?: 'Chọn...' ) . '</option>';
				foreach ( $opts_raw as $o ) {
					$opts_html .= '<option>' . esc_html( (string) $o ) . '</option>';
				}
				return '<div style="' . $wrap . '">' . $lbl . '<select name="' . $name . '"' . $req . ' style="' . $is . 'cursor:pointer;">' . $opts_html . '</select></div>';
			case 'checkbox':
				return '<div style="' . $wrap . 'display:flex;align-items:flex-start;gap:8px;"><input type="checkbox" name="' . $name . '"' . $req . ' style="margin-top:3px;width:16px;height:16px;accent-color:var(--accent);flex-shrink:0;"><label style="font-size:13px;color:var(--text1);">' . $label . '</label></div>';
			case 'hidden':
				return '<input type="hidden" name="' . $name . '">';
			default:
				return '<div style="' . $wrap . '">' . $lbl . '<input type="' . esc_attr( $type ) . '" name="' . $name . '" placeholder="' . $ph . '"' . $req . ' style="' . $is . '"></div>';
		}
	}

	private static function render_contact( array $p ): string {
		$title    = esc_html( $p['title'] ?? 'Liên hệ' );
		$subtitle = ! empty( $p['subtitle'] ) ? '<p style="color:var(--text1,#6b7280);margin:8px 0 0;">' . esc_html( $p['subtitle'] ) . '</p>' : '';
		$submit   = esc_html( $p['submitText'] ?? 'Gửi' );
		$is      = 'width:100%;padding:12px;border:1px solid var(--border,#d1d5db);border-radius:var(--radius,6px);background:var(--bg0);color:var(--text0);margin-bottom:16px;box-sizing:border-box;font-size:15px;font-family:inherit;';
		$fields   = '';

		foreach ( ( $p['fields'] ?? [ 'Họ tên', 'Email', 'Tin nhắn' ] ) as $f ) {
			$label = esc_html( (string) $f );
			$lc    = mb_strtolower( (string) $f, 'UTF-8' );

			if ( mb_strpos( $lc, 'email' ) !== false || mb_strpos( $lc, 'mail' ) !== false ) {
				$fields .= '<input type="email" name="email" placeholder="' . $label . '" style="' . $is . '">';
			} elseif ( mb_strpos( $lc, 'phone' ) !== false || mb_strpos( $lc, 'điện' ) !== false || mb_strpos( $lc, 'sdt' ) !== false || mb_strpos( $lc, 'tel' ) !== false || mb_strpos( $lc, 'số' ) !== false ) {
				$fields .= '<input type="tel" name="phone" placeholder="' . $label . '" style="' . $is . '">';
			} elseif ( mb_strpos( $lc, 'subject' ) !== false || mb_strpos( $lc, 'chủ đề' ) !== false || mb_strpos( $lc, 'tiêu đề' ) !== false ) {
				$fields .= '<input type="text" name="subject" placeholder="' . $label . '" style="' . $is . '">';
			} elseif ( mb_strpos( $lc, 'message' ) !== false || mb_strpos( $lc, 'nhắn' ) !== false || mb_strpos( $lc, 'nội dung' ) !== false ) {
				$fields .= '<textarea name="message" placeholder="' . $label . '" rows="4" style="' . $is . 'min-height:120px;resize:vertical;"></textarea>';
			} elseif ( mb_strpos( $lc, 'tên' ) !== false || mb_strpos( $lc, 'họ' ) !== false || mb_strpos( $lc, 'name' ) !== false || mb_strpos( $lc, 'full' ) !== false ) {
				// Explicit name field detection — before fallback to avoid sanitize_key() mangling Vietnamese
				$fields .= '<input type="text" name="name" placeholder="' . $label . '" style="' . $is . '">';
			} else {
				// Generic custom field — use name="name" as default since the handler expects it.
				// NOTE: sanitize_key() on Vietnamese text mangles to garbage (e.g. 'Họ tên' → 'htn'),
				// so we must not use it for field names that go to $_POST.
				$fields .= '<input type="text" name="name" placeholder="' . $label . '" style="' . $is . '">';
			}
		}

		return <<<HTML
<section class="section" style="max-width:600px;">
  <div style="text-align:center;margin-bottom:32px;">
    <h2 style="font-size:2rem;font-weight:700;margin:0;">{$title}</h2>
    {$subtitle}
  </div>
  <form data-bzpb-contact="1">
    {$fields}
    <button type="submit" data-bzpb-submit="1" class="btn-primary" style="width:100%;border:none;cursor:pointer;font-size:16px;padding:14px;">{$submit}</button>
  </form>
</section>
HTML;
	}

	private static function render_newsletter( array $p ): string {
		$title       = esc_html( $p['title'] ?? '' );
		$subtitle    = ! empty( $p['subtitle'] ) ? '<p style="color:var(--text1);">' . esc_html( $p['subtitle'] ) . '</p>' : '';
		$placeholder = esc_attr( $p['placeholder'] ?? 'Email' );
		$button      = esc_html( $p['buttonText'] ?? 'Subscribe' );

		return <<<HTML
<section class="section-sm" style="text-align:center;">
  <h2 style="font-size:1.5rem;font-weight:700;margin:0 0 8px;">{$title}</h2>
  {$subtitle}
  <form onsubmit="return false;" style="display:flex;gap:12px;max-width:400px;margin:24px auto 0;">
    <input type="email" placeholder="{$placeholder}" style="flex:1;padding:12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg0);color:var(--text0);">
    <button type="submit" class="btn-primary" style="border:none;cursor:pointer;white-space:nowrap;">{$button}</button>
  </form>
</section>
HTML;
	}

	private static function render_logocloud( array $p ): string {
		$title = ! empty( $p['title'] ) ? '<p style="color:var(--text1);text-align:center;margin-bottom:24px;font-size:14px;">' . esc_html( $p['title'] ) . '</p>' : '';
		$logos = '';
		foreach ( ( $p['logos'] ?? [] ) as $logo ) {
			$logos .= '<span style="color:var(--text1);font-size:14px;font-weight:600;opacity:.6;">' . esc_html( $logo ) . '</span>';
		}

		return <<<HTML
<section class="section-sm">
  {$title}
  <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:32px;align-items:center;">
    {$logos}
  </div>
</section>
HTML;
	}

	private static function render_content( array $p ): string {
		$title = ! empty( $p['title'] ) ? '<h2 style="font-size:2rem;font-weight:700;margin:0 0 24px;">' . esc_html( $p['title'] ) . '</h2>' : '';
		$body  = wp_kses_post( nl2br( $p['body'] ?? '' ) );

		return '<section class="section">' . $title . '<div style="color:var(--text1);line-height:1.8;max-width:720px;margin:0 auto;">' . $body . '</div></section>';
	}

	private static function render_image( array $p, string $variant ): string {
		$caption = ! empty( $p['caption'] ) ? '<p style="color:var(--text1);font-size:13px;text-align:center;margin-top:8px;">' . esc_html( $p['caption'] ) . '</p>' : '';

		if ( $variant === 'grid' && ! empty( $p['images'] ) && is_array( $p['images'] ) ) {
			$images = '';
			foreach ( $p['images'] as $img ) {
				$src = esc_url( $img['src'] ?? '' );
				$alt = esc_attr( $img['alt'] ?? '' );
				$images .= '<img src="' . $src . '" alt="' . $alt . '" style="width:100%;border-radius:var(--radius);object-fit:cover;aspect-ratio:4/3;">';
			}
			return '<section class="section"><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">' . $images . '</div></section>';
		}

		$src = esc_url( $p['src'] ?? '' );
		$alt = esc_attr( $p['alt'] ?? '' );
		return '<section class="section" style="text-align:center;"><img src="' . $src . '" alt="' . $alt . '" style="max-width:100%;border-radius:var(--radius);">' . $caption . '</section>';
	}

	private static function render_video( array $p ): string {
		$url   = $p['url'] ?? '';
		$title = ! empty( $p['title'] ) ? '<h3 style="text-align:center;margin-bottom:16px;font-weight:600;">' . esc_html( $p['title'] ) . '</h3>' : '';

		// Convert YouTube/Vimeo URLs to embed
		$embed = esc_url( $url );
		if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/', $url, $m ) ) {
			$embed = 'https://www.youtube.com/embed/' . $m[1];
		} elseif ( preg_match( '/vimeo\.com\/(\d+)/', $url, $m ) ) {
			$embed = 'https://player.vimeo.com/video/' . $m[1];
		}

		return <<<HTML
<section class="section">
  {$title}
  <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:var(--radius);">
    <iframe src="{$embed}" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe>
  </div>
</section>
HTML;
	}

	private static function render_gallery( array $p, string $variant ): string {
		$title  = ! empty( $p['title'] ) ? '<h2 style="font-size:2rem;font-weight:700;margin:0 0 32px;text-align:center;">' . esc_html( $p['title'] ) . '</h2>' : '';
		$filterable = ! empty( $p['filterable'] );
		$categories = array();
		if ( $filterable ) {
			foreach ( ( $p['images'] ?? array() ) as $image ) {
				$category = sanitize_key( (string) ( $image['category'] ?? '' ) );
				if ( '' !== $category && ! in_array( $category, $categories, true ) ) { $categories[] = $category; }
			}
		}
		$filter_html = '';
		$filter_script = '';
		if ( $filterable && ! empty( $categories ) ) {
			$filter_buttons = '<button type="button" data-profile-gallery-filter="all" aria-pressed="true" style="border:0;border-radius:999px;background:var(--accent);color:var(--bg0);padding:8px 13px;font:600 12px/1.2 inherit;cursor:pointer;">All</button>';
			foreach ( $categories as $category ) {
				$filter_buttons .= '<button type="button" data-profile-gallery-filter="' . esc_attr( $category ) . '" aria-pressed="false" style="border:1px solid var(--border);border-radius:999px;background:transparent;color:var(--text1);padding:8px 13px;font:600 12px/1.2 inherit;cursor:pointer;">' . esc_html( ucwords( str_replace( '-', ' ', $category ) ) ) . '</button>';
			}
			$filter_html = '<div class="bzpb-profile-gallery-filters" style="display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:0 0 22px;">' . $filter_buttons . '</div>';
			$filter_script = '<script>(function(){function init(){document.querySelectorAll("[data-profile-gallery]").forEach(function(root){if(root.__bzpbFilter)return;root.__bzpbFilter=true;var buttons=root.querySelectorAll("[data-profile-gallery-filter]"),items=root.querySelectorAll("[data-profile-gallery-item]");buttons.forEach(function(button){button.addEventListener("click",function(){var selected=button.getAttribute("data-profile-gallery-filter")||"all";buttons.forEach(function(item){var active=item===button;item.setAttribute("aria-pressed",active?"true":"false");item.style.background=active?"var(--accent)":"transparent";item.style.color=active?"var(--bg0)":"var(--text1)";});items.forEach(function(item){var category=item.getAttribute("data-profile-gallery-category")||"";item.hidden=selected!=="all"&&category!==selected;});});});});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init,{once:true});}else{init();}})();</script>';
		}
		$images = '';
		foreach ( ( $p['images'] ?? [] ) as $img ) {
			$src     = esc_url( $img['src'] ?? '' );
			$alt     = esc_attr( $img['alt'] ?? '' );
			$caption = ! empty( $img['caption'] ) ? '<p style="color:var(--text1);font-size:12px;margin-top:4px;">' . esc_html( $img['caption'] ) . '</p>' : '';
			$meta_title = ! empty( $img['title'] ) ? '<strong style="display:block;margin-top:8px;color:var(--text0);font-size:14px;">' . esc_html( $img['title'] ) . '</strong>' : '';
			$meta_date = ! empty( $img['date'] ) ? '<span style="display:block;margin-top:3px;color:var(--accent);font-size:11px;">' . esc_html( $img['date'] ) . '</span>' : '';
			$meta_description = ! empty( $img['description'] ) ? '<p style="color:var(--text1);font-size:12px;line-height:1.5;margin:5px 0 0;">' . esc_html( $img['description'] ) . '</p>' : '';
			$category = sanitize_key( (string) ( $img['category'] ?? '' ) );
			$item_attrs = $filterable ? ' data-profile-gallery-item="1" data-profile-gallery-category="' . esc_attr( $category ) . '"' : '';
			$images .= '<div' . $item_attrs . '><img src="' . $src . '" alt="' . $alt . '" loading="lazy" style="width:100%;border-radius:var(--radius);object-fit:cover;aspect-ratio:4/3;">' . $meta_title . $meta_date . $caption . $meta_description . '</div>';
		}

		$style = $variant === 'masonry'
			? 'columns:3;column-gap:16px;'
			: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;';

		$gallery_attr = $filterable ? ' data-profile-gallery="1"' : '';
		return '<section class="section"' . $gallery_attr . '>' . $title . $filter_html . '<div style="' . $style . '">' . $images . '</div>' . $filter_script . '</section>';
	}

	private static function render_divider( array $p, string $variant ): string {
		$h = (int) ( $p['height'] ?? 48 );
		if ( $variant === 'space' ) {
			return '<div style="height:' . $h . 'px;"></div>';
		}
		if ( $variant === 'dots' ) {
			return '<div style="text-align:center;padding:' . $h . 'px 0;color:var(--text1);">• • •</div>';
		}
		return '<hr style="border:none;border-top:1px solid var(--border);margin:' . $h . 'px auto;max-width:1200px;">';
	}

	private static function render_banner( array $p ): string {
		$text = esc_html( $p['text'] ?? '' );
		$cta  = ! empty( $p['ctaText'] ) ? ' <a href="' . esc_url( $p['ctaUrl'] ?? '#' ) . '" style="color:inherit;font-weight:600;text-decoration:underline;margin-left:12px;">' . esc_html( $p['ctaText'] ) . '</a>' : '';

		return '<div style="background:var(--accent);color:var(--bg0);text-align:center;padding:12px 24px;font-size:14px;">' . $text . $cta . '</div>';
	}

	/**
	 * Render a `shortcode` block: runs a single WordPress shortcode from an
	 * allowlist instead of letting `custom-html` execute arbitrary shortcodes.
	 *
	 * [2026-08-21 Johnny Chu] PHASE-PB-TRACKING Wave 6.1 item 5
	 *
	 * Security: `$p['shortcode']` is owner/editor-authored content saved through
	 * the Page Builder editor (not visitor/REST-public input). The allowlist is
	 * a second layer of defense, not the only one — only registered shortcode
	 * tags that are also explicitly allowed get executed.
	 *
	 * @param array $p Block props: shortcode (string), label (string, optional).
	 * @return string
	 */
	private static function render_shortcode_block( array $p ): string {
		$raw = trim( (string) ( $p['shortcode'] ?? '' ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/^\[\s*([a-zA-Z0-9_-]+)/', $raw, $m ) ) {
			return '<!-- bzpb shortcode block: invalid shortcode syntax -->';
		}
		$tag = strtolower( $m[1] );

		/**
		 * Filter the list of shortcode tags allowed to run inside the `shortcode` block.
		 *
		 * @param string[] $allowed Default allowlist.
		 */
		$allowed = apply_filters( 'bzpb_shortcode_block_allowlist', array(
			'contact-form-7',
			'gallery',
			'caption',
			'embed',
			'audio',
			'video',
			'playlist',
		) );

		if ( ! is_array( $allowed ) || ! in_array( $tag, $allowed, true ) || ! shortcode_exists( $tag ) ) {
			return '<div style="padding:16px;border:1px dashed #dce2eb;border-radius:12px;color:#68758b;font-size:13px;">'
				. sprintf( 'Shortcode "[%s]" chưa được cho phép hoặc chưa tồn tại.', esc_html( $tag ) )
				. '</div>';
		}

		return '<div class="bzpb-shortcode-block">' . do_shortcode( $raw ) . '</div>';
	}
}
