/**
 * Skill Library — React SPA (No build step required)
 *
 * Uses wp.element (WordPress-bundled React) + EasyMDE from CDN.
 * All components use React.createElement (aliased as `h`).
 *
 * @package  BizCity_Knowledge
 * @since    2026-03-31
 */
(function () {
    'use strict';

    var el = wp.element;
    var h = el.createElement;
    var useState = el.useState;
    var useEffect = el.useEffect;
    var useRef = el.useRef;
    var useCallback = el.useCallback;
    var Fragment = el.Fragment;

    var MODES = ['planning', 'execution', 'automation', 'coding', 'content', 'emotion', 'reflection', 'knowledge'];

    /* ================================================================
     *  API Layer
     * ================================================================ */
    var API = {
        base: (typeof skillLibVars !== 'undefined' ? skillLibVars.restBase : '/wp-json/bizcity/skill/v1'),
        nonce: (typeof skillLibVars !== 'undefined' ? skillLibVars.nonce : ''),

        _fetch: function (path, opts) {
            opts = opts || {};
            var headers = { 'X-WP-Nonce': API.nonce, 'Content-Type': 'application/json' };
            return fetch(API.base + path, Object.assign({ headers: headers, credentials: 'same-origin' }, opts))
                .then(function (r) {
                    if (!r.ok) return r.json().then(function (e) { throw new Error(e.error || 'API Error ' + r.status); });
                    return r.json();
                });
        },

        listSkills: function (filters) {
            var qs = Object.keys(filters || {}).filter(function (k) { return filters[k]; })
                .map(function (k) { return k + '=' + encodeURIComponent(filters[k]); }).join('&');
            return API._fetch('/skills' + (qs ? '?' + qs : ''));
        },
        getSkill: function (id) { return API._fetch('/skills/' + id); },
        saveSkill: function (data) {
            if (data.id) return API._fetch('/skills/' + data.id, { method: 'PUT', body: JSON.stringify(data) });
            return API._fetch('/skills', { method: 'POST', body: JSON.stringify(data) });
        },
        deleteSkill: function (id) { return API._fetch('/skills/' + id, { method: 'DELETE' }); },
        testSkill: function (d) { return API._fetch('/skills/test', { method: 'POST', body: JSON.stringify(d) }); },
        getCategories: function () { return API._fetch('/skills/categories'); },
    };

    /* ================================================================
     *  Toast Notification
     * ================================================================ */
    function showToast(msg, isError) {
        var d = document.createElement('div');
        d.className = 'sk-toast' + (isError ? ' error' : '');
        d.textContent = msg;
        document.body.appendChild(d);
        setTimeout(function () { d.remove(); }, 3000);
    }

    /* ================================================================
     *  EasyMDE Wrapper Component
     * ================================================================ */
    function MarkdownEditor(props) {
        var containerRef = useRef(null);
        var editorRef = useRef(null);
        var onChangeRef = useRef(props.onChange);
        onChangeRef.current = props.onChange;

        // Mount / unmount EasyMDE
        useEffect(function () {
            if (!containerRef.current || typeof EasyMDE === 'undefined') return;

            var ta = document.createElement('textarea');
            containerRef.current.innerHTML = '';
            containerRef.current.appendChild(ta);

            var mde = new EasyMDE({
                element: ta,
                initialValue: props.value || '',
                spellChecker: false,
                autofocus: false,
                placeholder: 'Viết skill instructions bằng Markdown...\n\n# Mục đích\n\n# Quy trình\n\n# Guardrails\n\n# Ví dụ',
                toolbar: [
                    'bold', 'italic', 'heading', '|',
                    'quote', 'unordered-list', 'ordered-list', '|',
                    'link', 'code', 'table', '|',
                    'preview', 'side-by-side', 'fullscreen', '|',
                    'guide'
                ],
                status: false,
                minHeight: '200px',
            });
            editorRef.current = mde;

            mde.codemirror.on('change', function () {
                onChangeRef.current(mde.value());
            });

            return function () {
                if (editorRef.current) {
                    editorRef.current.toTextArea();
                    editorRef.current = null;
                }
            };
        }, [props.skillId]); // Recreate editor when skill changes

        return h('div', { ref: containerRef, className: 'sk-md-editor-wrap' });
    }

    /* ================================================================
     *  Tag Input Component
     * ================================================================ */
    function TagInput(props) {
        var tags = props.value || [];
        var inputRef = useRef(null);

        function addTag(val) {
            val = val.trim();
            if (val && tags.indexOf(val) === -1) {
                props.onChange(tags.concat(val));
            }
        }

        function removeTag(i) {
            props.onChange(tags.filter(function (_, idx) { return idx !== i; }));
        }

        function onKeyDown(e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTag(e.target.value);
                e.target.value = '';
            }
            if (e.key === 'Backspace' && !e.target.value && tags.length) {
                removeTag(tags.length - 1);
            }
        }

        return h('div', {
            className: 'sk-tag-container',
            onClick: function () { inputRef.current && inputRef.current.focus(); }
        },
            tags.map(function (t, i) {
                return h('span', { key: t + i, className: 'sk-tag' },
                    t,
                    h('span', { className: 'sk-tag-remove', onClick: function () { removeTag(i); } }, '×')
                );
            }),
            h('input', {
                ref: inputRef,
                className: 'sk-tag-input',
                placeholder: props.placeholder || 'Nhập + Enter',
                onKeyDown: onKeyDown,
                onBlur: function (e) { if (e.target.value) { addTag(e.target.value); e.target.value = ''; } }
            })
        );
    }

    /* ================================================================
     *  Mode Selector Component
     * ================================================================ */
    function ModeSelector(props) {
        var selected = props.value || [];

        function toggle(mode) {
            if (selected.indexOf(mode) >= 0) {
                props.onChange(selected.filter(function (m) { return m !== mode; }));
            } else {
                props.onChange(selected.concat(mode));
            }
        }

        return h('div', { className: 'sk-mode-grid' },
            MODES.map(function (m) {
                var isSelected = selected.indexOf(m) >= 0;
                return h('span', {
                    key: m,
                    className: 'sk-mode-chip' + (isSelected ? ' selected' : ''),
                    onClick: function () { toggle(m); }
                }, m);
            })
        );
    }

    /* ================================================================
     *  Sidebar Component
     * ================================================================ */
    function SkillSidebar(props) {
        var skills = props.skills;
        var currentId = props.currentId;

        return h('div', { className: 'sk-sidebar' },
            // Header
            h('div', { className: 'sk-sidebar-header' },
                h('h2', null, '⚡ Skills'),
                h('button', { className: 'sk-btn-new', onClick: props.onNew }, '+ Tạo mới')
            ),
            // Filters
            h('div', { className: 'sk-sidebar-filters' },
                h('input', {
                    className: 'sk-search-input',
                    placeholder: '🔍 Tìm kiếm skill...',
                    value: props.filters.search,
                    onChange: function (e) { props.onFilter('search', e.target.value); }
                }),
                h('div', { className: 'sk-filter-row' },
                    h('select', {
                        className: 'sk-filter-select',
                        value: props.filters.status,
                        onChange: function (e) { props.onFilter('status', e.target.value); }
                    },
                        h('option', { value: '' }, 'Tất cả status'),
                        h('option', { value: 'active' }, 'Active'),
                        h('option', { value: 'draft' }, 'Draft')
                    ),
                    h('select', {
                        className: 'sk-filter-select',
                        value: props.filters.mode,
                        onChange: function (e) { props.onFilter('mode', e.target.value); }
                    },
                        h('option', { value: '' }, 'Tất cả mode'),
                        MODES.map(function (m) { return h('option', { key: m, value: m }, m); })
                    )
                )
            ),
            // List
            h('div', { className: 'sk-sidebar-list' },
                skills.length === 0
                    ? h('div', { className: 'sk-sidebar-empty' }, 'Chưa có skill nào. Bấm "+ Tạo mới" để bắt đầu.')
                    : skills.map(function (s) {
                        return h('div', {
                            key: s.id,
                            className: 'sk-skill-item' + (s.id === currentId ? ' active' : ''),
                            onClick: function () { props.onSelect(s); }
                        },
                            h('div', { className: 'sk-skill-item-title' }, s.title || '(Chưa có tiêu đề)'),
                            h('div', { className: 'sk-skill-item-meta' },
                                h('span', { className: 'sk-badge sk-badge-' + s.status }, s.status),
                                s.category ? h('span', null, s.category) : null,
                                h('span', null, '×' + s.use_count)
                            )
                        );
                    })
            )
        );
    }

    /* ================================================================
     *  Meta Panel Component (left column of editor)
     * ================================================================ */
    function MetaPanel(props) {
        var d = props.data;
        var set = props.onChange;

        return h('div', { className: 'sk-meta-panel' },
            // Skill Key
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Skill Key'),
                h('input', {
                    className: 'sk-meta-input', value: d.skill_key || '',
                    placeholder: 'vd: write-sales-post',
                    onChange: function (e) { set('skill_key', e.target.value); }
                })
            ),
            // Description
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Mô tả'),
                h('textarea', {
                    className: 'sk-meta-textarea', value: d.description || '',
                    placeholder: 'Mô tả ngắn gọn...',
                    onChange: function (e) { set('description', e.target.value); }
                })
            ),
            // Category
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Category'),
                h('input', {
                    className: 'sk-meta-input', value: d.category || '',
                    placeholder: 'vd: content, automation, tools',
                    onChange: function (e) { set('category', e.target.value); }
                })
            ),
            // Status
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Status'),
                h('div', { className: 'sk-status-toggle' },
                    h('button', {
                        type: 'button',
                        className: 'sk-status-btn' + (d.status === 'active' ? ' active-active' : ''),
                        onClick: function () { set('status', 'active'); }
                    }, '✓ Active'),
                    h('button', {
                        type: 'button',
                        className: 'sk-status-btn' + (d.status === 'draft' ? ' active-draft' : ''),
                        onClick: function () { set('status', 'draft'); }
                    }, '✎ Draft')
                )
            ),
            // Modes
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Modes'),
                h(ModeSelector, {
                    value: d.modes_json || [],
                    onChange: function (v) { set('modes_json', v); }
                })
            ),
            // Priority
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Priority ', h('span', { className: 'sk-priority-value' }, d.priority || 50)),
                h('input', {
                    type: 'range', min: 0, max: 100, className: 'sk-priority-slider',
                    value: d.priority || 50,
                    onChange: function (e) { set('priority', parseInt(e.target.value)); }
                })
            ),
            // Triggers
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Triggers (keywords)'),
                h(TagInput, {
                    value: d.triggers_json || [],
                    placeholder: 'Từ khóa + Enter',
                    onChange: function (v) { set('triggers_json', v); }
                })
            ),
            // Related Tools
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Related Tools'),
                h(TagInput, {
                    value: d.related_tools_json || [],
                    placeholder: 'Tool name + Enter',
                    onChange: function (v) { set('related_tools_json', v); }
                })
            ),
            // Related Plugins
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Related Plugins'),
                h(TagInput, {
                    value: d.related_plugins_json || [],
                    placeholder: 'Plugin slug + Enter',
                    onChange: function (v) { set('related_plugins_json', v); }
                })
            ),
            // Version
            h('div', { className: 'sk-meta-group' },
                h('label', null, 'Version'),
                h('input', {
                    className: 'sk-meta-input', value: d.version || '1.0',
                    onChange: function (e) { set('version', e.target.value); }
                })
            ),
            // Info
            d.id ? h('div', { className: 'sk-meta-group', style: { fontSize: '11px', color: '#64748b' } },
                h('div', null, 'ID: ' + d.id),
                h('div', null, 'Slug: ' + (d.slug || '')),
                h('div', null, 'Sử dụng: ' + (d.use_count || 0) + ' lần'),
                d.last_used_at ? h('div', null, 'Dùng cuối: ' + d.last_used_at) : null,
                d.created_at ? h('div', null, 'Tạo: ' + d.created_at) : null
            ) : null
        );
    }

    /* ================================================================
     *  Test Panel (slide-out)
     * ================================================================ */
    function TestPanel(props) {
        var _sMes = useState('');
        var message = _sMes[0]; var setMessage = _sMes[1];
        var _sMode = useState('');
        var mode = _sMode[0]; var setMode = _sMode[1];
        var _sGoal = useState('');
        var goal = _sGoal[0]; var setGoal = _sGoal[1];
        var _sRes = useState(null);
        var results = _sRes[0]; var setResults = _sRes[1];
        var _sLoading = useState(false);
        var loading = _sLoading[0]; var setLoading = _sLoading[1];

        function doTest() {
            if (!message.trim()) return;
            setLoading(true);
            API.testSkill({ message: message, mode: mode, goal: goal })
                .then(function (r) { setResults(r); })
                .catch(function (e) { showToast(e.message, true); })
                .finally(function () { setLoading(false); });
        }

        return h('div', { className: 'sk-test-overlay' + (props.open ? ' open' : '') },
            h('div', { className: 'sk-test-header' },
                h('h3', null, '🧪 Test Skill Matching'),
                h('button', { className: 'sk-test-close', onClick: props.onClose }, '×')
            ),
            h('div', { className: 'sk-test-body' },
                h('input', { className: 'sk-test-input', placeholder: 'Nhập tin nhắn thử...', value: message, onChange: function (e) { setMessage(e.target.value); } }),
                h('div', { style: { display: 'flex', gap: '6px', marginBottom: '8px' } },
                    h('select', { className: 'sk-test-input', style: { flex: 1 }, value: mode, onChange: function (e) { setMode(e.target.value); } },
                        h('option', { value: '' }, 'Mode'),
                        MODES.map(function (m) { return h('option', { key: m, value: m }, m); })
                    ),
                    h('input', { className: 'sk-test-input', style: { flex: 1 }, placeholder: 'Goal', value: goal, onChange: function (e) { setGoal(e.target.value); } })
                ),
                h('button', { className: 'sk-test-btn', onClick: doTest, disabled: loading }, loading ? 'Đang test...' : '▶ Test'),
                results ? h('div', { className: 'sk-test-result' },
                    results.length === 0
                        ? h('div', { style: { padding: '12px', color: '#94a3b8' } }, 'Không match skill nào.')
                        : results.map(function (m) {
                            return h('div', { key: m.skill_id, className: 'sk-test-match' },
                                h('div', { className: 'sk-test-match-title' }, m.title),
                                h('div', { className: 'sk-test-match-score' }, 'Score: ' + m.score),
                                h('div', { className: 'sk-test-match-reasons' }, m.reasons.join(', '))
                            );
                        })
                ) : null
            )
        );
    }

    /* ================================================================
     *  Main App Component
     * ================================================================ */
    function SkillLibraryApp() {
        // State
        var _sSkills = useState([]);
        var skills = _sSkills[0]; var setSkills = _sSkills[1];
        var _sCurrent = useState(null);
        var current = _sCurrent[0]; var setCurrent = _sCurrent[1];
        var _sFilters = useState({ search: '', status: '', mode: '' });
        var filters = _sFilters[0]; var setFilters = _sFilters[1];
        var _sLoading = useState(true);
        var loading = _sLoading[0]; var setLoading = _sLoading[1];
        var _sSaving = useState(false);
        var saving = _sSaving[0]; var setSaving = _sSaving[1];
        var _sTestOpen = useState(false);
        var testOpen = _sTestOpen[0]; var setTestOpen = _sTestOpen[1];
        var _sDirty = useState(false);
        var dirty = _sDirty[0]; var setDirty = _sDirty[1];

        // Debounced filter fetch
        var fetchTimerRef = useRef(null);

        var fetchSkills = useCallback(function (f) {
            setLoading(true);
            API.listSkills(f || filters)
                .then(function (data) { setSkills(data); })
                .catch(function (e) { showToast(e.message, true); })
                .finally(function () { setLoading(false); });
        }, []);

        useEffect(function () { fetchSkills(filters); }, []);

        // Debounce search filter
        function handleFilterChange(key, val) {
            var newF = Object.assign({}, filters);
            newF[key] = val;
            setFilters(newF);
            clearTimeout(fetchTimerRef.current);
            fetchTimerRef.current = setTimeout(function () { fetchSkills(newF); }, key === 'search' ? 400 : 0);
        }

        // Select skill → fetch full data
        function selectSkill(s) {
            if (dirty && !window.confirm('Bạn có thay đổi chưa lưu. Tiếp tục?')) return;
            if (s.id) {
                API.getSkill(s.id).then(function (full) { setCurrent(full); setDirty(false); });
            } else {
                setCurrent(s);
                setDirty(false);
            }
        }

        // New skill
        function newSkill() {
            if (dirty && !window.confirm('Bạn có thay đổi chưa lưu. Tiếp tục?')) return;
            setCurrent({
                title: '',
                skill_key: '',
                description: '',
                content_md: '',
                triggers_json: [],
                modes_json: [],
                related_tools_json: [],
                related_plugins_json: [],
                priority: 50,
                status: 'draft',
                version: '1.0',
                category: '',
            });
            setDirty(false);
        }

        // Update field
        function updateField(key, val) {
            setCurrent(function (prev) {
                var next = Object.assign({}, prev);
                next[key] = val;
                return next;
            });
            setDirty(true);
        }

        // Save
        function handleSave() {
            if (!current) return;
            if (!current.title) { showToast('Tiêu đề không được trống', true); return; }
            if (!current.skill_key) { showToast('Skill Key không được trống', true); return; }

            setSaving(true);
            var payload = Object.assign({}, current);
            // Ensure JSON fields are arrays
            ['triggers_json', 'modes_json', 'related_tools_json', 'related_plugins_json'].forEach(function (f) {
                if (!Array.isArray(payload[f])) payload[f] = [];
            });

            API.saveSkill(payload)
                .then(function (saved) {
                    setCurrent(saved);
                    setDirty(false);
                    showToast(payload.id ? 'Đã cập nhật!' : 'Đã tạo skill mới!');
                    fetchSkills();
                })
                .catch(function (e) { showToast(e.message, true); })
                .finally(function () { setSaving(false); });
        }

        // Delete
        function handleDelete() {
            if (!current || !current.id) return;
            if (!window.confirm('Xóa skill "' + current.title + '"? Không thể hoàn tác.')) return;

            API.deleteSkill(current.id)
                .then(function () {
                    showToast('Đã xóa!');
                    setCurrent(null);
                    setDirty(false);
                    fetchSkills();
                })
                .catch(function (e) { showToast(e.message, true); });
        }

        // Keyboard shortcuts
        useEffect(function () {
            function onKey(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    handleSave();
                }
            }
            document.addEventListener('keydown', onKey);
            return function () { document.removeEventListener('keydown', onKey); };
        });

        // Render
        return h('div', { className: 'sk-app' },
            // Sidebar
            h(SkillSidebar, {
                skills: skills,
                currentId: current ? current.id : null,
                filters: filters,
                onFilter: handleFilterChange,
                onSelect: selectSkill,
                onNew: newSkill,
            }),
            // Main area
            current
                ? h('div', { className: 'sk-main' },
                    // Header
                    h('div', { className: 'sk-editor-header' },
                        h('input', {
                            className: 'sk-editor-title-input',
                            value: current.title || '',
                            placeholder: 'Tiêu đề skill...',
                            onChange: function (e) { updateField('title', e.target.value); }
                        }),
                        h('button', { className: 'sk-btn-test', onClick: function () { setTestOpen(true); } }, '🧪 Test'),
                        current.id ? h('button', { className: 'sk-btn-delete', onClick: handleDelete }, '🗑 Xóa') : null,
                        h('button', {
                            className: 'sk-btn-save', disabled: saving,
                            onClick: handleSave
                        }, saving ? 'Đang lưu...' : (dirty ? '● Lưu' : '✓ Lưu'))
                    ),
                    // Body
                    h('div', { className: 'sk-editor-body' },
                        // Meta panel
                        h(MetaPanel, {
                            data: current,
                            onChange: updateField,
                        }),
                        // Markdown editor
                        h('div', { className: 'sk-md-panel' },
                            h('div', { className: 'sk-md-label' }, 'Skill Content (Markdown)'),
                            h(MarkdownEditor, {
                                skillId: current.id || '__new__',
                                value: current.content_md || '',
                                onChange: function (val) { updateField('content_md', val); }
                            })
                        )
                    )
                )
                : h('div', { className: 'sk-main' },
                    h('div', { className: 'sk-main-empty' },
                        h('span', { className: 'dashicons dashicons-lightbulb' }),
                        h('div', null, 'Chọn skill bên trái hoặc tạo mới'),
                        h('div', { style: { fontSize: '13px', color: '#475569', maxWidth: '400px', lineHeight: '1.6' } },
                            'Skills dạy AI cách thực hiện công việc — quy trình, mẫu prompt, guardrails, và ví dụ.'
                        )
                    )
                ),
            // Test panel
            h(TestPanel, { open: testOpen, onClose: function () { setTestOpen(false); } })
        );
    }

    /* ================================================================
     *  Mount
     * ================================================================ */
    var root = document.getElementById('skill-library-root');
    if (root) {
        if (el.createRoot) {
            el.createRoot(root).render(h(SkillLibraryApp));
        } else {
            el.render(h(SkillLibraryApp), root);
        }
    }
})();
