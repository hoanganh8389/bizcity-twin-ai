/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Admin Dashboard Chat — Console Module
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat\Assets
 * @license    GPL-2.0-or-later | https://bizcity.vn
 */
/* ── Ensure ajaxurl is available on frontend ── */
if (typeof ajaxurl === 'undefined') {
    var ajaxurl = bizcDashConfig.ajaxurl;
}
/* ── Console session must match the chat session so poll reads the correct transient ── */
window.bizcSessionId = bizcDashConfig.sessionId;

// Initialize caches for Plugin Suggestion API
window.bizcMentionAgentsCache = null;
window.bizcMentionAgentsCacheTime = 0;

var _bizcRouterInterval = null;
var _bizcIsFullscreen = false;
var _bizcRouterRawLogs = []; /* raw log data for export */

function bizcRouterPoll(e) {
    if (e) e.stopPropagation();
    var btn = document.getElementById('bizc-router-poll-btn');
    var dot = document.getElementById('bizc-poll-dot');
    if (_bizcRouterInterval) {
        clearInterval(_bizcRouterInterval);
        _bizcRouterInterval = null;
        btn.textContent = '▶ Poll';
        dot.style.background = '#a6e3a1';
        return;
    }
    btn.textContent = '⏸ Stop';
    dot.style.background = '#f38ba8';
    dot.style.animation = 'pulse 1s infinite';
    _fetchAllLogs();
    _bizcRouterInterval = setInterval(_fetchAllLogs, 5000);
}

/* ── Fetch Router logs ── */
function _fetchAllLogs() {
    _fetchRouterLogs();
}

function bizcRouterClear(e) {
    if (e) e.stopPropagation();
    document.getElementById('bizc-router-logs').innerHTML = '<div style="color:#6c7086;">Cleared.</div>';
    _bizcRouterRawLogs = [];
}

/* ── Log state ── */
var _bizcCurrentLogTab = 'router';

/* ── Tab Switching ── */
function bizcSwitchLogTab(tab) {
    _bizcCurrentLogTab = tab;
    var tabs = {
        router:   document.getElementById('bizc-tab-router')
    };
    var panels = {
        router:   document.getElementById('bizc-router-logs')
    };
    var exportBtn = document.getElementById('bizc-export-router-btn');

    // Deactivate all tabs + hide all panels
    Object.keys(tabs).forEach(function(k) {
        if (tabs[k]) { tabs[k].style.background = 'transparent'; tabs[k].style.color = '#6c7086'; }
        if (panels[k]) panels[k].style.display = 'none';
    });

    // Activate selected
    if (tabs[tab]) { tabs[tab].style.background = '#45475a'; tabs[tab].style.color = '#89b4fa'; }
    if (panels[tab]) panels[tab].style.display = 'block';

    // Tab-specific actions
    if (tab === 'console') {
        if (exportBtn) { exportBtn.onclick = function(e) { bizcExportJSON('console', e); }; exportBtn.title = 'Export console logs'; }
    } else {
        if (exportBtn) { exportBtn.onclick = function(e) { bizcExportJSON('router', e); }; exportBtn.title = 'Export router logs'; }
    }
}

