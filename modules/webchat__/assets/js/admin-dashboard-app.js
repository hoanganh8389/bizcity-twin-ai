/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Admin Dashboard Chat — Main App
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat\Assets
 * @license    GPL-2.0-or-later | https://bizcity.vn
 */
jQuery(function($) {
    // Prevent multiple initialization
    if (window.bizcDashInitialized) return;
    window.bizcDashInitialized = true;
    
    var baseSessionId = bizcDashConfig.sessionId,
        sessionId = bizcDashConfig.sessionId,
        nonce = bizcDashConfig.nonce,
        ajaxurl = bizcDashConfig.ajaxurl,
        isGuest = bizcDashConfig.isGuest,
        guestMsgLimit = 3,
        guestMsgKey = 'bizc_guest_msg_count',
        $msgs = $('#bizc-messages'),
        $input = $('#bizc-input'),
        $send = $('#bizc-send'),
        botAvatar = bizcDashConfig.botAvatar,
        messages = [],
        wcSessions = [],
        projects = [],
        currentWcId = null,        // webchat_conversations primary key
        lastMsgId = 0,             // last message ID for polling
        _renderedMsgIds = {},      // DB message IDs already rendered (dedup)
        _msgPollTimer = null,      // message polling interval
        currentProjectId = null,
        openProjects = {},
        pendingImages = [],
        dragSrcId = null,   // for drag & drop
        /* ═══ REST API Config (Phase 5.0 — AJAX→REST migration) ═══ */
        restUrl = bizcDashConfig.restUrl,
        wpRestNonce = bizcDashConfig.wpRestNonce,
        useRestApi = true;   // Feature flag: true = REST primary + AJAX fallback
    
    /**
     * Update page header (aiagent-header) with title and back button visibility
     * Used when switching between chat/agent views on mobile
     */
    function updatePageHeader(title, showBack) {
        var $headerTitle = $('#aiagent-header-title');
        var $backBtn = $('#aiagent-back-btn');
        if ($headerTitle.length) {
            $headerTitle.text(title || 'Trò chuyện gần đây');
        }
        if ($backBtn.length) {
            if (showBack) {
                $backBtn.show();
            } else {
                $backBtn.hide();
            }
        }
    }
    
    // Back button click handler
    $('#aiagent-back-btn').on('click', function() {
        hideAgentPanel();
        updatePageHeader('Trò chuyện gần đây', false);
    });
    
    // Init
    loadProjects();
    loadSessions(true); // true = initial load, auto-select most recent
    loadIntentConversations();
    _loadPluginChips(); // Pre-Intent: load plugin chips bar
    
    // Set Chat button as active initially (default view)
    $('.bizc-tb-chat').addClass('active');
    
    // Event delegation for sessions in "Gần đây" section
    $('#bizc-convs-list').off('click').on('click', '.bizc-conv', function(e) {
        var wcId = $(this).data('wc-id');
        if (wcId) {
            if (typeof window.closeMobileSidebar === 'function') window.closeMobileSidebar();
            loadSession(wcId);
        }
    });

    // Event delegation for sessions inside projects
    $('#bizc-proj-list').off('click', '.bizc-proj-conv').on('click', '.bizc-proj-conv', function(e) {
        var wcId = $(this).data('wc-id');
        if (wcId) {
            if (typeof window.closeMobileSidebar === 'function') window.closeMobileSidebar();
            loadSession(wcId);
        }
    });
    
    // Events
    $('#bizc-new-chat').off('click').on('click', function() {
        if (typeof window.closeMobileSidebar === 'function') window.closeMobileSidebar();
        startNewChat();
    });
    // "Xem chi tiết" — load list pages in iframe panel
    $('#bizc-sessions-view-all').on('click', function() {
        var url = $(this).data('url') + '?bizcity_iframe=1';
        openInlinePanel('💬 Phiên chat', url);
    });
    $('#bizc-intent-view-all').on('click', function() {
        var url = $(this).data('url') + '?bizcity_iframe=1';
        openInlinePanel('🎯 Nhiệm vụ', url);
    });
    $('#bizc-add-project').off('click').on('click', showAddProjectForm);

    // ── Touch Bar: Chat button → return to chat ──
    $('#bizc-touchbar').on('click', '.bizc-tb-chat', function(e) {
        e.preventDefault();
        hideAgentPanel();
    });

    // ── Touch Bar: agent plugin clicks (lazy-load iframe) ──
    $('#bizc-touchbar').on('click', '.bizc-tb-agent', function(e) {
        e.preventDefault();
        var $btn = $(this),
            slug = $btn.data('slug'),
            rawSrc = $btn.data('src') || '',
            templateUrl = rawSrc + (rawSrc.indexOf('?') > -1 ? '&' : '?') + 'bizcity_iframe=1';

        // Toggle: if already showing this agent, go back to chat
        if ($('#bizc-agent-panel').is(':visible') && $('#bizc-agent-panel').data('slug') === slug) {
            hideAgentPanel();
            return;
        }

        showAgentBySlug(slug, $btn.attr('title') || slug, $btn.find('.bizc-tb-icon img').attr('src') || '', templateUrl);

        // Remove chat button active state
        $('.bizc-tb-chat').removeClass('active');

        // Update URL
        bizcPushUrl('agent', slug);
    });

    // ── Touch Bar: non-agent link clicks (Hồ sơ, Kiến thức, Chợ AI) → open in iframe (lazy-load) ──
    $('#bizc-touchbar').on('click', '.bizc-tb-link', function(e) {
        e.preventDefault();
        var $btn = $(this),
            rawSrc = $btn.data('src') || '',
            iframeUrl = rawSrc + (rawSrc.indexOf('?') > -1 ? '&' : '?') + 'bizcity_iframe=1',
            key = 'link_' + rawSrc;

        // Toggle: if already showing this link, go back to chat
        if ($('#bizc-agent-panel').is(':visible') && $('#bizc-agent-panel').data('slug') === key) {
            hideAgentPanel();
            return;
        }

        // Set active state
        $('.bizc-tb-agent').removeClass('active');
        $('.bizc-tb-link').removeClass('active');
        $('.bizc-tb-chat').removeClass('active');
        $btn.addClass('active');

        // Populate header
        var title = $btn.attr('title') || 'Page';
        $('#bizc-agent-title').text(title);
        $('#bizc-agent-icon').hide();

        // Lazy-load iframe: clear old content first, then load new
        loadAgentIframe(iframeUrl);
        $('#bizc-agent-panel').data('slug', key).css('display', 'flex');
        $('#bizc-chat-panel').hide();
        $('#bizc-project-detail').hide();

        // Update URL with panel param
        var pageName = rawSrc.replace(/.*[?&]page=([^&]+).*/, '$1');
        bizcPushUrl('panel', pageName || 'page');
    });

    $('#bizc-agent-back').on('click', function() { hideAgentPanel(); });
    $('#bizc-agent-external').on('click', function() {
        var src = $('#bizc-agent-iframe').attr('src');
        if (src && src !== 'about:blank') window.open(src, '_blank');
    });

    // Close context menu on click elsewhere
    $(document).off('click.ctxmenu').on('click.ctxmenu', function() {
        $('.bizc-ctx-menu').remove();
    });

    // ── Tool capability button clicks → send as slash command ──
    $msgs.on('click', '.bizc-tool-cap-btn', function(e) {
        e.preventDefault();
        var goal = $(this).data('goal');
        if (!goal) return;
        $input.val('/' + goal);
        $send.trigger('click');
    });
    
    // File input handler (label will auto-trigger via for="bizc-file-input")
    var fileInput = document.getElementById('bizc-file-input');
    
    if (fileInput) {
        var handleFileChange = function(e) {
            console.log('File input changed:', e.target.files.length, 'file(s)');
            if (e.target.files.length > 0) {
                handleImages(e.target.files);
            }
            this.value = ''; // Clear for reselection
        };
        
        if (!window.bizcFileHandler) {
            window.bizcFileHandler = handleFileChange;
            fileInput.addEventListener('change', handleFileChange);
            console.log('File input handler initialized');
        }
    } else {
        console.error('File input not found!');
    }
    
    $send.off('click').on('click', sendMsg);
    
    // Combined handler for keydown - handles both @mention and regular Enter
    // (This replaces the old simple keydown handler)
    
    // Combined handler for input - handles both @mention and auto-resize  
    // (This replaces the old simple input handler)

    // ════════════════════════════════════════════════════════════
    //  PRE-INTENT — Plugin Chips Bar + Auto-Suggest
    //
    //  Always-visible horizontal plugin chips above the input.
    //  On typing (debounced 400ms), calls bizcity_pre_intent_estimate
    //  to highlight which plugin(s) match the draft message.
    //  Click a chip → enters Logic 2 (manual routing) directly.
    //  Reduces Intent Engine load by pre-selecting plugin.
    // ════════════════════════════════════════════════════════════
    var _preIntentTimer = null;
    var _preIntentLastQuery = '';
    var _preIntentChipsLoaded = false;
    var $chipsBar = $('#bizc-plugin-chips');
    var $chipsScroll = $('#bizc-chips-scroll');

    // Load plugin chips on init
    function _loadPluginChips() {
        // Use the same agent list from @mention API
        _loadMentionAgents(function(agents) {
            if (!agents || !agents.length) {
                $chipsScroll.html('<div class="bizc-chips-loading">Không có agent nào</div>');
                return;
            }

            var html = '';
            agents.forEach(function(a) {
                var iconHtml = (a.icon && a.icon.indexOf('/') > -1) 
                    ? '<img class="bizc-chip-icon" src="' + esc(a.icon) + '" alt="">'
                    : '<span class="bizc-chip-icon-emoji">' + (a.icon || '🤖') + '</span>';
                
                html += '<div class="bizc-plugin-chip" '
                    + 'data-slug="' + esc(a.slug) + '" '
                    + 'data-label="' + esc(a.label || a.title) + '" '
                    + 'data-icon="' + esc(a.icon || '🤖') + '">'
                    + '<span class="bizc-chip-suggest-dot"></span>'
                    + iconHtml + ' '
                    + esc(a.label || a.title)
                    + '</div>';
            });

            $chipsScroll.html(html);
            _preIntentChipsLoaded = true;
        });
    }

    // Auto-suggest: call pre_intent_estimate on typing
    function _preIntentEstimate(text) {
        if (!text || text.length < 3) {
            // Reset all chip states
            $chipsScroll.find('.bizc-plugin-chip').removeClass('suggested');
            return;
        }

        // Skip if text unchanged
        if (text === _preIntentLastQuery) return;
        _preIntentLastQuery = text;

        // Skip if already in manual mode (user already selected a chip)
        if (_mentionProvider) return;

        $.post(ajaxurl, {
            action: 'bizcity_pre_intent_estimate',
            message: text,
            _wpnonce: nonce
        }, function(response) {
            if (!response.success || !response.data) return;
            
            var suggestions = response.data.suggestions || [];
            var highlight = response.data.highlight || '';

            // Reset all chips
            $chipsScroll.find('.bizc-plugin-chip').removeClass('suggested');

            // Highlight matched chips
            suggestions.forEach(function(s) {
                $chipsScroll.find('.bizc-plugin-chip[data-slug="' + s.slug + '"]')
                    .addClass('suggested');
            });

            // Reorder: move suggested chips to front
            if (suggestions.length > 0) {
                var slugOrder = suggestions.map(function(s) { return s.slug; });
                var $chips = $chipsScroll.find('.bizc-plugin-chip').detach();
                var sorted = [];
                var rest = [];
                
                $chips.each(function() {
                    var idx = slugOrder.indexOf($(this).data('slug'));
                    if (idx >= 0) {
                        sorted[idx] = this;
                    } else {
                        rest.push(this);
                    }
                });
                
                // Filter nulls from sorted and append
                sorted = sorted.filter(function(el) { return !!el; });
                $chipsScroll.append(sorted).append(rest);

                // Scroll to beginning
                $chipsScroll[0].scrollLeft = 0;
            }

            console.log('🎯 [Pre-Intent] Estimate:', suggestions.length, 'matches, highlight:', highlight);
        });
    }

    // Chip click → select plugin (enters Logic 2 manual routing)
    $chipsScroll.on('click', '.bizc-plugin-chip', function() {
        var $chip = $(this);
        var slug = $chip.data('slug');
        var label = $chip.data('label');
        var icon = $chip.data('icon');

        // Toggle: if already active, deselect
        if ($chip.hasClass('active')) {
            $chipsScroll.find('.bizc-plugin-chip').removeClass('active');
            _clearMention();
            $input.attr('placeholder', 'Nhập tin nhắn... (@ chọn agent · / tìm tool)');
            $input.focus();
            return;
        }

        // Deactivate all, activate this one
        $chipsScroll.find('.bizc-plugin-chip').removeClass('active');
        $chip.addClass('active');

        // Reuse @mention selection flow → enters plugin-context-mode
        _selectMention(slug, label, icon);
        $input.attr('placeholder', 'Nhắn với ' + label + '...');
        $input.focus();
    });

    // Debounced input handler for pre-intent estimate
    $input.on('input', function() {
        // Don't run pre-intent if @mention dropdown is active
        if (_mentionActive) return;

        clearTimeout(_preIntentTimer);
        var text = $input.val().trim();

        _preIntentTimer = setTimeout(function() {
            _preIntentEstimate(text);
        }, 400);
    });

    // Sync chip active state with _clearMention
    var _origClearMention; // will be wrapped after _clearMention is defined

    // ════════════════════════════════════════════════════════════
    //  @Mention — Autocomplete Agent Targeting
    //
    //  Type @ in the input to see a dropdown of available agents.
    //  Select an agent to target commands directly to that plugin.
    //  The provider_hint is sent to the Intent Engine to bias
    //  classification toward the selected agent's goals.
    // ════════════════════════════════════════════════════════════
    var _mentionProvider = null; // { slug, label, icon }
    var _mentionQuery = '';
    var _mentionActive = false;
    var _mentionIdx = 0; // selected index in dropdown
    var $mentionDrop = $('#bizc-mention-dropdown');
    var $mentionTag = $('#bizc-mention-tag');
    
    console.log('Mention system initialized. $mentionDrop found:', $mentionDrop.length > 0);

    // Load agent list from Plugin Suggestion API
    function _getMentionAgents() {
        // Return cached agents if available
        if (window.bizcMentionAgentsCache && window.bizcMentionAgentsCache.length > 0) {
            return window.bizcMentionAgentsCache;
        }
        return [];
    }
    
    // Load agents from Plugin Suggestion API (async)
    function _loadMentionAgents(callback) {
        console.log('_loadMentionAgents called');
        
        // Check cache first (disabled for debugging)
        // if (window.bizcMentionAgentsCache && Date.now() - window.bizcMentionAgentsCacheTime < 30000) {
        //     console.log('Using cached agents');
        //     callback(window.bizcMentionAgentsCache);
        //     return;
        // }
        
        console.log('Fetching agents from API, ajaxurl:', ajaxurl, 'nonce:', nonce);
        
        // Fetch from API
        $.post(ajaxurl, {
            action: 'bizcity_get_plugin_suggestions',
            search: '',
            _wpnonce: nonce
        }, function(response) {
            console.log('API Response received:', response);
            
            // Handle both response formats:
            // 1. response.data.suggestions (new format)
            // 2. response.data (array directly)
            var plugins = [];
            if (response.success && response.data) {
                if (response.data.suggestions && Array.isArray(response.data.suggestions)) {
                    plugins = response.data.suggestions;
                    console.log('Found suggestions array:', plugins.length, 'items');
                } else if (Array.isArray(response.data)) {
                    plugins = response.data;
                    console.log('Found data array:', plugins.length, 'items');
                } else {
                    console.log('Unexpected data format:', typeof response.data);
                }
            } else {
                console.log('Response not successful or no data:', response);
            }
            
            if (plugins.length > 0) {
                var agents = plugins.map(function(plugin) {
                    return {
                        slug: plugin.slug,
                        label: plugin.name,
                        title: plugin.name,
                        icon: plugin.icon_url || plugin.icon || '🤖',
                        description: plugin.description
                    };
                });
                
                // Cache results
                window.bizcMentionAgentsCache = agents;
                window.bizcMentionAgentsCacheTime = Date.now();
                
                console.log('Processed agents:', agents);
                callback(agents);
            } else {
                console.log('No plugins found in response, returning empty');
                callback([]);
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX Failed:', status, error, xhr.responseText);
            callback([]);
        });
    }

    // Render dropdown with ChatGPT-style UI
    function _renderMentionDropdown(items) {
        console.log('Rendering mention dropdown with items:', items);
        
        if (!items || !items.length) {
            console.log('No items to render, hiding dropdown');
            $mentionDrop.html('<div class="bizc-mention-header">Không tìm thấy agent</div>').addClass('active');
            return;
        }
        
        _mentionIdx = 0;
        
        var html = '<div class="bizc-mention-header">Chọn Agent</div>';
        html += '<div class="bizc-mention-list">';
        
        items.forEach(function(a, i) {
            var iconHtml = (a.icon && a.icon.indexOf('/') > -1)
                ? '<img src="' + a.icon + '" alt="">'
                : (a.icon || '🤖');
            
            html += '<div class="bizc-mention-item' + (i === 0 ? ' selected' : '') + '" data-idx="' + i + '" data-slug="' + a.slug + '" data-label="' + (a.title || a.label) + '" data-icon="' + (a.icon || '🤖') + '">'
                + '<div class="bizc-mention-item-icon">' + iconHtml + '</div>'
                + '<div class="bizc-mention-item-info">'
                + '<div class="bizc-mention-item-name">' + (a.title || a.label) + '</div>'
                + '<div class="bizc-mention-item-slug">@' + a.slug + '</div>'
                + '</div></div>';
        });
        
        html += '</div>';
        
        console.log('Dropdown HTML generated, setting active');
        $mentionDrop.html(html).addClass('active');
        _mentionActive = true;
    }

    // Enhanced mention search with debouncing
    var _mentionSearchTimer = null;
    function _searchMentionAgents(query) {
        console.log('Searching agents with query:', query);
        clearTimeout(_mentionSearchTimer);
        _mentionSearchTimer = setTimeout(function() {
            console.log('Debounce complete, loading agents...');
            _loadMentionAgents(function(agents) {
                console.log('Got agents callback:', agents);
                if (query && query.trim()) {
                    var filtered = _filterMentionAgents(query, agents);
                    console.log('Filtered agents:', filtered);
                    _renderMentionDropdown(filtered);
                } else {
                    _renderMentionDropdown(agents);
                }
            });
        }, 150); // Debounce 150ms
    }

    // Improved filter function
    function _filterMentionAgents(query, agents) {
        if (!query) return agents || [];
        var q = query.toLowerCase();
        return (agents || []).filter(function(a) {
            return (a.slug && a.slug.toLowerCase().indexOf(q) > -1)
                || (a.label && a.label.toLowerCase().indexOf(q) > -1)
                || (a.title && a.title.toLowerCase().indexOf(q) > -1)
                || (a.description && a.description.toLowerCase().indexOf(q) > -1);
        }).sort(function(a, b) {
            // Sort by relevance - exact matches first
            var aScore = 0, bScore = 0;
            if (a.slug.toLowerCase().indexOf(q) === 0) aScore += 10;
            if (a.label.toLowerCase().indexOf(q) === 0) aScore += 8;
            if (b.slug.toLowerCase().indexOf(q) === 0) bScore += 10;
            if (b.label.toLowerCase().indexOf(q) === 0) bScore += 8;
            return bScore - aScore;
        });
    }

    // Select a mention agent
    function _selectMention(slug, label, icon) {
        _mentionProvider = { slug: slug, label: label, icon: icon };
        _mentionActive = false;
        _mentionIdx = 0;
        $mentionDrop.removeClass('active').empty();

        // Remove @query from textarea
        var val = $input.val();
        var atMatch = val.match(/@[\w-]*$/);
        if (atMatch) {
            $input.val(val.substring(0, val.length - atMatch[0].length));
        }

        // Show tag badge
        var iconHtml = (icon && icon.indexOf('/') > -1)
            ? '<img src="' + icon + '" style="width:16px;height:16px;border-radius:4px;vertical-align:middle;" alt="">'
            : (icon || '🤖');
        $mentionTag.html(iconHtml + ' ' + label + ' <span class="bizc-mt-remove" title="Bỏ chọn agent">✕</span>').show();
        
        // Enter plugin context mode (if not called from pill selection)
        if (!$('.bizc-pill[data-slug="' + slug + '"]').hasClass('active')) {
            enterPluginContextMode(slug, label, icon);
            
            // Also activate the corresponding pill if exists
            $('.bizc-pill[data-slug="' + slug + '"]').addClass('active');
        }
        
        // Sync Pre-Intent chips bar: activate the matching chip
        $chipsScroll.find('.bizc-plugin-chip').removeClass('active suggested');
        $chipsScroll.find('.bizc-plugin-chip[data-slug="' + slug + '"]').addClass('active');
        
        $input.focus();
    }

    // Clear mention
    function _clearMention() {
        _mentionProvider = null;
        $mentionTag.hide().empty();
        exitPluginContextMode();
        // Sync: deactivate all chips + reset placeholder
        $chipsScroll.find('.bizc-plugin-chip').removeClass('active');
        $input.attr('placeholder', 'Nhập tin nhắn... (@ chọn agent · / tìm tool)');
        _preIntentLastQuery = ''; // allow re-estimate
    }

    // ════════════════════════════════════════════════════════════
    //  / Slash Command — Tool-level Search & Selection
    //
    //  Type / in the input to search tools from bizcity_tool_registry.
    //  Reuses the @mention dropdown UI with tool-specific rendering.
    //  SLASH / = SKILL selection, @ = TOOL selection
    //
    //  When user types /:
    //    1. Search skills catalog
    //    2. Select a skill → sets _selectedSkill for context injection
    //
    //  When user types @:
    //    1. Search tool registry (was previously /)
    //    2. Auto-select the tool's plugin (enter plugin-context-mode)
    //    3. Set the specific goal for the Intent Engine
    //
    //  @since v4.1.0 (Phase 1.7 — Skill/Tool Command Refactor)
    // ════════════════════════════════════════════════════════════
    var _slashActive = false;
    var _slashQuery = '';
    var _slashIdx = 0;
    var _slashSearchTimer = null;
    var _selectedSkill = null; // { skill_key, title, description, path }
    var _selectedTool = null; // { goal, tool_name, title, goal_label, plugin_slug }
    var _contextToolsCache = {}; // { slug: [tools] }
    var $toolPill = $('#bizc-tool-pill');

    /**
     * Load tools for a plugin and render as inline chips in the context header.
     * Cached per slug so repeated enters don't re-fetch.
     * @param {string} pluginSlug
     */
    function _loadContextTools(pluginSlug) {
        var $row = $('#bizc-context-tools');
        if (!pluginSlug) {
            $row.html('<span style="color:#9ca3af;font-size:10px;">Nhấn / để tìm tools</span>');
            return;
        }
        // Use cache
        if (_contextToolsCache[pluginSlug]) {
            _renderContextToolChips(_contextToolsCache[pluginSlug], pluginSlug);
            return;
        }
        $row.html('<span style="color:#9ca3af;font-size:10px;">Đang tải tools...</span>');
        $.post(ajaxurl, {
            action: 'bizcity_search_tools',
            query: '',
            plugin_slug: pluginSlug,
            limit: 20,
            _wpnonce: nonce
        }, function(resp) {
            var tools = (resp.success && resp.data && resp.data.tools) ? resp.data.tools : [];
            _contextToolsCache[pluginSlug] = tools;
            _renderContextToolChips(tools, pluginSlug);
        }).fail(function() {
            $row.html('<span style="color:#9ca3af;font-size:10px;">Nhấn / để tìm tools</span>');
        });
    }

    /**
     * Render tool chips inside the context header tools row.
     * @param {Array}  tools
     * @param {string} pluginSlug
     */
    function _renderContextToolChips(tools, pluginSlug) {
        var $row = $('#bizc-context-tools');
        if (!tools || !tools.length) {
            $row.html('<span style="color:#9ca3af;font-size:10px;">Nhấn / để tìm tools</span>');
            return;
        }
        var html = '';
        tools.forEach(function(t) {
            var label = t.goal_label || t.title || t.goal;
            var activeClass = (_selectedTool && _selectedTool.goal === t.goal) ? ' active' : '';
            html += '<span class="bizc-tool-chip' + activeClass + '" '
                + 'data-goal="' + esc(t.goal) + '" '
                + 'data-tool-name="' + esc(t.tool_name) + '" '
                + 'data-title="' + esc(t.title || t.goal_label) + '" '
                + 'data-goal-label="' + esc(t.goal_label) + '" '
                + 'data-plugin-slug="' + esc(t.plugin_slug || pluginSlug) + '" '
                + 'data-plugin-name="' + esc(t.plugin_name) + '" '
                + 'data-icon="' + esc(t.icon || '🔧') + '">'
                + esc(label)
                + '</span>';
        });
        $row.html(html);
    }

    /**
     * Search skills via AJAX (debounced) — triggered by /command.
     * @param {string} query  Keyword from /command
     */
    function _searchSkills(query) {
        clearTimeout(_slashSearchTimer);
        _slashSearchTimer = setTimeout(function() {
            var params = {
                action: 'bizcity_search_skills',
                query: query || '',
                _wpnonce: nonce
            };

            $.post(ajaxurl, params, function(response) {
                if (!response.success || !response.data) {
                    _renderSlashDropdown([]);
                    return;
                }
                _renderSlashDropdown(response.data.skills || []);
            }).fail(function() {
                _renderSlashDropdown([]);
            });
        }, 150);
    }

    /**
     * Search tools via AJAX (debounced) — triggered by @command.
     * @param {string} query  Keyword from @command
     */
    function _searchTools(query) {
        clearTimeout(_slashSearchTimer);
        _slashSearchTimer = setTimeout(function() {
            var params = {
                action: 'bizcity_search_tools',
                query: query || '',
                _wpnonce: nonce
            };

            $.post(ajaxurl, params, function(response) {
                if (!response.success || !response.data) {
                    _renderAtDropdown([]);
                    return;
                }
                _renderAtDropdown(response.data.tools || []);
            }).fail(function() {
                _renderAtDropdown([]);
            });
        }, 150); // Debounce 150ms
    }

    /**
     * Render tool search results in the mention dropdown.
     * @param {Array} tools  Array of tool objects from API
     */
    /**
     * Render skill search results in the dropdown (/ trigger).
     * @param {Array} skills  Array of skill objects from API
     */
    function _renderSlashDropdown(skills) {
        if (!skills || !skills.length) {
            $mentionDrop.html(
                '<div class="bizc-mention-header">📋 Tìm kiếm Skills</div>' +
                '<div style="padding:12px 16px;color:#9ca3af;font-size:12px;">Không tìm thấy skill nào' +
                (_slashQuery ? ' cho "' + esc(_slashQuery) + '"' : '') + '</div>'
            ).addClass('active');
            _slashActive = true;
            return;
        }

        _slashIdx = 0;

        var html = '<div class="bizc-mention-header">📋 Chọn Skill</div>';
        html += '<div class="bizc-mention-list">';

        skills.forEach(function(s, i) {
            var desc = s.description || '';
            if (desc.length > 80) desc = desc.substring(0, 77) + '...';
            var cat = s.category && s.category !== '/' && s.category !== '.' ? s.category : '';
            var toolsLabel = (s.tools && s.tools.length) ? s.tools.slice(0, 3).join(', ') : '';

            html += '<div class="bizc-mention-item' + (i === 0 ? ' selected' : '') + '" '
                + 'data-idx="' + i + '" '
                + 'data-skill-key="' + esc(s.skill_key) + '" '
                + 'data-title="' + esc(s.title) + '" '
                + 'data-description="' + esc(s.description || '') + '" '
                + 'data-path="' + esc(s.path || '') + '" '
                + 'data-type="skill">'
                + '<div class="bizc-mention-item-icon">📋</div>'
                + '<div class="bizc-mention-item-info">'
                + '<div class="bizc-mention-item-name">' + esc(s.title) + '</div>'
                + '<div class="bizc-mention-item-slug">'
                + '<span style="color:#10b981;">/' + esc(s.skill_key) + '</span>'
                + (cat ? ' · ' + esc(cat) : '')
                + '</div>'
                + (desc ? '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + esc(desc) + '</div>' : '')
                + (toolsLabel ? '<div style="font-size:10px;color:#6366f1;margin-top:1px;">🔧 ' + esc(toolsLabel) + '</div>' : '')
                + '</div></div>';
        });

        html += '</div>';

        $mentionDrop.html(html).addClass('active');
        _slashActive = true;
    }

    /**
     * Render tool search results in the dropdown (@ trigger).
     * @param {Array} tools  Array of tool objects from API
     */
    function _renderAtDropdown(tools) {
        if (!tools || !tools.length) {
            $mentionDrop.html(
                '<div class="bizc-mention-header">🔍 Tìm kiếm Tools</div>' +
                '<div style="padding:12px 16px;color:#9ca3af;font-size:12px;">Không tìm thấy tool nào' +
                (_mentionQuery ? ' cho "' + esc(_mentionQuery) + '"' : '') + '</div>'
            ).addClass('active');
            _mentionActive = true;
            return;
        }

        _mentionIdx = 0;

        var html = '<div class="bizc-mention-header">🔧 Chọn Tool</div>';
        html += '<div class="bizc-mention-list">';

        tools.forEach(function(t, i) {
            var iconHtml = (t.icon && t.icon.indexOf('/') > -1)
                ? '<img src="' + esc(t.icon) + '" alt="" style="width:20px;height:20px;border-radius:4px;">'
                : '🔧';

            var desc = t.goal_description || t.title || '';
            if (desc.length > 60) desc = desc.substring(0, 57) + '...';

            html += '<div class="bizc-mention-item' + (i === 0 ? ' selected' : '') + '" '
                + 'data-idx="' + i + '" '
                + 'data-goal="' + esc(t.goal) + '" '
                + 'data-tool-name="' + esc(t.tool_name) + '" '
                + 'data-title="' + esc(t.title || t.goal_label) + '" '
                + 'data-goal-label="' + esc(t.goal_label) + '" '
                + 'data-plugin-slug="' + esc(t.plugin_slug) + '" '
                + 'data-plugin-name="' + esc(t.plugin_name) + '" '
                + 'data-icon="' + esc(t.icon || '🔧') + '" '
                + 'data-type="tool">'
                + '<div class="bizc-mention-item-icon">' + iconHtml + '</div>'
                + '<div class="bizc-mention-item-info">'
                + '<div class="bizc-mention-item-name">' + esc(t.goal_label || t.title) + '</div>'
                + '<div class="bizc-mention-item-slug">'
                + '<span style="color:#6366f1;">@' + esc(t.goal) + '</span>'
                + (t.plugin_name ? ' · ' + esc(t.plugin_name) : '')
                + '</div>'
                + (desc ? '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">' + esc(desc) + '</div>' : '')
                + '</div></div>';
        });

        html += '</div>';

        $mentionDrop.html(html).addClass('active');
        _mentionActive = true;
    }

    /**
     * User selects a skill from the / dropdown.
     */
    function _selectSkill(skillKey, title, description, path) {
        _selectedSkill = {
            skill_key: skillKey,
            title: title,
            description: description,
            path: path
        };

        // Close dropdown
        _slashActive = false;
        _slashIdx = 0;
        $mentionDrop.removeClass('active').empty();

        // Remove / query from textarea
        var val = $input.val();
        var slashMatch = val.match(/\/[\S]*$/);
        if (slashMatch) {
            $input.val(val.substring(0, val.length - slashMatch[0].length));
        }

        // Update placeholder
        $input.attr('placeholder', 'Nhập yêu cầu với skill ' + (title || skillKey) + '...');

        // Show skill pill
        $toolPill.html('📋 /' + esc(skillKey)
            + ' <span class="bizc-pill-remove" title="Thoát skill">✕</span>').show();

        console.log('📋 [Slash] Skill selected:', skillKey, 'path:', path);
        $input.focus();
    }

    /**
     * User selects a tool from the @ dropdown.
     * Auto-selects the parent plugin and enters focused tool mode.
     */
    function _selectTool(goal, toolName, title, goalLabel, pluginSlug, pluginName, icon) {
        _selectedTool = {
            goal: goal,
            tool_name: toolName,
            title: title,
            goal_label: goalLabel,
            plugin_slug: pluginSlug
        };

        // Close dropdown
        _slashActive = false;
        _slashIdx = 0;
        _mentionActive = false;
        _mentionIdx = 0;
        $mentionDrop.removeClass('active').empty();

        // Remove @ query from textarea (tool is now triggered by @)
        var val = $input.val();
        var atMatch = val.match(/@[\S]*$/);
        if (atMatch) {
            $input.val(val.substring(0, val.length - atMatch[0].length));
        }

        // Auto-select plugin (enters plugin-context-mode)
        if (pluginSlug && (!_mentionProvider || _mentionProvider.slug !== pluginSlug)) {
            _selectMention(pluginSlug, pluginName || pluginSlug, icon || '🔧');
        }

        // Update placeholder to hint tool usage
        var toolLabel = goalLabel || title || goal;
        $input.attr('placeholder', 'Mô tả yêu cầu cho ' + toolLabel + '...');

        // Show tool pill inline in input row
        $toolPill.html('@' + esc(toolName || goal)
            + ' <span class="bizc-pill-remove" title="Thoát tool">✕</span>').show();

        console.log('🔧 [@] Tool selected:', goal, 'plugin:', pluginSlug);
        $input.focus();
    }

    /**
     * Clear tool selection (but may keep plugin selection).
     */
    function _clearToolSelection() {
        _selectedTool = null;
        _selectedSkill = null;
        _slashActive = false;
        _slashIdx = 0;
        $mentionDrop.removeClass('active').empty();
        // Clear active tool chip highlight
        $('.bizc-tool-chip').removeClass('active');
        // Hide tool pill
        $toolPill.hide().empty();
        // Reset placeholder
        if (_mentionProvider) {
            $input.attr('placeholder', 'Nhập tin nhắn cho ' + (_mentionProvider.label || _mentionProvider.slug) + '...');
        } else {
            $input.attr('placeholder', 'Nhập tin nhắn... (/ chọn skill · @ tìm tool)');
        }
    }

    /**
     * Get currently selected tool (for sendMsg to include in request).
     * @return {object|null} { goal, tool_name, plugin_slug }
     */
    function _getSelectedTool() {
        return _selectedTool;
    }

    // Click handler for skill items in dropdown (/ trigger)
    $mentionDrop.on('click', '.bizc-mention-item[data-type="skill"]', function(e) {
        e.stopImmediatePropagation();
        var $el = $(this);
        _selectSkill(
            $el.data('skill-key'),
            $el.data('title'),
            $el.data('description'),
            $el.data('path')
        );
    });

    // Click handler for tool items in dropdown (@ trigger)
    $mentionDrop.on('click', '.bizc-mention-item[data-type="tool"]', function(e) {
        e.stopImmediatePropagation();
        var $el = $(this);
        _selectTool(
            $el.data('goal'),
            $el.data('tool-name'),
            $el.data('title'),
            $el.data('goal-label'),
            $el.data('plugin-slug'),
            $el.data('plugin-name'),
            $el.data('icon')
        );
    });

    // Fallback click handler for other dropdown items (legacy mention)
    $mentionDrop.on('click', '.bizc-mention-item', function() {
        var $el = $(this);
        if ($el.data('type') === 'skill' || $el.data('type') === 'tool') return; // handled above
        _selectMention($el.data('slug'), $el.data('label'), $el.data('icon'));
    });

    // Click handler for removing mention tag
    $mentionTag.on('click', '.bizc-mt-remove', function() {
        _clearMention();
        _clearToolSelection();
        $input.focus();
    });

    // Click handler for removing tool pill
    $toolPill.on('click', '.bizc-pill-remove', function() {
        _clearToolSelection();
        $input.focus();
    });

    // Close dropdown on outside click
    $(document).on('mousedown', function(e) {
        if (!$(e.target).closest('#bizc-mention-dropdown, #bizc-input').length) {
            $mentionDrop.removeClass('active').empty();
            _mentionActive = false;
        }
    });

    // Enhanced keyboard navigation for @mention and /slash dropdown
    $input.on('keydown', function(e) {
        // Handle /slash command navigation (same UI as @mention)
        if (_slashActive) {
            var items = $mentionDrop.find('.bizc-mention-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                _slashIdx = Math.min(_slashIdx + 1, items.length - 1);
                items.removeClass('selected').eq(_slashIdx).addClass('selected');
                var $selected = items.eq(_slashIdx);
                var dropdown = $mentionDrop[0];
                var itemTop = $selected.position().top;
                var itemBottom = itemTop + $selected.outerHeight();
                var dropdownHeight = $mentionDrop.height();
                if (itemBottom > dropdownHeight) dropdown.scrollTop += itemBottom - dropdownHeight;
                else if (itemTop < 0) dropdown.scrollTop += itemTop;
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                _slashIdx = Math.max(_slashIdx - 1, 0);
                items.removeClass('selected').eq(_slashIdx).addClass('selected');
                var $sel2 = items.eq(_slashIdx);
                if ($sel2.position().top < 0) $mentionDrop[0].scrollTop += $sel2.position().top;
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                e.stopImmediatePropagation();
                var $sel = items.eq(_slashIdx);
                if ($sel.data('type') === 'skill') {
                    _selectSkill(
                        $sel.data('skill-key'),
                        $sel.data('title'),
                        $sel.data('description'),
                        $sel.data('path')
                    );
                } else if ($sel.data('type') === 'tool') {
                    _selectTool(
                        $sel.data('goal'),
                        $sel.data('tool-name'),
                        $sel.data('title'),
                        $sel.data('goal-label'),
                        $sel.data('plugin-slug'),
                        $sel.data('plugin-name'),
                        $sel.data('icon')
                    );
                }
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                $mentionDrop.removeClass('active').empty();
                _slashActive = false;
                _slashIdx = 0;
                return;
            }
        }

        // Handle existing @ mention navigation
        if (_mentionActive) {
            var items = $mentionDrop.find('.bizc-mention-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                _mentionIdx = Math.min(_mentionIdx + 1, items.length - 1);
                items.removeClass('selected').eq(_mentionIdx).addClass('selected');
                
                // Scroll into view
                var $selected = items.eq(_mentionIdx);
                var dropdown = $mentionDrop[0];
                var itemTop = $selected.position().top;
                var itemBottom = itemTop + $selected.outerHeight();
                var dropdownHeight = $mentionDrop.height();
                
                if (itemBottom > dropdownHeight) {
                    dropdown.scrollTop += itemBottom - dropdownHeight;
                } else if (itemTop < 0) {
                    dropdown.scrollTop += itemTop;
                }
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                _mentionIdx = Math.max(_mentionIdx - 1, 0);
                items.removeClass('selected').eq(_mentionIdx).addClass('selected');
                
                // Scroll into view
                var $selected = items.eq(_mentionIdx);
                var itemTop = $selected.position().top;
                if (itemTop < 0) {
                    $mentionDrop[0].scrollTop += itemTop;
                }
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                e.stopImmediatePropagation(); // Prevent sendMsg() from firing
                var $sel = items.eq(_mentionIdx);
                if ($sel.data('type') === 'tool') {
                    _selectTool(
                        $sel.data('goal'),
                        $sel.data('tool-name'),
                        $sel.data('title'),
                        $sel.data('goal-label'),
                        $sel.data('plugin-slug'),
                        $sel.data('plugin-name'),
                        $sel.data('icon')
                    );
                } else {
                    _selectMention($sel.data('slug'), $sel.data('label'), $sel.data('icon'));
                }
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                $mentionDrop.removeClass('active').empty();
                _mentionActive = false;
                _mentionIdx = 0;
                return;
            }
        }
        
        // Handle regular Enter for sending message (when not in mention/slash mode)  
        if (e.key === 'Enter' && !e.shiftKey && !_mentionActive && !_slashActive) {
            e.preventDefault();
            sendMsg();
        }
    });

// Watch input for / (skills) and @ (tools) triggers with async loading
    $input.on('input', function() {
        var val = $input.val();

        // ── / Slash command detection → Search Skills ──
        // Match /keyword at start of input OR after whitespace
        var slashMatch = val.match(/(?:^|\s)\/([\S]*)$/);
        if (slashMatch) {
            _slashQuery = slashMatch[1] || '';
            console.log('/ detected, searching skills:', _slashQuery);
            
            // Close @mention if active
            if (_mentionActive) {
                _mentionActive = false;
            }

            // Show loading state
            $mentionDrop.html(
                '<div class="bizc-mention-header">📋 Tìm kiếm Skills...</div>' +
                '<div style="padding:12px 16px;text-align:center;color:#9ca3af;font-size:12px;">⏳ Đang tải...</div>'
            ).addClass('active');
            _slashActive = true;

            // Search skills
            _searchSkills(_slashQuery);
        }
        // ── @ Mention detection → Search Tools ──
        else {
            var atMatch = val.match(/@([\w-]*)$/);
            if (atMatch) {
                _mentionQuery = atMatch[1];
                console.log('@ detected, searching tools:', _mentionQuery);
                
                // Close /slash if active
                if (_slashActive) {
                    _slashActive = false;
                }

                // Show loading state
                $mentionDrop.html('<div class="bizc-mention-header">🔧 Tìm kiếm Tools...</div>' +
                    '<div style="padding: 16px; text-align: center; color: #9ca3af; font-size: 12px;">⏳ Đang tải...</div>').addClass('active');
                _mentionActive = true;
                
                // Search tools instead of agents
                _searchTools(_mentionQuery);
            } else if (_mentionActive || _slashActive) {
                $mentionDrop.removeClass('active').empty();
                _mentionActive = false;
                _slashActive = false;
            }
        }
        
        // Also handle auto-resize
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        updateBtn();
    });
    
    // Tool cards are now links to BizCoach Map steps
    // No click handler needed

    // ── Projects (ChatGPT-style folders) ──
    function loadProjects() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'bizcity_project_list', _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                projects = (res.success && res.data) ? res.data : [];
                renderProjects();
            },
            error: function() { projects = []; renderProjects(); }
        });
    }

    function renderProjects() {
        var $list = $('#bizc-proj-list').empty();
        if (!projects.length) {
            $list.append('<div style="padding:8px 12px;color:#6b7280;font-size:12px;">Chưa có dự án</div>');
            return;
        }
        projects.forEach(function(proj) {
            var isOpen = openProjects[proj.id] || false;
            var $item = $('<div class="bizc-proj-item" data-project-id="' + esc(proj.id) + '"></div>');

            var $header = $('<div class="bizc-proj-header' + (isOpen ? ' active' : '') + '">' +
                '<span class="bizc-proj-arrow' + (isOpen ? ' open' : '') + '">▶</span>' +
                '<span class="bizc-proj-icon">' + esc(proj.icon || '📁') + '</span>' +
                '<span class="bizc-proj-name">' + esc(proj.name) + '</span>' +
                '<span class="bizc-proj-count">' + (proj.session_count || proj.conv_count || 0) + '</span>' +
                '<span class="bizc-proj-menu-btn" title="Menu">⋯</span>' +
                '</div>');

            var $convs = $('<div class="bizc-proj-convs' + (isOpen ? '' : ' collapsed') + '"></div>');

            // Toggle + show project detail
            $header.on('click', function(e) {
                if ($(e.target).hasClass('bizc-proj-menu-btn')) return;
                var wasOpen = openProjects[proj.id] || false;
                openProjects[proj.id] = !wasOpen;
                $header.toggleClass('active');
                $header.find('.bizc-proj-arrow').toggleClass('open');
                $convs.toggleClass('collapsed');
                if (!wasOpen) {
                    loadProjectSessions(proj.id, $convs);
                    showProjectDetail(proj);
                } else {
                    hideProjectDetail();
                }
            });

            // Menu button
            $header.find('.bizc-proj-menu-btn').on('click', function(e) {
                e.stopPropagation();
                showProjectMenu(e.pageX, e.pageY, proj);
            });

            // Drop target for drag & drop (improved visual feedback)
            $item[0].addEventListener('dragover', function(e) { 
                e.preventDefault(); 
                e.dataTransfer.dropEffect = 'move';
                $item.addClass('drag-over');
            });
            $item[0].addEventListener('dragleave', function(e) { 
                // Only remove highlight if leaving the item entirely
                if (!$item[0].contains(e.relatedTarget)) {
                    $item.removeClass('drag-over');
                }
            });
            $item[0].addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $item.removeClass('drag-over');
                var rawData = e.dataTransfer.getData('text/plain');
                var wcId = parseInt(rawData);
                if (wcId && wcId > 0) {
                    moveSessionToProject(wcId, proj.id);
                } else {
                    console.warn('[bizc-dash] DROP: invalid wcId, skipping move');
                }
            });

            $item.append($header).append($convs);
            $list.append($item);
            if (isOpen) loadProjectSessions(proj.id, $convs);
        });
    }

    function loadProjectSessions(projectId, $container) {
        $container.html('<div style="padding:6px 12px 6px 32px;color:#9ca3af;font-size:11px;">Đang tải...</div>');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'bizcity_webchat_sessions', project_id: projectId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                var sessions = (res.success && res.data) ? res.data : [];
                $container.empty();
                if (!sessions.length) {
                    $container.html('<div style="padding:6px 12px 6px 32px;color:#9ca3af;font-size:11px;">Trống</div>');
                    return;
                }
                sessions.forEach(function(s) {
                    var displayTitle = s.title && s.title.trim() ? s.title : 'Hội thoại mới';
                    var $c = $('<div class="bizc-proj-conv' + (s.id === currentWcId ? ' active' : '') + '" data-wc-id="' + s.id + '">' +
                        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path></svg> ' +
                        '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(displayTitle) + '</span>' +
                        '</div>');
                    $c.on('click', function() { loadSession(s.id); });
                    $container.append($c);
                });
            }
        });
    }

    function showAddProjectForm() {
        console.log('[bizc-dash] showAddProjectForm() called');
        var $list = $('#bizc-proj-list');
        if ($list.find('.bizc-proj-add-form').length) {
            $list.find('.bizc-proj-add-form input').focus();
            return;
        }
        // Build form elements individually for reliable event binding
        var $form = $('<div class="bizc-proj-add-form"></div>');
        var $inp = $('<input type="text" placeholder="Tên dự án..." />');
        var $btnOk = $('<button type="button" class="bizc-proj-save">OK</button>');
        var $btnCancel = $('<button type="button" class="bizc-proj-cancel">✕</button>');
        $form.append($inp).append($btnOk).append($btnCancel);
        $list.prepend($form);
        $inp.focus();

        var _creating = false;
        var doCreate = function() {
            var name = $inp.val().trim();
            console.log('[bizc-dash] doCreate() name="' + name + '"');
            if (!name) { $form.remove(); return; }
            if (_creating) return;
            _creating = true;
            $btnOk.prop('disabled', true).text('...');
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'bizcity_project_create', name: name, _wpnonce: nonce },
                dataType: 'json',
                success: function(res) {
                    console.log('[bizc-dash] project_create response:', res);
                    $form.remove();
                    if (res.success) {
                        // Add auto-created character to the select dropdown
                        if (res.data && res.data.character_id && res.data.character_id > 0) {
                            var $sel = $('#bizc-proj-character-select');
                            if (!$sel.find('option[value="' + res.data.character_id + '"]').length) {
                                var charLabel = (res.data.icon || '📁') + ' ' + (res.data.name || 'Dự án');
                                $sel.append('<option value="' + res.data.character_id + '">' + esc(charLabel) + '</option>');
                            }
                        }
                        loadProjects();
                    }
                    else alert(res.data && res.data.message ? res.data.message : 'Lỗi tạo dự án');
                },
                error: function(xhr, status, err) {
                    console.error('[bizc-dash] project_create AJAX error:', status, err);
                    $form.remove();
                    alert('Lỗi kết nối khi tạo dự án');
                }
            });
        };

        // Bind events directly on jQuery elements
        $btnOk.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            doCreate();
        });
        $btnCancel.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $form.remove();
        });
        $inp.on('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); doCreate(); }
            if (e.key === 'Escape') { e.preventDefault(); $form.remove(); }
        });
        // Prevent clicks inside form from bubbling to parent handlers
        $form.on('click', function(e) { e.stopPropagation(); });
    }

    function showProjectMenu(x, y, proj) {
        $('.bizc-ctx-menu').remove();
        var $menu = $('<div class="bizc-ctx-menu" style="left:' + x + 'px;top:' + y + 'px;"></div>');
        $menu.append('<div class="bizc-ctx-menu-item" data-action="rename">✏️ Đổi tên</div>');
        $menu.append('<div class="bizc-ctx-menu-item danger" data-action="delete">🗑️ Xóa dự án</div>');
        $menu.on('click', '.bizc-ctx-menu-item', function() {
            var act = $(this).data('action');
            $menu.remove();
            if (act === 'rename') {
                var newName = prompt('Tên mới:', proj.name);
                if (newName && newName.trim()) {
                    $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'bizcity_project_rename', project_id: proj.id, name: newName.trim(), _wpnonce: nonce }, dataType: 'json', success: function() { loadProjects(); } });
                }
            } else if (act === 'delete') {
                if (confirm('Xóa dự án "' + proj.name + '"? Hội thoại bên trong sẽ chuyển về Gần đây.')) {
                    $.ajax({ url: ajaxurl, type: 'POST', data: { action: 'bizcity_project_delete', project_id: proj.id, _wpnonce: nonce }, dataType: 'json', success: function() { loadProjects(); loadSessions(); } });
                }
            }
        });
        $('body').append($menu);
    }

    function moveSessionToProject(wcId, projectId) {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_session_move', session_id: wcId, project_id: projectId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    loadProjects(); loadSessions();
                } else {
                    console.warn('[bizc-dash] session_move failed:', res.data);
                }
            },
            error: function(xhr, status, err) {
                console.error('[bizc-dash] session_move error:', status, err);
            }
        });
    }

    // ── Project Detail Panel (ChatGPT-style) ──
    var currentProjectData = null; // Store full project data
    
    function showProjectDetail(proj) {
        currentProjectId = proj.id;
        currentProjectData = proj;
        $('#bizc-proj-detail-icon').text(proj.icon || '📁');
        $('#bizc-proj-detail-name').text(proj.name);
        // Set character binding value
        $('#bizc-proj-character-select').val(proj.character_id || 0);
        $('#bizc-proj-char-status').text('');
        $('#bizc-chat-panel').hide();
        $('#bizc-project-detail').css('display', 'flex');
        loadProjectDetailList(proj.id);
        $('.bizc-proj-header').removeClass('active');
        $('.bizc-proj-item[data-project-id="' + proj.id + '"] .bizc-proj-header').addClass('active');
    }
    
    // Character binding change handler
    $('#bizc-proj-character-select').on('change', function() {
        var charId = parseInt($(this).val()) || 0;
        if (!currentProjectId) return;
        var $status = $('#bizc-proj-char-status');
        $status.text('Đang lưu...').css('color', '#9ca3af');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'bizcity_project_update',
                project_id: currentProjectId,
                character_id: charId,
                _wpnonce: nonce
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $status.text('✓ Đã lưu').css('color', '#22c55e');
                    // Update cached project data
                    if (currentProjectData) currentProjectData.character_id = charId;
                    loadProjects(); // Refresh sidebar
                } else {
                    $status.text('❌ Lỗi').css('color', '#ef4444');
                }
                setTimeout(function() { $status.text(''); }, 2000);
            },
            error: function() {
                $status.text('❌ Lỗi kết nối').css('color', '#ef4444');
                setTimeout(function() { $status.text(''); }, 2000);
            }
        });
    });

    function hideProjectDetail() {
        currentProjectId = null;
        $('#bizc-project-detail').hide();
        // Also dismiss agent panel if open (lazy-unload iframe)
        if ($('#bizc-agent-panel').is(':visible')) {
            $('#bizc-agent-panel').hide().data('slug', '');
            loadAgentIframe(''); // Clear iframe to release memory
            $('.bizc-tb-agent').removeClass('active');
        }
        $('#bizc-chat-panel').css('display', 'flex');
        $('.bizc-proj-header').removeClass('active');
    }

    /**
     * Lazy-load iframe helper: clear old iframe first, then load new URL.
     * Ensures only 1 iframe content is loaded at a time to reduce DOM load.
     * @param {string} url - The URL to load (or empty/blank to clear)
     */
    function loadAgentIframe(url) {
        var $iframe = $('#bizc-agent-iframe');
        // Always clear first (unload any existing content)
        $iframe.attr('src', 'about:blank');
        // If a valid URL is provided, load it after a micro-tick
        if (url && url !== 'about:blank') {
            setTimeout(function() {
                $iframe.attr('src', url);
            }, 10);
        }
    }

    function hideAgentPanel() {
        $('#bizc-agent-panel').hide().data('slug', '');
        // Clear iframe to release memory (lazy-unload)
        loadAgentIframe('');
        $('.bizc-tb-agent').removeClass('active');
        $('.bizc-tb-link').removeClass('active');
        $('.bizc-tb-chat').addClass('active');
        $('#bizc-chat-panel').css('display', 'flex');
        // Reset URL to base (no agent/panel param)
        bizcPushUrl();
        // Reset page header for mobile
        updatePageHeader('Trò chuyện gần đây', false);
    }

    /**
     * Open an inline detail page (tasks, sessions) in the agent iframe panel.
     * Reuses the existing agent panel — sets title, loads URL, hides chat.
     * @param {string} title - Panel header title
     * @param {string} url   - URL to load in iframe
     */
    function openInlinePanel(title, url) {
        // Deactivate touch bar buttons
        $('.bizc-tb-agent, .bizc-tb-link, .bizc-tb-chat').removeClass('active');
        // Set header
        $('#bizc-agent-title').text(title);
        $('#bizc-agent-icon').hide();
        // Lazy-load iframe
        loadAgentIframe(url);
        // Show panel, hide chat
        $('#bizc-agent-panel').data('slug', 'inline-detail').css('display', 'flex');
        $('#bizc-chat-panel').hide();
        $('#bizc-project-detail').hide();
        // Mobile header
        updatePageHeader(title, true);
    }

    /**
     * Listen for postMessage from agent/canvas iframes.
     * - bizcity_agent_command: agent sends text → hide agent, send as chat message
     * - bizcity_canvas_ready: iframe loaded, send context
     * - bizcity_canvas_done: generation completed, update status
     * - bizcity_canvas_title: update header title
     * - bizcity_canvas_error: generation error
     */
    window.addEventListener('message', function(event) {
        if (!event.data || !event.data.type) return;

        // Security: only accept same-origin messages
        if (event.origin !== window.location.origin) return;

        switch (event.data.type) {
            case 'bizcity_agent_command':
                var text = (event.data.text || '').trim();
                if (!text) return;
                hideAgentPanel();
                setTimeout(function() {
                    $input.val(text);
                    sendMsg();
                }, 150);
                break;

            case 'bizcity_canvas_ready':
                // Send context to iframe
                var iframe = document.getElementById('bizc-canvas-iframe');
                if (iframe && iframe.contentWindow) {
                    iframe.contentWindow.postMessage({
                        type: 'bizcity_canvas_context',
                        session_id: window.bizcSessionId || '',
                        user_id: (window.bizcData || {}).userId || 0,
                        output_id: $('#bizc-rp-canvas').data('outputId') || 0
                    }, window.location.origin);
                }
                break;

            case 'bizcity_canvas_done':
                updateCanvasStatus('completed');
                break;

            case 'bizcity_canvas_error':
                updateCanvasStatus('error');
                break;

            case 'bizcity_canvas_title':
                if (event.data.title) {
                    $('#bizc-canvas-title').text(event.data.title);
                }
                break;
        }
    });

    /**
     * Show agent panel by slug — reusable for click + URL restore (lazy-load)
     */
    function showAgentBySlug(slug, title, iconSrc, iframeUrl) {
        $('.bizc-tb-agent').removeClass('active');
        $('.bizc-tb-link').removeClass('active');
        $('.bizc-tb-chat').removeClass('active');

        // Find & activate the matching touch bar button
        var $btn = $('#bizc-touchbar .bizc-tb-agent[data-slug="' + slug + '"]');
        if ($btn.length) {
            $btn.addClass('active');
            if (!title || title === slug) title = $btn.attr('title') || slug;
            if (!iconSrc) iconSrc = $btn.find('.bizc-tb-icon img').attr('src') || '';
            if (!iframeUrl) {
                var u = $btn.data('src') || '';
                iframeUrl = u + (u.indexOf('?') > -1 ? '&' : '?') + 'bizcity_iframe=1';
            }
        }

        // Populate header
        $('#bizc-agent-title').text(title || slug);
        if (iconSrc) { $('#bizc-agent-icon').attr('src', iconSrc).show(); }
        else { $('#bizc-agent-icon').hide(); }

        // Update page header for mobile (aiagent-header)
        updatePageHeader(title || slug, true);

        // Lazy-load iframe: clear old, then load new
        loadAgentIframe(iframeUrl);
        $('#bizc-agent-panel').data('slug', slug).css('display', 'flex');
        $('#bizc-chat-panel').hide();
        $('#bizc-project-detail').hide();
    }

    /**
     * Push URL state — SPA-style routing
     * In admin: /wp-admin/admin.php?page=bizcity-webchat-dashboard&chat=wcs_xxx
     * In frontend: /chat/?chat=wcs_xxx
     * bizcPushUrl()  →  reset to base
     */
    function bizcPushUrl(key, value) {
        var base = window.location.pathname;
        var url = base;
        
        // In wp-admin, preserve the page= param
        var isAdmin = base.indexOf('/wp-admin/') !== -1;
        var pageParam = '';
        if (isAdmin) {
            var urlParams = new URLSearchParams(window.location.search);
            pageParam = urlParams.get('page') || 'bizcity-webchat-dashboard';
            url = base + '?page=' + encodeURIComponent(pageParam);
            if (key && value) {
                url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
            }
        } else {
            // Frontend: /chat/?chat=wcs_xxx
            if (key && value) {
                url = base + '?' + encodeURIComponent(key) + '=' + encodeURIComponent(value);
            }
        }
        
        if (window.location.href !== window.location.origin + url) {
            history.pushState({ bizcKey: key || '', bizcVal: value || '' }, '', url);
        }
    }

    /**
     * Handle browser back/forward buttons
     */
    $(window).on('popstate', function(e) {
        var state = e.originalEvent.state;
        if (state && state.bizcKey === 'agent' && state.bizcVal) {
            showAgentBySlug(state.bizcVal, '', '', '');
        } else if (state && state.bizcKey === 'panel' && state.bizcVal) {
            var $link = $('#bizc-touchbar .bizc-tb-link').filter(function() {
                return ($(this).data('src') || '').indexOf('page=' + state.bizcVal) > -1;
            });
            if ($link.length) $link.trigger('click');
        } else if (state && state.bizcKey === 'chat' && state.bizcVal) {
            // Restore a specific chat session
            var $conv = $('#bizc-convs-list .bizc-conv').filter(function() {
                return $(this).data('session-id') === state.bizcVal;
            });
            var wcId = $conv.length ? $conv.data('wc-id') : null;
            if (wcId) { loadSession(wcId, true); }
            else { _loadSessionBySessionId(state.bizcVal); }
        } else if (state && state.bizcKey === 'canvas' && state.bizcVal) {
            // Phase 1.20: Restore canvas panel from URL state
            _restoreCanvasFromUrl(state.bizcVal);
        } else {
            // Back to chat (default / new chat) - lazy-unload iframe
            $('#bizc-agent-panel').hide().data('slug', '');
            loadAgentIframe(''); // Clear iframe to release memory
            hideCanvasPanel();
            $('.bizc-tb-agent').removeClass('active');
            $('.bizc-tb-link').removeClass('active');
            $('#bizc-chat-panel').css('display', 'flex');
        }
    });

    /**
     * On page load: auto-open agent/panel from URL params
     */
    /**
     * Helper: load session by session_id string (for URL restore)
     */
    function _loadSessionBySessionId(sid) {
        // Try to find wcId from sidebar
        var found = false;
        wcSessions.forEach(function(s) {
            if (s.session_id === sid && !found) {
                found = true;
                loadSession(s.id, true);
            }
        });
        if (!found) {
            // Sessions not loaded yet (race) — try AJAX directly with UUID
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'bizcity_webchat_session_messages', session_id: sid, _wpnonce: nonce },
                dataType: 'json',
                success: function(res) {
                    if (!res.success || !res.data) return;
                    currentWcId = res.data.id || 0;
                    sessionId = res.data.session_id;
                    window.bizcSessionId = sessionId;
                    window.bizcCurrentSessionId = sessionId;
                    messages = [];
                    $msgs.empty();
                    var msgs = res.data.messages || [];
                    msgs.forEach(function(m) {
                        if (m.from === 'system') return;
                        var from = (m.from === 'bot') ? 'bot' : 'user';
                        var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                        var mTs = m.created_ts ? m.created_ts * 1000 : new Date(m.created_at).getTime();
                        messages.push({ role: from, content: m.text, timestamp: mTs, images: imgs });
                        appendMsg(m.text, from, mTs, false, imgs);
                    });
                    scrollBottom();
                    renderSessions();
                }
            });
        }
    }

    (function bizcRestoreFromUrl() {
        var params = new URLSearchParams(window.location.search);
        var agent  = params.get('agent');
        var panel  = params.get('panel');
        var chat   = params.get('chat');
        var canvas = params.get('canvas');
        if (agent) {
            history.replaceState({ bizcKey: 'agent', bizcVal: agent }, '', window.location.href);
            showAgentBySlug(agent, '', '', '');
        } else if (panel) {
            history.replaceState({ bizcKey: 'panel', bizcVal: panel }, '', window.location.href);
            var $link = $('#bizc-touchbar .bizc-tb-link').filter(function() {
                return ($(this).data('src') || '').indexOf('page=' + panel) > -1;
            });
            if ($link.length) $link.trigger('click');
        } else if (chat) {
            history.replaceState({ bizcKey: 'chat', bizcVal: chat }, '', window.location.href);
            setTimeout(function() { _loadSessionBySessionId(chat); }, 500);
        } else if (canvas) {
            // Phase 1.20: Restore canvas from URL
            history.replaceState({ bizcKey: 'canvas', bizcVal: canvas }, '', window.location.href);
            _restoreCanvasFromUrl(canvas);
        }
    })();

    // ══════════════════════════════════════════════════════════════
    //  Phase 1.20 — Right Panel (Canvas, Suy nghĩ, Studio)
    // ══════════════════════════════════════════════════════════════

    var _rpActiveTab = 'canvas';

    /** Toggle right panel visibility */
    function toggleRightPanel(open) {
        var $rp = $('#bizc-right-panel');
        if (typeof open === 'undefined') open = !$rp.is(':visible');
        if (open) {
            $rp.css('display', 'flex');
            $('.bizc-dash').addClass('rp-open');
        } else {
            $rp.hide();
            $('.bizc-dash').removeClass('rp-open');
        }
    }

    /** Switch right panel tab */
    function switchRpTab(tab) {
        _rpActiveTab = tab;
        $('.bizc-rp-tab').removeClass('active');
        $('.bizc-rp-tab[data-rp-tab="' + tab + '"]').addClass('active');
        $('.bizc-rp-content').hide();
        $('#bizc-rp-' + tab).css('display', 'flex');
    }

    // Tab click handlers
    $(document).on('click', '.bizc-rp-tab[data-rp-tab]', function() {
        switchRpTab($(this).data('rp-tab'));
    });
    // Close button
    $(document).on('click', '#bizc-rp-close', function() {
        toggleRightPanel(false);
    });
    // Toggle button (in chat panel)
    $(document).on('click', '#bizc-rp-toggle', function() {
        toggleRightPanel();
    });

    // ── Canvas type icon map ──
    var _canvasTypeIcons = {
        content: '📄', image: '🖼️', video: '🎬', design: '🎨', document: '📝'
    };

    // ── Canvas context menu per type ──
    var _canvasMenuItems = {
        content: [
            { action: 'export-pdf',  icon: '📄', label: 'Tải PDF' },
            { action: 'export-word', icon: '📝', label: 'Tải Word' },
            { action: 'export-pptx', icon: '📊', label: 'Tải PPTX' },
            { action: 'share',       icon: '🔗', label: 'Chia sẻ' }
        ],
        image: [
            { action: 'download', icon: '⬇️', label: 'Tải về' },
            { action: 'edit',     icon: '✏️', label: 'Chỉnh sửa' }
        ],
        video: [
            { action: 'download', icon: '⬇️', label: 'Tải về' }
        ],
        design: [
            { action: 'download',  icon: '⬇️', label: 'Tải về' },
            { action: 'duplicate', icon: '📋', label: 'Nhân bản' }
        ]
    };

    /** Update canvas status badge */
    function updateCanvasStatus(status) {
        var $s = $('#bizc-canvas-status');
        $s.removeClass('generating completed error');
        switch (status) {
            case 'generating':
                $s.addClass('generating').text('⏳ Đang tạo...');
                break;
            case 'completed':
                $s.addClass('completed').text('✅ Hoàn thành');
                break;
            case 'error':
                $s.addClass('error').text('❌ Lỗi');
                break;
            default:
                $s.text('');
        }
    }

    /** Build context menu dropdown based on type */
    function buildCanvasMenu(type) {
        var items = _canvasMenuItems[type] || _canvasMenuItems.content;
        var html = '';
        items.forEach(function(item) {
            html += '<button class="bizc-canvas-dropdown-item" data-action="' + item.action + '">'
                  + '<span class="bizc-cd-icon">' + item.icon + '</span>'
                  + '<span>' + item.label + '</span>'
                  + '</button>';
        });
        $('#bizc-canvas-dropdown').html(html);
    }

    /** Lazy-load canvas iframe */
    function loadCanvasIframe(url) {
        var $iframe = $('#bizc-canvas-iframe');
        $iframe.attr('src', 'about:blank');
        if (url && url !== 'about:blank') {
            var sep = url.indexOf('?') > -1 ? '&' : '?';
            var fullUrl = url + sep + 'bizcity_iframe=1';
            setTimeout(function() { $iframe.attr('src', fullUrl).show(); }, 10);
            $('#bizc-canvas-empty').hide();
            $('#bizc-canvas-header').show();
        } else {
            $iframe.hide();
            $('#bizc-canvas-header').hide();
            $('#bizc-canvas-empty').show();
        }
    }

    /**
     * Show canvas in right panel
     * @param {Object} options
     * @param {string} options.url       - URL to load in iframe
     * @param {string} options.title     - Header title
     * @param {string} options.icon      - Emoji icon
     * @param {string} options.type      - 'content'|'image'|'video'|'design'
     * @param {number} options.outputId  - studio_outputs.id
     * @param {string} options.status    - 'generating'|'completed'|'error'
     */
    function showCanvasPanel(options) {
        if (!options || !options.url) return;

        // Set header
        $('#bizc-canvas-title').text(options.title || 'Canvas');
        $('#bizc-canvas-icon').html(options.icon || _canvasTypeIcons[options.type] || '📄');
        updateCanvasStatus(options.status || 'ready');

        // Build context menu
        buildCanvasMenu(options.type || 'content');

        // Store metadata
        $('#bizc-rp-canvas').data({
            outputId: options.outputId || 0,
            type: options.type || 'content',
            url: options.url
        });

        // Load iframe
        loadCanvasIframe(options.url);

        // Show right panel + switch to canvas tab
        toggleRightPanel(true);
        switchRpTab('canvas');

        // Push URL state
        if (options.outputId) {
            bizcPushUrl('canvas', options.outputId);
        }
    }
    // Expose globally for studio cards and other triggers
    window.showCanvasPanel = showCanvasPanel;

    /** Hide canvas / clear iframe */
    function hideCanvasPanel() {
        loadCanvasIframe('');
        $('#bizc-rp-canvas').data({ outputId: 0, type: '', url: '' });
        $('#bizc-canvas-dropdown').hide();
    }

    // ── Canvas header button handlers ──

    // Back button → close canvas (clear iframe, stay on right panel)
    $(document).on('click', '#bizc-canvas-back', function() {
        hideCanvasPanel();
    });

    // External link → open URL in new tab
    $(document).on('click', '#bizc-canvas-external', function() {
        var url = $('#bizc-rp-canvas').data('url');
        if (url) window.open(url, '_blank');
    });

    // Menu toggle
    $(document).on('click', '#bizc-canvas-menu', function(e) {
        e.stopPropagation();
        $('#bizc-canvas-dropdown').toggle();
    });

    // Close dropdown on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#bizc-canvas-menu, #bizc-canvas-dropdown').length) {
            $('#bizc-canvas-dropdown').hide();
        }
    });

    // Dropdown action → relay to iframe via postMessage
    $(document).on('click', '.bizc-canvas-dropdown-item', function() {
        var action = $(this).data('action');
        var iframe = document.getElementById('bizc-canvas-iframe');
        if (iframe && iframe.contentWindow) {
            iframe.contentWindow.postMessage({
                type: 'bizcity_canvas_command',
                command: 'export',
                format: action.replace('export-', '')
            }, window.location.origin);
        }
        $('#bizc-canvas-dropdown').hide();
    });

    // ── Thinking panel — trace entry management ──

    var _thinkingEntryCount = 0;

    /** Step icon resolver (simplified from React WorkingPanel) */
    function _thinkingIcon(step) {
        var map = {
            gateway_entry: '🔌', kci_ratio_applied: '📊', local_intent_start: '🧠',
            mode_classified: '🔄', objectives_detected: '🎯', multi_goal_decision: '🔀',
            slot_progress: '📋', local_intent_result: '📤', local_intent_terminal: '✨',
            classify: '🎯', plan: '📝', execute_tool: '🔧', slot_fill_rate: '📊',
            input: '💬', mode_classify: '🔄', slot_analyze: '📋',
            llm_request: '🤖', llm_first_chunk: '⚡', stream_first_chunk: '📡',
            context_resolver: '🧩', intent_router: '🎯', intent_planner: '📝',
            tool_trace: '🔧', trace_end: '✅'
        };
        if (step && step.startsWith('mw:')) return '⚙️';
        if (step && step.startsWith('twin:')) return '🧬';
        return map[step] || '▸';
    }

    /** Step label resolver — uses thinking text when available */
    function _thinkingLabel(entry) {
        var d = entry.data || {};
        if (d.thinking) return d.thinking;
        if (d.text) return d.text;
        switch (entry.step) {
            case 'input': return 'Nhận tin nhắn mới...';
            case 'gateway_entry': return 'Đang phân tích...';
            case 'mode_classified': case 'mode_classify': return 'Chế độ: ' + (d.mode || 'tự động');
            case 'objectives_detected': return (d.objectives_count || 0) + ' mục tiêu nhận diện.';
            case 'classify': return d.goal ? 'Mục tiêu: ' + d.goal : 'Đang nhận diện ý định...';
            case 'plan': return d.tool_name ? 'Kế hoạch: gọi ' + d.tool_name : 'Đang lên kế hoạch...';
            case 'execute_tool': return d.tool_name ? 'Thực thi: ' + d.tool_name + '...' : 'Đang thực thi...';
            case 'context_resolver': return 'Đang xây dựng prompt...';
            case 'llm_request': return 'Đang gửi cho AI' + (d.model ? ' (' + d.model + ')' : '') + '...';
            case 'llm_first_chunk': return 'AI bắt đầu phản hồi...';
            case 'trace_end': return 'Xong! ✓';
            default: return entry.step ? entry.step.replace(/_/g, ' ') : '...';
        }
    }

    /** Add a trace entry to thinking panel */
    function addThinkingEntry(entry) {
        var $logs = $('#bizc-thinking-logs');
        // Remove empty state on first entry
        if (_thinkingEntryCount === 0) {
            $logs.find('.bizc-rp-empty').remove();
        }
        _thinkingEntryCount++;

        var icon = _thinkingIcon(entry.step);
        var label = _thinkingLabel(entry);
        var ms = (entry.ms && entry.ms > 50) ? (entry.ms > 1000 ? (entry.ms / 1000).toFixed(1) + 's' : Math.round(entry.ms) + 'ms') : '';

        var html = '<div class="bizc-thinking-entry active" id="bizc-te-' + _thinkingEntryCount + '">'
                 + '<span class="bizc-thinking-icon">' + icon + '</span>'
                 + '<span class="bizc-thinking-text">' + esc(label) + '</span>'
                 + (ms ? '<span class="bizc-thinking-time">' + ms + '</span>' : '')
                 + '</div>';

        // Mark previous entry as done
        $logs.find('.bizc-thinking-entry.active').removeClass('active');
        $logs.append(html);

        // Auto-scroll
        $logs.scrollTop($logs[0].scrollHeight);

        // Auto-open right panel on key steps
        var autoOpenSteps = ['objectives_detected', 'multi_goal_decision', 'classify', 'plan', 'execute_tool'];
        if (autoOpenSteps.indexOf(entry.step) > -1) {
            toggleRightPanel(true);
            switchRpTab('thinking');
        }
    }

    /** Add separator between message traces */
    function addThinkingSeparator() {
        var $logs = $('#bizc-thinking-logs');
        if ($logs.find('.bizc-thinking-entry').length > 0) {
            $logs.append('<hr class="bizc-thinking-separator">');
        }
    }

    /** Restore canvas from URL output_id — fetch output info via AJAX */
    function _restoreCanvasFromUrl(outputId) {
        if (!outputId) return;
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_studio_outputs', output_id: outputId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.data && res.data.canvas_url) {
                    showCanvasPanel({
                        url: res.data.canvas_url,
                        title: res.data.title || 'Canvas',
                        type: res.data.media_type || 'content',
                        outputId: parseInt(outputId, 10),
                        status: res.data.status || 'completed'
                    });
                }
            }
        });
    }

    /** Intercept chat link clicks to open in canvas instead of navigating */
    $(document).on('click', '#bizc-messages a[href]', function(e) {
        var href = $(this).attr('href') || '';
        // Match content creator result URLs or studio URLs
        var creatorMatch = href.match(/\/creator\/result\/(\d+)/);
        var studioMatch = href.match(/\/studio\/(image|video|design)\/(\d+)/);
        if (creatorMatch) {
            e.preventDefault();
            showCanvasPanel({
                url: href,
                title: $(this).text() || 'Content Creator',
                type: 'content',
                status: 'completed'
            });
        } else if (studioMatch) {
            e.preventDefault();
            showCanvasPanel({
                url: href,
                title: $(this).text() || 'Studio',
                type: studioMatch[1],
                status: 'completed'
            });
        }
    });

    // ══════════════════════════════════════════════════════════════

    function loadProjectDetailList(projectId) {
        var $list = $('#bizc-proj-detail-list');
        $list.html('<div style="padding:20px;text-align:center;color:#9ca3af;">Đang tải...</div>');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_sessions', project_id: projectId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                var sessions = (res.success && res.data) ? res.data : [];
                $list.empty();
                if (!sessions.length) {
                    $list.html('<div style="padding:40px;text-align:center;color:#9ca3af;font-size:13px;">Chưa có chat nào trong dự án này.<br>Nhấn "+ New chat" hoặc kéo chat từ "Gần đây" vào.</div>');
                    return;
                }
                sessions.forEach(function(s) {
                    var dateStr = '';
                    if (s.started_at) {
                        var d = new Date(s.started_at);
                        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                        dateStr = months[d.getMonth()] + ' ' + d.getDate();
                    }
                    var displayTitle = s.title && s.title.trim() ? s.title : 'Hội thoại mới';
                    var $item = $('<div class="bizc-proj-detail-item" data-wc-id="' + s.id + '">' +
                        '<div style="flex:1;min-width:0;">' +
                            '<div class="pdi-title">' + esc(displayTitle) + '</div>' +
                        '</div>' +
                        '<span class="pdi-date">' + dateStr + '</span>' +
                        '</div>');
                    $item.on('click', function() { hideProjectDetail(); loadSession(s.id); });
                    $list.append($item);
                });
            }
        });
    }

    // Back button from project detail
    $('#bizc-proj-back').on('click', hideProjectDetail);

    // New chat in project
    $('#bizc-proj-new-chat-input').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            var text = $(this).val().trim();
            $(this).val('');
            var projId = currentProjectId;
            hideProjectDetail();
            // Create session → move to project → send message
            createNewSession(function() {
                if (projId && currentWcId) {
                    moveSessionToProject(currentWcId, projId);
                }
                if (text) { $input.val(text); sendMsg(); }
            });
        }
    });

    // Tab switching in project detail
    $(document).on('click', '.bizc-proj-tab', function() {
        $('.bizc-proj-tab').css({color:'#9ca3af', borderBottom:'2px solid transparent', fontWeight:'400'});
        $(this).css({color:'#6366f1', borderBottom:'2px solid #6366f1', fontWeight:'600'});
    });

    // ── Webchat Sessions (replace intent conversations in sidebar) ──
    function loadSessions(isInitial) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: { action: 'bizcity_webchat_sessions', _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                wcSessions = (res.success && res.data) ? res.data : [];
                renderSessions();

                // On initial page load, auto-select the most recent session
                if (isInitial && !currentWcId) {
                    var recent = wcSessions.filter(function(s) { return !s.project_id || s.project_id === ''; });
                    if (recent.length > 0) {
                        sessionId = recent[0].session_id;
                        window.bizcSessionId = sessionId;
                        window.bizcCurrentSessionId = sessionId;
                        console.log('[bizc-dash] Auto-selected session:', recent[0].id, sessionId);
                        // Load messages for the auto-selected session
                        loadSession(recent[0].id);
                    } else {
                        // No sessions yet — that's OK, lazy-create on first message
                        console.log('[bizc-dash] No sessions yet, will create on first message');
                    }
                }
            },
            error: function() {
                wcSessions = [];
                renderSessions();
            }
        });
    }

    function renderSessions() {
        var $list = $('#bizc-convs-list').empty();
        // Only show sessions NOT in a project
        var recent = wcSessions.filter(function(s) { return !s.project_id || s.project_id === ''; });
        if (!recent.length) {
            $list.append('<div style="padding:12px;text-align:center;color:#9ca3af;font-size:12px;">Chưa có hội thoại</div>');
            return;
        }
        recent.forEach(function(s) {
            var displayTitle = s.title && s.title.trim() ? s.title : 'Hội thoại mới';
            var $conv = $('<div class="bizc-conv' + (s.id === currentWcId ? ' active' : '') + '" data-wc-id="' + s.id + '" data-session-id="' + esc(s.session_id || '') + '" draggable="true" title="Kéo thả vào dự án để di chuyển">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"></path></svg>' +
                '<span class="bizc-conv-title">' + esc(displayTitle) + '</span>' +
                '</div>');

            // Drag & drop support (improved)
            $conv[0].addEventListener('dragstart', function(e) {
                e.dataTransfer.setData('text/plain', String(s.id));
                e.dataTransfer.effectAllowed = 'move';
                dragSrcId = s.id;
                $conv.addClass('dragging');
                // Highlight all project drop zones
                $('.bizc-proj-item').css('border', '2px dashed rgba(99,102,241,0.3)');
            });
            $conv[0].addEventListener('dragend', function() { 
                $conv.removeClass('dragging'); 
                dragSrcId = null; 
                // Remove highlight from project drop zones
                $('.bizc-proj-item').css('border', 'none').removeClass('drag-over');
            });

            $list.append($conv);
        });
    }

    /**
     * Ensure a V3 session exists before sending a message.
     * Uses mutex to prevent concurrent AJAX calls.
     * callback(ok) — true if session is ready, false on failure.
     */
    var _creatingSession = false;
    var _isFirstMessage = true; // track for gen-title
    function ensureSession(callback) {
        // Already have a valid session
        if (currentWcId && currentWcId > 0) {
            if (callback) callback(true);
            return;
        }
        // Concurrent guard
        if (_creatingSession) {
            console.log('[bizc-dash] ensureSession() — waiting for in-flight creation');
            var _wait = setInterval(function() {
                if (!_creatingSession) {
                    clearInterval(_wait);
                    if (callback) callback(currentWcId > 0);
                }
            }, 150);
            return;
        }
        _creatingSession = true;
        _isFirstMessage = true;
        console.log('[bizc-dash] ensureSession() → creating session...');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_session_create', _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                _creatingSession = false;
                if (res.success && res.data && res.data.id) {
                    currentWcId = res.data.id;
                    sessionId = res.data.session_id;
                    window.bizcSessionId = sessionId;
                    window.bizcCurrentSessionId = sessionId;
                    console.log('[bizc-dash] Session created OK:', currentWcId, sessionId);
                    if (callback) callback(true);
                } else {
                    console.error('[bizc-dash] Session create response error:', res);
                    // Fallback: use legacy base session so chat still works
                    sessionId = baseSessionId;
                    window.bizcSessionId = baseSessionId;
                    window.bizcCurrentSessionId = baseSessionId;
                    currentWcId = -1; // sentinel: no real PK but won't re-trigger create
                    if (callback) callback(true); // let message through anyway
                }
            },
            error: function(xhr, status, err) {
                _creatingSession = false;
                console.error('[bizc-dash] Session create AJAX error:', status, err);
                sessionId = baseSessionId;
                window.bizcSessionId = baseSessionId;
                window.bizcCurrentSessionId = baseSessionId;
                currentWcId = -1;
                if (callback) callback(true); // let message through anyway
            }
        });
    }

    function startNewChat() {
        messages = [];
        currentWcId = null;
        lastMsgId = 0;
        _renderedMsgIds = {}; // Reset dedup tracker
        stopMsgPoll(); // Stop any active polling from previous session
        sessionId = baseSessionId;
        window.bizcSessionId = baseSessionId;
        window.bizcCurrentSessionId = baseSessionId;
        window.dispatchEvent(new CustomEvent('bizcitySessionChanged', { detail: { sessionId: baseSessionId } }));
        _isFirstMessage = true;
        $msgs.find('.bizc-msg').remove();
        hideProjectDetail();
        // Close agent panel if open (lazy-unload iframe)
        if ($('#bizc-agent-panel').is(':visible')) {
            $('#bizc-agent-panel').hide().data('slug', '');
            loadAgentIframe(''); // Clear iframe to release memory
            $('.bizc-tb-agent').removeClass('active');
            $('.bizc-tb-link').removeClass('active');
            $('.bizc-tb-chat').addClass('active');
            $('#bizc-chat-panel').css('display', 'flex');
        }
        // Show greeting immediately — session will be created on first message
        var greetingHtml = bizcDashConfig.greeting;
        if (greetingHtml) appendMsg(greetingHtml, 'bot', Date.now(), false, []);
        // Deselect all sessions in sidebar
        $('#bizc-convs-list .bizc-conv').removeClass('active');
        $input.focus();
        // Reset URL
        bizcPushUrl();
        console.log('[bizc-dash] New chat ready (lazy session)');
    }

    /**
     * After first bot reply, ask server to AI-generate a better title.
     */
    function maybeGenTitle(userText, botReply) {
        if (!_isFirstMessage || !currentWcId || currentWcId < 0) return;
        _isFirstMessage = false;
        console.log('[bizc-dash] Requesting AI gen-title for session', currentWcId);
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'bizcity_webchat_session_gen_title',
                session_id: currentWcId,
                user_message: (userText || '').substring(0, 300),
                bot_reply: (botReply || '').substring(0, 300),
                _wpnonce: nonce
            },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.data && res.data.title) {
                    console.log('[bizc-dash] Title generated:', res.data.title);
                }
                loadSessions(); // refresh sidebar with new title
            },
            error: function() {
                loadSessions(); // still refresh to show truncated title
            }
        });
    }

    function loadSession(wcId, skipPushUrl) {
        if (wcId === currentWcId && $('#bizc-chat-panel').is(':visible')) return;
        hideProjectDetail();
        // Close agent/panel iframe if open → return to chat (lazy-unload iframe)
        if ($('#bizc-agent-panel').is(':visible')) {
            $('#bizc-agent-panel').hide().data('slug', '');
            loadAgentIframe(''); // Clear iframe to release memory
            $('.bizc-tb-agent').removeClass('active');
            $('.bizc-tb-link').removeClass('active');
            $('.bizc-tb-chat').addClass('active');
            $('#bizc-chat-panel').css('display', 'flex');
            // Reset page header for mobile
            updatePageHeader('Trò chuyện gần đây', false);
        }
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_session_messages', session_id: wcId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                if (!res.success || !res.data) return;
                currentWcId = res.data.id || wcId;
                sessionId = res.data.session_id;  // switch SSE/AJAX session to this conversation
                window.bizcSessionId = sessionId;  // sync for Router Console poll
                window.bizcCurrentSessionId = sessionId;
                messages = [];
                $msgs.empty();
                var msgs = res.data.messages || [];
                lastMsgId = 0;
                _renderedMsgIds = {}; // Reset dedup tracker on session switch
                msgs.forEach(function(m) {
                    if (m.id && m.id > lastMsgId) lastMsgId = m.id;
                    if (m.id) _renderedMsgIds[m.id] = true; // Track as rendered
                    if (m.from === 'system') return;
                    var from = (m.from === 'bot') ? 'bot' : 'user';
                    var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                    var mTs = m.created_ts ? m.created_ts * 1000 : new Date(m.created_at).getTime();
                    messages.push({ role: from, content: m.text, timestamp: mTs, images: imgs });
                    appendMsg(m.text, from, mTs, false, imgs);
                });
                scrollBottom();
                renderSessions();
                // Push URL: /chat/?chat=wcs_xxx
                if (!skipPushUrl) {
                    bizcPushUrl('chat', sessionId);
                }
            }
        });
    }
    
    // Expose loadSession and startNewChat globally for search modal
    window.bizcLoadSession = loadSession;
    window.bizcStartNewChat = startNewChat;

    function updateCurrentSession() {
        // Refresh sidebar to reflect new titles (auto-title after first message)
        loadSessions();
        loadIntentConversations();
    }

    // Intent polling for in-progress tasks (poll every 5s)
    var _intentPollInterval = null;
    function startIntentPolling() {
        if (_intentPollInterval) return;
        _intentPollInterval = setInterval(function() {
            loadIntentConversations(true); // silent poll
        }, 15000);
    }
    function stopIntentPolling() {
        if (_intentPollInterval) {
            clearInterval(_intentPollInterval);
            _intentPollInterval = null;
        }
    }

    // ── Intent Conversations (Tasks / Nhiệm vụ) ──
    // Note: không gửi session_id để load TẤT CẢ nhiệm vụ của user (không chỉ session hiện tại)
    function loadIntentConversations(silent) {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_intent_conversations', _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                var intents = (res.success && res.data) ? res.data : [];
                renderIntentConversations(intents);
                // Auto-poll if any intent is ACTIVE (in-progress)
                var hasActive = intents.some(function(i) {
                    var s = (i.status || '').toLowerCase();
                    return s === 'active' || s === 'in_progress' || s === 'pending';
                });
                if (hasActive) startIntentPolling();
                else stopIntentPolling();
            },
            error: function() { renderIntentConversations([]); stopIntentPolling(); }
        });
    }

    function renderIntentConversations(intents) {
        var $list = $('#bizc-intent-list').empty();
        $('#bizc-intent-count').text(intents.length);
        if (!intents.length) {
            $list.append('<div style="padding:8px 12px;color:#9ca3af;font-size:11px;">Chưa có nhiệm vụ</div>');
            return;
        }
        intents.slice(0, 10).forEach(function(intent) {
            var status = (intent.status || '').toLowerCase();
            var goal = (intent.goal || '').toLowerCase();
            var statusIcon = '⏳';
            var statusColor = '#f59e0b';
            if (status === 'completed') { statusIcon = '✅'; statusColor = '#10b981'; }
            else if (status === 'failed' || status === 'cancelled') { statusIcon = '❌'; statusColor = '#ef4444'; }
            else if (status === 'active' || status === 'in_progress') { statusIcon = '🔄'; statusColor = '#3b82f6'; }
            // Override icon for knowledge goals
            if (goal.indexOf('knowledge') === 0 || goal.indexOf('mode:knowledge') === 0) { statusIcon = '📚'; statusColor = '#8b5cf6'; }
            else if (goal.indexOf('mode:emotion') === 0) { statusIcon = '💛'; statusColor = '#f59e0b'; }
            else if (goal.indexOf('mode:reflection') === 0) { statusIcon = '🪞'; statusColor = '#06b6d4'; }
            else if (goal.indexOf('mode:planning') === 0) { statusIcon = '📋'; statusColor = '#8b5cf6'; }
            // v4.3.4: Cancel button for non-terminal tasks
            var canCancel = (status !== 'completed' && status !== 'cancelled' && status !== 'failed' && status !== 'closed');
            var cancelBtn = canCancel
                ? '<span class="bizc-intent-cancel" data-conv-id="' + esc(intent.id) + '" title="Hủy nhiệm vụ" style="margin-left:4px;color:#6b7280;font-size:10px;cursor:pointer;padding:2px 4px;border-radius:3px;opacity:0;transition:opacity .15s;">&times;</span>'
                : '';
            var $item = $('<div class="bizc-conv bizc-intent-item" data-conv-id="' + esc(intent.id) + '" style="font-size:11px;padding:6px 10px;cursor:pointer;" title="Goal: ' + esc(intent.goal || '?') + '\nTrạng thái: ' + esc(intent.status) + '\nNhấn để xem lịch sử">' +
                '<span style="color:' + statusColor + ';margin-right:4px;">' + statusIcon + '</span>' +
                '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(intent.title || intent.goal || 'Nhiệm vụ') + '</span>' +
                cancelBtn +
                '</div>');
            $list.append($item);
        });
    }

    // Click handler for intent items — load task detail in iframe panel
    $('#bizc-intent-list').on('click', '.bizc-intent-item', function(e) {
        if ($(e.target).hasClass('bizc-intent-cancel')) return; // handled below
        var convId = $(this).data('conv-id');
        if (!convId) return;
        var title = $(this).find('span').eq(1).text() || 'Nhiệm vụ';
        var url = bizcDashConfig.tasksUrl + encodeURIComponent(convId) + '/?bizcity_iframe=1';
        openInlinePanel('🎯 ' + title, url);
    });

    // v4.3.4: Cancel button — hover show + click handler
    $('#bizc-intent-list').on('mouseenter', '.bizc-intent-item', function() {
        $(this).find('.bizc-intent-cancel').css('opacity', '1');
    }).on('mouseleave', '.bizc-intent-item', function() {
        $(this).find('.bizc-intent-cancel').css('opacity', '0');
    });
    $('#bizc-intent-list').on('click', '.bizc-intent-cancel', function(e) {
        e.stopPropagation();
        var $btn = $(this);
        var convId = $btn.data('conv-id');
        if (!convId) return;
        $btn.css({color:'#ef4444',opacity:'1'}).text('…');
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_intent_cancel', conversation_id: convId, _wpnonce: nonce },
            dataType: 'json',
            success: function() { loadIntentConversations(); },
            error: function() { $btn.text('×'); }
        });
    });

    function loadIntentTurns(convId) {
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_intent_turns', conversation_id: convId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                if (!res.success || !res.data) return;
                currentWcId = null; // not a webchat session
                messages = [];
                $msgs.empty();
                // Show goal header
                var goalLabel = res.data.goal_label || res.data.goal || 'Nhiệm vụ';
                $msgs.append('<div style="text-align:center;padding:12px;color:#9ca3af;font-size:12px;border-bottom:1px solid #374151;margin-bottom:8px;">🎯 ' + esc(goalLabel) + ' — ' + esc(res.data.status) + '</div>');
                // Render turns
                var turns = res.data.turns || [];
                turns.forEach(function(t) {
                    // Skip 'tool' turns - their content is already in the following assistant turn
                    if (t.role === 'tool') return;
                    var from = t.role === 'assistant' ? 'bot' : 'user';
                    var tTs = t.created_ts ? t.created_ts * 1000 : new Date(t.created_at).getTime();
                    messages.push({ role: from, content: t.content, timestamp: tTs });
                    appendMsg(t.content, from, tTs, false);
                });
                scrollBottom();
                // Highlight active intent in sidebar
                $('#bizc-intent-list .bizc-intent-item').removeClass('active');
                $('#bizc-intent-list .bizc-intent-item[data-conv-id="' + convId + '"]').addClass('active');
                $('#bizc-convs-list .bizc-conv').removeClass('active');
            }
        });
    }

    function clearAllSessions() {
        if (!confirm('Đóng tất cả hội thoại đang mở?')) return;
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_close_all', _wpnonce: nonce },
            dataType: 'json',
            success: function() { startNewChat(); loadSessions(); }
        });
    }
    
    // Send message — ensures session exists, then SSE stream, falls back to AJAX
    var _lastUserText = ''; // for gen-title
    
    // Guest trial: get message count from localStorage
    function getGuestMsgCount() {
        try { return parseInt(localStorage.getItem(guestMsgKey) || '0', 10); } catch(e) { return 0; }
    }
    function incrementGuestMsgCount() {
        try { localStorage.setItem(guestMsgKey, (getGuestMsgCount() + 1).toString()); } catch(e) {}
        updateGuestHint();
    }
    function updateGuestHint() {
        var $hint = $('#bizc-guest-hint');
        var $remaining = $('#bizc-guest-remaining');
        if (!$hint.length) return;
        var count = getGuestMsgCount();
        var left = Math.max(0, guestMsgLimit - count);
        $remaining.text(left);
        if (left <= 0) {
            $hint.addClass('exhausted');
            $hint.find('.bizc-guest-hint-text').html('<strong>Hết tin nhắn thử nghiệm!</strong> <a href="#" id="bizc-guest-signup-link">Đăng nhập ngay</a> để tiếp tục.');
        }
    }
    // Init guest hint on load
    if (isGuest) {
        updateGuestHint();
        $(document).on('click', '#bizc-guest-signup-link', function(e) {
            e.preventDefault();
            if (typeof window.aiagentShowAuth === 'function') {
                window.aiagentShowAuth('register');
            } else {
                window.location.href = bizcDashConfig.loginUrl;
            }
        });
    }
    
    var _isSending = false; // Debounce flag to prevent duplicate sends

    function sendMsg() {
        // Guard: prevent duplicate sends (double-click, rapid Enter)
        if (_isSending) return;

        var text = $input.val().trim();
        
        // ═══ TOOL PILL PREFIX: Prepend @tool_name to message text ═══
        // When user selected a tool via @ command, include the
        // @tool_name slug in the message for self-documenting history
        // and backend Logic 2 parsing (works across all channels).
        if (_selectedTool && _selectedTool.tool_name) {
            var slug = _selectedTool.tool_name;
            // Only prepend if not already there (user may have typed it)
            if (text.indexOf('@' + slug) !== 0 && text.indexOf('/' + slug) !== 0) {
                text = '@' + slug + (text ? ' ' + text : '');
            }
        }

        // ═══ SKILL PREFIX: Prepend /skill_key to message text ═══
        if (_selectedSkill && _selectedSkill.skill_key) {
            var skillSlug = _selectedSkill.skill_key;
            if (text.indexOf('/' + skillSlug) !== 0) {
                text = '/' + skillSlug + (text ? ' ' + text : '');
            }
        }
        
        if (!text && !pendingImages.length) return;
        
        // Guest trial limit check
        if (isGuest) {
            var count = getGuestMsgCount();
            if (count >= guestMsgLimit) {
                // Show login dialog
                if (typeof window.aiagentShowAuth === 'function') {
                    window.aiagentShowAuth('login');
                } else {
                    alert('Bạn đã dùng hết ' + guestMsgLimit + ' tin nhắn thử nghiệm. Vui lòng đăng nhập để tiếp tục.');
                    window.location.href = bizcDashConfig.loginUrl;
                }
                return;
            }
        }
        
        _lastUserText = text;
        _clearToolSelection();

        // Step 1: Ensure session exists (lazy create on first message)
        ensureSession(function(ok) {
            if (!ok) {
                console.error('[bizc-dash] Cannot create session — aborting send');
                return;
            }
            _doSend(text);
        });
    }

    function _doSend(text) {
        _isSending = true; // Lock: prevent concurrent sends
        $input.val('').css('height', 'auto');
        $send.prop('disabled', true);
        
        // Increment guest message count
        if (isGuest) incrementGuestMsgCount();
        
        var timestamp = Date.now();
        var images = pendingImages.map(function(img) { return img.data; });
        
        // ════════════════════════════════════════════════════════════
        //  DUAL-PATH ROUTING SYSTEM
        //  
        //  Determine routing path based on user selection:
        //  - Manual: User selected plugin via @mention or pills → direct routing
        //  - Automatic: No selection → intent detection routing
        // ════════════════════════════════════════════════════════════
        var routingMode = 'automatic';
        var selectedPlugin = null;
        var routingInfo = '';
        
        if (_mentionProvider && _mentionProvider.slug) {
            routingMode = 'manual';
            selectedPlugin = _mentionProvider.slug;
            routingInfo = '🎯 Manual routing to: ' + _mentionProvider.label + ' (' + selectedPlugin + ')';
        } else {
            routingInfo = '🤖 Automatic intent detection routing';
        }
        
        console.log('📍 [Dual-Path Routing]', routingInfo);
        
        var messageData = { 
            role: 'user', 
            content: text, 
            timestamp: timestamp, 
            images: images,
            routing_mode: routingMode,
            selected_plugin: selectedPlugin
        };
        
        messages.push(messageData);
        appendMsg(text, 'user', timestamp, true, images);
        updateCurrentSession();
        clearImages();
        
        // Typing indicator
        var typId = 'typ-' + Math.random().toString(36).substr(2, 6);
        var _sendPluginSlug = (routingMode === 'manual' && selectedPlugin) ? selectedPlugin : '';

        // Phase 1.20: Add separator in thinking panel for new message
        addThinkingSeparator();
        $msgs.append(
            '<div class="bizc-typing" id="' + typId + '">' +
            '<div class="bizc-msg-av">' + avHtml('bot') + '</div>' +
            '<div class="bizc-typing-body">' +
            '<div class="bizc-typing-dots">' +
            '<div class="bizc-typing-dot"></div><div class="bizc-typing-dot"></div><div class="bizc-typing-dot"></div>' +
            '</div>' +
            '<div class="bizc-routing-indicator">' + 
            (routingMode === 'manual' ? 
                '🎯 ' + _mentionProvider.label + ' mode' : 
                '🤖 Auto-routing') + 
            '</div>' +
            (_sendPluginSlug ? '<div class="bizc-plugin-badge">🔌 ' + esc(_sendPluginSlug) + '</div>' : '') +
            ((_selectedTool && _selectedTool.goal_label) ? '<div class="bizc-tool-badge">🛠️ ' + esc(_selectedTool.goal_label) + '</div>' : '') +
            '</div></div>'
        );
        scrollBottom();
        
        // SSE streaming (falls back to AJAX inside sendMsgStream)
        sendMsgStream(text, images, typId);

        // Auto-start console polling on first message
        if (!_bizcRouterInterval) bizcRouterPoll(null);
    }
    
    // ── SSE Streaming via fetch + ReadableStream ──
    function sendMsgStream(text, images, typId) {
        // Stop any active message poll from previous exchange to prevent
        // race condition where poll picks up the new bot response before
        // SSE finishes streaming (causing duplicate messages in DOM).
        stopMsgPoll();

        var formData = new FormData();
        formData.append('action', 'bizcity_chat_stream');
        formData.append('message', text);
        formData.append('session_id', sessionId);
        formData.append('platform_type', 'ADMINCHAT');
        formData.append('_wpnonce', nonce);
        if (images && images.length) {
            formData.append('images', JSON.stringify(images));
        }
        // Active intent conversation — signal backend to skip attachment-buffer
        if (window._bizcIntentConvId) {
            formData.append('intent_conversation_id', window._bizcIntentConvId);
        }
        
        // ═══ DUAL-PATH ROUTING PARAMETERS ═══
        // Send both provider_hint (for Intent Engine biasing) and plugin_slug (for message logging)
        var _ssePluginSlug = ''; // Persist for badge display on bot bubble
        var _sseToolLabel = '';  // Persist tool label for badge
        if (_mentionProvider && _mentionProvider.slug) {
            formData.append('provider_hint', _mentionProvider.slug);  // Intent Engine hint
            formData.append('plugin_slug', _mentionProvider.slug);   // Message logging
            formData.append('routing_mode', 'manual');               // Routing mode
            _ssePluginSlug = _mentionProvider.slug;
            
            // ═══ @ TOOL COMMAND: include selected tool goal ═══
            var _selTool = _getSelectedTool();
            if (_selTool) {
                _sseToolLabel = _selTool.goal_label || _selTool.title || _selTool.tool_name || '';
            }
            if (_selTool && _selTool.goal) {
                formData.append('tool_goal', _selTool.goal);         // Direct tool targeting
                formData.append('tool_name', _selTool.tool_name);    // Tool registry name
                console.log('📤 [@] Sending tool_goal:', _selTool.goal, 'tool_name:', _selTool.tool_name);
            }
            
            console.log('📤 [Dual-Path] Sending manual routing params:', {
                provider_hint: _mentionProvider.slug,
                plugin_slug: _mentionProvider.slug,
                routing_mode: 'manual'
            });
            
            // Only clear mention if NOT in HIL focus mode
            // (focus mode lifecycle is controlled by server focus_mode signal)
            if (!$('#bizc-context-header').hasClass('active')) {
                _clearMention();
            }
        } else {
            formData.append('routing_mode', 'automatic');            // Automatic intent detection
            console.log('📤 [Dual-Path] Sending automatic routing params');
        }

        // ═══ / SKILL CONTEXT: include selected skill for context injection ═══
        if (_selectedSkill && _selectedSkill.skill_key) {
            formData.append('selected_skill', _selectedSkill.skill_key);
            formData.append('skill_path', _selectedSkill.path || '');
            console.log('📤 [/] Sending selected_skill:', _selectedSkill.skill_key);
        }
        
        // Create streaming bot bubble
        var bubbleId = 'stream-' + Math.random().toString(36).substr(2, 6);
        var fullText = '';
        var bubbleCreated = false;
        
        fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(response) {
            if (!response.ok || !response.body) {
                throw new Error('Stream not available');
            }
            
            var reader = response.body.getReader();
            var decoder = new TextDecoder();
            var buffer = '';
            
            function processStream() {
                return reader.read().then(function(result) {
                    if (result.done) {
                        // Stream finished
                        $('#' + typId).remove();
                        if (!bubbleCreated && fullText) {
                            appendMsg(fullText, 'bot', Date.now(), true, []);
                        } else if (bubbleCreated && fullText) {
                            // Re-format the final text and add copy button
                            $('#' + bubbleId).html(formatMsg(fullText));
                            var $msgDiv = $('#' + bubbleId).closest('.bizc-msg');
                            if (!$msgDiv.find('.bizc-msg-actions').length) {
                                $('#' + bubbleId).append(
                                    '<div class="bizc-msg-actions">' +
                                    '<button class="bizc-msg-action-btn" onclick="bizcCopyMsg(this)" title="Copy">📋</button>' +
                                    '</div>'
                                );
                            }
                        }
                        if (fullText) {
                            messages.push({ role: 'assistant', content: fullText, timestamp: Date.now() });
                            updateCurrentSession();
                            // Sync lastMsgId BEFORE starting poll to avoid
                            // re-fetching messages that SSE already rendered.
                            syncLastMsgId(function() {
                                // Start polling for executor async messages
                                // (task progress, completion, etc.)
                                startMsgPoll();
                            });
                        }
                        updateBtn();
                        _isSending = false; // Unlock: SSE stream completed
                        // Gen AI title + refresh sidebar
                        maybeGenTitle(_lastUserText, fullText);
                        return;
                    }
                    
                    buffer += decoder.decode(result.value, { stream: true });
                    var lines = buffer.split('\n');
                    buffer = lines.pop(); // Keep incomplete line in buffer
                    
                    for (var i = 0; i < lines.length; i++) {
                        var line = lines[i].trim();
                        
                        // Parse SSE event type
                        if (line.startsWith('event:')) {
                            var evType = line.substring(6).trim();
                            if (evType === 'close') continue;
                            // Check if next line is data for status event
                            if (evType === 'status' && i + 1 < lines.length) {
                                var nextLine = lines[i + 1].trim();
                                if (nextLine.startsWith('data: ')) {
                                    try {
                                        var statusData = JSON.parse(nextLine.substring(6));
                                        if (statusData.text) {
                                            $('#' + typId).find('.bizc-typing-dots').html(
                                                '<span style="font-size:13px;opacity:.85">' + statusData.text + '</span>'
                                            );
                                            scrollBottom();
                                        }
                                        // Phase 1.20: Feed status events to thinking panel
                                        if (statusData.step) {
                                            addThinkingEntry(statusData);
                                        }
                                    } catch(e) {}
                                    i++; // skip the data line
                                }
                            }
                            // Phase 1.20: Handle log events for thinking panel
                            if (evType === 'log' && i + 1 < lines.length) {
                                var logLine = lines[i + 1].trim();
                                if (logLine.startsWith('data: ')) {
                                    try {
                                        var logData = JSON.parse(logLine.substring(6));
                                        addThinkingEntry(logData);
                                    } catch(e) {}
                                    i++;
                                }
                            }
                            continue;
                        }
                        
                        if (!line.startsWith('data: ')) continue;
                        
                        try {
                            var data = JSON.parse(line.substring(6));
                        } catch(e) { continue; }
                        
                        // Handle chunk — stream text into bubble
                        if (data.delta) {
                            // Remove typing indicator on first chunk
                            if (!bubbleCreated) {
                                $('#' + typId).remove();
                                var t = new Date().toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit'});
                                var _badgeSlug = data.plugin_slug || _ssePluginSlug;
                                var _badgeTool = data.tool_name || _sseToolLabel;
                                $msgs.append(
                                    '<div class="bizc-msg bot">' +
                                    '<div class="bizc-msg-av">' + avHtml('bot') + '</div>' +
                                    '<div>' +
                                    '<div class="bizc-msg-bubble" id="' + bubbleId + '"></div>' +
                                    (_badgeSlug ? '<div class="bizc-plugin-badge" id="badge-' + bubbleId + '">🔌 ' + esc(_badgeSlug) + '</div>' : '') +
                                    (_badgeTool ? '<div class="bizc-tool-badge" id="toolbadge-' + bubbleId + '">🛠️ ' + esc(_badgeTool) + '</div>' : '') +
                                    '<div class="bizc-msg-time">' + t + '</div>' +
                                    '</div></div>'
                                );
                                bubbleCreated = true;
                            }
                            fullText = data.full || (fullText + data.delta);
                            $('#' + bubbleId).html(formatMsg(fullText));
                            scrollBottom();
                        }
                        
                        // Handle done event (full message + conversation_id)
                        if (data.message && !data.delta) {
                            fullText = data.message;
                            if (bubbleCreated) {
                                $('#' + bubbleId).html(formatMsg(fullText));
                            }
                            // Intent conversation_id captured (internal only)
                            if (data.conversation_id) {
                                window._bizcIntentConvId = data.conversation_id;
                            }
                            // ── Dedup: capture bot DB message ID immediately ──
                            // This prevents pollNewMessages from re-displaying
                            // the same message fetched from DB.
                            if (data.bot_message_id) {
                                _renderedMsgIds[data.bot_message_id] = true;
                            }
                            // ═══ Show plugin_slug badge (SSE done) ═══
                            var _doneSlug = data.plugin_slug || _ssePluginSlug;
                            if (_doneSlug && bubbleCreated) {
                                var $bubble = $('#' + bubbleId);
                                // Update existing badge or create new one
                                var $existBadge = $('#badge-' + bubbleId);
                                if ($existBadge.length) {
                                    $existBadge.html('🔌 ' + esc(_doneSlug));
                                } else if ($bubble.length && !$bubble.next('.bizc-plugin-badge').length) {
                                    $bubble.after('<div class="bizc-plugin-badge">🔌 ' + esc(_doneSlug) + '</div>');
                                }
                                console.log('🏷️ [SSE] Bot message tagged with plugin:', _doneSlug);
                            }
                            // ═══ Show tool_name badge (SSE done) ═══
                            var _doneTool = data.tool_name || _sseToolLabel;
                            if (_doneTool && bubbleCreated) {
                                var $existToolBadge = $('#toolbadge-' + bubbleId);
                                if ($existToolBadge.length) {
                                    $existToolBadge.html('🛠️ ' + esc(_doneTool));
                                } else {
                                    var $plugBadge = $('#badge-' + bubbleId);
                                    var $anchor = $plugBadge.length ? $plugBadge : $('#' + bubbleId);
                                    if ($anchor.length && !$('#toolbadge-' + bubbleId).length) {
                                        $anchor.after('<div class="bizc-tool-badge" id="toolbadge-' + bubbleId + '">🛠️ ' + esc(_doneTool) + '</div>');
                                    }
                                }
                            }

                            // ═══ HIL Focus Mode lifecycle (SSE) ═══
                            _handleFocusMode(data);

                            // ═══ v4.3.1: Tool Suggest Card ═══
                            // When tool_registry_verify found TOOL_EXISTS,
                            // render an activation card after the bot message
                            if (data.suggest_tool && data.suggest_tool.goal && bubbleCreated) {
                                var st = data.suggest_tool;
                                var stLabel = esc(st.goal_label || st.tool_name || st.goal);
                                var stDesc  = st.description ? esc(st.description) : '';
                                var stHtml  = '<div class="bizc-tool-suggest-card"'
                                    + ' data-goal="' + esc(st.goal) + '"'
                                    + ' data-tool-name="' + esc(st.tool_name || '') + '"'
                                    + ' data-goal-label="' + stLabel + '"'
                                    + ' data-plugin-slug="' + esc(st.plugin_slug || '') + '">'
                                    + '<div class="bizc-tool-suggest-icon">🔧</div>'
                                    + '<div class="bizc-tool-suggest-info">'
                                    + '<div class="bizc-tool-suggest-label">' + stLabel + '</div>'
                                    + (stDesc ? '<div class="bizc-tool-suggest-desc">' + stDesc + '</div>' : '')
                                    + '</div>'
                                    + '<button class="bizc-tool-suggest-btn" type="button">Kích hoạt 🎯</button>'
                                    + '</div>';
                                // Append after the bot bubble's parent <div>
                                var $bubble = $('#' + bubbleId);
                                $bubble.closest('.bizc-msg.bot').find('.bizc-msg-time').before(stHtml);
                                scrollBottom();
                                console.log('🔧 [SuggestTool] Card rendered:', st.goal, st.plugin_slug);
                            }

                            // ═══ Phase 1.2: Pipeline Monitor Sidebar (SSE) ═══
                            if (data.pipeline_id && data.pipeline_nodes && window.BizCityPipelineMonitor) {
                                var sidebar = document.getElementById('bc-pipeline-sidebar');
                                if (sidebar) {
                                    var monitor = new window.BizCityPipelineMonitor(sidebar, data.pipeline_id, {
                                        nonce: (window.BIZC_PIPELINE_MONITOR || {}).nonce || ''
                                    });
                                    monitor.init(data.pipeline_nodes);
                                    sidebar.classList.add('active');
                                    document.body.classList.add('bc-sidebar-open');
                                    console.log('📊 [PipelineMonitor] Sidebar opened for:', data.pipeline_id);
                                }
                            }

                            // ═══ Phase 1.20: Canvas auto-open (SSE) ═══
                            if (data.canvas_open) {
                                showCanvasPanel(data.canvas_open);
                                console.log('🖼️ [Canvas] Auto-opened:', data.canvas_open.url);
                            }

                            // ═══ Phase 1.20: Canvas Adapter handoff (Intent Engine → Canvas) ═══
                            if (data.action === 'canvas_handoff' && data.canvas) {
                                showCanvasPanel({
                                    url: data.canvas.launch_url,
                                    title: data.message ? data.message.substring(0, 100) : 'Content Creator',
                                    type: 'content',
                                    outputId: data.canvas.artifact_id || '',
                                    status: data.canvas.auto_execute ? 'generating' : 'pending'
                                });
                                console.log('🎨 [Canvas] Handoff:', data.canvas.launch_url);
                            }
                        }
                    }
                    
                    return processStream();
                });
            }
            
            return processStream();
        })
        .catch(function(err) {
            console.log('SSE stream failed, falling back to AJAX:', err.message);
            // ── Fallback: regular AJAX ──
            sendMsgAjax(text, images, typId);
        });
    }
    
    // ── Fallback: regular AJAX (non-streaming) ──
    function sendMsgAjax(text, images, typId) {
        // ═══ REST API primary path (Phase 5.0) ═══
        if (useRestApi) {
            var restBody = {
                message: text,
                session_id: sessionId,
                platform_type: 'ADMINCHAT',
                character_id: bizcDashConfig.characterId,
                images: images || [],
                routing_mode: 'automatic'
            };
            if (window._bizcIntentConvId) {
                restBody.intent_conversation_id = window._bizcIntentConvId;
            }

            var _restPluginSlug = '';
            var _restToolLabel = '';
            if (_mentionProvider && _mentionProvider.slug) {
                restBody.provider_hint = _mentionProvider.slug;
                restBody.plugin_slug   = _mentionProvider.slug;
                restBody.routing_mode  = 'manual';
                _restPluginSlug = _mentionProvider.slug;

                // ═══ SLASH COMMAND: include selected tool goal ═══
                var _restTool = _getSelectedTool();
                if (_restTool && _restTool.goal) {
                    restBody.tool_goal = _restTool.goal;
                    restBody.tool_name = _restTool.tool_name;
                    _restToolLabel = _restTool.goal_label || _restTool.title || _restTool.tool_name || '';
                    console.log('📤 [REST] Sending tool_goal:', _restTool.goal, 'tool_name:', _restTool.tool_name);
                }

                console.log('📤 [REST] manual routing:', restBody.plugin_slug);
                if (!$('#bizc-context-header').hasClass('active')) {
                    _clearMention();
                }
            } else {
                console.log('📤 [REST] automatic routing');
            }

            fetch(restUrl + 'send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': wpRestNonce
                },
                body: JSON.stringify(restBody),
                credentials: 'same-origin'
            })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(response) {
                $('#' + typId).remove();

                if (response.success && response.data) {
                    var reply = response.data.message || response.data.reply || '';
                    var replyTime = Date.now();

                    if (response.data.conversation_id) {
                        window._bizcIntentConvId = response.data.conversation_id;
                    }

                    messages.push({ role: 'assistant', content: reply, timestamp: replyTime });
                    appendMsg(reply, 'bot', replyTime, true, []);

                    // Plugin badge
                    var doneSlug = response.data.plugin_slug || _restPluginSlug;
                    if (doneSlug) {
                        var $lastBotMsg = $msgs.find('.bizc-msg.bot').last();
                        if ($lastBotMsg.length) {
                            var $bubble = $lastBotMsg.find('.bizc-msg-bubble');
                            if ($bubble.length && !$bubble.next('.bizc-plugin-badge').length) {
                                $bubble.after('<div class="bizc-plugin-badge">🔌 ' + esc(doneSlug) + '</div>');
                            }
                        }
                        console.log('🏷️ [REST] plugin:', doneSlug);
                    }
                    // Tool badge
                    var doneToolRest = response.data.tool_name || _restToolLabel;
                    if (doneToolRest) {
                        var $lastBotMsgT = $msgs.find('.bizc-msg.bot').last();
                        if ($lastBotMsgT.length) {
                            var $plugB = $lastBotMsgT.find('.bizc-plugin-badge');
                            var $anchorT = $plugB.length ? $plugB : $lastBotMsgT.find('.bizc-msg-bubble');
                            if ($anchorT.length && !$lastBotMsgT.find('.bizc-tool-badge').length) {
                                $anchorT.after('<div class="bizc-tool-badge">🛠️ ' + esc(doneToolRest) + '</div>');
                            }
                        }
                    }

                    // ═══ HIL Focus Mode lifecycle (REST) ═══
                    _handleFocusMode(response.data);

                    updateCurrentSession();
                    syncLastMsgId(function() { startMsgPoll(); });
                    maybeGenTitle(_lastUserText, reply);
                    _isSending = false; // Unlock: REST completed
                } else {
                    var errMsg = (response.data && response.data.message) || 'Có lỗi xảy ra';
                    appendMsg('❌ ' + errMsg, 'bot', Date.now(), true, []);
                    _isSending = false; // Unlock: REST error response
                }
            })
            .catch(function(err) {
                console.warn('⚠️ REST send failed, falling back to AJAX:', err.message);
                _sendMsgAjaxLegacy(text, images, typId);
            });

            return;
        }

        // ═══ AJAX legacy path ═══
        _sendMsgAjaxLegacy(text, images, typId);
    }

    function _sendMsgAjaxLegacy(text, images, typId) {
        var requestData = {
            action: 'bizcity_chat_send',
            platform_type: 'ADMINCHAT',
            message: text,
            session_id: sessionId,
            image_data: images && images.length > 0 ? images[0] : '',
            _wpnonce: nonce
        };
        if (window._bizcIntentConvId) {
            requestData.intent_conversation_id = window._bizcIntentConvId;
        }
        
        // ═══ DUAL-PATH ROUTING PARAMETERS (AJAX FALLBACK) ═══
        var _ajaxPluginSlug = ''; // Persist for badge display
        var _ajaxToolLabel = ''; // Persist tool label for badge
        if (_mentionProvider && _mentionProvider.slug) {
            requestData.provider_hint = _mentionProvider.slug;  // Intent Engine hint
            requestData.plugin_slug = _mentionProvider.slug;   // Message logging
            requestData.routing_mode = 'manual';               // Routing mode
            _ajaxPluginSlug = _mentionProvider.slug;

            // ═══ SLASH COMMAND: include selected tool goal ═══
            var _ajaxTool = _getSelectedTool();
            if (_ajaxTool && _ajaxTool.goal) {
                requestData.tool_goal = _ajaxTool.goal;
                requestData.tool_name = _ajaxTool.tool_name;
                _ajaxToolLabel = _ajaxTool.goal_label || _ajaxTool.title || _ajaxTool.tool_name || '';
                console.log('📤 [AJAX] Sending tool_goal:', _ajaxTool.goal, 'tool_name:', _ajaxTool.tool_name);
            }
            
            console.log('📤 [Dual-Path AJAX] Sending manual routing params:', {
                provider_hint: _mentionProvider.slug,
                plugin_slug: _mentionProvider.slug,
                routing_mode: 'manual'
            });
            
            if (!$('#bizc-context-header').hasClass('active')) {
                _clearMention();
            }
        } else {
            requestData.routing_mode = 'automatic';            // Automatic intent detection
            console.log('📤 [Dual-Path AJAX] Sending automatic routing params');
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: requestData,
            dataType: 'text',
            success: function(response) {
                $('#' + typId).remove();
                
                try {
                    if (typeof response === 'string') {
                        response = response.replace(/^\uFEFF/, '');
                        var jsonStart = response.indexOf('{');
                        if (jsonStart > 0) response = response.substring(jsonStart);
                        var jsonEnd = response.lastIndexOf('}');
                        if (jsonEnd > 0 && jsonEnd < response.length - 1) {
                            response = response.substring(0, jsonEnd + 1);
                        }
                        response = JSON.parse(response);
                    }
                    
                    if (response.success && response.data && response.data.reply) {
                        var reply = response.data.reply;
                        var replyTime = Date.now();
                        
                        // Capture conversation_id from intent engine
                        // Intent conversation_id (internal)
                        if (response.data.conversation_id) {
                            window._bizcIntentConvId = response.data.conversation_id;
                        }
                        
                        messages.push({
                            role: 'assistant',
                            content: reply,
                            timestamp: replyTime
                        });
                        
                        appendMsg(reply, 'bot', replyTime, true, []);
                        
                        // ═══ Show plugin_slug badge (AJAX done) ═══
                        var _ajaxDoneSlug = response.data.plugin_slug || _ajaxPluginSlug;
                        if (_ajaxDoneSlug) {
                            var $lastBotMsg = $msgs.find('.bizc-msg.bot').last();
                            if ($lastBotMsg.length) {
                                var $bubble = $lastBotMsg.find('.bizc-msg-bubble');
                                if ($bubble.length && !$bubble.next('.bizc-plugin-badge').length) {
                                    $bubble.after('<div class="bizc-plugin-badge">🔌 ' + esc(_ajaxDoneSlug) + '</div>');
                                }
                            }
                            console.log('🏷️ [AJAX] Bot message tagged with plugin:', _ajaxDoneSlug);
                        }
                        // ═══ Show tool_name badge (AJAX done) ═══
                        var _ajaxDoneTool = response.data.tool_name || _ajaxToolLabel;
                        if (_ajaxDoneTool) {
                            var $lastBotMsgT = $msgs.find('.bizc-msg.bot').last();
                            if ($lastBotMsgT.length) {
                                var $plugB = $lastBotMsgT.find('.bizc-plugin-badge');
                                var $anchorT = $plugB.length ? $plugB : $lastBotMsgT.find('.bizc-msg-bubble');
                                if ($anchorT.length && !$lastBotMsgT.find('.bizc-tool-badge').length) {
                                    $anchorT.after('<div class="bizc-tool-badge">🛠️ ' + esc(_ajaxDoneTool) + '</div>');
                                }
                            }
                        }

                        // ═══ HIL Focus Mode lifecycle (AJAX) ═══
                        _handleFocusMode(response.data);
                        
                        updateCurrentSession();
                        // Always start polling for async executor/tool messages
                        syncLastMsgId(function() { startMsgPoll(); });
                        // Gen AI title + refresh sidebar
                        maybeGenTitle(_lastUserText, reply);
                    } else {
                        var errorMsg = response.data?.message || 'Có lỗi xảy ra';
                        appendMsg('❌ ' + errorMsg, 'bot', Date.now(), true, []);
                    }
                } catch(e) {
                    console.error('Error:', e);
                    appendMsg('❌ Lỗi xử lý phản hồi', 'bot', Date.now(), true, []);
                }
            },
            error: function(xhr, status, error) {
                $('#' + typId).remove();
                console.error('AJAX Error:', status, error);
                appendMsg('❌ Không thể kết nối server', 'bot', Date.now(), true, []);
                _isSending = false; // Unlock on AJAX error
            },
            complete: function() {
                _isSending = false; // Unlock: AJAX legacy completed
                updateBtn();
            }
        });
    }
    
    function appendMsg(text, from, time, scroll, imgs) {
        var t = new Date(time).toLocaleTimeString('vi-VN', {hour:'2-digit', minute:'2-digit'});
        
        var imgHtml = '';
        if (imgs && imgs.length) {
            imgHtml = '<div class="bizc-msg-images">';
            imgs.forEach(function(img) {
                imgHtml += '<img src="' + esc(img) + '" alt="">';
            });
            imgHtml += '</div>';
        }
        
        var formatted;
        if (from === 'bot') {
            formatted = formatMsg(text);
        } else {
            // Highlight /tool_name prefix in user messages
            var slashPfx = text.match(/^(\/[a-z0-9_]+)\s*/i);
            if (slashPfx) {
                formatted = '<span class="bizc-msg-slash">' + esc(slashPfx[1]) + '</span> ' + esc(text.substring(slashPfx[0].length));
            } else {
                formatted = esc(text);
            }
        }
        
        var actionsHtml = '';
        if (from === 'bot' && text && text.length > 20) {
            actionsHtml = '<div class="bizc-msg-actions">' +
                '<button class="bizc-msg-action-btn" onclick="bizcCopyMsg(this)" title="Copy">📋</button>' +
                '</div>';
        }
        
        $msgs.append(
            '<div class="bizc-msg ' + from + '">' +
            '<div class="bizc-msg-av">' + avHtml(from) + '</div>' +
            '<div>' +
            imgHtml +
            '<div class="bizc-msg-bubble">' + formatted + actionsHtml + '</div>' +
            '<div class="bizc-msg-time">' + t + '</div>' +
            '</div></div>'
        );
        
        if (scroll) scrollBottom();
    }
    
    function avHtml(from) {
        if (from === 'user') return '👤';
        if (botAvatar) return '<img src="' + esc(botAvatar) + '" alt="">';
        return '🤖';
    }
    
    function handleImages(files) {
        if (!files || !files.length) return;
        console.log('Handling', files.length, 'file(s)');
        Array.from(files).forEach(function(f) {
            if (!f.type.startsWith('image/')) {
                console.warn('Skipped non-image file:', f.name);
                return;
            }
            var reader = new FileReader();
            reader.onload = function(e) {
                pendingImages.push({ name: f.name, data: e.target.result });
                console.log('Image loaded:', f.name);
                renderPreviews();
                updateBtn();
            };
            reader.onerror = function(e) {
                console.error('Error reading file:', f.name, e);
            };
            reader.readAsDataURL(f);
        });
    }
    
    function renderPreviews() {
        var $preview = $('#bizc-img-preview');
        var $hint = $('#bizc-vision-hint');
        $preview.empty();
        
        if (!pendingImages.length) {
            $preview.hide();
            $hint.hide();
            return;
        }
        
        pendingImages.forEach(function(img, idx) {
            var $thumb = $(
                '<div class="bizc-img-thumb">' +
                '<img src="' + img.data + '" alt="' + esc(img.name) + '">' +
                '<button class="bizc-img-rm" data-idx="' + idx + '" type="button">&times;</button>' +
                '</div>'
            );
            $preview.append($thumb);
        });
        
        $preview.show();
        $hint.show();
        
        // Bind remove buttons
        $preview.find('.bizc-img-rm').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var idx = parseInt($(this).data('idx'));
            pendingImages.splice(idx, 1);
            renderPreviews();
            updateBtn();
        });
    }
    
    function clearImages() {
        pendingImages = [];
        renderPreviews();
    }
    
    function updateBtn() {
        $send.prop('disabled', !$input.val().trim() && !pendingImages.length);
    }
    
    function scrollBottom() {
        $msgs.scrollTop($msgs[0].scrollHeight);
    }
    
    function esc(t) {
        return $('<div>').text(t).html();
    }
    
    function formatMsg(t) {
        if (!t) return '';
        // If already HTML, return as-is
        if (/<\/?(?:div|p|br|h[1-6]|ul|ol|li|strong|em|table|tr|td|th|blockquote|pre|code|span|a|img)[\s>]/i.test(t)) {
            return t;
        }
        t = esc(t);
        // URL auto-link (after escape, before markdown)
        t = t.replace(/(https?:\/\/[^\s<\]]+)/g, function(m, url) {
            // Balance parentheses — trim unmatched trailing ')'
            var open = (url.match(/\(/g) || []).length;
            var close = (url.match(/\)/g) || []).length;
            var after = '';
            while (close > open && url.endsWith(')')) { after = ')' + after; url = url.slice(0, -1); close--; }
            return '<a href="' + url + '" target="_blank" rel="noopener" style="color:#7c3aed;text-decoration:underline;">' + url + '</a>' + after;
        });
        // Fenced code blocks: ```lang\n...\n```
        t = t.replace(/```(\w*)\n([\s\S]*?)```/g, function(m, lang, code) {
            var langLabel = lang ? '<span style="position:absolute;top:6px;left:12px;font-size:10px;color:#89b4fa;text-transform:uppercase;">' + lang + '</span>' : '';
            return '<div class="bizc-code-wrap">' + langLabel +
                '<button class="bizc-copy-btn" onclick="bizcCopyCode(this)">Copy</button>' +
                '<pre><code>' + code + '</code></pre></div>';
        });
        // Fenced code blocks without newline: ```...```
        t = t.replace(/```([\s\S]*?)```/g, function(m, code) {
            return '<div class="bizc-code-wrap">' +
                '<button class="bizc-copy-btn" onclick="bizcCopyCode(this)">Copy</button>' +
                '<pre><code>' + code + '</code></pre></div>';
        });
        // Headings: #### / ### / ## / #
        t = t.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
        t = t.replace(/^### (.+)$/gm, '<h4>$1</h4>');
        t = t.replace(/^## (.+)$/gm, '<h3>$1</h3>');
        t = t.replace(/^# (.+)$/gm, '<h2>$1</h2>');
        // Bold + Italic: ***text***
        t = t.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
        // Bold: **text**
        t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        // Italic: *text*
        t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
        // Inline code: `text`
        t = t.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Unordered list: - item
        t = t.replace(/((?:^|\n)- .+(?:\n- .+)*)/g, function(block) {
            var items = block.trim().split('\n').map(function(line) {
                return '<li>' + line.replace(/^- /, '') + '</li>';
            }).join('');
            return '<ul>' + items + '</ul>';
        });
        // Ordered list: 1. item
        t = t.replace(/((?:^|\n)\d+\. .+(?:\n\d+\. .+)*)/g, function(block) {
            var items = block.trim().split('\n').map(function(line) {
                return '<li>' + line.replace(/^\d+\.\s*/, '') + '</li>';
            }).join('');
            return '<ol>' + items + '</ol>';
        });
        // Line breaks
        t = t.replace(/\n/g, '<br>');
        // Clean up <br> around block elements
        t = t.replace(/(<\/(?:h[2-4]|ul|ol|pre|li|div)>)<br>/g, '$1');
        t = t.replace(/<br>(<(?:h[2-4]|ul|ol|pre|div))/g, '$1');

        // ── Convert image links to actual <img> tags ──
        var imgRx = /<a [^>]*href="(https?:\/\/[^"]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^"]*)?)"[^>]*>[^<]*<\/a>/gi;
        var imgMatches = t.match(imgRx);
        if (imgMatches && imgMatches.length) {
            imgMatches.forEach(function(tag) {
                var m = tag.match(/href="([^"]+)"/);
                if (!m) return;
                var url = m[1];
                var imgTag = '<img src="' + url + '" alt="Generated image" loading="lazy" onclick="window.open(this.src,\'_blank\')">';
                if (imgMatches.length > 1) {
                    t = t.replace(tag, imgTag);
                } else {
                    t = t.replace(tag, '<div class="bizc-img-single">' + imgTag + '</div>');
                }
            });
            if (imgMatches.length > 1) {
                // Wrap all loose <img> in a grid
                var imgTags = [];
                t = t.replace(/<img [^>]+>/g, function(img) { imgTags.push(img); return '{{BIZC_IMG_' + (imgTags.length - 1) + '}}'; });
                var grid = '<div class="bizc-img-grid">' + imgTags.join('') + '</div>';
                imgTags.forEach(function(_, i) { t = t.replace('{{BIZC_IMG_' + i + '}}', ''); });
                // Remove empty <br> left behind
                t = t.replace(/(<br>){2,}/g, '<br>');
                t += grid;
            }
        }

        return t;
    }

    // Copy code block content
    window.bizcCopyCode = function(btn) {
        var code = btn.parentElement.querySelector('code');
        if (!code) return;
        navigator.clipboard.writeText(code.innerText).then(function() {
            btn.textContent = '✓ Copied';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'Copy';
                btn.classList.remove('copied');
            }, 2000);
        });
    };

    // ── Message polling — for async push-back (tarot result, etc.) ──
    var _msgPollStartTime = 0;
    var _msgPollMaxDuration = 5 * 60 * 1000; // 5 minutes max
    var _msgPollGraceTimer = null;
    function syncLastMsgId(cb) {
        // Quick fetch to get current max message ID for this session.
        // This ensures poll starts AFTER the last known message,
        // preventing re-fetching messages already rendered by SSE/AJAX.
        // Also renders any NEW bot messages inserted during SSE (e.g. workflow actions).
        var syncId = sessionId || currentWcId;
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'bizcity_webchat_session_messages', session_id: syncId, _wpnonce: nonce },
            dataType: 'json',
            success: function(res) {
                if (res.success && res.data && res.data.messages) {
                    var msgs = res.data.messages;
                    if (msgs.length) {
                        lastMsgId = msgs[msgs.length - 1].id || lastMsgId;
                        msgs.forEach(function(m) {
                            // Render NEW bot messages not yet in DOM (e.g. from workflow bc_send_adminchat)
                            if (m.id && !_renderedMsgIds[m.id] && m.from === 'bot') {
                                var mText = (m.text || '').trim();
                                if (mText) {
                                    // DOM dedup: skip if same text already visible in a bubble
                                    var mTextClean = mText.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
                                    var domDup = false;
                                    if (mTextClean) {
                                        $msgs.find('.bizc-msg.bot .bizc-msg-bubble').each(function() {
                                            var $cl = $(this).clone();
                                            $cl.find('.bizc-msg-actions').remove();
                                            var bt = ($cl.text() || '').replace(/\s+/g, ' ').trim();
                                            if (bt === mTextClean) { domDup = true; return false; }
                                            if (bt.length > 40 && mTextClean.length > 40 && bt.substring(0,80) === mTextClean.substring(0,80)) { domDup = true; return false; }
                                        });
                                    }
                                    if (!domDup) {
                                        var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                                        var mTime = m.created_ts ? m.created_ts * 1000 : Date.now();
                                        messages.push({ role: 'bot', content: mText, timestamp: mTime, images: imgs });
                                        appendMsg(mText, 'bot', mTime, true, imgs);
                                    }
                                }
                            }
                            if (m.id) _renderedMsgIds[m.id] = true;
                        });
                    }
                }
                if (cb) cb();
            },
            error: function() { if (cb) cb(); }
        });
    }
    function startMsgPoll() {
        if (_msgPollTimer) return;
        _msgPollStartTime = Date.now();
        _msgPollTimer = setInterval(pollNewMessages, 2000);
        // Initial grace: stop after 60s if no new messages arrive at all
        if (_msgPollGraceTimer) clearTimeout(_msgPollGraceTimer);
        _msgPollGraceTimer = setTimeout(function() { stopMsgPoll(); }, 60000);
    }
    // Expose for executor panel (different script scope)
    window._bizcStartMsgPoll = startMsgPoll;
    window._bizcStopMsgPoll  = stopMsgPoll;
    function stopMsgPoll() {
        if (_msgPollTimer) {
            clearInterval(_msgPollTimer);
            _msgPollTimer = null;
        }
        if (_msgPollGraceTimer) {
            clearTimeout(_msgPollGraceTimer);
            _msgPollGraceTimer = null;
        }
    }
    function pollNewMessages() {
        if (!sessionId || !lastMsgId) return;
        // Auto-stop after max duration
        if (Date.now() - _msgPollStartTime > _msgPollMaxDuration) {
            stopMsgPoll();
            return;
        }
        $.ajax({
            url: ajaxurl, type: 'POST',
            data: {
                action: 'bizcity_webchat_session_poll',
                session_id: sessionId,
                since_id: lastMsgId,
                _wpnonce: nonce
            },
            dataType: 'json',
            success: function(res) {
                if (!res.success || !res.data || !res.data.messages) return;
                var newMsgs = res.data.messages;
                if (!newMsgs.length) return;
                newMsgs.forEach(function(m) {
                    if (m.id && m.id > lastMsgId) lastMsgId = m.id;

                    // ── Dedup layer 1: skip if this DB id was already rendered ──
                    if (m.id && _renderedMsgIds[m.id]) return;

                    if (m.from === 'system') return;
                    var from = (m.from === 'bot') ? 'bot' : 'user';

                    // ── Dedup layer 2: skip user messages from poll ──
                    // User messages are always rendered locally by _doSend().
                    // The poll should only bring in async bot messages (executor, tools, etc.)
                    if (from === 'user') {
                        if (m.id) _renderedMsgIds[m.id] = true;
                        return;
                    }

                    // ── Dedup layer 3: text + time fuzzy match ──
                    // Catch SSE-rendered bot replies that arrive again via poll.
                    var mRole = 'assistant';
                    var mText = (m.text || '').trim();
                    var mTime = m.created_ts ? m.created_ts * 1000 : new Date(m.created_at.replace(' ', 'T') + 'Z').getTime();
                    var dominated = messages.some(function(existing) {
                        if (existing.role !== mRole && existing.role !== 'bot') return false;
                        var existText = (existing.content || '').trim();
                        // Exact text match — always dedup regardless of time
                        if (existText === mText) return true;
                        // Partial match: first 100 chars match (covers LLM enrichment differences)
                        if (mText.length > 50 && existText.substring(0, 100) === mText.substring(0, 100)) return true;
                        return false;
                    });
                    if (dominated) {
                        if (m.id) _renderedMsgIds[m.id] = true;
                        return;
                    }

                    // ── Dedup layer 4: DOM content check ──
                    // Safety net: if the same text is already visible in a
                    // bubble (e.g. SSE rendered it while poll was in-flight),
                    // skip instead of creating a duplicate.
                    var mTextClean = mText.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
                    var domDup = false;
                    if (mTextClean) {
                        $msgs.find('.bizc-msg.bot .bizc-msg-bubble').each(function() {
                            var $cl = $(this).clone();
                            $cl.find('.bizc-msg-actions').remove();
                            var bt = ($cl.text() || '').replace(/\s+/g, ' ').trim();
                            if (bt === mTextClean) { domDup = true; return false; }
                            // Partial match (first 80 chars)
                            if (bt.length > 40 && mTextClean.length > 40 && bt.substring(0,80) === mTextClean.substring(0,80)) { domDup = true; return false; }
                        });
                    }
                    if (domDup) {
                        if (m.id) _renderedMsgIds[m.id] = true;
                        return;
                    }

                    // Mark as rendered and display
                    if (m.id) _renderedMsgIds[m.id] = true;
                    var imgs = (m.attachments && m.attachments.length) ? m.attachments : [];
                    messages.push({ role: from, content: m.text, timestamp: mTime, images: imgs });
                    appendMsg(m.text, from, mTime, true, imgs);
                });
                // Keep polling 120s more in case multi-step executor workflow
                if (_msgPollGraceTimer) clearTimeout(_msgPollGraceTimer);
                _msgPollGraceTimer = setTimeout(function() { stopMsgPoll(); }, 120000);
            }
        });
    }

    // Copy entire bot message
    window.bizcCopyMsg = function(btn) {
        var bubble = btn.closest('.bizc-msg').querySelector('.bizc-msg-bubble');
        if (!bubble) return;
        // Clone bubble, remove action buttons, then get text
        var clone = bubble.cloneNode(true);
        var acts = clone.querySelector('.bizc-msg-actions');
        if (acts) acts.remove();
        var text = clone.innerText || clone.textContent;
        navigator.clipboard.writeText(text).then(function() {
            btn.innerHTML = '✓';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.innerHTML = '📋';
                btn.classList.remove('copied');
            }, 2000);
        });
    };

    // ════════════════════════════════════════════════════════════
    //  PLUGIN PILLS SYSTEM
    //  
    //  Load and display available agent plugins as pills above input
    //  Uses Plugin Suggestion API to get active agents with icons
    // ════════════════════════════════════════════════════════════
    
    function loadPluginPills() {
        // DISABLED: Pills system removed for ChatGPT-style simplicity
        // Only @ mention dropdown is used now
        console.log('Pills system disabled - using @ mentions only');
        return;
    }
    
    function renderPluginPills(plugins) {
        var $pillsContainer = $('#bizc-pills-container');
        var html = '';
        
        plugins.forEach(function(plugin) {
            var iconSrc = plugin.icon || '';
            var iconHtml = iconSrc ? 
                '<img src="' + iconSrc + '" alt="" class="bizc-pill-icon" onerror="this.style.display=\'none\'">' :
                '<span class="bizc-pill-icon">🤖</span>';
            
            html += '<div class="bizc-pill" data-slug="' + plugin.slug + '" data-name="' + plugin.name + '" title="' + plugin.description + '">' +
                        iconHtml +
                        '<span class="bizc-pill-name">' + plugin.name + '</span>' +
                    '</div>';
        });
        
        $pillsContainer.html(html);
        
        // Add click handlers for pills
        $pillsContainer.on('click', '.bizc-pill', function() {
            var slug = $(this).data('slug');
            var name = $(this).data('name');
            selectPluginPill(slug, name);
        });
    }
    
    function selectPluginPill(slug, name) {
        // Toggle active state
        var $pill = $('.bizc-pill[data-slug="' + slug + '"]');
        var wasActive = $pill.hasClass('active');
        
        // Clear all active states
        $('.bizc-pill').removeClass('active');
        
        if (!wasActive) {
            // Activate this pill
            $pill.addClass('active');
            
            // Set mention provider (same as @mention system)
            var icon = $pill.find('.bizc-pill-icon img').attr('src') || 
                       $pill.find('.bizc-pill-icon').text() || '🤖';
            _selectMention(slug, name, icon);
            
            // Enter plugin context mode
            enterPluginContextMode(slug, name, icon);
            
            // Update input placeholder
            $input.attr('placeholder', 'Nhập tin nhắn cho ' + name + '...');
            
            // Trigger visual feedback
            $pill.css('transform', 'scale(0.95)');
            setTimeout(function() {
                $pill.css('transform', '');
            }, 150);
        } else {
            // Deactivate - clear selection
            clearPluginSelection();
        }
    }
    
    function clearPluginSelection() {
        $('.bizc-pill').removeClass('active');
        _clearMention(); // Also clear @mention system
        $input.attr('placeholder', 'Nhập tin nhắn... (@ chọn agent · / tìm tool)');
        exitPluginContextMode();
    }
    
    // ════════════════════════════════════════════════════════════
    //  PLUGIN CONTEXT MODE UI/UX FUNCTIONS
    //  
    //  Visual indicators when user is in plugin-specific context
    // ════════════════════════════════════════════════════════════
    
    function enterPluginContextMode(pluginSlug, pluginName, pluginIcon) {
        var $inputArea = $('#bizc-input-area');
        var $contextHeader = $('#bizc-context-header');
        var $contextIcon = $('#bizc-context-icon');
        var $messages = $('#bizc-messages');
        
        // Add context mode class to input area
        $inputArea.addClass('plugin-context-mode');
        
        // Update plugin icon
        if (pluginIcon && pluginIcon.indexOf('/') > -1) {
            $contextIcon.html('<img src="' + pluginIcon + '" class="bizc-context-plugin-icon" alt="">');
        } else {
            $contextIcon.text(pluginIcon || '🤖');
        }
        
        // Show context header with slide animation
        $contextHeader.addClass('active');
        
        // Load inline tool chips for this plugin
        _loadContextTools(pluginSlug);
        
        // Add context mode to messages container for styling
        $messages.addClass('plugin-context-mode');
        
        // Visual feedback - brief highlight
        $inputArea.css({
            'transform': 'scale(1.01)',
            'transition': 'transform 0.3s ease'
        });
        
        setTimeout(function() {
            $inputArea.css('transform', 'scale(1)');
        }, 300);
        
        console.log('🎯 Entered plugin context mode:', pluginSlug);
    }
    
    function exitPluginContextMode() {
        var $inputArea = $('#bizc-input-area');
        var $contextHeader = $('#bizc-context-header');
        var $messages = $('#bizc-messages');
        
        // Remove context mode classes
        $inputArea.removeClass('plugin-context-mode');
        $contextHeader.removeClass('active');
        $messages.removeClass('plugin-context-mode');
        
        // Clear tool chips row
        $('#bizc-context-tools').empty();
        
        console.log('↩️ Exited plugin context mode');
    }
    
    // ═══ HIL FOCUS MODE — handle focus_mode from SSE/REST/AJAX done ═══
    // Re-enters or exits plugin context mode based on server signal.
    // 'active'    → keep/enter HIL loop focus
    // 'completed' → goal achieved, exit focus
    // 'none'      → no goal context, no change
    function _handleFocusMode(data) {
        var fm = data.focus_mode || 'none';
        var ps = data.plugin_slug || '';

        if (fm === 'active' && ps) {
            // Look up label + icon from Pre-Intent chips bar
            var $chip = $chipsScroll.find('.bizc-plugin-chip[data-slug="' + ps + '"]');
            var chipLabel = $chip.length ? ($chip.data('label') || ps) : ps;
            var chipIcon  = $chip.length ? ($chip.data('icon') || '🤖') : '🤖';

            // Restore _mentionProvider so next send still includes provider_hint
            _mentionProvider = { slug: ps, label: chipLabel, icon: chipIcon };

            // Enter/maintain visual context mode
            enterPluginContextMode(ps, chipLabel, chipIcon);

            // Sync chip bar
            $chipsScroll.find('.bizc-plugin-chip').removeClass('active suggested');
            $chipsScroll.find('.bizc-plugin-chip[data-slug="' + ps + '"]').addClass('active');

            // Restore _selectedTool if done payload carries tool_name
            var tn = data.tool_name || '';
            if (tn) {
                // Try to find full tool info from cache
                var cachedTools = _contextToolsCache[ps] || [];
                var found = null;
                for (var ti = 0; ti < cachedTools.length; ti++) {
                    if (cachedTools[ti].tool_name === tn) { found = cachedTools[ti]; break; }
                }
                _selectedTool = {
                    goal:        found ? found.goal : (data.goal || ''),
                    tool_name:   tn,
                    title:       found ? (found.title || found.goal_label || tn) : (data.goal_label || tn),
                    goal_label:  found ? (found.goal_label || '') : (data.goal_label || ''),
                    plugin_slug: ps
                };
                // Show tool pill inline
                $toolPill.html('/' + tn + ' <span class="bizc-pill-remove" title="Thoát tool">✕</span>').show();
                var toolLabel = _selectedTool.goal_label || _selectedTool.title || tn;
                $input.attr('placeholder', 'Mô tả yêu cầu cho ' + toolLabel + '...');
                console.log('🔧 [Focus] Restored _selectedTool:', tn);
            } else {
                // No tool — hide pill if lingering
                $toolPill.hide().empty();
            }

            // Show plugin-level mention tag
            var iconHtml = (chipIcon && chipIcon.indexOf('/') > -1)
                ? '<img src="' + chipIcon + '" style="width:16px;height:16px;border-radius:4px;vertical-align:middle;" alt="">'
                : (chipIcon || '🤖');
            $mentionTag.html(iconHtml + ' ' + chipLabel + ' <span class="bizc-mt-remove" title="Bỏ chọn agent">✕</span>').show();

            console.log('🎯 [Focus] Maintaining plugin context:', ps);
        } else if (fm === 'completed') {
            _clearToolSelection();
            _clearMention();
            console.log('✅ [Focus] Goal completed — exiting plugin context');
        }
        // 'none' — no action needed (already cleared or general conversation)
    }

    // Context mode event handlers — cancel active intent conversation on close
    $('#bizc-context-close').on('click', function() {
        // Cancel the active intent conversation on server
        if (window._bizcIntentConvId) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bizcity_intent_cancel',
                    conversation_id: window._bizcIntentConvId,
                    _wpnonce: nonce
                }
            });
            console.log('🚫 [Focus] Cancelled intent conversation:', window._bizcIntentConvId);
            window._bizcIntentConvId = null;
        }
        _clearToolSelection();
        clearPluginSelection();
    });

    // ═══ Tool Chip Click — select tool from inline context header chips ═══
    $(document).on('click', '.bizc-tool-chip', function() {
        var $chip = $(this);
        var goal      = $chip.data('goal');
        var toolName  = $chip.data('tool-name');
        var title     = $chip.data('title');
        var goalLabel = $chip.data('goal-label');
        var slug      = $chip.data('plugin-slug');
        var name      = $chip.data('plugin-name');
        var icon      = $chip.data('icon');

        // Highlight active chip
        $('.bizc-tool-chip').removeClass('active');
        $chip.addClass('active');

        _selectTool(goal, toolName, title, goalLabel, slug, name, icon);
    });

    // ═══ v4.3.1: Tool Suggest Card Click — activate tool from bot message ═══
    $(document).on('click', '.bizc-tool-suggest-card, .bizc-tool-suggest-btn', function(e) {
        e.stopPropagation();
        var $card = $(this).closest('.bizc-tool-suggest-card');
        var goal      = $card.data('goal');
        var toolName  = $card.data('tool-name');
        var goalLabel = $card.data('goal-label');
        var slug      = $card.data('plugin-slug');

        // Look up plugin name + icon from pill bar
        var $pill = $chipsScroll.find('.bizc-plugin-chip[data-slug="' + slug + '"]');
        var pluginName = $pill.length ? ($pill.data('label') || slug) : slug;
        var pluginIcon = $pill.length ? ($pill.data('icon') || '🔧') : '🔧';

        _selectTool(goal, toolName, goalLabel, goalLabel, slug, pluginName, pluginIcon);

        // Visual feedback — pulse the card then fade it
        $card.css({ opacity: 0.5, pointerEvents: 'none' });
        $card.find('.bizc-tool-suggest-btn').text('✅ Đã kích hoạt');

        // Focus input so user can type their request
        $input.focus();
        console.log('🎯 [SuggestTool] Activated:', goal, 'plugin:', slug);
    });
    
    // (Floating indicator removed — only context header in input area is used)
    
    // Load pills on initialization
    // loadPluginPills(); // DISABLED: Using @mentions only for ChatGPT-style simplicity
    
    // Refresh pills every 30 seconds to catch newly activated plugins
    // setInterval(loadPluginPills, 30000); // DISABLED: Pills system removed
    
    // ════════════════════════════════════════════════════════════
    //  ENHANCED CONTEXT MODE FEEDBACK
    //  
    //  Audio and haptic feedback for better user experience
    // ════════════════════════════════════════════════════════════
    
    // Audio feedback (subtle beeps)
    function playContextEnterSound() {
        try {
            // Create a short, pleasant beep
            var audioContext = new (window.AudioContext || window.webkitAudioContext)();
            var oscillator = audioContext.createOscillator();
            var gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
            oscillator.frequency.setValueAtTime(1000, audioContext.currentTime + 0.1);
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.1, audioContext.currentTime + 0.01);
            gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.2);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.2);
        } catch (e) {
            // Audio not supported, ignore
        }
    }
    
    function playContextExitSound() {
        try {
            var audioContext = new (window.AudioContext || window.webkitAudioContext)();
            var oscillator = audioContext.createOscillator();
            var gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.setValueAtTime(600, audioContext.currentTime);
            oscillator.frequency.setValueAtTime(400, audioContext.currentTime + 0.1);
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.05, audioContext.currentTime + 0.01);
            gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.15);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.15);
        } catch (e) {
            // Audio not supported, ignore
        }
    }
    
    // Haptic feedback (mobile)
    function triggerHapticFeedback(type) {
        if (navigator.vibrate) {
            if (type === 'enter') {
                navigator.vibrate([50, 50, 100]); // short-pause-long
            } else if (type === 'exit') {
                navigator.vibrate([100, 50, 50]); // long-pause-short
            } else {
                navigator.vibrate(50); // single short vibration
            }
        }
    }
    
    // Enhanced enterPluginContextMode with feedback
    var originalEnterPluginContextMode = enterPluginContextMode;
    enterPluginContextMode = function(pluginSlug, pluginName, pluginIcon) {
        originalEnterPluginContextMode(pluginSlug, pluginName, pluginIcon);
        
        // Add feedback
        playContextEnterSound();
        triggerHapticFeedback('enter');
    };
    
    // Enhanced exitPluginContextMode with feedback
    var originalExitPluginContextMode = exitPluginContextMode;
    exitPluginContextMode = function() {
        originalExitPluginContextMode();
        
        // Add feedback
        playContextExitSound();
        triggerHapticFeedback('exit');
    };
    
    // ════════════════════════════════════════════════════════════
    //  ROUTING CONFIRMATION SYSTEM
    //  
    //  Show confirmation of which routing path was used
    // ════════════════════════════════════════════════════════════
    
    function showRoutingConfirmation(mode, pluginName) {
        var icon = mode === 'manual' ? '🎯' : '🤖';
        var text = mode === 'manual' ? 
            'Đã gửi đến ' + (pluginName || 'Plugin') :
            'Đang phân tích intent tự động';
        
        var confirmationHtml = 
            '<div class="bizc-routing-success" id="bizc-routing-confirm">' +
            '<div class="bizc-routing-success-icon">✓</div>' +
            '<span>' + icon + ' ' + text + '</span>' +
            '</div>';
        
        // Insert before messages
        $('#bizc-messages').prepend(confirmationHtml);
        
        // Auto-remove after 3 seconds
        setTimeout(function() {
            $('#bizc-routing-confirm').fadeOut(300, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // Routing confirmation disabled — no longer showing "Đang phân tích intent tự động"
});