/**!
 * Bizcity Twin AI — Personalized AI Companion Platform
 * Admin Dashboard Chat — Touchbar Module
 * (c) 2024-2026 BizCity by Johnny Chu (Chu Hoàng Anh) — Made in Vietnam 🇻🇳
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat\Assets
 * @license    GPL-2.0-or-later | https://bizcity.vn
 */
(function() {
    var COLS = 4;
    var ROW_H = 88;           /* ~px per grid row */
    var PAGE_PAD = 20;        /* grid page padding */
    var DOTS_H = 28;          /* dots bar height */
    var EXPAND_THRESHOLD = 90; /* px to switch to grid mode */
    var COMPACT_MAX_ITEMS = 8; /* max items to render in compact mode */
    var POOL_SIZE = 16;       /* DOM element pool size (recycle instead of create) */

    var touchbar = document.getElementById('bizc-touchbar');
    var dotsEl  = document.getElementById('bizc-tb-dots');
    var wrap    = document.getElementById('bizc-touchbar-wrap');
    var handle  = document.getElementById('bizc-tb-resize');
    var dataEl  = document.getElementById('bizc-tb-data');
    if (!touchbar || !dataEl) return;

    /* Parse JSON data */
    var tbData;
    try { tbData = JSON.parse(dataEl.textContent); } catch(e) { return; }
    var coreItems = tbData.core || [];
    var agentItems = tbData.agents || [];
    var allItemsData = coreItems.concat(agentItems);
    if (!allItemsData.length) return;

    /* ═══════════════════════════════════════════
       DOM POOL — recycle elements instead of creating new ones
       ═══════════════════════════════════════════ */
    var domPool = [];
    var activeElements = {}; /* slug → element */

    function createButton() {
        var btn = document.createElement('button');
        btn.className = 'bizc-tb-item';
        btn.innerHTML = '<span class="bizc-tb-icon"></span><span class="bizc-tb-label"></span>';
        return btn;
    }

    function acquireButton() {
        return domPool.length ? domPool.pop() : createButton();
    }

    function releaseButton(btn) {
        if (btn.parentNode) btn.parentNode.removeChild(btn);
        btn.className = 'bizc-tb-item';
        btn.removeAttribute('data-slug');
        btn.removeAttribute('data-src');
        btn.title = '';
        var iconEl = btn.querySelector('.bizc-tb-icon');
        if (iconEl) iconEl.innerHTML = '';
        var labelEl = btn.querySelector('.bizc-tb-label');
        if (labelEl) labelEl.textContent = '';
        if (domPool.length < POOL_SIZE) domPool.push(btn);
    }

    function renderButton(item) {
        var btn = acquireButton();
        var cssClass = 'bizc-tb-item';
        if (item.type === 'chat') cssClass += ' bizc-tb-chat';
        else if (item.type === 'link') cssClass += ' bizc-tb-link';
        else if (item.type === 'agent') cssClass += ' bizc-tb-agent';
        btn.className = cssClass;
        btn.setAttribute('data-slug', item.slug);
        if (item.src) btn.setAttribute('data-src', item.src);
        btn.title = item.title || item.label || '';
        
        var iconEl = btn.querySelector('.bizc-tb-icon');
        if (iconEl) {
            if (item.type === 'agent' && item.icon && item.icon.indexOf('http') === 0) {
                iconEl.innerHTML = '<img src="' + item.icon + '" alt="" loading="lazy">';
            } else {
                iconEl.textContent = item.icon || '🤖';
            }
        }
        var labelEl = btn.querySelector('.bizc-tb-label');
        if (labelEl) labelEl.textContent = item.label || '';
        
        activeElements[item.slug] = btn;
        return btn;
    }

    /* ═══════════════════════════════════════════
       VIRTUAL PAGINATION — only render visible page
       ═══════════════════════════════════════════ */
    var _currentRows = 0;
    var _currentPage = 0;
    var _totalPages = 1;
    var _perPage = 8;
    var _isExpanded = false;

    function clearTouchbar() {
        /* Release all active elements back to pool */
        for (var slug in activeElements) {
            releaseButton(activeElements[slug]);
        }
        activeElements = {};
        touchbar.innerHTML = '';
    }

    function renderCompact() {
        /* Compact mode: render up to COMPACT_MAX_ITEMS in single strip */
        clearTouchbar();
        var count = Math.min(allItemsData.length, COMPACT_MAX_ITEMS);
        for (var i = 0; i < count; i++) {
            var btn = renderButton(allItemsData[i]);
            touchbar.appendChild(btn);
        }
        /* If more items exist, add "more" indicator */
        if (allItemsData.length > COMPACT_MAX_ITEMS) {
            var moreBtn = acquireButton();
            moreBtn.className = 'bizc-tb-item bizc-tb-more';
            moreBtn.title = 'Xem thêm ' + (allItemsData.length - COMPACT_MAX_ITEMS) + ' ứng dụng';
            var iconEl = moreBtn.querySelector('.bizc-tb-icon');
            if (iconEl) iconEl.textContent = '➕';
            var labelEl = moreBtn.querySelector('.bizc-tb-label');
            if (labelEl) labelEl.textContent = '+' + (allItemsData.length - COMPACT_MAX_ITEMS);
            moreBtn.onclick = function() { expandTouchbar(); };
            touchbar.appendChild(moreBtn);
            activeElements['__more__'] = moreBtn;
        }
        updateDots(1, 0);
    }

    function renderPage(pageIndex) {
        /* Expanded mode: render only items for current page */
        clearTouchbar();
        _currentPage = pageIndex;
        
        var start = pageIndex * _perPage;
        var end = Math.min(start + _perPage, allItemsData.length);
        
        var pg = document.createElement('div');
        pg.className = 'bizc-tb-page';
        
        for (var i = start; i < end; i++) {
            var btn = renderButton(allItemsData[i]);
            pg.appendChild(btn);
        }
        touchbar.appendChild(pg);
        updateDots(_totalPages, pageIndex);
    }

    function updateDots(total, current) {
        if (!dotsEl) return;
        dotsEl.innerHTML = '';
        if (total > 1) {
            dotsEl.classList.add('has-dots');
            for (var d = 0; d < total; d++) {
                var dot = document.createElement('span');
                dot.className = 'bizc-tb-dot' + (d === current ? ' active' : '');
                dot.setAttribute('data-page', d);
                dotsEl.appendChild(dot);
            }
        } else {
            dotsEl.classList.remove('has-dots');
        }
    }

    function expandTouchbar() {
        if (!wrap) return;
        wrap.style.height = '200px';
        syncExpandState();
    }

    /* ── Calculate rows and pages ── */
    function calcLayout(wrapH) {
        if (!wrapH || wrapH <= EXPAND_THRESHOLD) {
            return { rows: 1, perPage: COMPACT_MAX_ITEMS, expanded: false };
        }
        var available = wrapH - PAGE_PAD - DOTS_H;
        var rows = Math.max(2, Math.floor(available / ROW_H));
        return { rows: rows, perPage: COLS * rows, expanded: true };
    }

    function syncExpandState() {
        if (!wrap) return;
        var h = wrap.offsetHeight;
        var layout = calcLayout(h);
        var wasExpanded = _isExpanded;
        _isExpanded = layout.expanded;
        
        wrap.classList.toggle('expanded', _isExpanded);
        
        if (_isExpanded) {
            _perPage = layout.perPage;
            _totalPages = Math.ceil(allItemsData.length / _perPage);
            /* Re-render current page if layout changed */
            if (layout.rows !== _currentRows || !wasExpanded) {
                _currentRows = layout.rows;
                renderPage(_currentPage);
            }
        } else {
            if (wasExpanded) {
                _currentRows = 1;
                renderCompact();
            }
        }
    }

    /* ── Dot click → navigate pages ── */
    if (dotsEl) {
        dotsEl.addEventListener('click', function(e) {
            var dot = e.target.closest('.bizc-tb-dot');
            if (dot && _isExpanded) {
                var pg = parseInt(dot.getAttribute('data-page'), 10);
                if (!isNaN(pg) && pg !== _currentPage) {
                    renderPage(pg);
                }
            }
        });
    }

    /* ── Swipe navigation for expanded pages ── */
    var _touchStartX = 0;
    touchbar.addEventListener('touchstart', function(e) {
        _touchStartX = e.touches[0].clientX;
    }, {passive: true});
    touchbar.addEventListener('touchend', function(e) {
        if (!_isExpanded || _totalPages <= 1) return;
        var diffX = e.changedTouches[0].clientX - _touchStartX;
        if (Math.abs(diffX) > 50) {
            if (diffX < 0 && _currentPage < _totalPages - 1) {
                renderPage(_currentPage + 1);
            } else if (diffX > 0 && _currentPage > 0) {
                renderPage(_currentPage - 1);
            }
        }
    }, {passive: true});

    /* ── Initial render (compact mode) ── */
    renderCompact();

    /* ── Resize handle: drag to expand/collapse ── */
    if (!handle || !wrap) return;
    var _startY, _startH;
    var _minH = 56, _maxH = Math.min(520, window.innerHeight * 0.6);

    function rStart(y) {
        _startY = y; _startH = wrap.offsetHeight;
        wrap.style.transition = 'none';
        document.body.style.cursor = 'ns-resize';
        document.body.style.userSelect = 'none';
        document.body.style.webkitUserSelect = 'none';
    }
    function rMove(y) {
        var newH = Math.max(_minH, Math.min(_maxH, _startH + (y - _startY)));
        wrap.style.height = newH + 'px';
        syncExpandState();
    }
    function rEnd() {
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
        document.body.style.webkitUserSelect = '';
        var h = wrap.offsetHeight;
        if (h <= EXPAND_THRESHOLD) {
            wrap.style.height = '';
        }
        wrap.style.transition = '';
        syncExpandState();
    }

    handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        rStart(e.clientY);
        function mm(ev) { rMove(ev.clientY); }
        function mu() { document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); rEnd(); }
        document.addEventListener('mousemove', mm);
        document.addEventListener('mouseup', mu);
    });
    handle.addEventListener('touchstart', function(e) {
        rStart(e.touches[0].clientY);
        function tm(ev) { ev.preventDefault(); rMove(ev.touches[0].clientY); }
        function te() { document.removeEventListener('touchmove', tm); document.removeEventListener('touchend', te); rEnd(); }
        document.addEventListener('touchmove', tm, {passive:false});
        document.addEventListener('touchend', te);
    }, {passive:true});
    
    /* Expose for external access (e.g., jQuery handlers) */
    window.bizcTouchbarData = allItemsData;
    window.bizcTouchbarRefresh = function() { _isExpanded ? renderPage(_currentPage) : renderCompact(); };
    
    /* ── Helper: Close mobile sidebar ── */
    function closeMobileSidebar() {
        var sidebar = document.querySelector('.bizc-sidebar');
        var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
        var hamburgerBtn = document.getElementById('bizc-tb-hamburger');
        if (sidebar && sidebar.classList.contains('mobile-open')) {
            sidebar.classList.remove('mobile-open');
            if (hamburgerBtn) hamburgerBtn.classList.remove('open');
            if (backdrop) backdrop.classList.remove('active');
        }
    }
    // Expose globally for jQuery handlers
    window.closeMobileSidebar = closeMobileSidebar;
    
    /* ── Backdrop click → close sidebar ── */
    var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
    if (backdrop) {
        backdrop.addEventListener('click', function() {
            closeMobileSidebar();
            // Also close right drawer if open
            var rightDrawer = document.getElementById('aiagent-right-drawer');
            if (rightDrawer) rightDrawer.classList.remove('mobile-open');
            backdrop.classList.remove('active');
        });
    }
    
    /* ── Hamburger button → toggle sidebar ── */
    var hamburgerBtn = document.getElementById('bizc-tb-hamburger');
    if (hamburgerBtn) {
        hamburgerBtn.addEventListener('click', function() {
            var sidebar = document.querySelector('.bizc-sidebar');
            var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
            var isMobile = window.innerWidth <= 768;
            
            if (sidebar) {
                if (isMobile) {
                    /* Mobile: show/hide with mobile-open + overlay */
                    var isOpen = sidebar.classList.contains('mobile-open');
                    if (isOpen) {
                        sidebar.classList.remove('mobile-open');
                        hamburgerBtn.classList.remove('open');
                        if (backdrop) backdrop.classList.remove('active');
                    } else {
                        sidebar.classList.add('mobile-open');
                        hamburgerBtn.classList.add('open');
                        if (backdrop) backdrop.classList.add('active');
                        // Close right drawer if open
                        var rightDrawer = document.getElementById('aiagent-right-drawer');
                        if (rightDrawer) rightDrawer.classList.remove('mobile-open');
                    }
                } else {
                    /* Desktop: toggle desktop-hidden (slide left to hide) */
                    var isHidden = sidebar.classList.contains('desktop-hidden');
                    if (isHidden) {
                        sidebar.classList.remove('desktop-hidden');
                        hamburgerBtn.classList.remove('open');
                    } else {
                        sidebar.classList.add('desktop-hidden');
                        hamburgerBtn.classList.add('open');
                    }
                }
            }
        });
    }
    
    /* ── Profile button → toggle right drawer (or login dialog for guests) ── */
    var profileBtn = document.getElementById('bizc-tb-profile');
    var isGuestUser = bizcDashConfig.isGuest;
    if (profileBtn) {
        profileBtn.addEventListener('click', function() {
            // If guest, show login dialog instead
            if (isGuestUser) {
                if (typeof window.aiagentShowAuth === 'function') {
                    window.aiagentShowAuth('login');
                } else {
                    window.location.href = bizcDashConfig.loginUrl;
                }
                return;
            }
            
            var rightDrawer = document.getElementById('aiagent-right-drawer');
            var backdrop = document.getElementById('bizc-drawer-backdrop') || document.getElementById('aiagent-drawer-backdrop');
            if (rightDrawer) {
                var isOpen = rightDrawer.classList.contains('mobile-open');
                if (isOpen) {
                    rightDrawer.classList.remove('mobile-open');
                    if (backdrop) backdrop.classList.remove('active');
                } else {
                    rightDrawer.classList.add('mobile-open');
                    if (backdrop) backdrop.classList.add('active');
                    // Close sidebar if open
                    var sidebar = document.querySelector('.bizc-sidebar');
                    if (sidebar) sidebar.classList.remove('mobile-open');
                    if (hamburgerBtn) hamburgerBtn.classList.remove('open');
                }
            }
        });
    }
    
    /* ── Sidebar Collapse button (desktop) ── */
    var collapseBtn = document.getElementById('bizc-sidebar-collapse');
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function() {
            var sidebar = document.querySelector('.bizc-sidebar');
            var hamburgerBtn = document.getElementById('bizc-tb-hamburger');
            if (sidebar) {
                var isHidden = sidebar.classList.contains('desktop-hidden');
                if (isHidden) {
                    sidebar.classList.remove('desktop-hidden');
                    if (hamburgerBtn) hamburgerBtn.classList.remove('open');
                } else {
                    sidebar.classList.add('desktop-hidden');
                    if (hamburgerBtn) hamburgerBtn.classList.add('open');
                }
            }
        });
    }
    
    /* ── Guest Login Button ── */
    var guestLoginBtn = document.getElementById('bizc-guest-login-btn');
    if (guestLoginBtn) {
        guestLoginBtn.addEventListener('click', function() {
            closeMobileSidebar(); // Close sidebar first
            if (typeof window.aiagentShowAuth === 'function') {
                window.aiagentShowAuth('login');
            } else {
                // Fallback: redirect to login page
                window.location.href = bizcDashConfig.loginUrl;
            }
        });
    }
    
    /* ── Search Chat Modal ── */
    var searchBtn = document.getElementById('bizc-search-btn');
    var searchModal = document.getElementById('bizc-search-modal');
    var searchClose = document.getElementById('bizc-search-close');
    var searchInput = document.getElementById('bizc-search-input');
    var searchResults = document.getElementById('bizc-search-results');
    var searchTimeout = null;
    var searchAjaxUrl = bizcDashConfig.ajaxurl;
    var searchNonce = bizcDashConfig.nonce;
    
    function showSearchModal() {
        if (searchModal) {
            searchModal.classList.add('active');
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            // Load all recent chats on open
            loadAllChats();
        }
    }
    
    function hideSearchModal() {
        if (searchModal) searchModal.classList.remove('active');
    }
    
    function loadAllChats() {
        if (!searchResults) return;
        searchResults.innerHTML = '<div class="bizc-search-empty">Loading...</div>';
        
        fetch(searchAjaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=bizcity_webchat_sessions&_wpnonce=' + searchNonce
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (!res.success || !res.data) {
                renderSearchList([]);
                return;
            }
            renderSearchList(res.data);
        })
        .catch(function() {
            searchResults.innerHTML = '<div class="bizc-search-empty">Connection error</div>';
        });
    }
    
    function renderSearchList(sessions, filterQuery) {
        if (!searchResults) return;
        
        // Filter if query provided
        var items = sessions;
        if (filterQuery && filterQuery.trim()) {
            var q = filterQuery.toLowerCase();
            items = sessions.filter(function(s) {
                return (s.title || '').toLowerCase().indexOf(q) !== -1;
            });
        }
        
        // Group by date
        var today = new Date();
        today.setHours(0,0,0,0);
        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        var weekAgo = new Date(today);
        weekAgo.setDate(weekAgo.getDate() - 7);
        var monthAgo = new Date(today);
        monthAgo.setDate(monthAgo.getDate() - 30);
        
        var groups = { today: [], yesterday: [], week: [], month: [], older: [] };
        
        items.forEach(function(s) {
            var d = new Date(s.last_activity || s.started_at);
            d.setHours(0,0,0,0);
            if (d >= today) groups.today.push(s);
            else if (d >= yesterday) groups.yesterday.push(s);
            else if (d >= weekAgo) groups.week.push(s);
            else if (d >= monthAgo) groups.month.push(s);
            else groups.older.push(s);
        });
        
        var html = '<ol class="bizc-search-list">';
        
        // New chat option
        html += '<li><div class="bizc-search-item" data-action="new-chat">' +
            '<div class="bizc-search-item-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>' +
            '<div class="bizc-search-item-title">New chat</div>' +
            '</div></li>';
        
        // Render groups
        var groupLabels = [
            { key: 'today', label: 'Today' },
            { key: 'yesterday', label: 'Yesterday' },
            { key: 'week', label: 'Previous 7 Days' },
            { key: 'month', label: 'Previous 30 Days' },
            { key: 'older', label: 'Older' }
        ];
        
        groupLabels.forEach(function(g) {
            if (groups[g.key].length === 0) return;
            html += '<li><div class="bizc-search-group-label">' + g.label + '</div></li>';
            groups[g.key].forEach(function(s) {
                var title = escapeHtml(s.title || 'New chat');
                html += '<li><div class="bizc-search-item" data-wc-id="' + s.id + '">' +
                    '<div class="bizc-search-item-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>' +
                    '<div class="bizc-search-item-title">' + title + '</div>' +
                    '</div></li>';
            });
        });
        
        html += '</ol>';
        
        if (items.length === 0 && filterQuery) {
            html = '<div class="bizc-search-empty">No results found</div>';
        }
        
        searchResults.innerHTML = html;
        
        // Click handlers
        searchResults.querySelectorAll('.bizc-search-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var action = this.dataset.action;
                var wcId = this.dataset.wcId;
                hideSearchModal();
                closeMobileSidebar(); // Close sidebar on mobile
                
                if (action === 'new-chat') {
                    if (window.bizcStartNewChat) window.bizcStartNewChat();
                    else if (document.getElementById('bizc-new-chat')) document.getElementById('bizc-new-chat').click();
                } else if (wcId) {
                    if (window.bizcLoadSession) window.bizcLoadSession(parseInt(wcId));
                    else {
                        var convItem = document.querySelector('.bizc-conv[data-wc-id="' + wcId + '"]');
                        if (convItem) convItem.click();
                    }
                }
            });
        });
        
        // Store sessions for filtering
        searchResults._sessions = sessions;
    }
    
    function doSearch(query) {
        if (!searchResults || !searchResults._sessions) return;
        renderSearchList(searchResults._sessions, query);
    }
    
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    if (searchBtn) {
        searchBtn.addEventListener('click', showSearchModal);
    }
    
    if (searchClose) {
        searchClose.addEventListener('click', hideSearchModal);
    }
    
    if (searchModal) {
        searchModal.addEventListener('click', function(e) {
            if (e.target === searchModal) hideSearchModal();
        });
    }
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value;
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                doSearch(val);
            }, 300);
        });
    }
})();