/* ── Nonce for AJAX calls ── */
var _bizcChatNonce = bizcDashConfig.chatNonce;
/* ── Fetch Execution Logs ── */
function _fetchExecLogs() {
    var pollSessionId = window.bizcCurrentSessionId || window.bizcSessionId || '';
    jQuery.post(ajaxurl, {
        action: 'bizcity_poll_execution_log',
        nonce: bizcDashConfig.chatNonce,
        session_id: pollSessionId
    }, function(r) {
        if (!r.success) return;
        var logs = r.data.logs || [];
        var stats = r.data.stats || {};
        _bizcExecRawLogs = logs;

        if (!logs.length) {
            document.getElementById('bizc-exec-logs').innerHTML = 
                '<div style="color:#6c7086;">Chưa có log thực thi. Tool sẽ ghi log khi được gọi.</div>';
            return;
        }

        // Render execution logs
        var html = '';
        
        // Stats header
        if (stats.tools_invoked > 0) {
            html += '<div style="color:#89b4fa;padding:4px 0;margin-bottom:6px;border-bottom:1px solid #313244;">';
            html += '📊 <strong>' + stats.tools_invoked + '</strong> tools called';
            if (stats.tools_succeeded > 0) html += ' • <span style="color:#a6e3a1;">✓ ' + stats.tools_succeeded + '</span>';
            if (stats.tools_failed > 0) html += ' • <span style="color:#f38ba8;">✗ ' + stats.tools_failed + '</span>';
            if (stats.errors > 0) html += ' • <span style="color:#f38ba8;">⚠ ' + stats.errors + ' errors</span>';
            html += '</div>';
        }

        // Render each log entry
        logs.forEach(function(log) {
            html += _renderExecLogEntry(log);
        });

        document.getElementById('bizc-exec-logs').innerHTML = html;
    });
}

/* ── Render single execution log entry ── */
function _renderExecLogEntry(log) {
    var h = '<div class="bizc-elog">';
    var step = log.step || 'unknown';
    var time = (log.timestamp || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '');
    
    // Step badge with color
    var stepColors = {
        'pipeline_start': '#cba6f7',
        'pipeline_step': '#89b4fa',
        'pipeline_complete': '#a6e3a1',
        'tool_invoke': '#f9e2af',
        'tool_result': '#a6e3a1',
        'tool_step': '#fab387',
        'slot_resolve': '#94e2d5',
        'goal_update': '#f5c2e7',
        'error': '#f38ba8'
    };
    var stepColor = stepColors[step] || '#cdd6f4';
    
    h += '<div class="bizc-elog-header">';
    h += '<span class="bizc-elog-step" style="color:' + stepColor + ';">' + _esc(step) + '</span>';
    h += '<span class="bizc-elog-time">' + time + '</span>';
    
    // Duration
    if (log.duration_ms) {
        h += '<span class="bizc-rlog-ms">' + log.duration_ms + 'ms</span>';
    }
    h += '</div>';

    // Content based on step type
    switch (step) {
        case 'pipeline_start':
            h += '<div class="bizc-elog-detail">🚀 Template: <strong>' + _esc(log.template || 'unknown') + '</strong></div>';
            if (log.steps && log.steps.length) {
                h += '<div class="bizc-elog-detail">Steps: ' + log.steps.map(_esc).join(' → ') + '</div>';
            }
            break;

        case 'tool_invoke':
            h += '<div class="bizc-elog-detail">🔧 <strong style="color:#f9e2af;">' + _esc(log.tool_name) + '</strong>';
            if (log.source) h += ' <span style="color:#6c7086;">(' + log.source + ')</span>';
            h += '</div>';
            if (log.params && typeof log.params === 'object' && !log.params._truncated) {
                h += '<div class="bizc-elog-detail" style="color:#89dceb;">Params: ' + _esc(JSON.stringify(log.params).substring(0, 200)) + '</div>';
            }
            break;

        case 'tool_result':
            var icon = log.success ? '✅' : '❌';
            var resultColor = log.success ? '#a6e3a1' : '#f38ba8';
            h += '<div class="bizc-elog-detail">' + icon + ' <strong style="color:' + resultColor + ';">' + _esc(log.tool_name) + '</strong></div>';
            if (log.message) {
                h += '<div class="bizc-elog-detail" style="color:#cdd6f4;">' + _esc(log.message.substring(0, 150)) + '</div>';
            }
            if (log.data_type) {
                h += '<div class="bizc-elog-detail" style="color:#94e2d5;">data.type: ' + _esc(log.data_type) + '</div>';
            }
            if (log.data_id) {
                h += '<div class="bizc-elog-detail" style="color:#94e2d5;">data.id: ' + _esc(log.data_id) + '</div>';
            }
            break;

        case 'tool_step':
            var tsIcon = log.status === 'success' ? '✅' : (log.status === 'error' ? '❌' : (log.status === 'skipped' ? '⏭' : '⋯'));
            var tsColor = log.status === 'success' ? '#a6e3a1' : (log.status === 'error' ? '#f38ba8' : '#fab387');
            h += '<div class="bizc-elog-detail">' + tsIcon + ' <span style="color:' + tsColor + ';font-weight:bold;">' + _esc(log.sub_step || log.step_name || '—') + '</span>';
            if (log.status) h += ' <span style="color:#6c7086;">(' + _esc(log.status) + ')</span>';
            h += '</div>';
            if (log.title) {
                h += '<div class="bizc-elog-detail" style="color:#cdd6f4;">📝 ' + _esc(log.title.substring(0, 120)) + '</div>';
            }
            if (log.content_len) {
                h += '<div class="bizc-elog-detail" style="color:#89dceb;">' + log.content_len + ' chars generated</div>';
            }
            if (log.post_id) {
                h += '<div class="bizc-elog-detail" style="color:#94e2d5;">post_id: ' + log.post_id;
                if (log.url) h += ' · <a href="' + _esc(log.url) + '" target="_blank" style="color:#89b4fa;">view</a>';
                h += '</div>';
            }
            if (log.message && log.status === 'error') {
                h += '<div class="bizc-elog-detail" style="color:#f38ba8;">' + _esc(log.message.substring(0, 150)) + '</div>';
            }
            break;

        case 'pipeline_complete':
            var statusIcon = log.status === 'success' ? '✅' : (log.status === 'partial' ? '⚠️' : '❌');
            h += '<div class="bizc-elog-detail">' + statusIcon + ' Status: <strong>' + _esc(log.status) + '</strong></div>';
            if (log.duration_ms) {
                h += '<div class="bizc-elog-detail">Total: ' + log.duration_ms + 'ms</div>';
            }
            break;

        case 'goal_update':
            h += '<div class="bizc-elog-detail">🎯 Goal: <strong>' + _esc(log.goal_id) + '</strong> → ' + _esc(log.status) + '</div>';
            if (log.missing_info && log.missing_info.length) {
                h += '<div class="bizc-elog-detail">Missing: ' + log.missing_info.join(', ') + '</div>';
            }
            break;

        case 'error':
            h += '<div class="bizc-elog-detail" style="color:#f38ba8;">⚠️ ' + _esc(log.error_type) + ': ' + _esc(log.message) + '</div>';
            break;

        default:
            h += '<div class="bizc-elog-detail">' + _esc(JSON.stringify(log).substring(0, 200)) + '</div>';
    }

    h += '</div>';
    return h;
}

