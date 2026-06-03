/*!
 * BizCoach Pro — Coach Builder JS
 * Powers both:
 *   (a) Frontend landing /coach-builder/ — full Step1 + Step2 + AI fill + save
 *   (b) Admin Step 2 page (?page=bccm_step2_coach_template) — only inject AI-fill widget
 */
(function () {
	'use strict';

	var CFG = window.BCPRO_BUILDER || {};
	if (!CFG.restUrl) { console.error('[bcpro] BCPRO_BUILDER not set'); return; }

	var COACH_TYPE_EMOJI = {
		biz_coach: '💼', career_coach: '🚀', baby_coach: '👶',
		health_coach: '💪', tiktok_coach: '🎬', astro_coach: '🔮',
		tarot_coach: '🃏'
	};

	function api(path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({
			'Content-Type': 'application/json',
			'X-WP-Nonce': CFG.nonce || ''
		}, opts.headers || {});
		if (opts.body && typeof opts.body !== 'string') {
			opts.body = JSON.stringify(opts.body);
		}
		return fetch(CFG.restUrl + path, opts).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) throw new Error(j.error || j.message || ('HTTP ' + r.status));
				return j;
			});
		});
	}

	function setStatus(el, msg, kind) {
		if (!el) return;
		el.textContent = msg || '';
		el.className = 'bcpro-ai-status' + (kind ? ' is-' + kind : '');
	}

	/* ============================================================
	 * MODE A — Frontend landing
	 * ============================================================ */
	function bootFrontend() {
		var form = document.getElementById('bcpro-builder-form');
		if (!form) return;

		var typeListEl     = document.getElementById('bcpro-coach-type-list');
		var extraCard      = document.getElementById('bcpro-extra-fields-card');
		var extraEl        = document.getElementById('bcpro-extra-fields');
		var aiPanel        = document.getElementById('bcpro-ai-fill-panel');
		var qCard          = document.getElementById('bcpro-questions-card');
		var qListEl        = document.getElementById('bcpro-questions-list');
		var qCountEl       = document.querySelector('.bcpro-q-count');
		var aiBtn          = document.getElementById('bcpro-ai-fill-btn');
		var aiStatus       = document.getElementById('bcpro-ai-status');
		var submitBtn      = document.getElementById('bcpro-submit-btn');
		var submitStat     = document.getElementById('bcpro-submit-status');
		var typeInput      = document.getElementById('bcpro-coach-type-input');

		var resultPanel    = document.getElementById('bcpro-result-panel');
		var resultTitle    = document.getElementById('bcpro-result-title');
		var sectionListEl  = document.getElementById('bcpro-section-list');
		var progressFill   = document.getElementById('bcpro-progress-fill');
		var progressText   = document.getElementById('bcpro-progress-text');
		var resultActions  = document.getElementById('bcpro-result-actions');
		var shareBtn       = document.getElementById('bcpro-share-btn');
		var printBtn       = document.getElementById('bcpro-print-btn');
		var rebuildBtn     = document.getElementById('bcpro-rebuild-btn');
		var shareUrlEl     = document.getElementById('bcpro-share-url');
		var restartBtn     = document.getElementById('bcpro-restart-btn');

		var typesIndex  = {};      // type → {label, fields, questions}
		var currentTpl  = null;    // full template for selected type
		var currentMap  = null;    // { public_key, public_url } once saved
		var lastGens    = [];      // last generator list (for rebuild-all)

		// Share + print actions — wired early so they work in both resume and post-save modes.
		if (shareBtn) shareBtn.addEventListener('click', function () {
			if (!currentMap) return;
			var url = (CFG.pageBase || '/coach-builder/') + currentMap.public_key + '/';
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function () {
					shareUrlEl.hidden = false;
					shareUrlEl.textContent = '✅ Đã copy: ' + url;
				}, function () {
					shareUrlEl.hidden = false;
					shareUrlEl.textContent = url;
				});
			} else {
				shareUrlEl.hidden = false;
				shareUrlEl.textContent = url;
			}
		});
		if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
		if (rebuildBtn) rebuildBtn.addEventListener('click', function () {
			if (!currentMap || !lastGens.length) return;
			if (!confirm('Xác nhận tạo lại toàn bộ ' + lastGens.length + ' phần? Dữ liệu cũ sẽ bị ghi đè.')) return;
			resultActions.hidden = true;
			runProgressiveGeneration(currentMap.public_key, lastGens, /*forceAll=*/true);
		});
		if (restartBtn) restartBtn.addEventListener('click', function () {
			window.location.href = (CFG.pageBase || '/coach-builder/');
		});

		// Resume mode: /coach-builder/{key}/ → skip form, render result panel directly.
		if (CFG.mapKey) {
			form.style.display = 'none';
			var headerEl = document.querySelector('.bcpro-builder-header');
			if (headerEl) headerEl.style.display = 'none';
			resumeMap(CFG.mapKey);
			return;
		}

		// Load coach types (lightweight list).
		api('coach-types').then(function (res) {
			typeListEl.innerHTML = '';
			(res.types || []).forEach(function (t) {
				typesIndex[t.type] = t;
				var div = document.createElement('div');
				div.className = 'bcpro-coach-type-item';
				div.dataset.type = t.type;
				div.innerHTML =
					'<span class="bcpro-ct-emoji">' + (COACH_TYPE_EMOJI[t.type] || '🧭') + '</span>' +
					'<span class="bcpro-ct-label">' + escHtml(t.label) + '</span>';
				div.addEventListener('click', function () { selectType(t.type); });
				typeListEl.appendChild(div);
			});
			if (!res.types || !res.types.length) {
				typeListEl.innerHTML = '<div class="bcpro-loading">Chưa có Coach template nào. Vui lòng vào admin tạo trước.</div>';
				return;
			}
			// Auto-select coach when ?type=<slug> is in the URL (used by TwinChat
			// → "Tạo bản đồ mới" deep link from BizCoachProArtifactDialog).
			try {
				var qsType = new URLSearchParams(window.location.search).get('type');
				if (qsType && typesIndex[qsType]) {
					selectType(qsType);
					var card = typeListEl.querySelector('[data-type="' + qsType + '"]');
					if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}
			} catch (e) { /* URLSearchParams unsupported \u2014 ignore */ }
		}).catch(function (e) {
			typeListEl.innerHTML = '<div class="bcpro-loading">Lỗi tải Coach types: ' + escHtml(e.message) + '</div>';
		});

		function selectType(type) {
			Array.prototype.forEach.call(typeListEl.querySelectorAll('.bcpro-coach-type-item'), function (el) {
				el.classList.toggle('is-selected', el.dataset.type === type);
			});
			typeInput.value = type;
			submitBtn.disabled = true;
			setStatus(submitStat, '⏳ Đang tải bộ câu hỏi…');
			extraCard.hidden = true;
			extraEl.innerHTML = '';
			qListEl.innerHTML = '';
			qCard.hidden = true;
			aiPanel.hidden = true;

			api('template?type=' + encodeURIComponent(type)).then(function (tpl) {
				currentTpl = tpl;
				renderExtraFields(tpl.fields || []);
				var qs = tpl.questions || [];
				renderQuestions(qs);
				if (qCountEl) qCountEl.textContent = qs.length;
				aiPanel.hidden = !qs.length;
				qCard.hidden   = !qs.length;
				submitBtn.disabled = false;
				if (!qs.length) {
					setStatus(submitStat, 'Loại coach này chưa có bộ câu hỏi — bạn vẫn tạo được bản đồ với hồ sơ cơ bản.');
				} else {
					setStatus(submitStat, 'Đã sẵn sàng — bạn có thể nhấn ✨ AI fill rồi review, hoặc tự điền và bấm "Tạo bản đồ".');
				}
			}).catch(function (e) {
				setStatus(submitStat, '❌ Lỗi tải template: ' + e.message, 'error');
			});
		}

		function renderExtraFields(fields) {
			if (!fields.length) { extraCard.hidden = true; return; }
			extraCard.hidden = false;
			extraEl.innerHTML = '';
			fields.forEach(function (f) {
				var label = document.createElement('label');
				label.innerHTML = '<span class="bcpro-fl">' + escHtml(f.label) + '</span>';
				var input;
				if (f.type === 'select' && f.options) {
					input = document.createElement('select');
					var opt0 = document.createElement('option');
					opt0.value = ''; opt0.textContent = '— Chọn —';
					input.appendChild(opt0);
					Object.keys(f.options).forEach(function (k) {
						var o = document.createElement('option');
						o.value = k; o.textContent = f.options[k];
						input.appendChild(o);
					});
				} else if (f.type === 'textarea') {
					input = document.createElement('textarea');
					input.rows = 3;
				} else {
					input = document.createElement('input');
					input.type = (f.type === 'number' || f.type === 'date' || f.type === 'tel' || f.type === 'email') ? f.type : 'text';
					if (f.placeholder) input.placeholder = f.placeholder;
					if (f.step) input.step = f.step;
				}
				input.name = 'extra_fields[' + f.key + ']';
				input.dataset.fkey = f.key;
				label.appendChild(input);
				extraEl.appendChild(label);
			});
		}

		function renderQuestions(qs) {
			qListEl.innerHTML = '';
			qs.forEach(function (q, i) {
				var row = document.createElement('div');
				row.className = 'bcpro-q-row';
				row.innerHTML =
					'<div class="bcpro-q-num">' + (i + 1) + '</div>' +
					'<div class="bcpro-q-body">' +
						'<div class="bcpro-q-text">' + escHtml(q) + '</div>' +
						'<textarea name="answers[]" data-qidx="' + i + '"></textarea>' +
					'</div>';
				qListEl.appendChild(row);
			});
		}

		// AI fill
		aiBtn.addEventListener('click', function () {
			var type = typeInput.value;
			if (!type) { setStatus(aiStatus, 'Hãy chọn Coach trước.', 'error'); return; }
			var summary = collectSummary(form);
			// Merge extra-fields into summary (richer context for AI).
			var ex = collectExtraFields();
			Object.keys(ex).forEach(function (k) { if (!summary[k]) summary[k] = ex[k]; });
			var questions = (currentTpl && currentTpl.questions) || [];
			setStatus(aiStatus, '⏳ Đang gọi AI… (5–25 giây)');
			aiBtn.disabled = true;
			api('ai-fill', { method: 'POST', body: { coach_type: type, summary: summary, questions: questions } })
				.then(function (res) {
					var ans = res.answers || [];
					var inputs = qListEl.querySelectorAll('textarea[name="answers[]"]');
					ans.forEach(function (a, i) { if (inputs[i]) inputs[i].value = a; });
					setStatus(aiStatus, '✅ AI đã điền ' + ans.filter(function(x){return x;}).length + '/' + inputs.length + ' câu. Bạn có thể chỉnh sửa.', 'ok');
				})
				.catch(function (e) { setStatus(aiStatus, '❌ ' + e.message, 'error'); })
				.finally(function () { aiBtn.disabled = false; });
		});

		// Submit → save → switch to result panel → progressive generate
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var type = typeInput.value;
			if (!type) { setStatus(submitStat, 'Vui lòng chọn loại Coach.', 'error'); return; }
			var profile = collectGroup(form, 'profile');
			var summary = collectSummary(form);
			var extra   = collectExtraFields();
			var answers = Array.prototype.map.call(
				qListEl.querySelectorAll('textarea[name="answers[]"]'),
				function (t) { return t.value || ''; }
			);
			if (!profile.full_name) { setStatus(submitStat, 'Vui lòng điền Họ và tên.', 'error'); return; }
			submitBtn.disabled = true;
			setStatus(submitStat, '⏳ Đang lưu hồ sơ…');
			api('save', { method: 'POST', body: {
				coach_type: type, profile: profile, extra_fields: extra,
				summary: summary, answers: answers
			} }).then(function (res) {
				if (!res.public_key) throw new Error('Server không trả public_key');
				switchToResultMode(res, currentTpl);
			}).catch(function (e) {
				setStatus(submitStat, '❌ ' + e.message, 'error');
				submitBtn.disabled = false;
			});
		});

		function collectExtraFields() {
			var out = {};
			Array.prototype.forEach.call(extraEl.querySelectorAll('[data-fkey]'), function (el) {
				out[el.dataset.fkey] = el.value || '';
			});
			return out;
		}

		// ── Result panel: skeleton + progressive section generation ──
		function switchToResultMode(saveRes, tpl) {
			form.style.display = 'none';
			resultPanel.hidden = false;
			currentMap = saveRes;
			window.scrollTo({ top: resultPanel.offsetTop - 40, behavior: 'smooth' });

			// Update browser URL to canonical share form (without page reload).
			var shareUrl = (CFG.pageBase || '/coach-builder/') + saveRes.public_key + '/';
			try { history.replaceState(null, '', shareUrl); } catch (e) {}

			var fullName = (collectGroup(form, 'profile').full_name) || '';
			resultTitle.textContent = '🗺️ Bản đồ ' + (tpl && tpl.label ? tpl.label : 'Coach') +
				(fullName ? ' của ' + fullName : '');

			var generators = (tpl && tpl.generators) || [];
			runProgressiveGeneration(saveRes.public_key, generators, /*forceAll=*/true);
		}

		// Resume an existing map by public_key (shareable URL).
		function resumeMap(publicKey) {
			resultPanel.hidden = false;
			progressText.textContent = '⏳ Đang tải bản đồ…';
			api('section-status?key=' + encodeURIComponent(publicKey)).then(function (st) {
				currentMap = {
					public_key: publicKey,
					public_url: (CFG.pageBase || '/coach-builder/') + publicKey + '/'
				};
				resultTitle.textContent = '🗺️ Bản đồ Coach' + (st.full_name ? ' của ' + st.full_name : '');
				var gens = st.generators || [];
				if (!gens.length) {
					progressText.textContent = '⚠️ Bản đồ này chưa có section nào.';
					resultActions.hidden = false;
					return;
				}
				runProgressiveGeneration(publicKey, gens, /*forceAll=*/false);
			}).catch(function (e) {
				progressText.textContent = '❌ ' + e.message;
			});
		}

		function runProgressiveGeneration(publicKey, generators, forceAll) {
			lastGens = generators; // keep reference for rebuild-all btn
			renderSectionSkeletons(generators);
			var total = generators.length, done = 0, errors = 0, fromCache = 0;
			updateProgress(done, total);

			// Pre-fill any sections that section-status already returned with cached content_md
			// → no /generate-section roundtrip needed for those (server-side cache hit).
			generators.forEach(function (g) {
				if (!forceAll && g.has_content && (g.content_html || g.content_md)) {
					fillSection(g.key, g.label, g.content_md, g.content_html);
					done++;
					fromCache++;
				}
			});
			updateProgress(done, total);

			var pending = generators.filter(function (g) {
				return forceAll || !(g.has_content && (g.content_html || g.content_md));
			});

			var chain = Promise.resolve();
			pending.forEach(function (g) {
				chain = chain.then(function () {
					setSectionStatus(g.key, 'loading');
					progressText.textContent = '⏳ Đang luận giải: ' + g.label + ' (' + (done + 1) + '/' + total + ')';
					return api('generate-section', { method: 'POST', body: {
						public_key: publicKey, gen_key: g.key, force: !!forceAll
					} }).then(function (sec) {
						fillSection(g.key, sec.label || g.label, sec.content_md || '', sec.content_html || '');
						if (sec && sec.cached) { fromCache++; }
						done++;
						updateProgress(done, total);
					}).catch(function (e) {
						setSectionError(g.key, e.message || 'Lỗi không xác định');
						errors++; done++;
						updateProgress(done, total);
					});
				});
			});

			chain.then(function () {
				var cacheMsg = fromCache ? ' (' + fromCache + '/' + total + ' từ cache, không gọi LLM)' : '';
				progressText.textContent = errors
					? '⚠️ Hoàn tất với ' + errors + ' lỗi. Bạn có thể tải lại trang để thử các phần lỗi.'
					: '✅ Đã luận giải xong toàn bộ ' + total + ' phần' + cacheMsg + '.';
				resultActions.hidden = false;
			});
		}

		function renderSectionSkeletons(gens) {
			sectionListEl.innerHTML = '';
			gens.forEach(function (g, i) {
				var card = document.createElement('div');
				card.className = 'bcpro-sec-card is-pending';
				card.dataset.gen = g.key;
				card.innerHTML =
					'<div class="bcpro-sec-head">' +
						'<span class="bcpro-sec-num">' + (i + 1) + '</span>' +
						'<h3 class="bcpro-sec-title">' + escHtml(g.label) + '</h3>' +
						'<button type="button" class="bcpro-sec-rebuild" title="Tạo lại mục này" data-rebuild-key="' + escHtml(g.key) + '">🔁</button>' +
						'<span class="bcpro-sec-badge">⏳ Chờ</span>' +
					'</div>' +
					'<div class="bcpro-sec-body">' +
						'<div class="bcpro-skel"></div><div class="bcpro-skel"></div><div class="bcpro-skel" style="width:75%"></div>' +
					'</div>';
				sectionListEl.appendChild(card);
			});
			// Delegation already wired once at boot.
		}
		// Rebuild-section delegation — wired once, survives re-renders.
		sectionListEl.addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-rebuild-key]');
			if (!btn || !currentMap) return;
			var key = btn.dataset.rebuildKey;
			rebuildSection(currentMap.public_key, key);
		});

		function setSectionStatus(key, status) {
			var card = sectionListEl.querySelector('[data-gen="' + cssEsc(key) + '"]');
			if (!card) return;
			card.classList.remove('is-pending', 'is-loading', 'is-done', 'is-error');
			card.classList.add('is-' + status);
			var badge = card.querySelector('.bcpro-sec-badge');
			if (badge) badge.textContent = status === 'loading' ? '⏳ Đang luận giải…' : badge.textContent;
		}
		function fillSection(key, label, md, html) {
			var card = sectionListEl.querySelector('[data-gen="' + cssEsc(key) + '"]');
			if (!card) return;
			card.classList.remove('is-pending', 'is-loading', 'is-error');
			card.classList.add('is-done');
			var badge = card.querySelector('.bcpro-sec-badge');
			if (badge) badge.textContent = '✅ Xong';
			var body = card.querySelector('.bcpro-sec-body');
			if (!body) return;
			if (html && typeof html === 'string' && html.length > 0) {
				// Server-side rich render (JSON-mode generator). Trusted: built by PHP renderer with esc_html.
				body.innerHTML = html;
				card.classList.add('is-rich');
			} else {
				body.innerHTML = '<div class="bcpro-md">' + mdToHtml(md || '') + '</div>';
			}
		}
		function setSectionError(key, msg) {
			var card = sectionListEl.querySelector('[data-gen="' + cssEsc(key) + '"]');
			if (!card) return;
			card.classList.remove('is-pending', 'is-loading', 'is-done');
			card.classList.add('is-error');
			var badge = card.querySelector('.bcpro-sec-badge');
			if (badge) badge.textContent = '❌ Lỗi';
			var body = card.querySelector('.bcpro-sec-body');
			if (body) body.innerHTML = '<div class="bcpro-sec-err">' + escHtml(msg) + ' <button type="button" class="bcpro-sec-rebuild-inline" data-rebuild-key="' + escHtml(key) + '">🔁 Thử lại</button></div>';
			// Wire inline retry btn.
			if (body) body.querySelector('[data-rebuild-key]') && body.querySelector('[data-rebuild-key]').addEventListener('click', function () {
				if (currentMap) rebuildSection(currentMap.public_key, key);
			});
		}
		function rebuildSection(publicKey, key) {
			if (!publicKey || !key) return;
			var gen = lastGens.find(function (g) { return g.key === key; }) || { key: key, label: key };
			setSectionStatus(key, 'loading');
			progressText.textContent = '⏳ Đang tạo lại: ' + gen.label + '…';
			api('generate-section', { method: 'POST', body: {
				public_key: publicKey, gen_key: key, force: true
			} }).then(function (sec) {
				fillSection(key, sec.label || gen.label, sec.content_md || '', sec.content_html || '');
				progressText.textContent = '✅ Đã tạo lại: ' + (sec.label || gen.label);
			}).catch(function (e) {
				setSectionError(key, e.message || 'Lỗi không xác định');
				progressText.textContent = '⚠️ Lỗi khi tạo lại: ' + gen.label;
			});
		}
		function updateProgress(done, total) {
			var pct = total ? Math.round(done / total * 100) : 0;
			progressFill.style.width = pct + '%';
		}
	}

	function collectGroup(form, prefix) {
		var out = {};
		Array.prototype.forEach.call(form.querySelectorAll('[name^="' + prefix + '["]'), function (el) {
			var m = el.name.match(/^([a-z]+)\[([^\]]+)\]$/);
			if (m && m[1] === prefix) out[m[2]] = el.value || '';
		});
		return out;
	}
	function collectSummary(form) { return collectGroup(form, 'summary'); }

	function escHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function cssEsc(s) {
		// Escape attribute selector value (basic).
		return String(s).replace(/[^a-zA-Z0-9_\-]/g, '\\$&');
	}

	/**
	 * Minimal markdown → HTML (headings ###/####, bold/italic, lists, blockquote, code).
	 * Good enough for the section render; avoids pulling marked.js.
	 */
	function mdToHtml(md) {
		if (!md) return '';
		var src = String(md).replace(/\r\n?/g, '\n');

		// Extract code blocks first to protect contents.
		var codeBlocks = [];
		src = src.replace(/```([a-zA-Z0-9]*)\n([\s\S]*?)```/g, function (_, lang, code) {
			codeBlocks.push('<pre><code>' + escHtml(code) + '</code></pre>');
			return '\u0000CB' + (codeBlocks.length - 1) + '\u0000';
		});

		var lines = src.split('\n');
		var out = [];
		var inList = null; // 'ul' | 'ol' | null
		var inBQ = false;
		var paraBuf = [];
		function flushPara() {
			if (!paraBuf.length) return;
			out.push('<p>' + inline(paraBuf.join(' ')) + '</p>');
			paraBuf = [];
		}
		function closeList() {
			if (inList) { out.push('</' + inList + '>'); inList = null; }
		}
		function closeBQ() {
			if (inBQ) { out.push('</blockquote>'); inBQ = false; }
		}
		function inline(s) {
			s = escHtml(s);
			s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
			s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
			s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
			s = s.replace(/\u0000CB(\d+)\u0000/g, function (_, i) { return codeBlocks[+i] || ''; });
			return s;
		}

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			var t = line.trim();

			if (!t) { flushPara(); closeList(); closeBQ(); continue; }

			var mh = t.match(/^(#{1,6})\s+(.+)$/);
			if (mh) {
				flushPara(); closeList(); closeBQ();
				var lvl = Math.min(6, Math.max(3, mh[1].length + 2)); // shift # → h3 baseline
				out.push('<h' + lvl + '>' + inline(mh[2]) + '</h' + lvl + '>');
				continue;
			}

			var ml = t.match(/^[\-\*]\s+(.+)$/);
			if (ml) {
				flushPara(); closeBQ();
				if (inList !== 'ul') { closeList(); out.push('<ul>'); inList = 'ul'; }
				out.push('<li>' + inline(ml[1]) + '</li>');
				continue;
			}
			var mo = t.match(/^\d+\.\s+(.+)$/);
			if (mo) {
				flushPara(); closeBQ();
				if (inList !== 'ol') { closeList(); out.push('<ol>'); inList = 'ol'; }
				out.push('<li>' + inline(mo[1]) + '</li>');
				continue;
			}

			var mq = t.match(/^>\s?(.*)$/);
			if (mq) {
				flushPara(); closeList();
				if (!inBQ) { out.push('<blockquote>'); inBQ = true; }
				out.push(inline(mq[1]));
				continue;
			}

			// Code-block placeholder line
			if (t.indexOf('\u0000CB') === 0) {
				flushPara(); closeList(); closeBQ();
				out.push(inline(t));
				continue;
			}

			// Plain paragraph line
			closeList(); closeBQ();
			paraBuf.push(t);
		}
		flushPara(); closeList(); closeBQ();
		return out.join('\n');
	}

	/* ============================================================
	 * MODE B — Admin Step 2 inject
	 * ============================================================ */
	function bootAdmin() {
		var mount = document.getElementById('bcpro-ai-fill-mount');
		if (!mount) return;

		// Lazy lookup: legacy admin renders the questions table AFTER coach_type is picked,
		// so we re-query the DOM at click time + on each render via MutationObserver.
		function findAnswerInputs() {
			return document.querySelectorAll('input[name="answers[]"], textarea[name="answers[]"]');
		}
		function findCoachType() {
			var el = document.querySelector('input[name="coach_type"], select[name="coach_type"]');
			return el ? (el.value || '') : '';
		}

		// Build widget once (insert near the mount point — moves above questions table when found).
		var panel = document.createElement('div');
		panel.className = 'bcpro-admin-ai-fill';
		panel.innerHTML =
			'<h3>✨ AI fill nhanh — Bizcoach Pro</h3>' +
			'<div class="bcpro-muted" style="font-size:12px;color:#6b7280">Điền tóm tắt, AI sẽ điền hộ toàn bộ câu trả lời.</div>' +
			'<div class="bcpro-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">' +
				'<input type="text"   placeholder="Vị trí / nghề nghiệp"     data-bk="job_title">' +
				'<input type="number" placeholder="Số năm kinh nghiệm"       data-bk="years" min="0">' +
				'<input type="text"   placeholder="Trình độ học vấn"         data-bk="education">' +
				'<input type="text"   placeholder="Mục tiêu / mong muốn lớn" data-bk="goal">' +
			'</div>' +
			'<div style="margin-top:10px;display:flex;align-items:center;gap:12px">' +
				'<button type="button" class="bcpro-btn bcpro-btn-ai" id="bcpro-admin-ai-go">✨ Để AI điền hộ</button>' +
				'<span class="bcpro-ai-status" id="bcpro-admin-ai-status"></span>' +
			'</div>';

		mount.parentNode.insertBefore(panel, mount.nextSibling);
		mount.style.display = 'none';

		// Re-position above questions table when it appears.
		function repositionAboveQuestions() {
			var inputs = findAnswerInputs();
			if (!inputs.length) return;
			var table = inputs[0].closest('table');
			var anchor = table || inputs[0].closest('tr') || inputs[0];
			if (anchor && anchor.parentNode && panel.nextSibling !== anchor) {
				anchor.parentNode.insertBefore(panel, anchor);
			}
		}
		repositionAboveQuestions();
		new MutationObserver(repositionAboveQuestions).observe(document.body, { childList: true, subtree: true });

		var statEl = panel.querySelector('#bcpro-admin-ai-status');
		var btn    = panel.querySelector('#bcpro-admin-ai-go');
		btn.addEventListener('click', function () {
			var coachType = findCoachType();
			var inputs    = findAnswerInputs();
			if (!coachType) { setStatus(statEl, 'Không xác định được coach_type. Hãy chọn coach rồi thử lại.', 'error'); return; }
			if (!inputs.length) { setStatus(statEl, 'Chưa tìm thấy bảng câu hỏi. Chọn coach để admin render bảng trước.', 'error'); return; }
			var summary = {};
			Array.prototype.forEach.call(panel.querySelectorAll('[data-bk]'), function (el) {
				summary[el.dataset.bk] = el.value || '';
			});
			// Collect questions text from rendered table for richer context.
			var questions = [];
			Array.prototype.forEach.call(inputs, function (el) {
				var tr = el.closest('tr');
				var tds = tr ? tr.querySelectorAll('td') : null;
				// Legacy table: <td>#</td><td>question text</td><td>input</td>
				if (tds && tds.length >= 2) { questions.push((tds[1].textContent || '').trim()); }
				else { questions.push(''); }
			});
			setStatus(statEl, '⏳ Đang gọi AI… (' + inputs.length + ' câu)');
			btn.disabled = true;
			api('ai-fill', { method: 'POST', body: { coach_type: coachType, summary: summary, questions: questions } })
				.then(function (res) {
					var ans  = res.answers || [];
					var live = findAnswerInputs(); // refresh in case DOM moved
					var filled = 0;
					ans.forEach(function (a, i) {
						if (live[i] && a) {
							live[i].value = a;
							live[i].dispatchEvent(new Event('input',  { bubbles: true }));
							live[i].dispatchEvent(new Event('change', { bubbles: true }));
							filled++;
						}
					});
					setStatus(statEl, '✅ Đã điền ' + filled + '/' + live.length + ' câu. Review và bấm "Lưu câu trả lời" bên dưới.', 'ok');
				})
				.catch(function (e) { setStatus(statEl, '❌ ' + e.message, 'error'); })
				.finally(function () { btn.disabled = false; });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', dispatch);
	} else {
		dispatch();
	}
	function dispatch() {
		if (CFG.mode === 'admin') { bootAdmin(); }
		else                      { bootFrontend(); }
	}
})();
