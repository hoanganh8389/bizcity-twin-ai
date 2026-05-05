/**
 * Maturity Dashboard v4 — Inline Editable Tables + Sub-Pages
 *
 * Supports 4 page contexts: dashboard, training, memory, monitor
 * Editable tabs use contenteditable inline editing with auto-save.
 */
(function() {
	'use strict';

	var PAGE_CTX = window.bizcPageContext || 'dashboard';

	var COLORS = {
		intake:      { bg: 'rgba(99, 102, 241, 0.2)',  border: '#6366f1' },
		compression: { bg: 'rgba(16, 185, 129, 0.2)',  border: '#10b981' },
		continuity:  { bg: 'rgba(245, 158, 11, 0.2)',  border: '#f59e0b' },
		execution:   { bg: 'rgba(239, 68, 68, 0.2)',   border: '#ef4444' },
		retrieval:   { bg: 'rgba(139, 92, 246, 0.2)',  border: '#8b5cf6' },
		overall:     { bg: 'rgba(59, 130, 246, 0.15)', border: '#3b82f6' },
	};
	var DIMENSION_LABELS = {
		intake: 'Hấp thụ tri thức', compression: 'Nén & Tổng hợp',
		continuity: 'Tính liên tục', execution: 'Thực thi', retrieval: 'Truy xuất',
	};
	var LEVEL_MAP = [
		{ min: 0,  label: '🌱 Mới bắt đầu', color: '#9ca3af' },
		{ min: 20, label: '🌿 Đang học hỏi', color: '#10b981' },
		{ min: 40, label: '🌳 Có nền tảng',  color: '#3b82f6' },
		{ min: 60, label: '🧠 Hiểu biết tốt', color: '#8b5cf6' },
		{ min: 80, label: '🔥 Trưởng thành',  color: '#f59e0b' },
		{ min: 95, label: '⭐ Chuyên gia',    color: '#ef4444' },
	];
	var PALETTE = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6','#3b82f6','#ec4899','#14b8a6','#f97316','#06b6d4'];

	/* ── Column definitions per tab (for inline editable tables) ── */
	var TAB_COLUMNS = {
		quickfaq: {
			idField: 'id', editable: true, exportable: true,
			cols: [
				{ f: 'question', l: 'Câu hỏi', e: true, w: true },
				{ f: 'answer', l: 'Câu trả lời', e: true, w: true },
				{ f: 'status', l: 'TT', badge: true },
				{ f: 'updated_at', l: 'Cập nhật', dt: true },
			],
		},
		memories: {
			idField: 'id', editable: true, exportable: true,
			cols: [
				{ f: 'memory_type', l: 'Loại', e: true, sel: ['fact','preference','identity','goal','pain','constraint','habit','relationship','request'] },
				{ f: 'content', l: 'Nội dung', e: true, w: true },
				{ f: 'importance', l: 'Điểm', e: true, num: true },
				{ f: 'memory_key', l: 'Key', cls: 'td-key' },
				{ f: 'times_seen', l: 'Lần' },
				{ f: 'updated_at', l: 'Cập nhật', dt: true },
			],
		},
		notes: {
			idField: 'note_id', editable: true,
			cols: [
				{ f: 'title', l: 'Tiêu đề', e: true, w: true },
				{ f: 'note_type', l: 'Loại', badge: true },
				{ f: 'created_by', l: 'Tạo bởi' },
				{ f: 'is_starred', l: '⭐', fn: function(v) { return v === '1' ? '⭐' : ''; } },
				{ f: 'created_at', l: 'Ngày', dt: true },
			],
		},
		episodic: {
			idField: 'id', editable: true,
			cols: [
				{ f: 'event_type', l: 'Loại', e: true, sel: ['fact','goal_success','goal_cancel','pain_point','satisfaction','tool_usage','habit','decision','preference_change'] },
				{ f: 'content', l: 'Nội dung', e: true, w: true },
				{ f: 'importance', l: 'Điểm', e: true, num: true },
				{ f: 'source_goal', l: 'Mục tiêu gốc' },
				{ f: 'times_seen', l: 'Lần' },
				{ f: 'last_seen', l: 'Cập nhật', dt: true },
			],
		},
		rolling: {
			idField: 'id', editable: true,
			cols: [
				{ f: 'goal_label', l: 'Mục tiêu', e: true },
				{ f: 'status', l: 'TT', badge: true },
				{ f: 'content', l: 'Tóm tắt', e: true, w: true, get: function(r) { return r.content || r.completion_summary || ''; } },
				{ f: 'total_turns', l: 'Lượt' },
				{ f: 'updated_at', l: 'Cập nhật', dt: true },
			],
		},
		sources: {
			idField: 'id', editable: false,
			cols: [
				{ f: 'source_name', l: 'Tên nguồn', w: true, get: function(r) { return r.source_name || r.source_url || '—'; } },
				{ f: 'source_type', l: 'Loại', badge: true },
				{ f: 'status', l: 'TT', badge: true, fn: function(v) {
					var map = { ready: '✅ Ready', pending: '⏳ Pending', processing: '⚙️ ...', error: '❌ Error' };
					return map[v] || v || '⏳ Pending';
				}},
				{ f: 'chunks_count', l: 'Chunks' },
				{ f: 'created_at', l: 'Ngày', dt: true },
			],
			customActions: true,
		},
		sessions: {
			idField: 'session_id', editable: false,
			cols: [
				{ f: 'title', l: 'Tiêu đề', w: true, get: function(r) { return r.title || r.session_id || ''; } },
				{ f: 'platform_type', l: 'Nền tảng' },
				{ f: 'status', l: 'TT', badge: true },
				{ f: 'message_count', l: 'Tin nhắn' },
				{ f: 'kci_ratio', l: 'KCI' },
				{ f: 'last_message_at', l: 'Hoạt động', dt: true },
			],
		},
		goals: {
			idField: 'id', editable: false,
			cols: [
				{ f: 'goal_label', l: 'Mục tiêu', w: true, get: function(r) { return r.goal_label || r.goal || ''; } },
				{ f: 'channel', l: 'Kênh' },
				{ f: 'status', l: 'TT', badge: true },
				{ f: 'turn_count', l: 'Lượt' },
				{ f: 'last_activity_at', l: 'Hoạt động', dt: true },
			],
		},
	};

	var charts = {};
	var detailCache = {};

	/* ══════════════════════════════
	 * INIT
	 * ══════════════════════════════ */
	document.addEventListener('DOMContentLoaded', function() {
		initTabs();
		// Bind inline-edit events on tables that PHP pre-rendered (training/memory pages).
		// This must run before initSubPage() so Add New Row works immediately without
		// waiting for the AJAX detail-load to finish.
		document.querySelectorAll('.detail-list[data-preloaded]').forEach(function(el) {
			bindInlineEdit(el);
		});
		if (PAGE_CTX === 'dashboard') {
			initQuickForms();
			loadData();
		} else {
			initSubPage();
		}
		initActionButtons();
		initSourceActions();
	});

	/* ── Tab Navigation ── */
	function initTabs() {
		document.querySelectorAll('.stat-card[data-tab]').forEach(function(card) {
			card.addEventListener('click', function() { switchTab(this.getAttribute('data-tab')); });
		});
	}

	function switchTab(tab) {
		document.querySelectorAll('.stat-card[data-tab]').forEach(function(c) {
			c.setAttribute('aria-selected', c.getAttribute('data-tab') === tab ? 'true' : 'false');
		});
		document.querySelectorAll('.maturity-tab-panel').forEach(function(p) {
			p.classList.toggle('active', p.id === 'panel-' + tab);
		});
		if (tab !== 'overview' && !detailCache[tab]) {
			loadDetail(tab);
		}
	}

	/* ── Quick Add/Edit Forms ── */
	function initQuickForms() {
		document.querySelectorAll('.btn-quick-add').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var form = document.getElementById('form-' + this.getAttribute('data-tab'));
				if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
			});
		});
		document.querySelectorAll('.btn-cancel').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var form = document.getElementById('form-' + this.getAttribute('data-tab'));
				if (form) { form.style.display = 'none'; form.removeAttribute('data-edit-id'); }
			});
		});
		document.querySelectorAll('.btn-save').forEach(function(btn) {
			btn.addEventListener('click', function() { saveItem(this.getAttribute('data-tab')); });
		});
	}

	function saveItem(tab) {
		var form = document.getElementById('form-' + tab);
		if (!form) return;
		var editId = form.getAttribute('data-edit-id') || 0;
		var postData = {
			action: 'bizcity_twin_maturity_save',
			nonce: bizcMaturity.nonce,
			tab: tab,
			action_type: editId ? 'edit' : 'add',
			item_id: editId,
		};
		// Collect form fields
		form.querySelectorAll('input, select, textarea').forEach(function(el) {
			if (el.name) postData[el.name] = el.value;
		});
		jQuery.post(bizcMaturity.ajaxUrl, postData, function(res) {
			if (res.success) {
				form.style.display = 'none';
				form.removeAttribute('data-edit-id');
				// Reset inputs
				form.querySelectorAll('input[type=text], textarea').forEach(function(el) { el.value = ''; });
				// Reload tab data
				delete detailCache[tab];
				loadDetail(tab);
			} else {
				alert(res.data || 'Lỗi khi lưu');
			}
		});
	}

	function startEdit(tab, id, data) {
		var form = document.getElementById('form-' + tab);
		if (!form) return;
		form.style.display = 'block';
		form.setAttribute('data-edit-id', id);
		Object.keys(data).forEach(function(key) {
			var el = form.querySelector('[name="' + key + '"]');
			if (el) el.value = data[key];
		});
		form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	/* ══════════════════════════════
	 * MAIN DATA LOAD
	 * ══════════════════════════════ */
	function loadData() {
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_data',
			nonce: bizcMaturity.nonce,
		}, function(res) {
			document.getElementById('maturity-loading').style.display = 'none';
			document.getElementById('maturity-content').style.display = 'block';
			if (!res.success) {
				document.getElementById('maturity-content').innerHTML =
					'<p style="color:red">Lỗi: ' + esc(res.data || 'Không xác định') + '</p>';
				return;
			}
			renderAll(res.data);
		}).fail(function() {
			document.getElementById('maturity-loading').innerHTML =
				'<p style="color:red">Không thể kết nối server.</p>';
		});
	}

	function renderAll(data) {
		renderOverall(data.scores);
		renderStats(data.stats);
		renderWave(data.wave);
		renderRadar(data.scores);
		renderDimensionList(data.scores);
		renderGrowth(data.growth);
		renderTimeline(data.timeline, data.scores);
		renderExecution(data.execution);
	}

	/* ── Overall Score ── */
	function renderOverall(scores) {
		var el = document.getElementById('overall-score');
		animateNumber(el, scores.overall);
		document.getElementById('stat-overall').textContent = scores.overall;
		var level = LEVEL_MAP[0];
		for (var i = LEVEL_MAP.length - 1; i >= 0; i--) {
			if (scores.overall >= LEVEL_MAP[i].min) { level = LEVEL_MAP[i]; break; }
		}
		var levelEl = document.getElementById('maturity-level');
		levelEl.textContent = level.label;
	}

	function animateNumber(el, target) {
		var current = 0, step = Math.max(1, Math.floor(target / 30));
		var timer = setInterval(function() {
			current += step;
			if (current >= target) { current = target; clearInterval(timer); }
			el.textContent = current;
		}, 30);
	}

	/* ── Stats Row ── */
	function renderStats(stats) {
		var map = {
			memories: stats.memories || 0,
			notes: stats.notes || 0,
			episodic: stats.episodic || 0,
			rolling: stats.rolling || 0,
			knowledge: stats.knowledge || 0,
			sources: stats.sources || 0,
			sessions: stats.sessions || 0,
			goals: stats.goals_done || 0,
			messages: stats.messages || 0,
			quickfaq: stats.quickfaq || 0,
			trend: stats.trend || 0,
		};
		Object.keys(map).forEach(function(k) {
			var el = document.getElementById('stat-' + k);
			if (el) el.textContent = map[k];
		});
	}

	/* ══════════════════════════════
	 * OVERVIEW CHARTS
	 * ══════════════════════════════ */

	function renderWave(wave) {
		var ctx = document.getElementById('chart-wave');
		if (!ctx || !wave) return;
		var TYPE_VI = { file: 'Tệp', url: 'Web/URL', text: 'Văn bản', web: 'Web', unknown: 'Khác' };
		var sourceLabels = [], sourceCounts = [];
		(wave.source_types || []).forEach(function(s) {
			sourceLabels.push(TYPE_VI[s.source_type] || s.source_type);
			sourceCounts.push(parseInt(s.cnt));
		});
		var NOTE_VI = { manual: 'Thủ công', 'auto-extracted': 'Tự động', 'ai-generated': 'AI tạo',
			chat_pinned: 'Ghim chat', auto_pinned: 'Tự ghim', research_auto: 'Research' };
		var noteMap = {};
		(wave.note_types || []).forEach(function(n) {
			var key = (NOTE_VI[n.note_type] || n.note_type) + ' (' + n.created_by + ')';
			noteMap[key] = (noteMap[key] || 0) + parseInt(n.cnt);
		});
		var noteLabels = Object.keys(noteMap), noteCounts = noteLabels.map(function(k) { return noteMap[k]; });

		var allLabels = [], dsSource = [], dsNotes = [];
		sourceLabels.forEach(function(l, i) { allLabels.push('📄 ' + l); dsSource.push(sourceCounts[i]); dsNotes.push(0); });
		noteLabels.forEach(function(l, i) { allLabels.push('📝 ' + l); dsSource.push(0); dsNotes.push(noteCounts[i]); });

		if (charts.wave) charts.wave.destroy();
		charts.wave = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: allLabels,
				datasets: [
					{ label: 'Nguồn liệu', data: dsSource, backgroundColor: '#6366f1', borderRadius: 4 },
					{ label: 'Ghi nhớ', data: dsNotes, backgroundColor: '#10b981', borderRadius: 4 },
				]
			},
			options: {
				indexAxis: 'y', responsive: true, maintainAspectRatio: false,
				scales: { x: { beginAtZero: true, grid: { display: false } }, y: { grid: { display: false } } },
				plugins: {
					legend: { position: 'top', labels: { boxWidth: 12 } },
					title: { display: true, text: sourceCounts.reduce(function(a,b){return a+b;},0) + ' nguồn → ' + noteCounts.reduce(function(a,b){return a+b;},0) + ' ghi nhớ', font: { size: 13 } },
				}
			}
		});
	}

	function renderRadar(scores) {
		var ctx = document.getElementById('chart-radar');
		if (!ctx) return;
		if (charts.radar) charts.radar.destroy();
		charts.radar = new Chart(ctx, {
			type: 'radar',
			data: {
				labels: Object.values(DIMENSION_LABELS),
				datasets: [{
					label: 'Hiện tại',
					data: [scores.intake, scores.compression, scores.continuity, scores.execution, scores.retrieval],
					backgroundColor: 'rgba(99,102,241,0.15)', borderColor: '#6366f1', borderWidth: 2,
					pointBackgroundColor: '#6366f1', pointRadius: 4,
				}]
			},
			options: {
				responsive: true, maintainAspectRatio: false,
				scales: { r: { beginAtZero: true, max: 100, ticks: { stepSize: 20, font: { size: 10 } }, grid: { color: 'rgba(0,0,0,0.05)' }, pointLabels: { font: { size: 11 } } } },
				plugins: { legend: { display: false } }
			}
		});
	}

	function renderDimensionList(scores) {
		var container = document.getElementById('dimension-list');
		if (!container) return;
		var html = '';
		['intake','compression','continuity','execution','retrieval'].forEach(function(key) {
			var score = scores[key] || 0, color = COLORS[key].border;
			html += '<div class="dimension-item">'
				+ '<span class="dimension-name"><span class="dimension-dot" style="background:'+color+'"></span>' + DIMENSION_LABELS[key] + '</span>'
				+ '<span class="dimension-bar"><span class="dimension-bar__fill" style="width:'+score+'%;background:'+color+'"></span></span>'
				+ '<span class="dimension-score" style="color:'+color+'">' + score + '</span></div>';
		});
		container.innerHTML = html;
	}

	function renderGrowth(growth) {
		var ctx = document.getElementById('chart-growth');
		if (!ctx || !growth || !growth.length) return;
		var days = {};
		growth.forEach(function(r) {
			if (!days[r.day]) days[r.day] = { source:0, note:0, goal:0, message:0 };
			days[r.day][r.type] = parseInt(r.cnt) || 0;
		});
		var labels = Object.keys(days).sort();
		var sources=[], notes=[], goals=[], messages=[];
		labels.forEach(function(d) { sources.push(days[d].source); notes.push(days[d].note); goals.push(days[d].goal); messages.push(Math.min(days[d].message,50)); });
		var shortLabels = labels.map(function(d) { var p=d.split('-'); return parseInt(p[1])+'/'+parseInt(p[2]); });
		if (charts.growth) charts.growth.destroy();
		charts.growth = new Chart(ctx, {
			type: 'bar',
			data: { labels: shortLabels, datasets: [
				{ label: 'Nguồn', data: sources, backgroundColor: '#6366f1', stack: 'a' },
				{ label: 'Ghi nhớ', data: notes, backgroundColor: '#10b981', stack: 'a' },
				{ label: 'Mục tiêu', data: goals, backgroundColor: '#f59e0b', stack: 'a' },
				{ label: 'Tin nhắn', data: messages, backgroundColor: '#e5e7eb', stack: 'a' },
			]},
			options: { responsive: true, maintainAspectRatio: false,
				scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true } },
				plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
		});
	}

	function renderTimeline(timeline, currentScores) {
		var ctx = document.getElementById('chart-timeline');
		if (!ctx) return;
		var labels, overall, intake, compression, continuity, execution, retrieval;
		if (timeline && timeline.length > 0) {
			labels = timeline.map(function(r) { var p=r.snapshot_date.split('-'); return parseInt(p[1])+'/'+parseInt(p[2]); });
			overall = timeline.map(function(r) { return parseInt(r.overall_score); });
			intake = timeline.map(function(r) { return parseInt(r.intake_score); });
			compression = timeline.map(function(r) { return parseInt(r.compression_score); });
			continuity = timeline.map(function(r) { return parseInt(r.continuity_score); });
			execution = timeline.map(function(r) { return parseInt(r.execution_score); });
			retrieval = timeline.map(function(r) { return parseInt(r.retrieval_score); });
		} else {
			labels = ['Hôm nay']; overall = [currentScores.overall]; intake = [currentScores.intake];
			compression = [currentScores.compression]; continuity = [currentScores.continuity];
			execution = [currentScores.execution]; retrieval = [currentScores.retrieval];
		}
		if (charts.timeline) charts.timeline.destroy();
		charts.timeline = new Chart(ctx, {
			type: 'line',
			data: { labels: labels, datasets: [
				{ label: 'Tổng', data: overall, borderColor: COLORS.overall.border, backgroundColor: COLORS.overall.bg, borderWidth: 3, fill: true, tension: 0.3 },
				{ label: 'Hấp thụ', data: intake, borderColor: COLORS.intake.border, borderWidth: 1.5, borderDash: [4,4], tension: 0.3, pointRadius: 2 },
				{ label: 'Nén', data: compression, borderColor: COLORS.compression.border, borderWidth: 1.5, borderDash: [4,4], tension: 0.3, pointRadius: 2 },
				{ label: 'Liên tục', data: continuity, borderColor: COLORS.continuity.border, borderWidth: 1.5, borderDash: [4,4], tension: 0.3, pointRadius: 2 },
				{ label: 'Thực thi', data: execution, borderColor: COLORS.execution.border, borderWidth: 1.5, borderDash: [4,4], tension: 0.3, pointRadius: 2 },
				{ label: 'Truy xuất', data: retrieval, borderColor: COLORS.retrieval.border, borderWidth: 1.5, borderDash: [4,4], tension: 0.3, pointRadius: 2 },
			]},
			options: { responsive: true, maintainAspectRatio: false,
				scales: { y: { beginAtZero: true, max: 100, ticks: { stepSize: 20 } }, x: { grid: { display: false } } },
				plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
		});
	}

	function renderExecution(execution) {
		var ctx = document.getElementById('chart-execution');
		if (!ctx || !execution || !execution.length) {
			if (ctx && ctx.parentNode) ctx.parentNode.innerHTML = '<p style="text-align:center;color:#9ca3af;padding:40px">Chưa có dữ liệu thực thi mục tiêu</p>';
			return;
		}
		var labels = execution.map(function(r) { var p=r.day.split('-'); return parseInt(p[1])+'/'+parseInt(p[2]); });
		if (charts.execution) charts.execution.destroy();
		charts.execution = new Chart(ctx, {
			type: 'bar',
			data: { labels: labels, datasets: [
				{ label: '✅ Hoàn thành', data: execution.map(function(r){return parseInt(r.completed)||0;}), backgroundColor: '#10b981', stack: 'a' },
				{ label: '⏳ Đang làm', data: execution.map(function(r){return parseInt(r.active)||0;}), backgroundColor: '#f59e0b', stack: 'a' },
				{ label: '❌ Huỷ bỏ', data: execution.map(function(r){return parseInt(r.cancelled)||0;}), backgroundColor: '#ef4444', stack: 'a' },
			]},
			options: { responsive: true, maintainAspectRatio: false,
				scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true } },
				plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
		});
	}

	/* ══════════════════════════════
	 * DETAIL TAB LOADING
	 * ══════════════════════════════ */
	function loadDetail(tab) {
		var panel = document.getElementById('panel-' + tab);
		if (!panel) return;
		var loading = panel.querySelector('.detail-loading');
		var list = panel.querySelector('.detail-list');
		if (loading) loading.style.display = 'block';
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_detail',
			nonce: bizcMaturity.nonce,
			tab: tab, page: 1,
		}, function(res) {
			if (loading) loading.style.display = 'none';
			if (!res.success) { if (list) list.innerHTML = '<p class="detail-empty">Không thể tải dữ liệu.</p>'; return; }
			detailCache[tab] = res.data;
			renderDetail(tab, res.data);
		}).fail(function() {
			if (loading) loading.style.display = 'none';
			if (list) list.innerHTML = '<p class="detail-empty">Lỗi kết nối.</p>';
		});
	}

	function renderDetail(tab, data) {
		if (data.chart && data.chart.length) renderPieChart(tab, data.chart);

		// If PHP pre-rendered this tab, skip the first AJAX table-render (one-shot).
		// The attribute is removed so subsequent calls (after delete/import) DO re-render.
		var listEl = document.getElementById('detail-' + tab);
		if (listEl && listEl.getAttribute('data-preloaded')) {
			listEl.removeAttribute('data-preloaded');
			return;
		}

		// Special renderers
		if (tab === 'messages') { renderMessagesList(data.items || []); return; }
		if (tab === 'trend') { renderTrendList(data.items || []); return; }
		if (tab === 'knowledge') { renderKnowledgeList(data.items || []); return; }

		// Generic editable / read-only table
		if (TAB_COLUMNS[tab]) {
			renderEditableTable(tab, data.items || []);
			return;
		}

		// Fallback for unknown tabs
		var el = document.getElementById('detail-' + tab);
		if (el) el.innerHTML = '<p class="detail-empty">Không có dữ liệu.</p>';
	}

	/* ── Per-tab doughnut chart ── */
	function renderPieChart(tab, chartData) {
		var canvasMap = {
			memories: 'chart-memory-types', notes: 'chart-note-types', episodic: 'chart-episodic-types',
			sources: 'chart-source-types', sessions: 'chart-session-platforms',
			goals: 'chart-goal-status', messages: 'chart-msg-types', quickfaq: 'chart-quickfaq-status',
		};
		var ctx = document.getElementById(canvasMap[tab]);
		if (!ctx) return;
		var labels = chartData.map(function(r) { return r.label; });
		var values = chartData.map(function(r) { return parseInt(r.cnt); });
		var colors = labels.map(function(_, i) { return PALETTE[i % PALETTE.length]; });
		if (charts['pie_' + tab]) charts['pie_' + tab].destroy();
		charts['pie_' + tab] = new Chart(ctx, {
			type: 'doughnut',
			data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 1 }] },
			options: { responsive: true, maintainAspectRatio: false,
				plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } }
		});
	}

	/* ══════════════════════════════
	 * GENERIC EDITABLE TABLE RENDERER
	 * ══════════════════════════════ */
	function renderEditableTable(tab, items) {
		var el = document.getElementById('detail-' + tab);
		if (!el) return;
		var cfg = TAB_COLUMNS[tab];
		if (!cfg) return;
		var cols = cfg.cols;

		// Always render table structure (even empty)
		var hasActions = cfg.editable || cfg.customActions;
		var html = '<table class="bk-editable-table" data-tab="' + tab + '"><thead><tr><th class="bk-col-num">#</th>';
		cols.forEach(function(col) { html += '<th' + (col.w ? ' class="bk-col-wide"' : '') + '>' + esc(col.l) + '</th>'; });
		if (hasActions) html += '<th class="bk-col-action"></th>';
		html += '</tr></thead><tbody>';

		if (!items.length) {
			var emptyColspan = cols.length + 1 + (hasActions ? 1 : 0);
			html += '<tr class="bk-empty-row"><td colspan="' + emptyColspan + '" style="text-align:center;color:#9ca3af;padding:24px 0">' + (cfg.editable ? 'Chưa có dữ liệu. Nhấn <strong>+ Thêm</strong> để tạo.' : 'Chưa có dữ liệu. Nhấn <strong>📁 Tải file</strong> hoặc <strong>🌐 Thêm URL</strong> để bắt đầu.') + '</td></tr>';
		}

		items.forEach(function(r, idx) {
			var id = r[cfg.idField] || r.id || 0;
			html += '<tr class="bk-editable-row" data-id="' + id + '">';
			html += '<td class="bk-row-number">' + (idx + 1) + '</td>';
			cols.forEach(function(col) {
				var val = col.get ? col.get(r) : (r[col.f] != null ? r[col.f] : '');
				if (col.fn) val = col.fn(val);
				if (col.e && cfg.editable) {
					if (col.sel) {
						html += '<td><select class="bk-cell-select" data-field="' + col.f + '">';
						col.sel.forEach(function(opt) {
							html += '<option value="' + esc(opt) + '"' + (String(val) === opt ? ' selected' : '') + '>' + esc(opt) + '</option>';
						});
						html += '</select></td>';
					} else {
						html += '<td contenteditable="true" class="bk-editable' + (col.w ? ' bk-col-wide' : '') + '" data-field="' + col.f + '" data-placeholder="...">' + esc(String(val)) + '</td>';
					}
				} else if (col.dt) {
					html += '<td>' + fdate(val) + '</td>';
				} else if (col.badge) {
					html += '<td><span class="badge badge--blue">' + esc(String(val)) + '</span></td>';
				} else {
					html += '<td' + (col.cls ? ' class="' + col.cls + '"' : '') + '>' + esc(String(val)) + '</td>';
				}
			});
			if (cfg.editable) html += '<td><button class="bk-row-delete" onclick="window._matDelete(\'' + tab + '\',' + id + ')" title="Xoá">🗑️</button></td>';
			if (cfg.customActions && tab === 'sources') {
				var st = r.status || 'pending';
				var embedBtn = (st === 'pending' || st === 'error')
					? '<button class="bk-embed-btn" data-id="' + id + '" onclick="window._matEmbedSource(' + id + ')" title="Embed">⚡</button>'
					: '';
				html += '<td>' + embedBtn + '<button class="bk-row-delete" onclick="window._matDeleteSource(' + id + ')" title="Xoá">🗑️</button></td>';
			}
			html += '</tr>';
		});

		html += '</tbody></table>';
		html += '<div class="bk-table-footer"><span class="bk-row-count">Tổng: <strong>' + items.length + '</strong></span></div>';
		el.innerHTML = html;
		bindInlineEdit(el);
	}

	function bindInlineEdit(container) {
		container.querySelectorAll('.bk-editable').forEach(function(cell) {
			cell.addEventListener('blur', function() {
				var row = this.closest('.bk-editable-row');
				var tbl = this.closest('.bk-editable-table');
				if (!row || !tbl) return;
				var tab = tbl.getAttribute('data-tab');
				if (row.getAttribute('data-id') === '0') {
					tryCreateDraftRow(tab, row);
					return;
				}
				saveCellValue(tab, row.getAttribute('data-id'), this.getAttribute('data-field'), this.textContent.trim());
			});
		});
		container.querySelectorAll('.bk-cell-select').forEach(function(sel) {
			sel.addEventListener('change', function() {
				var row = this.closest('.bk-editable-row');
				var tbl = this.closest('.bk-editable-table');
				if (!row || !tbl) return;
				var tab = tbl.getAttribute('data-tab');
				if (row.getAttribute('data-id') === '0') {
					tryCreateDraftRow(tab, row);
					return;
				}
				saveCellValue(tab, row.getAttribute('data-id'), this.getAttribute('data-field'), this.value);
			});
		});
	}

	function getDraftRowPayload(tab, row) {
		var payload = {};
		row.querySelectorAll('.bk-editable[data-field]').forEach(function(cell) {
			payload[cell.getAttribute('data-field')] = cell.textContent.trim();
		});
		row.querySelectorAll('.bk-cell-select[data-field]').forEach(function(sel) {
			payload[sel.getAttribute('data-field')] = sel.value;
		});

		if (tab === 'quickfaq') {
			return payload.question || payload.answer ? payload : null;
		}
		if (tab === 'memories' || tab === 'episodic') {
			return payload.content ? payload : null;
		}
		if (tab === 'notes') {
			return payload.title || payload.content ? payload : null;
		}
		if (tab === 'rolling') {
			return payload.goal_label || payload.content ? payload : null;
		}

		return payload;
	}

	function tryCreateDraftRow(tab, row) {
		if (!row || row.getAttribute('data-id') !== '0') return;
		if (row.getAttribute('data-creating') === '1') return;

		var payload = getDraftRowPayload(tab, row);
		if (!payload) return;

		row.setAttribute('data-creating', '1');
		jQuery.post(bizcMaturity.ajaxUrl, Object.assign({
			action: 'bizcity_twin_maturity_save',
			nonce: bizcMaturity.nonce,
			tab: tab,
			action_type: 'add',
		}, payload), function(res) {
			row.removeAttribute('data-creating');
			if (res.success) {
				var newId = res.data && res.data.id ? res.data.id : 0;
				if (!newId) {
					delete detailCache[tab];
					loadDetail(tab);
					return;
				}
				row.setAttribute('data-id', String(newId));
				row.classList.remove('bk-new-row');

				// Flush any cells edited while AJAX was in-flight (race condition fix)
				row.querySelectorAll('.bk-editable[data-field]').forEach(function(cell) {
					var f = cell.getAttribute('data-field');
					var current = cell.textContent.trim();
					if (current !== String(payload[f] || '')) {
						saveCellValue(tab, newId, f, current);
					}
				});
				row.querySelectorAll('.bk-cell-select[data-field]').forEach(function(sel) {
					var f = sel.getAttribute('data-field');
					if (sel.value !== String(payload[f] || '')) {
						saveCellValue(tab, newId, f, sel.value);
					}
				});

				var delBtn = row.querySelector('.bk-row-delete');
				if (delBtn) {
					delBtn.disabled = false;
					delBtn.setAttribute('onclick', "window._matDelete('" + tab + "'," + newId + ")");
				}
				var footer = document.querySelector('#detail-' + tab + ' .bk-row-count strong');
				if (footer) footer.textContent = parseInt(footer.textContent || '0', 10) + 1;
				delete detailCache[tab];
			} else if (res.data !== 'Question or answer required' && res.data !== 'Content required' && res.data !== 'Title or content required') {
				alert('Lỗi khi tạo: ' + (res.data || 'Unknown'));
			}
		}).fail(function() {
			row.removeAttribute('data-creating');
		});
	}

	function saveCellValue(tab, id, field, value) {
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_inline_save',
			nonce: bizcMaturity.nonce,
			tab: tab, item_id: id, field: field, value: value,
		});
	}

	/* ── Sub-page initialization ── */
	function initSubPage() {
		// Load stats to populate stat cards, then auto-open first tab
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_data',
			nonce: bizcMaturity.nonce,
		}, function(res) {
			var loading = document.getElementById('maturity-loading');
			var content = document.getElementById('maturity-content');
			if (loading) loading.style.display = 'none';
			if (content) content.style.display = 'block';
			if (res.success) renderStats(res.data.stats);
			var first = document.querySelector('.stat-card[data-tab]');
			if (first) switchTab(first.getAttribute('data-tab'));
		});
	}

	/* ── Action buttons: Add Row, Export, Import ── */
	function initActionButtons() {
		document.querySelectorAll('.bk-btn-add').forEach(function(btn) {
			btn.addEventListener('click', function() { addNewRow(this.getAttribute('data-tab')); });
		});
		document.querySelectorAll('.bk-btn-export').forEach(function(btn) {
			btn.addEventListener('click', function() { exportTab(this.getAttribute('data-tab'), this.getAttribute('data-format')); });
		});
		document.querySelectorAll('.bk-btn-import input[type=file]').forEach(function(inp) {
			inp.addEventListener('change', function() {
				if (this.files[0]) { importTab(this.getAttribute('data-tab'), this.files[0]); this.value = ''; }
			});
		});
	}

	function addNewRow(tab) {
		var cfg = TAB_COLUMNS[tab];
		if (!cfg || !cfg.editable) return;

		// Default values per tab
		var defaults = {};
		if (tab === 'quickfaq') defaults = { question: '', answer: '' };
		else if (tab === 'memories') defaults = { memory_type: 'fact', content: '', importance: 50 };
		else if (tab === 'episodic') defaults = { event_type: 'fact', content: '', importance: 50 };
		else if (tab === 'rolling') defaults = { goal_label: '', content: '' };
		else if (tab === 'notes') defaults = { title: '', content: '', note_type: 'manual' };
		else return;

		// Build a new editable row in DOM first (instant feedback)
		var tbody = document.querySelector('#detail-' + tab + ' .bk-editable-table tbody');
		if (!tbody) {
			// Table not yet rendered — do a full reload instead
			var postData = Object.assign({ action: 'bizcity_twin_maturity_save', nonce: bizcMaturity.nonce, tab: tab, action_type: 'add' }, defaults);
			jQuery.post(bizcMaturity.ajaxUrl, postData, function(res) {
				if (res.success) { delete detailCache[tab]; loadDetail(tab); }
			});
			return;
		}

		// Remove empty-row placeholder if present
		var emptyRow = tbody.querySelector('.bk-empty-row');
		if (emptyRow) emptyRow.remove();

		// Create DOM row
		var tr = document.createElement('tr');
		tr.className = 'bk-editable-row bk-new-row';
		tr.setAttribute('data-id', '0');
		var cols = cfg.cols;
		var cellHtml = '<td class="bk-row-number">★</td>';
		cols.forEach(function(col) {
			var dv = defaults[col.f] != null ? defaults[col.f] : '';
			if (col.e) {
				if (col.sel) {
					cellHtml += '<td><select class="bk-cell-select" data-field="' + col.f + '">';
					col.sel.forEach(function(opt) {
						cellHtml += '<option value="' + esc(opt) + '"' + (String(dv) === opt ? ' selected' : '') + '>' + esc(opt) + '</option>';
					});
					cellHtml += '</select></td>';
				} else {
					cellHtml += '<td contenteditable="true" class="bk-editable' + (col.w ? ' bk-col-wide' : '') + '" data-field="' + col.f + '" data-placeholder="Nhập ' + esc(col.l) + '...">' + esc(String(dv)) + '</td>';
				}
			} else {
				cellHtml += '<td>—</td>';
			}
		});
		cellHtml += '<td><button class="bk-row-delete" title="Xoá" disabled>🗑️</button></td>';
		tr.innerHTML = cellHtml;

		// Insert at beginning of tbody
		tbody.insertBefore(tr, tbody.firstChild);

		// Focus the first editable cell
		var firstEditable = tr.querySelector('[contenteditable]');
		if (firstEditable) {
			firstEditable.focus();
		}

		// Bind inline events for the draft row.
		// It will auto-create in DB after the first valid user input.
		bindInlineEdit(tr);
	}

	function exportTab(tab, format) {
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_export', nonce: bizcMaturity.nonce, tab: tab, format: format,
		}, function(res) {
			if (!res.success) return;
			var blob, fname = tab + '_export.' + format;
			if (format === 'csv') {
				blob = new Blob([res.data.content], { type: 'text/csv;charset=utf-8;' });
			} else {
				blob = new Blob([JSON.stringify(res.data.content, null, 2)], { type: 'application/json' });
			}
			var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = fname; a.click();
		});
	}

	function importTab(tab, file) {
		var reader = new FileReader();
		reader.onload = function(e) {
			jQuery.post(bizcMaturity.ajaxUrl, {
				action: 'bizcity_twin_maturity_import', nonce: bizcMaturity.nonce,
				tab: tab, content: e.target.result, format: file.name.endsWith('.csv') ? 'csv' : 'json',
			}, function(res) {
				if (res.success) { delete detailCache[tab]; loadDetail(tab); }
				else { alert('Import lỗi: ' + (res.data || 'Unknown')); }
			});
		};
		reader.readAsText(file);
	}

	/* ══════════════════════════════
	 * DETAIL RENDERERS (special)
	 * ══════════════════════════════ */

	/* ── Helpers ── */
	function esc(s) { if (!s && s !== 0) return ''; var d = document.createElement('div'); d.textContent = String(s); return d.innerHTML; }
	function fdate(s) { if (!s) return '—'; return String(s).substring(0, 16).replace('T', ' '); }

	/* ── Knowledge Characters (card grid — special) ── */
	function renderKnowledgeList(items) {
		var el = document.getElementById('detail-knowledge');
		if (!el) return;
		if (!items.length) { el.innerHTML = '<p class="detail-empty">Chưa có nhân vật tri thức nào.</p>'; return; }
		var STATUS_VI = { draft: '📝 Nháp', active: '✅ Hoạt động', published: '🌐 Đã xuất bản', archived: '📦 Lưu trữ' };
		var html = '<div class="knowledge-grid">';
		items.forEach(function(r) {
			html += '<div class="knowledge-card">'
				+ '<div class="knowledge-card__header">'
				+ (r.avatar ? '<img src="' + esc(r.avatar) + '" class="knowledge-card__avatar" alt="">' : '<span class="knowledge-card__avatar-placeholder">🎭</span>')
				+ '<div><strong>' + esc(r.name) + '</strong>'
				+ '<span class="badge ' + (r.status === 'active' || r.status === 'published' ? 'badge--green' : 'badge--muted') + '">'
				+ (STATUS_VI[r.status] || r.status) + '</span></div></div>'
				+ '<p class="knowledge-card__desc">' + esc((r.description || '').substring(0, 120)) + '</p>'
				+ '<div class="knowledge-card__meta">'
				+ '<span>💬 ' + (r.total_conversations || 0) + '</span>'
				+ '<span>📨 ' + (r.total_messages || 0) + '</span>'
				+ '<span>⭐ ' + (r.rating || '0.00') + '</span>'
				+ '<span>' + esc(r.owner_type || '') + '</span>'
				+ '</div></div>';
		});
		el.innerHTML = html + '</div>';
	}

	/* ── Sessions (via generic table) ── */
	/* ── Goals (via generic table) ── */

	/* ── Messages (Monitor-style) ── */
	function renderMessagesList(items) {
		var el = document.getElementById('detail-messages');
		if (!el) return;
		if (!items.length) { el.innerHTML = '<p class="detail-empty">Chưa có tin nhắn nào.</p>'; return; }
		var html = '<div class="monitor-messages">';
		items.forEach(function(r) {
			var fromClass = r.message_from === 'bot' ? 'msg--bot' : (r.message_from === 'user' ? 'msg--user' : 'msg--system');
			var fromIcon = r.message_from === 'bot' ? '🤖' : (r.message_from === 'user' ? '👤' : '⚙️');
			var preview = r.message_preview || '';
			html += '<div class="monitor-msg ' + fromClass + '">'
				+ '<div class="monitor-msg__header">'
				+ '<span class="monitor-msg__from">' + fromIcon + ' ' + esc(r.message_from) + '</span>'
				+ (r.tool_name ? '<span class="badge badge--yellow">🔧 ' + esc(r.tool_name) + '</span>' : '')
				+ (r.message_type !== 'text' ? '<span class="badge badge--muted">' + esc(r.message_type) + '</span>' : '')
				+ '<span class="monitor-msg__time">' + fdate(r.created_at) + '</span>'
				+ '</div>'
				+ '<div class="monitor-msg__body">' + esc(preview) + '</div>'
				+ '</div>';
		});
		el.innerHTML = html + '</div>';
	}

	/* ── Quick FAQ (via generic editable table) ── */

	/* ── Trend (§30 Snapshot Timeline Chart) ── */
	function renderTrendList(items) {
		var el = document.getElementById('detail-trend');
		if (!el) return;
		if (!items.length) { el.innerHTML = '<p class="detail-empty">Chưa có dữ liệu snapshot. Dữ liệu tự tạo mỗi ngày khi truy cập dashboard.</p>'; return; }

		// Render timeline chart
		var ctx = document.getElementById('chart-trend');
		if (ctx) {
			var labels = items.map(function(r) { return r.snapshot_date; });
			var dims = ['intake_score', 'compression_score', 'continuity_score', 'execution_score', 'retrieval_score', 'overall_score'];
			var dimKeys = ['intake', 'compression', 'continuity', 'execution', 'retrieval', 'overall'];
			var dimLabels = ['Hấp thụ', 'Nén & Tổng hợp', 'Liên tục', 'Thực thi', 'Truy xuất', 'Tổng'];
			var datasets = dims.map(function(d, i) {
				return {
					label: dimLabels[i],
					data: items.map(function(r) { return parseInt(r[d]) || 0; }),
					borderColor: COLORS[dimKeys[i]] ? COLORS[dimKeys[i]].border : PALETTE[i],
					backgroundColor: COLORS[dimKeys[i]] ? COLORS[dimKeys[i]].bg : 'transparent',
					fill: d === 'overall_score',
					tension: 0.3,
					borderWidth: d === 'overall_score' ? 3 : 1.5,
					pointRadius: 2,
				};
			});
			if (charts.trend) charts.trend.destroy();
			charts.trend = new Chart(ctx, {
				type: 'line',
				data: { labels: labels, datasets: datasets },
				options: {
					responsive: true, maintainAspectRatio: false,
					scales: { y: { min: 0, max: 100 } },
					plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
				},
			});
		}

		// Render data table
		var html = '<table class="detail-table" style="margin-top:16px"><thead><tr><th>Ngày</th><th>Hấp thụ</th><th>Nén</th><th>Liên tục</th><th>Thực thi</th><th>Truy xuất</th><th>Tổng</th></tr></thead><tbody>';
		items.forEach(function(r) {
			html += '<tr>'
				+ '<td>' + esc(r.snapshot_date) + '</td>'
				+ '<td>' + (r.intake_score || 0) + '</td>'
				+ '<td>' + (r.compression_score || 0) + '</td>'
				+ '<td>' + (r.continuity_score || 0) + '</td>'
				+ '<td>' + (r.execution_score || 0) + '</td>'
				+ '<td>' + (r.retrieval_score || 0) + '</td>'
				+ '<td><strong>' + (r.overall_score || 0) + '</strong></td></tr>';
		});
		el.innerHTML = html + '</tbody></table>';
	}

	/* ── Source Management: Upload, Embed, Delete, URL ── */
	function initSourceActions() {
		// Toggle upload area
		var uploadBtn = document.querySelector('.bk-btn-upload-source');
		var uploadArea = document.getElementById('source-upload-area');
		var urlBtn = document.querySelector('.bk-btn-add-url-source');
		var urlArea = document.getElementById('source-url-area');
		if (uploadBtn && uploadArea) {
			uploadBtn.addEventListener('click', function() {
				uploadArea.style.display = uploadArea.style.display === 'none' ? 'block' : 'none';
				if (urlArea) urlArea.style.display = 'none';
			});
		}
		if (urlBtn && urlArea) {
			urlBtn.addEventListener('click', function() {
				urlArea.style.display = urlArea.style.display === 'none' ? 'block' : 'none';
				if (uploadArea) uploadArea.style.display = 'none';
			});
		}

		// Dropzone click → trigger file input
		var dropzone = document.getElementById('source-dropzone');
		var fileInput = document.getElementById('source-file-input');
		if (dropzone && fileInput) {
			dropzone.addEventListener('click', function() { fileInput.click(); });
			fileInput.addEventListener('change', function() {
				if (this.files.length) uploadSourceFiles(this.files);
				this.value = '';
			});
			// Drag & Drop
			dropzone.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('bk-dragover'); });
			dropzone.addEventListener('dragleave', function() { this.classList.remove('bk-dragover'); });
			dropzone.addEventListener('drop', function(e) {
				e.preventDefault(); this.classList.remove('bk-dragover');
				if (e.dataTransfer.files.length) uploadSourceFiles(e.dataTransfer.files);
			});
		}

		// URL submit
		var urlSubmit = document.getElementById('source-url-submit');
		if (urlSubmit) {
			urlSubmit.addEventListener('click', function() {
				var input = document.getElementById('source-url-input');
				var url = (input ? input.value.trim() : '');
				if (!url) return;
				urlSubmit.disabled = true;
				jQuery.post(bizcMaturity.ajaxUrl, {
					action: 'bizcity_twin_maturity_add_url_source',
					nonce: bizcMaturity.nonce,
					url: url,
				}, function(res) {
					urlSubmit.disabled = false;
					if (res.success) {
						input.value = '';
						delete detailCache['sources'];
						loadDetail('sources');
					} else {
						alert('Lỗi: ' + (res.data || 'Unknown'));
					}
				}).fail(function() { urlSubmit.disabled = false; alert('Lỗi kết nối'); });
			});
		}

		// Embed All button
		var embedAllBtn = document.querySelector('.bk-btn-embed-all');
		if (embedAllBtn) {
			embedAllBtn.addEventListener('click', function() {
				if (!confirm('Embed tất cả nguồn đang chờ? Quá trình có thể mất vài phút.')) return;
				embedAllBtn.disabled = true;
				embedAllBtn.textContent = '⏳ Đang embed...';
				jQuery.post(bizcMaturity.ajaxUrl, {
					action: 'bizcity_twin_maturity_embed_source',
					nonce: bizcMaturity.nonce,
					source_id: 0,
				}, function(res) {
					embedAllBtn.disabled = false;
					embedAllBtn.textContent = '⚡ Embed tất cả';
					if (res.success) {
						alert(res.data.message || 'Done');
						delete detailCache['sources'];
						loadDetail('sources');
					} else {
						alert('Lỗi: ' + (res.data || 'Unknown'));
					}
				}).fail(function() {
					embedAllBtn.disabled = false;
					embedAllBtn.textContent = '⚡ Embed tất cả';
					alert('Lỗi kết nối');
				});
			});
		}
	}

	function uploadSourceFiles(fileList) {
		var progressArea = document.getElementById('source-upload-progress');
		var progressFill = document.getElementById('source-progress-fill');
		var statusEl = document.getElementById('source-upload-status');
		if (progressArea) progressArea.style.display = 'block';
		if (progressFill) progressFill.style.width = '0%';
		if (statusEl) statusEl.textContent = 'Đang tải ' + fileList.length + ' file...';

		var formData = new FormData();
		formData.append('action', 'bizcity_twin_maturity_upload_source');
		formData.append('nonce', bizcMaturity.nonce);
		for (var i = 0; i < fileList.length; i++) {
			formData.append('files[]', fileList[i]);
		}

		var xhr = new XMLHttpRequest();
		xhr.open('POST', bizcMaturity.ajaxUrl, true);
		xhr.upload.addEventListener('progress', function(e) {
			if (e.lengthComputable && progressFill) {
				var pct = Math.round((e.loaded / e.total) * 100);
				progressFill.style.width = pct + '%';
				if (statusEl) statusEl.textContent = 'Đang tải... ' + pct + '%';
			}
		});
		xhr.onload = function() {
			if (progressArea) progressArea.style.display = 'none';
			try {
				var res = JSON.parse(xhr.responseText);
				if (res.success) {
					var msg = 'Đã tải ' + (res.data.uploaded || 0) + ' file';
					if (res.data.errors && res.data.errors.length) msg += '. Lỗi: ' + res.data.errors.join('; ');
					if (statusEl) statusEl.textContent = msg;
					delete detailCache['sources'];
					loadDetail('sources');
				} else {
					alert('Upload lỗi: ' + (res.data || 'Unknown'));
				}
			} catch (e) {
				alert('Upload lỗi: Invalid response');
			}
		};
		xhr.onerror = function() {
			if (progressArea) progressArea.style.display = 'none';
			alert('Upload lỗi: Lỗi kết nối');
		};
		xhr.send(formData);
	}

	// Single source embed handler
	window._matEmbedSource = function(sourceId) {
		var btn = document.querySelector('.bk-embed-btn[data-id="' + sourceId + '"]');
		if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_embed_source',
			nonce: bizcMaturity.nonce,
			source_id: sourceId,
		}, function(res) {
			if (res.success) {
				delete detailCache['sources'];
				loadDetail('sources');
			} else {
				alert('Embed lỗi: ' + (res.data || 'Unknown'));
				if (btn) { btn.disabled = false; btn.textContent = '⚡'; }
			}
		}).fail(function() {
			if (btn) { btn.disabled = false; btn.textContent = '⚡'; }
			alert('Lỗi kết nối');
		});
	};

	// Single source delete handler
	window._matDeleteSource = function(sourceId) {
		if (!confirm('Xoá nguồn tài liệu này?')) return;
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_delete_source',
			nonce: bizcMaturity.nonce,
			source_id: sourceId,
		}, function(res) {
			if (res.success) {
				delete detailCache['sources'];
				loadDetail('sources');
			} else {
				alert('Xoá lỗi: ' + (res.data || 'Unknown'));
			}
		});
	};

	/* ── Delete handler (for Rolling CRUD + Quick FAQ) ── */
	window._matDelete = function(tab, id) {
		if (!confirm('Bạn chắc chắn muốn xoá?')) return;
		jQuery.post(bizcMaturity.ajaxUrl, {
			action: 'bizcity_twin_maturity_save',
			nonce: bizcMaturity.nonce,
			tab: tab,
			action_type: 'delete',
			item_id: id,
		}, function(res) {
			if (res.success) {
				delete detailCache[tab];
				loadDetail(tab);
			}
		});
	};

})();