function bizcExportJSON(type, e) {
    if (e) e.stopPropagation();
    var logs, filename, label;
    logs = _bizcRouterRawLogs || [];
    filename = 'router-logs';
    label = 'Tư duy';
    if (!logs.length) {
        alert('Chưa có log ' + label + '. Hãy Poll trước.');
        return;
    }
    var json = JSON.stringify(logs, null, 2);
    // Copy to clipboard
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(json).then(function() {
            _bizcRouterExportNotify('✅ Copied ' + label + '! (' + logs.length + ' logs)');
        }).catch(function() {
            _bizcExportFallback(json, filename);
        });
    } else {
        _bizcExportFallback(json, filename);
    }
}

// Backward compat
function bizcRouterExportJSON(e) {
    bizcExportJSON('router', e);
}

function _bizcExportFallback(json, filename) {
    // Fallback: download as file
    var blob = new Blob([json], {type: 'application/json'});
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = (filename || 'logs') + '-' + new Date().toISOString().slice(0,19).replace(/:/g,'-') + '.json';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    _bizcRouterExportNotify('📥 Downloaded JSON file');
}

// Backward compat
function _bizcRouterExportFallback(json) {
    _bizcExportFallback(json, 'router-logs');
}

function _bizcRouterExportNotify(msg) {
    var el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = 'position:fixed;top:20px;right:20px;background:#313244;color:#a6e3a1;padding:8px 16px;border-radius:8px;font-size:12px;z-index:999999;font-family:monospace;box-shadow:0 4px 12px rgba(0,0,0,.4);transition:opacity .3s;';
    document.body.appendChild(el);
    setTimeout(function(){ el.style.opacity='0'; }, 2000);
    setTimeout(function(){ el.remove(); }, 2500);
}

function bizcRouterFullscreen(e) {
    if (e) e.stopPropagation();
    var el = document.getElementById('bizc-router-console');
    var btn = document.getElementById('bizc-fs-btn');
    _bizcIsFullscreen = !_bizcIsFullscreen;
    if (_bizcIsFullscreen) {
        // Move console to body so no parent overflow:hidden clips it
        el._origParent = el.parentNode;
        el._origMaxH = el.style.maxHeight;
        // Overlay
        var ov = document.createElement('div');
        ov.className = 'bizc-router-overlay';
        ov.id = 'bizc-router-overlay';
        ov.onclick = function(){ bizcRouterFullscreen(null); };
        document.body.appendChild(ov);
        // Move to body + expand
        document.body.appendChild(el);
        el.style.maxHeight = 'none';
        el.classList.add('bizc-router-expanded');
        btn.textContent = '⛶ Collapse';
    } else {
        // Remove overlay
        var ov = document.getElementById('bizc-router-overlay');
        if (ov) ov.remove();
        // Restore to original parent
        el.classList.remove('bizc-router-expanded');
        el.style.maxHeight = el._origMaxH || '180px';
        if (el._origParent) el._origParent.appendChild(el);
        btn.textContent = '⛶ Expand';
    }
}

function _renderLogEntry(log) {
    var h = '<div class="bizc-rlog">';
    h += '<div class="bizc-rlog-header">';
    h += '<span class="bizc-rlog-step">' + (log.step || 'event') + '</span>';
    h += '<span class="bizc-rlog-mode">[' + (log.mode || '?') + ']</span>';
    if (log.confidence) h += '<span class="bizc-rlog-conf">conf=' + log.confidence + '</span>';
    if (log.method) h += '<span class="bizc-rlog-method">via ' + log.method + '</span>';
    var ms = log.mode_ms || log.classify_ms || log.duration_ms || log.profile_ms || log.transit_ms || log.build_ms || log.context_ms || log.chain_ms || log.search_ms;
    if (ms) {
        h += '<span class="bizc-rlog-ms">' + ms + 'ms</span>';
    }
    h += '<span class="bizc-rlog-time">' + (log.timestamp || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '') + '</span>';
    h += '</div>';
    if (log.pipeline) {
        var steps = Array.isArray(log.pipeline) ? log.pipeline : [log.pipeline];
        h += '<div class="bizc-rlog-pipeline">📋 ' + steps.map(function(s,i){
            return '<span style="background:#313244;padding:1px 5px;border-radius:3px;margin-right:2px;">'+(i+1)+'. '+_esc(s)+'</span>';
        }).join(' → ') + '</div>';
    }
    if (log.functions_called) h += '<div class="bizc-rlog-fn">⚙️ ' + _esc(log.functions_called) + '</div>';
    if (log.file_line) h += '<div class="bizc-rlog-line">📍 ' + _esc(log.file_line) + '</div>';
    if (log.memory_count !== undefined) h += '<div class="bizc-rlog-memory">🧠 Memory: ' + log.memory_count + ' items (user=' + (log.memory_user_id||'?') + ')</div>';
    // Layer 6: Plugin context (profile, transit, knowledge)
    if (log.step === 'context_build' && log.context_length) {
        h += '<div class="bizc-rlog-context">📚 L6 Base: ' + log.context_length + ' chars (profile=' + (log.has_profile?'✓':'✗') + ' transit=' + (log.has_transit?'✓':'✗') + ' knowledge=' + (log.has_knowledge?'✓':'✗') + ')</div>';
        if (log.profile_preview) h += '<div class="bizc-rlog-preview">👤 ' + _esc(log.profile_preview).substring(0,150) + '</div>';
        if (log.transit_preview) h += '<div class="bizc-rlog-preview">⭐ ' + _esc(log.transit_preview).substring(0,150) + '</div>';
    }
    // Layer 2-5: Context chain (intent, session, cross, project)
    else if (log.step === 'context_chain' && log.context_length) {
        h += '<div class="bizc-rlog-context">🔗 Chain: ' + log.context_length + ' chars (intent=' + (log.has_intent?'✓':'✗') + ' session=' + (log.has_session?'✓':'✗') + ' cross=' + (log.has_cross?'✓':'✗') + ' project=' + (log.has_project?'✓':'✗') + ')</div>';
    }
    // BizCoach context injection at priority 95
    else if (log.step === 'bizcoach_inject') {
        h += '<div class="bizc-rlog-context">🌟 <b>BizCoach Injected</b> (pri ' + (log.priority||95) + '): profile=' + (log.has_profile?'✓':'✗') + ' (' + (log.profile_length||0) + ' chars) | transit=' + (log.has_transit?'✓':'✗') + ' (' + (log.transit_length||0) + ' chars) → ' + (log.total_injection||0) + ' chars total</div>';
    }
    // BizCoach precheck (debug - shows if profile/transit available before filter)
    else if (log.step === 'bizcoach_precheck') {
        var willIcon = log.will_inject ? '✅' : '⛔';
        h += '<div class="bizc-rlog-context">🔍 <b>BizCoach Pre-check</b>: profile=' + (log.has_profile?'✓':'✗') + ' (' + (log.profile_length||0) + ') transit=' + (log.has_transit?'✓':'✗') + ' (' + (log.transit_length||0) + ') → will_inject=' + willIcon + '</div>';
    }
    // 💓 INTENSITY DETECTION — emotional routing decision
    else if (log.step === 'intensity_detect') {
        var intLvl = log.intensity || 1;
        var intColors = ['#6c7086','#89dceb','#a6e3a1','#f9e2af','#fab387','#f38ba8'];
        var intLabels = ['neutral','calm','mild','moderate','high','critical'];
        var intColor = intColors[Math.min(intLvl, 5)];
        var intLabel = intLabels[Math.min(intLvl, 5)];
        var empIcon = log.empathy_flag ? '💛' : '🤍';
        var branchIcons = {execution:'⚡',knowledge:'📚',reflection:'🪞',emotion_low:'💬',emotion_high:'💓',emotion_critical:'🚨'};
        var branchIcon = branchIcons[log.routing_branch] || '🔀';
        h += '<div class="bizc-rlog-context">💓 <b>Intensity</b>: ';
        h += '<span style="display:inline-block;width:60px;height:8px;background:#313244;border-radius:4px;overflow:hidden;vertical-align:middle;margin:0 6px;">';
        h += '<span style="display:block;width:' + (intLvl*20) + '%;height:100%;background:' + intColor + ';"></span></span>';
        h += '<span style="color:' + intColor + ';font-weight:700;">' + intLvl + '/5 (' + intLabel + ')</span> ';
        h += empIcon + ' empathy=' + (log.empathy_flag?'ON':'off') + ' ';
        h += branchIcon + ' <span style="color:#cba6f7;font-weight:700;">' + (log.routing_branch||'?') + '</span>';
        if (log.intensity_ms) h += ' <span style="color:#6c7086;font-size:9px;">' + log.intensity_ms + 'ms</span>';
        h += '</div>';
    }
    // 🎀 EMOTIONAL SMOOTHING — wrap tool ask with empathy
    else if (log.step === 'emotional_smooth') {
        h += '<div class="bizc-rlog-context">🎀 <b>Emotional Smooth</b> (intensity=' + (log.intensity||'?') + '):</div>';
        if (log.raw_prompt) {
            h += '<div class="bizc-rlog-detail" style="color:#f38ba8;">❌ Raw: "' + _esc(log.raw_prompt).substring(0,100) + '"</div>';
        }
        if (log.smoothed_prompt) {
            h += '<div class="bizc-rlog-detail" style="color:#a6e3a1;">✅ Smoothed: "' + _esc(log.smoothed_prompt).substring(0,150) + '"</div>';
        }
    }
    // 📋 FINAL PROMPT — collapsible textarea showing full system prompt
    else if (log.step === 'final_prompt') {
        var chkBiz = log.has_bizcoach ? '✅' : '❌';
        var chkMem = log.has_memory ? '✅' : '❌';
        var chkCtx = log.has_context_chain ? '✅' : '❌';
        h += '<div class="bizc-rlog-context">📋 <b>Final Prompt</b>: ' + (log.prompt_length||0) + ' chars (~' + (log.word_count||0) + ' words) | BizCoach=' + chkBiz + ' Memory=' + chkMem + ' Context=' + chkCtx + '</div>';
        // Collapsible head preview
        if (log.prompt_head) {
            h += '<div class="bizc-rlog-prompt-preview">';
            h += '<div class="bizc-rlog-preview" style="max-height:60px;overflow:hidden;">📝 ' + _esc(log.prompt_head).substring(0,300) + '</div>';
            h += '</div>';
        }
        // Full prompt in collapsible textarea
        if (log.full_prompt) {
            var uid = 'fp_' + Date.now() + '_' + Math.random().toString(36).substr(2,5);
            h += '<div class="bizc-rlog-collapse">';
            h += '<button type="button" class="bizc-rlog-collapse-btn" onclick="var t=document.getElementById(\'' + uid + '\');var b=this;if(t.style.display===\'none\'){t.style.display=\'block\';b.textContent=\'▼ Thu gọn\'}else{t.style.display=\'none\';b.textContent=\'▶ Xem full prompt (' + (log.prompt_length||0) + ' chars)\'}">▶ Xem full prompt (' + (log.prompt_length||0) + ' chars)</button>';
            h += '<textarea id="' + uid + '" class="bizc-rlog-full-prompt" style="display:none;width:100%;min-height:200px;max-height:400px;overflow:auto;font-size:11px;font-family:monospace;background:#1a1a2e;color:#e0e0e0;border:1px solid #444;border-radius:4px;padding:8px;margin-top:4px;resize:vertical;white-space:pre-wrap;" readonly>' + _esc(log.full_prompt) + '</textarea>';
            h += '</div>';
        }
    }
    // Transit build debug
    else if (log.step === 'transit_build') {
        var statusIcon = log.status === 'success' ? '✅' : '⚠️';
        h += '<div class="bizc-rlog-context">⭐ Transit: ' + statusIcon + ' ' + (log.status||'?') + ' (coachee=' + (log.coachee_id||'0') + ')';
        if (log.intent_type) h += ' intent=' + log.intent_type + '/' + (log.intent_period||'?');
        if (log.context_length) h += ' → ' + log.context_length + ' chars';
        h += '</div>';
        if (log.context_preview) h += '<div class="bizc-rlog-preview">🌟 ' + _esc(log.context_preview).substring(0,150) + '</div>';
    }
    // Session/Project CRUD operations
    else if (log.step && log.step.match(/^(session|project)_(create|rename|move|delete|update|auto_create|stats_update)/)) {
        var opIcon = {'create':'➕','rename':'✏️','move':'📦','delete':'🗑️','update':'⚙️','auto_create':'🆕','stats_update':'📊'}[log.step.split('_').slice(1).join('_')] || '📋';
        h += '<div class="bizc-rlog-context">' + opIcon + ' ' + _esc(log.step);
        if (log.status) h += ' → ' + log.status;
        if (log.session_uuid) h += ' [' + _esc(log.session_uuid).substring(0,20) + '...]';
        if (log.title_generated === 'yes' && log.new_title) h += ' 📝"' + _esc(log.new_title) + '"';
        else if (log.session_title || log.new_title) h += ' "' + _esc(log.session_title || log.new_title) + '"';
        if (log.from_project || log.to_project) h += ' ' + _esc(log.from_project||'') + '→' + _esc(log.to_project||'');
        if (log.message_count) h += ' #' + log.message_count;
        h += '</div>';
        if (log.db_error) h += '<div class="bizc-rlog-error">❌ ' + _esc(log.db_error) + '</div>';
    }
    // Legacy fallback for other steps with context_length
    else if (log.context_length) {
        h += '<div class="bizc-rlog-context">📚 ' + log.context_length + ' chars</div>';
    }
    if (log.response_preview) h += '<div class="bizc-rlog-response">✅ ' + _esc(log.response_preview).substring(0,200) + '</div>';
    if (log.prompt_preview) h += '<div class="bizc-rlog-prompt">📝 ' + _esc(log.prompt_preview).substring(0,300) + '</div>';

    // Router debug: matched pattern, candidates, provider info
    if (log.matched_pattern) {
        h += '<div class="bizc-rlog-detail">🎯 Pattern: <code style="background:#313244;padding:1px 4px;border-radius:2px;color:#f9e2af;font-size:9px;">' + _esc(log.matched_pattern) + '</code>';
        if (log.pattern_source) h += ' <span style="color:#89dceb;">[' + _esc(log.pattern_source) + ']</span>';
        h += '</div>';
    }
    if (log.classify_step) {
        h += '<div class="bizc-rlog-detail">📌 Router step: <span style="color:#f9e2af;">' + _esc(log.classify_step) + '</span></div>';
    }
    if (log.active_goal) {
        h += '<div class="bizc-rlog-detail">🔄 Active goal: <span style="color:#cba6f7;">' + _esc(log.active_goal) + '</span> [' + _esc(log.active_goal_status || '?') + ']</div>';
    }
    if (log.provider_override) {
        var po = log.provider_override;
        h += '<div class="bizc-rlog-detail" style="color:#f9e2af;">⚡ Provider override: <span style="color:#f38ba8;">' + _esc(po.original_mode) + '</span> → <span style="color:#a6e3a1;">execution</span>';
        if (po.matched_goal) h += ' (goal=' + _esc(po.matched_goal) + ')';
        h += '</div>';
    }
    if (log.all_goal_candidates && log.all_goal_candidates.length) {
        h += '<div class="bizc-rlog-detail">🔍 Candidates tested: ';
        h += log.all_goal_candidates.map(function(c){
            var style = c.matched ? 'color:#a6e3a1;font-weight:700;' : 'color:#6c7086;';
            return '<span style="'+style+'">' + _esc(c.goal) + (c.source ? ' ['+_esc(c.source)+']' : '') + '</span>';
        }).join(', ');
        h += '</div>';
    }
    if (log.registered_providers && log.registered_providers.length) {
        h += '<div class="bizc-rlog-detail">🔌 Providers: ' + log.registered_providers.map(function(p){
            return '<span style="color:#89b4fa;">' + _esc(p) + '</span>';
        }).join(', ') + '</div>';
    }
    if (log.goal_map && Object.keys(log.goal_map).length) {
        var gKeys = Object.keys(log.goal_map);
        h += '<div class="bizc-rlog-detail">📑 Goal map (' + gKeys.length + '): ';
        h += gKeys.slice(0,15).map(function(k){
            return '<span style="color:#cba6f7;">' + _esc(k) + '</span>';
        }).join(', ');
        if (gKeys.length > 15) h += '… +' + (gKeys.length-15);
        h += '</div>';
    }
    if (log.pattern_count !== undefined) {
        h += '<div class="bizc-rlog-detail">📊 Patterns: ' + log.pattern_count + ' total';
        if (log.provider_pattern_count !== undefined) h += ' (' + log.provider_pattern_count + ' from providers)';
        h += '</div>';
    }

    h += '</div>';
    return h;
}

function _fetchRouterLogs() {
    // Use current sessionId (dynamically updated) for polling
    var pollSessionId = window.bizcCurrentSessionId || window.bizcSessionId || '';
    jQuery.post(ajaxurl, {
        action: 'bizcity_memory_poll_router',
        nonce: bizcDashConfig.chatNonce,
        session_id: pollSessionId
    }, function(r) {
        if (!r.success || !r.data.logs || !r.data.logs.length) return;
        // Store logs for export — strip full_prompt to keep JSON lightweight
        _bizcRouterRawLogs = r.data.logs.map(function(l) {
            var cleaned = Object.assign({}, l);
            delete cleaned.full_prompt;
            delete cleaned.prompt_head;
            delete cleaned.prompt_tail;
            return cleaned;
        });

        // Group logs by user message (detect by mode_classify or first step per timestamp cluster)
        var groups = [], curGroup = null;
        // Logs are newest-first from server, reverse for chronological grouping
        var chronoLogs = r.data.logs.slice().reverse();
        chronoLogs.forEach(function(log) {
            var msg = _esc((log.message || '').substring(0, 80));
            // A new group starts at each mode_classify step (= beginning of a user message pipeline)
            if (log.step === 'mode_classify') {
                curGroup = { message: msg, time: log.timestamp || '', logs: [] };
                groups.push(curGroup);
            }
            if (!curGroup) {
                curGroup = { message: msg || '...', time: log.timestamp || '', logs: [] };
                groups.push(curGroup);
            }
            curGroup.logs.push(log);
        });

        // Render newest group first
        groups.reverse();
        var html = '';
        groups.forEach(function(g, gi) {
            var collapsed = gi > 0 ? ' collapsed' : ''; // only latest group open
            var arrow = gi > 0 ? '▸' : '▾';
            var stepCount = g.logs.length;
            html += '<div class="bizc-rlog-group' + collapsed + '">';
            html += '<div class="bizc-rlog-group-header" onclick="this.parentNode.classList.toggle(\'collapsed\');var a=this.querySelector(\'span\');a.textContent=a.textContent===\'▸\'?\'▾\':\'▸\'">';
            html += '<span>' + arrow + '</span> 💬 ' + g.message + ' <span style="color:#6c7086;font-weight:400;font-size:9px;">(' + stepCount + ' steps • ' + (g.time || '').replace(/^\d{4}-\d{2}-\d{2}\s/, '') + ')</span>';
            html += '</div>';
            html += '<div class="bizc-rlog-group-body">';
            g.logs.forEach(function(log) { html += _renderLogEntry(log); });
            html += '</div></div>';
        });

        document.getElementById('bizc-router-logs').innerHTML = html;
        document.getElementById('bizc-router-logs').scrollTop = 0;
    });
}

function _esc(s) { if (typeof s !== 'string') s = String(s||''); var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

/* ── Drag-resize handle logic ── */
document.addEventListener('DOMContentLoaded', function() {
    var handle = document.getElementById('bizc-resize-handle');
    if (!handle) return;
    var consoleEl = document.getElementById('bizc-router-console');
    var startY, startH;
    handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        startY = e.clientY;
        startH = consoleEl.offsetHeight;
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
        document.body.style.cursor = 'ns-resize';
        document.body.style.userSelect = 'none';
    });
    // Touch support for mobile/tablet
    handle.addEventListener('touchstart', function(e) {
        var t = e.touches[0];
        startY = t.clientY;
        startH = consoleEl.offsetHeight;
        document.addEventListener('touchmove', onTouchMove, {passive:false});
        document.addEventListener('touchend', onTouchEnd);
    });
    function onMove(e) {
        var newH = Math.max(60, Math.min(600, startH + (e.clientY - startY)));
        consoleEl.style.maxHeight = newH + 'px';
    }
    function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    }
    function onTouchMove(e) {
        e.preventDefault();
        var t = e.touches[0];
        var newH = Math.max(60, Math.min(600, startH + (t.clientY - startY)));
        consoleEl.style.maxHeight = newH + 'px';
    }
    function onTouchEnd() {
        document.removeEventListener('touchmove', onTouchMove);
        document.removeEventListener('touchend', onTouchEnd);
    }
});

/* Pulse animation */
var _style = document.createElement('style');
_style.textContent = '@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}';
document.head.appendChild(_style);