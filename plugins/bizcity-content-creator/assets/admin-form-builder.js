/**
 * BizCity Content Creator — Admin Form Builder (Multi-Step)
 *
 * Each step has its own field list. Step 1 is always present.
 * If only 1 step → frontend renders single page.
 * If 2+ steps → frontend renders wizard mode.
 *
 * Dependencies: jQuery (WordPress admin)
 */
(function ($) {
	'use strict';

	/* ───────────────────── Field type definitions ───────────────────── */
	const FIELD_TYPES = {
		text:     { icon: '✏️', label: 'Text',      hasOptions: false },
		textarea: { icon: '📝', label: 'Textarea',  hasOptions: false },
		number:   { icon: '🔢', label: 'Number',    hasOptions: false },
		select:   { icon: '📋', label: 'Select',    hasOptions: true  },
		radio:    { icon: '🔘', label: 'Radio',     hasOptions: true  },
		checkbox: { icon: '☑️', label: 'Checkbox',  hasOptions: true  },
		rating:   { icon: '⭐', label: 'Rating',    hasOptions: false, hasRange: true },
		scale:    { icon: '📊', label: 'Scale',     hasOptions: false, hasRange: true },
		range:    { icon: '🎚️', label: 'Range',     hasOptions: false, hasRange: true },
		toggle:   { icon: '🔀', label: 'Toggle',    hasOptions: false },
		image:    { icon: '🖼️', label: 'Image',     hasOptions: false },
		// ── Layout / Advanced field types ──
		heading:        { icon: '🏷️', label: 'Heading',        hasOptions: false, isLayout: true },
		button_group:   { icon: '💊', label: 'Button Group',   hasOptions: true,  isLayout: true },
		checkbox_grid:  { icon: '🔲', label: 'Checkbox Grid',  hasOptions: true,  isLayout: true, hasColumns: true },
		collapsible:    { icon: '📂', label: 'Collapsible',    hasOptions: false, isLayout: true },
		tab_group:      { icon: '📑', label: 'Tab Group',      hasOptions: false, isLayout: true, hasTabs: true },
	};

	const GRID_OPTIONS = [
		{ value: 'full', label: 'Full width' },
		{ value: 'half', label: 'Half width' },
	];

	/* ───────────────────── State ───────────────────── */
	/**
	 * steps = [
	 *   { label: 'Bước 1', description: '', fields: [ {slug, label, type, ...}, ... ] },
	 *   { label: 'Bước 2', description: '', fields: [ ... ] },
	 * ]
	 */
	let steps         = [];
	let activeStep    = 0;   // which step tab is active
	let activeField   = -1;  // which field in the active step is expanded
	let dragSrcIndex  = -1;

	/* ───────────────────── DOM refs ───────────────────── */
	let $mount, $hiddenFields, $hiddenWizard;

	/* ═══════════════════════════════════════════════
	 *  Init — parse existing data into steps
	 * ═══════════════════════════════════════════════ */
	function init() {
		$mount = $('#bzcc-form-builder');
		if (!$mount.length) return;

		$hiddenFields = $('textarea[name="form_fields"]');
		$hiddenWizard = $('textarea[name="wizard_steps"]');
		if (!$hiddenFields.length) return;

		// Parse existing data
		let allFields = [];
		let wizardSteps = [];
		try { allFields   = JSON.parse($hiddenFields.val().trim()) || []; } catch (e) { allFields = []; }
		try { wizardSteps = JSON.parse($hiddenWizard.val().trim()) || []; } catch (e) { wizardSteps = []; }
		if (!Array.isArray(allFields)) allFields = [];
		if (!Array.isArray(wizardSteps)) wizardSteps = [];

		// Build steps from existing data
		if (wizardSteps.length >= 2) {
			// Has wizard: distribute fields into steps by slug
			const fieldMap = {};
			allFields.forEach(f => { fieldMap[f.slug] = f; });
			const assigned = new Set();

			steps = wizardSteps.map((ws, i) => {
				const stepFields = (ws.fields || [])
					.filter(slug => fieldMap[slug])
					.map(slug => { assigned.add(slug); return { ...fieldMap[slug] }; });
				return {
					label:       ws.label || ('Bước ' + (i + 1)),
					description: ws.description || '',
					fields:      stepFields,
				};
			});

			// Any unassigned fields go to step 1
			allFields.forEach(f => {
				if (!assigned.has(f.slug) && steps.length > 0) {
					steps[0].fields.push({ ...f });
				}
			});
		} else {
			// Single step: restore label/desc from wizard_steps[0] if available
			const ws0 = wizardSteps.length === 1 ? wizardSteps[0] : {};
			steps = [{
				label:       ws0.label || 'Bước 1',
				description: ws0.description || '',
				fields:      allFields.map(f => ({ ...f })),
			}];
		}

		// Ensure at least 1 step
		if (steps.length === 0) {
			steps = [{ label: 'Bước 1', description: '', fields: [] }];
		}

		render();
		syncAll();
	}

	/* ═══════════════════════════════════════════════
	 *  Full render
	 * ═══════════════════════════════════════════════ */
	function render() {
		let html = '';

		// Step tabs
		html += '<div class="fb-step-tabs">';
		steps.forEach((s, si) => {
			const active  = si === activeStep ? ' fb-step-tab--active' : '';
			const count   = s.fields.length;
			const canDel  = steps.length > 1 && si > 0;
			html += `<div class="fb-step-tab${active}" data-step="${si}">
				<span class="fb-step-tab__label" data-step="${si}">
					<span class="fb-step-tab__num">${si + 1}</span>
					${esc(s.label)}
					<span class="fb-step-tab__count">${count}</span>
				</span>
				${canDel ? `<button type="button" class="fb-step-tab__remove" data-action="remove-step" data-step="${si}" title="Xóa bước này">✕</button>` : ''}
			</div>`;
		});
		html += `<button type="button" class="fb-step-tab fb-step-tab--add" data-action="add-step">
			＋ Thêm bước
		</button>`;
		html += '</div>';

		// Wizard badge
		if (steps.length > 1) {
			html += `<div class="fb-wizard-badge">
				🧙 Wizard mode: ${steps.length} bước — Frontend sẽ hiển thị dạng wizard step-by-step
			</div>`;
		} else {
			html += `<div class="fb-wizard-badge fb-wizard-badge--single">
				📄 Single page — Nhấn "Thêm bước" nếu muốn chia form thành nhiều bước (wizard)
			</div>`;
		}

		// Active step header (editable label/desc)
		const cs = steps[activeStep];
		html += `<div class="fb-step-header">
			<div class="fb-step-header__fields">
				<div class="fb-editor-group">
					<label>Tên bước</label>
					<input type="text" id="fbStepLabel" value="${esc(cs.label)}" placeholder="Bước ${activeStep + 1}">
				</div>
				<div class="fb-editor-group">
					<label>Mô tả <small>(tùy chọn, hiện trên frontend)</small></label>
					<input type="text" id="fbStepDesc" value="${esc(cs.description)}" placeholder="Mô tả ngắn cho bước này">
				</div>
			</div>
		</div>`;

		// Side-by-side layout: builder + live preview
		html += '<div class="fb-layout">';

		// Left: builder (palette + canvas)
		html += '<div class="fb-layout__builder">';
		html += '<div class="fb-container">';
		html += renderPalette();
		html += renderCanvas(cs);
		html += '</div>';
		html += '</div>';

		// Right: live preview panel
		html += '<div class="fb-layout__preview">';
		html += '<div class="fb-preview-wrap">';
		html += '<div class="fb-preview-header">';
		html += '<span class="fb-preview-header__icon">👁️</span>';
		html += '<span class="fb-preview-header__title">Live Preview</span>';
		html += '<span class="fb-preview-header__badge">Frontend</span>';
		html += '</div>';
		html += '<div class="fb-preview" id="fbPreview"></div>';
		html += '</div>';
		html += '</div>';

		html += '</div>';

		$mount.html(html);
		bindEvents();
		renderPreview();
	}

	/* ── Palette (left) ── */
	function renderPalette() {
		let h = '<div class="fb-palette">';

		// Input fields
		h += '<p class="fb-palette__title">Trường nhập liệu</p>';
		h += '<div class="fb-palette__grid">';
		for (const [type, def] of Object.entries(FIELD_TYPES)) {
			if (def.isLayout) continue;
			h += `<div class="fb-palette__item" data-type="${type}">
				<span class="fb-palette__icon">${def.icon}</span>
				${def.label}
			</div>`;
		}
		h += '</div>';

		// Layout blocks
		h += '<p class="fb-palette__title fb-palette__title--layout">Bố cục nâng cao</p>';
		h += '<div class="fb-palette__grid">';
		for (const [type, def] of Object.entries(FIELD_TYPES)) {
			if (!def.isLayout) continue;
			h += `<div class="fb-palette__item fb-palette__item--layout" data-type="${type}">
				<span class="fb-palette__icon">${def.icon}</span>
				${def.label}
			</div>`;
		}
		h += '</div>';

		h += '</div>';
		return h;
	}

	/* ── Canvas (right) ── */
	function renderCanvas(step) {
		const count = step.fields.length;
		let h = `<div class="fb-canvas">
			<div class="fb-canvas__header">
				<h3 class="fb-canvas__title">
					Bước ${activeStep + 1}: ${esc(step.label)}
					<span class="fb-canvas__count">${count}</span>
				</h3>
				<div class="fb-canvas__actions">
					<button type="button" class="fb-canvas__btn" data-action="collapse-all">Thu gọn</button>
				</div>
			</div>
			<div class="fb-fields" id="fbFields">`;

		if (count === 0) {
			h += `<div class="fb-fields--empty">
				<span class="fb-fields--empty__icon">📋</span>
				<span>Chưa có trường. Bấm thanh bên trái để thêm vào bước ${activeStep + 1}.</span>
			</div>`;
		} else {
			step.fields.forEach((f, i) => {
				const def = FIELD_TYPES[f.type] || FIELD_TYPES.text;
				h += renderFieldCard(f, i, def, i === activeField);
			});
		}

		h += '</div></div>';
		return h;
	}

	/* ── Single field card ── */
	function renderFieldCard(f, i, def, isActive) {
		const activeCls = isActive ? ' fb-field--active' : '';
		const layoutCls = def.isLayout ? ' fb-field--layout' : '';
		const reqBadge  = f.required ? '<span class="fb-field__required">*</span>' : '';
		const badgeHtml = f.badge ? `<span class="fb-field__badge">${esc(f.badge)}</span>` : '';

		let html = `<div class="fb-field${activeCls}${layoutCls}" data-index="${i}" draggable="true">`;

		html += `<div class="fb-field__header">
			<span class="fb-field__drag" title="Kéo để di chuyển">⠿</span>
			<span class="fb-field__type-icon">${def.icon}</span>
			<div class="fb-field__info">
				<div class="fb-field__label">${esc(f.label)} ${reqBadge} ${badgeHtml}</div>
				<div class="fb-field__meta">
					<span>${def.label}</span>
					<span>${f.slug}</span>
					<span>${f.grid === 'half' ? '½' : '1/1'}</span>
				</div>
			</div>
			<div class="fb-field__actions">
				${steps.length > 1 ? `<select class="fb-field__move-step" data-index="${i}" title="Di chuyển sang bước khác">
					${steps.map((s, si) => `<option value="${si}" ${si === activeStep ? 'selected disabled' : ''}>→ ${esc(s.label)}</option>`).join('')}
				</select>` : ''}
				<button type="button" class="fb-field__btn fb-field__btn--duplicate" data-action="duplicate" data-index="${i}" title="Nhân bản">📋</button>
				<button type="button" class="fb-field__btn fb-field__btn--delete" data-action="delete" data-index="${i}" title="Xóa">🗑️</button>
			</div>
		</div>`;

		html += `<div class="fb-field__editor">${renderEditor(f, i, def)}</div>`;
		html += '</div>';
		return html;
	}

	/* ── Inline editor ── */
	function renderEditor(f, i, def) {
		let h = '';

		h += `<div class="fb-editor-row">
			<div class="fb-editor-group">
				<label>Label</label>
				<input type="text" data-field="label" data-index="${i}" value="${esc(f.label)}">
			</div>
			<div class="fb-editor-group">
				<label>Slug</label>
				<input type="text" data-field="slug" data-index="${i}" value="${esc(f.slug)}">
			</div>
		</div>`;

		let typeOpts = '';
		for (const [t, d] of Object.entries(FIELD_TYPES)) {
			typeOpts += `<option value="${t}" ${t === f.type ? 'selected' : ''}>${d.icon} ${d.label}</option>`;
		}
		let gridOpts = GRID_OPTIONS.map(g =>
			`<option value="${g.value}" ${g.value === f.grid ? 'selected' : ''}>${g.label}</option>`
		).join('');

		h += `<div class="fb-editor-row">
			<div class="fb-editor-group">
				<label>Type</label>
				<select data-field="type" data-index="${i}">${typeOpts}</select>
			</div>
			<div class="fb-editor-group">
				<label>Grid</label>
				<select data-field="grid" data-index="${i}">${gridOpts}</select>
			</div>
		</div>`;

		// Layout types: heading, collapsible — show badge + description + icon
		if (f.type === 'heading' || f.type === 'collapsible') {
			h += `<div class="fb-editor-row">
				<div class="fb-editor-group">
					<label>Mô tả</label>
					<input type="text" data-field="description" data-index="${i}" value="${esc(f.description || '')}" placeholder="Mô tả hiển thị bên dưới tiêu đề">
				</div>
				<div class="fb-editor-group">
					<label>Badge <small>(tùy chọn)</small></label>
					<input type="text" data-field="badge" data-index="${i}" value="${esc(f.badge || '')}" placeholder="VD: 3 engine / 8 options">
				</div>
			</div>`;
			if (f.type === 'collapsible') {
				h += `<div class="fb-toggle-row">
					<label class="fb-toggle">
						<input type="checkbox" data-field="collapsed_default" data-index="${i}" ${f.collapsed_default ? 'checked' : ''}>
						Mặc định thu gọn
					</label>
				</div>`;
			}
		}
		// Tab group: tab labels
		else if (f.type === 'tab_group') {
			h += renderTabsEditor(f, i);
		}
		// Normal input fields: placeholder, description, required
		else if (!def.isLayout) {
			h += `<div class="fb-editor-row">
				<div class="fb-editor-group">
					<label>Placeholder</label>
					<input type="text" data-field="placeholder" data-index="${i}" value="${esc(f.placeholder || '')}">
				</div>
				<div class="fb-editor-group">
					<label>Mô tả</label>
					<input type="text" data-field="description" data-index="${i}" value="${esc(f.description || '')}">
				</div>
			</div>`;

			h += `<div class="fb-toggle-row">
				<label class="fb-toggle">
					<input type="checkbox" data-field="required" data-index="${i}" ${f.required ? 'checked' : ''}>
					Bắt buộc
				</label>
			</div>`;
		}

		// Button group: multi-select toggle
		if (f.type === 'button_group') {
			h += `<div class="fb-toggle-row">
				<label class="fb-toggle">
					<input type="checkbox" data-field="multi" data-index="${i}" ${f.multi !== false ? 'checked' : ''}>
					Cho phép chọn nhiều
				</label>
			</div>`;
		}

		// Checkbox grid: columns
		if (f.type === 'checkbox_grid') {
			h += `<div class="fb-editor-row">
				<div class="fb-editor-group">
					<label>Số cột</label>
					<select data-field="columns" data-index="${i}">
						<option value="2" ${(f.columns||3)==2?'selected':''}>2 cột</option>
						<option value="3" ${(f.columns||3)==3?'selected':''}>3 cột</option>
						<option value="4" ${(f.columns||3)==4?'selected':''}>4 cột</option>
					</select>
				</div>
				<div class="fb-editor-group">
					<label>Mô tả</label>
					<input type="text" data-field="description" data-index="${i}" value="${esc(f.description || '')}">
				</div>
			</div>`;
		}

		if (def.hasOptions) h += renderOptionsEditor(f, i);
		if (def.hasRange)   h += renderRangeEditor(f, i);

		return h;
	}

	/* ── Tab Group editor ── */
	function renderTabsEditor(f, i) {
		const tabs = f.tabs || [];
		let h = '<div class="fb-options"><div class="fb-options__title">Tab labels</div><div class="fb-options__list">';
		tabs.forEach((tab, ti) => {
			h += `<div class="fb-option-row" data-field-index="${i}" data-opt-index="${ti}">
				<input type="text" data-role="tab-icon" placeholder="Icon/Emoji" value="${esc(tab.icon || '')}" style="width:60px;">
				<input type="text" data-role="tab-label" placeholder="Tên tab" value="${esc(tab.label || '')}">
				<button type="button" class="fb-option-row__btn" data-action="remove-tab" data-field-index="${i}" data-opt-index="${ti}">✕</button>
			</div>`;
		});
		h += `</div><button type="button" class="fb-options__add" data-action="add-tab" data-index="${i}">＋ Thêm tab</button></div>`;
		h += `<p class="fb-layout-hint">💡 Đặt các field bên dưới tab_group này. Field tiếp theo thuộc tab đầu tiên. Dùng thêm <strong>heading</strong> với slug <code>tab:2</code>, <code>tab:3</code>... để phân chia field vào từng tab.</p>`;
		return h;
	}

	function renderOptionsEditor(f, i) {
		const opts = f.options || [];
		let h = '<div class="fb-options"><div class="fb-options__title">Tùy chọn</div><div class="fb-options__list">';
		opts.forEach((opt, oi) => {
			h += `<div class="fb-option-row" data-field-index="${i}" data-opt-index="${oi}">
				<input type="text" data-role="opt-value" placeholder="Giá trị" value="${esc(opt.value || '')}">
				<input type="text" data-role="opt-label" placeholder="Nhãn" value="${esc(opt.label || '')}">
				<button type="button" class="fb-option-row__btn" data-action="remove-opt" data-field-index="${i}" data-opt-index="${oi}">✕</button>
			</div>`;
		});
		h += `</div><button type="button" class="fb-options__add" data-action="add-opt" data-index="${i}">＋ Thêm tùy chọn</button></div>`;
		return h;
	}

	function renderRangeEditor(f, i) {
		return `<div class="fb-range-config">
			<div class="fb-editor-group"><label>Min</label><input type="number" data-field="min" data-index="${i}" value="${f.min ?? 1}"></div>
			<div class="fb-editor-group"><label>Max</label><input type="number" data-field="max" data-index="${i}" value="${f.max ?? 10}"></div>
			<div class="fb-editor-group"><label>Min label</label><input type="text" data-field="min_label" data-index="${i}" value="${esc(f.min_label || '')}"></div>
			<div class="fb-editor-group"><label>Max label</label><input type="text" data-field="max_label" data-index="${i}" value="${esc(f.max_label || '')}"></div>
		</div>`;
	}

	/* ═══════════════════════════════════════════════
	 *  Add field to active step
	 * ═══════════════════════════════════════════════ */
	function addField(type) {
		const def = FIELD_TYPES[type] || FIELD_TYPES.text;
		const allCount = steps.reduce((n, s) => n + s.fields.length, 0);
		const idx = allCount + 1;
		const slug = type + '_' + idx;

		const field = {
			slug, label: def.label + ' ' + idx, type, placeholder: '',
			required: false, grid: 'full', sort_order: idx, description: '',
		};
		if (def.hasOptions) {
			field.options = [{ value: 'opt1', label: 'Tùy chọn 1' }, { value: 'opt2', label: 'Tùy chọn 2' }];
		}
		if (def.hasRange) {
			field.min = 1; field.max = type === 'rating' ? 5 : 10;
			field.min_label = ''; field.max_label = '';
		}

		// Defaults for layout types
		if (type === 'heading') {
			field.label = 'Section ' + idx;
			field.badge = '';
		}
		if (type === 'collapsible') {
			field.label = 'Collapsible ' + idx;
			field.description = '';
			field.badge = '';
			field.collapsed_default = true;
		}
		if (type === 'button_group') {
			field.label = 'Button Group ' + idx;
			field.multi = true;
			field.options = [
				{ value: 'opt1', label: '🎯 Tùy chọn 1' },
				{ value: 'opt2', label: '📦 Tùy chọn 2' },
				{ value: 'opt3', label: '🔮 Tùy chọn 3' },
			];
		}
		if (type === 'checkbox_grid') {
			field.label = 'Checkbox Grid ' + idx;
			field.columns = 3;
			field.options = [
				{ value: 'opt1', label: 'Tùy chọn 1' },
				{ value: 'opt2', label: 'Tùy chọn 2' },
				{ value: 'opt3', label: 'Tùy chọn 3' },
			];
		}
		if (type === 'tab_group') {
			field.label = 'Tabs ' + idx;
			field.tabs = [
				{ label: 'Tab 1', icon: '📋' },
				{ label: 'Tab 2', icon: '🔥' },
			];
		}

		steps[activeStep].fields.push(field);
		activeField = steps[activeStep].fields.length - 1;
		render();
		syncAll();

		setTimeout(() => {
			const el = $mount.find('#fbFields .fb-field').last()[0];
			if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}, 50);
	}

	/* ═══════════════════════════════════════════════
	 *  Event bindings
	 * ═══════════════════════════════════════════════ */
	function bindEvents() {
		// Palette click
		$mount.off('click.pal').on('click.pal', '.fb-palette__item', function () {
			addField($(this).data('type'));
		});

		// Step tab click
		$mount.off('click.tab').on('click.tab', '.fb-step-tab__label', function () {
			activeStep  = parseInt($(this).data('step'), 10);
			activeField = -1;
			render();
		});

		// Add step
		$mount.off('click.addstep').on('click.addstep', '[data-action="add-step"]', function () {
			const n = steps.length + 1;
			steps.push({ label: 'Bước ' + n, description: '', fields: [] });
			activeStep  = steps.length - 1;
			activeField = -1;
			render();
			syncAll();
		});

		// Remove step
		$mount.off('click.rmstep').on('click.rmstep', '[data-action="remove-step"]', function (e) {
			e.stopPropagation();
			const si = parseInt($(this).data('step'), 10);
			if (steps.length <= 1) return;
			const moving = steps[si].fields;
			if (!confirm('Xóa "' + steps[si].label + '"? ' + (moving.length ? moving.length + ' trường sẽ chuyển về Bước 1.' : ''))) return;
			// Move fields to step 1 (index 0)
			if (si !== 0 && moving.length) {
				steps[0].fields = steps[0].fields.concat(moving);
			} else if (si === 0 && moving.length && steps.length > 1) {
				steps[1].fields = moving.concat(steps[1].fields);
			}
			steps.splice(si, 1);
			if (activeStep >= steps.length) activeStep = steps.length - 1;
			activeField = -1;
			render();
			syncAll();
		});

		// Step label/desc
		$mount.off('input.stepinfo').on('input.stepinfo', '#fbStepLabel, #fbStepDesc', function () {
			const id = $(this).attr('id');
			if (id === 'fbStepLabel') steps[activeStep].label = $(this).val();
			if (id === 'fbStepDesc')  steps[activeStep].description = $(this).val();
			// Update tab label live
			$mount.find('.fb-step-tab[data-step="' + activeStep + '"] .fb-step-tab__label')
				.contents().filter(function () { return this.nodeType === 3; }).first()
				.replaceWith(document.createTextNode(' ' + ($(this).val() || 'Bước ' + (activeStep + 1)) + ' '));
			syncAll();
		});

		// Collapse all
		$mount.off('click.collapse').on('click.collapse', '[data-action="collapse-all"]', function () {
			activeField = -1;
			render();
		});

		// Field header click → toggle
		$mount.off('click.fhdr').on('click.fhdr', '.fb-field__header', function (e) {
			if ($(e.target).closest('.fb-field__actions').length) return;
			if ($(e.target).hasClass('fb-field__drag')) return;
			const idx = $(this).closest('.fb-field').data('index');
			activeField = activeField === idx ? -1 : idx;
			render();
		});

		// Delete field
		$mount.off('click.fdel').on('click.fdel', '[data-action="delete"]', function () {
			const idx = parseInt($(this).data('index'), 10);
			const f = steps[activeStep].fields[idx];
			if (!confirm('Xóa trường "' + f.label + '"?')) return;
			steps[activeStep].fields.splice(idx, 1);
			activeField = -1;
			render();
			syncAll();
		});

		// Duplicate field
		$mount.off('click.fdup').on('click.fdup', '[data-action="duplicate"]', function () {
			const idx  = parseInt($(this).data('index'), 10);
			const copy = JSON.parse(JSON.stringify(steps[activeStep].fields[idx]));
			copy.slug  = copy.slug + '_copy';
			copy.label = copy.label + ' (bản sao)';
			steps[activeStep].fields.splice(idx + 1, 0, copy);
			activeField = idx + 1;
			render();
			syncAll();
		});

		// Move field to another step
		$mount.off('change.fmove').on('change.fmove', '.fb-field__move-step', function () {
			const fieldIdx = parseInt($(this).data('index'), 10);
			const targetStep = parseInt($(this).val(), 10);
			if (targetStep === activeStep) return;
			const field = steps[activeStep].fields.splice(fieldIdx, 1)[0];
			steps[targetStep].fields.push(field);
			activeField = -1;
			render();
			syncAll();
		});

		// Inline edits
		$mount.off('change.fedit input.fedit').on('change.fedit input.fedit',
			'.fb-field__editor input, .fb-field__editor select, .fb-field__editor textarea',
			function () {
				const $el   = $(this);
				const idx   = parseInt($el.data('index'), 10);
				const prop  = $el.data('field');
				const f     = steps[activeStep].fields[idx];
				if (!prop || !f) return;

				if ($el.attr('type') === 'checkbox') {
					f[prop] = $el.is(':checked');
				} else if (prop === 'min' || prop === 'max' || prop === 'sort_order' || prop === 'columns') {
					f[prop] = parseInt($el.val(), 10) || 0;
				} else {
					f[prop] = $el.val();
				}

				if (prop === 'type') { render(); }
				else {
					const $card = $el.closest('.fb-field');
					if (prop === 'label') $card.find('.fb-field__label').html(esc($el.val()) + (f.required ? ' <span class="fb-field__required">*</span>' : ''));
					if (prop === 'slug')  $card.find('.fb-field__meta span').eq(1).text($el.val());
				}
				syncAll();
			}
		);

		// Options edit
		$mount.off('input.fopt').on('input.fopt', '.fb-option-row input', function () {
			const $row = $(this).closest('.fb-option-row');
			const fi = parseInt($row.data('field-index'), 10);
			const oi = parseInt($row.data('opt-index'), 10);
			const f  = steps[activeStep].fields[fi];
			if (f && f.options && f.options[oi]) {
				const role = $(this).data('role');
				if (role === 'opt-value') f.options[oi].value = $(this).val();
				if (role === 'opt-label') f.options[oi].label = $(this).val();
				syncAll();
			}
		});

		$mount.off('click.optrm').on('click.optrm', '[data-action="remove-opt"]', function () {
			const fi = parseInt($(this).data('field-index'), 10);
			const oi = parseInt($(this).data('opt-index'), 10);
			const f  = steps[activeStep].fields[fi];
			if (f && f.options) { f.options.splice(oi, 1); render(); syncAll(); }
		});

		$mount.off('click.optadd').on('click.optadd', '[data-action="add-opt"]', function () {
			const fi = parseInt($(this).data('index'), 10);
			const f  = steps[activeStep].fields[fi];
			if (f) {
				if (!f.options) f.options = [];
				const n = f.options.length + 1;
				f.options.push({ value: 'opt' + n, label: 'Tùy chọn ' + n });
				render(); syncAll();
			}
		});

		// Tab group edits
		$mount.off('input.ftab').on('input.ftab', '[data-role="tab-icon"], [data-role="tab-label"]', function () {
			const $row = $(this).closest('.fb-option-row');
			const fi = parseInt($row.data('field-index'), 10);
			const ti = parseInt($row.data('opt-index'), 10);
			const f  = steps[activeStep].fields[fi];
			if (f && f.tabs && f.tabs[ti]) {
				const role = $(this).data('role');
				if (role === 'tab-icon')  f.tabs[ti].icon  = $(this).val();
				if (role === 'tab-label') f.tabs[ti].label = $(this).val();
				syncAll();
			}
		});

		$mount.off('click.tabrm').on('click.tabrm', '[data-action="remove-tab"]', function () {
			const fi = parseInt($(this).data('field-index'), 10);
			const ti = parseInt($(this).data('opt-index'), 10);
			const f  = steps[activeStep].fields[fi];
			if (f && f.tabs && f.tabs.length > 1) { f.tabs.splice(ti, 1); render(); syncAll(); }
		});

		$mount.off('click.tabadd').on('click.tabadd', '[data-action="add-tab"]', function () {
			const fi = parseInt($(this).data('index'), 10);
			const f  = steps[activeStep].fields[fi];
			if (f) {
				if (!f.tabs) f.tabs = [];
				const n = f.tabs.length + 1;
				f.tabs.push({ label: 'Tab ' + n, icon: '' });
				render(); syncAll();
			}
		});

		// Drag & Drop
		bindDragDrop();
	}

	/* ═══════════════════════════════════════════════
	 *  Drag & Drop
	 * ═══════════════════════════════════════════════ */
	function bindDragDrop() {
		const $fields = $mount.find('#fbFields');
		const cards   = $fields.find('.fb-field');

		cards.off('dragstart.fb dragend.fb dragover.fb drop.fb');

		cards.on('dragstart.fb', function (e) {
			dragSrcIndex = parseInt($(this).data('index'), 10);
			$(this).addClass('fb-field--dragging');
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			e.originalEvent.dataTransfer.setData('text/plain', String(dragSrcIndex));
		});
		cards.on('dragend.fb', function () {
			$(this).removeClass('fb-field--dragging');
			$fields.find('.fb-field').removeClass('fb-field--drag-over');
		});
		cards.on('dragover.fb', function (e) {
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'move';
			$fields.find('.fb-field').removeClass('fb-field--drag-over');
			$(this).addClass('fb-field--drag-over');
		});
		cards.on('drop.fb', function (e) {
			e.preventDefault();
			const dest = parseInt($(this).data('index'), 10);
			if (dragSrcIndex === dest) return;
			const arr = steps[activeStep].fields;
			const moved = arr.splice(dragSrcIndex, 1)[0];
			arr.splice(dest, 0, moved);
			reindex(arr);
			if (activeField === dragSrcIndex) activeField = dest;
			render(); syncAll();
		});
	}

	/* ═══════════════════════════════════════════════
	 *  Live Preview — renders frontend-identical HTML
	 * ═══════════════════════════════════════════════ */
	function renderPreview() {
		const $preview = $mount.find('#fbPreview');
		if (!$preview.length) return;

		let h = '';

		// Wizard step indicator (if multi-step)
		if (steps.length > 1) {
			h += '<div class="bzcc-steps" style="margin-bottom:16px;">';
			h += '<div class="bzcc-steps__track">';
			steps.forEach((s, si) => {
				const activeClass = si === activeStep ? ' bzcc-step--active' : '';
				const doneClass   = si < activeStep ? ' bzcc-step--done' : '';
				h += `<div class="bzcc-step${activeClass}${doneClass}" data-step="${si + 1}">
					<div class="bzcc-step__circle">${si + 1}</div>
					<div class="bzcc-step__label">${esc(s.label)}</div>
				</div>`;
				if (si < steps.length - 1) {
					h += '<div class="bzcc-step__line"></div>';
				}
			});
			h += '</div>';
			h += `<div class="bzcc-steps__counter">Bước ${activeStep + 1} / ${steps.length}</div>`;
			h += '</div>';
		}

		// Form card for active step
		const cs = steps[activeStep];
		h += '<div class="bzcc-form-card">';
		h += `<h2 class="bzcc-form-card__title">`;
		if (steps.length > 1) {
			h += `<span class="bzcc-form-card__num">${activeStep + 1}</span>`;
		} else {
			h += `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>`;
		}
		h += esc(steps.length > 1 ? cs.label : 'Nhập thông tin');
		h += '</h2>';
		if (cs.description) {
			h += `<p class="bzcc-form-card__desc">${esc(cs.description)}</p>`;
		} else if (steps.length <= 1) {
			h += '<p class="bzcc-form-card__desc">Điền thông tin bên dưới để AI tạo nội dung cho bạn</p>';
		}

		// Fields
		h += '<div class="bzcc-fields">';
		if (cs.fields.length === 0) {
			h += '<div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#94a3b8;font-size:14px;">Chưa có trường nào — thêm từ palette bên trái</div>';
		} else {
			cs.fields.forEach(f => {
				h += renderPreviewField(f);
			});
		}
		h += '</div>';
		h += '</div>';

		// Action bar
		h += '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">';
		if (steps.length > 1) {
			if (activeStep > 0) {
				h += '<button type="button" class="bzcc-btn bzcc-btn--outline" disabled style="opacity:0.7;padding:8px 16px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;cursor:default;">← Quay lại</button>';
			}
			if (activeStep < steps.length - 1) {
				h += '<button type="button" class="bzcc-btn bzcc-btn--primary" disabled style="padding:8px 20px;border:none;border-radius:8px;font-size:13px;background:#6366f1;color:#fff;cursor:default;">Tiếp tục →</button>';
			} else {
				h += '<button type="button" class="bzcc-btn bzcc-btn--primary" disabled style="padding:8px 20px;border:none;border-radius:8px;font-size:13px;background:#6366f1;color:#fff;cursor:default;">⚡ Tạo nội dung</button>';
			}
		} else {
			h += '<button type="button" disabled style="padding:8px 16px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff;cursor:default;opacity:0.7;">Quay lại</button>';
			h += '<button type="button" disabled style="padding:8px 20px;border:none;border-radius:8px;font-size:13px;background:#6366f1;color:#fff;cursor:default;">⚡ Tạo nội dung</button>';
		}
		h += '</div>';

		$preview.html(h);
	}

	/* ── Preview: render a single field ── */
	function renderPreviewField(f) {
		const def       = FIELD_TYPES[f.type] || FIELD_TYPES.text;
		const gridClass = f.grid === 'half' ? 'bzcc-field--half' : 'bzcc-field--full';
		const reqStar   = f.required ? '<span class="bzcc-req">*</span>' : '';
		const desc      = f.description || '';
		const badge     = f.badge || '';
		const label     = esc(f.label);
		const ph        = esc(f.placeholder || '');

		// ── Layout types ──
		if (f.type === 'heading') {
			let h = '<div class="bzcc-heading bzcc-field--full">';
			h += '<div class="bzcc-heading__text">';
			h += `<h3 class="bzcc-heading__title">${label}</h3>`;
			if (desc) h += `<p class="bzcc-heading__desc">${esc(desc)}</p>`;
			h += '</div>';
			if (badge) h += `<span class="bzcc-heading__badge">${esc(badge)}</span>`;
			h += '</div>';
			return h;
		}

		if (f.type === 'collapsible') {
			const collapsed = f.collapsed_default;
			const state = collapsed ? 'collapsed' : 'expanded';
			let h = `<div class="bzcc-collapsible bzcc-field--full" data-state="${state}">`;
			h += '<button type="button" class="bzcc-collapsible__header">';
			h += '<div class="bzcc-collapsible__icon">▶</div>';
			h += '<div class="bzcc-collapsible__info">';
			h += `<span class="bzcc-collapsible__title">${label}</span>`;
			if (desc) h += `<span class="bzcc-collapsible__desc">${esc(desc)}</span>`;
			h += '</div>';
			if (badge) h += `<span class="bzcc-collapsible__badge">${esc(badge)}</span>`;
			h += '<svg class="bzcc-collapsible__chevron" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>';
			h += '</button>';
			h += `<div class="bzcc-collapsible__body"${collapsed ? ' style="display:none;"' : ''}></div>`;
			h += '</div>';
			return h;
		}

		if (f.type === 'tab_group') {
			const tabs = f.tabs || [];
			if (!tabs.length) return '';
			let h = '<div class="bzcc-tab-group bzcc-field--full">';
			h += '<div class="bzcc-tab-group__nav">';
			tabs.forEach((tab, ti) => {
				const active = ti === 0 ? ' bzcc-tab-group__tab--active' : '';
				h += `<button type="button" class="bzcc-tab-group__tab${active}" data-tab-index="${ti}">`;
				if (tab.icon) h += `<span class="bzcc-tab-group__icon">${esc(tab.icon)}</span>`;
				h += esc(tab.label || 'Tab ' + (ti + 1));
				h += '</button>';
			});
			h += '</div>';
			tabs.forEach((tab, ti) => {
				const hidden = ti > 0 ? ' style="display:none;"' : '';
				h += `<div class="bzcc-tab-group__pane" data-tab-index="${ti}"${hidden}></div>`;
			});
			h += '</div>';
			return h;
		}

		if (f.type === 'button_group') {
			const isMulti = f.multi !== false;
			const itype = isMulti ? 'checkbox' : 'radio';
			const opts = f.options || [];
			let h = `<div class="bzcc-field ${gridClass}">`;
			h += `<label class="bzcc-label">${label}${reqStar}</label>`;
			if (desc) h += `<p class="bzcc-field-desc">${esc(desc)}</p>`;
			h += '<div class="bzcc-button-group">';
			opts.forEach(opt => {
				h += `<label class="bzcc-pill">`;
				h += `<input type="${itype}" style="display:none;">`;
				h += `<span class="bzcc-pill__label">${esc(opt.label || '')}</span>`;
				h += '</label>';
			});
			h += '</div></div>';
			return h;
		}

		if (f.type === 'checkbox_grid') {
			const cols = f.columns || 3;
			const opts = f.options || [];
			let h = `<div class="bzcc-field ${gridClass}">`;
			h += `<label class="bzcc-label">${label}${reqStar}</label>`;
			if (desc) h += `<p class="bzcc-field-desc">${esc(desc)}</p>`;
			h += `<div class="bzcc-checkbox-grid" style="grid-template-columns:repeat(${cols},1fr);">`;
			opts.forEach(opt => {
				h += '<label class="bzcc-grid-check">';
				h += '<input type="checkbox">';
				h += '<span class="bzcc-grid-check__mark"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg></span>';
				h += `<span class="bzcc-grid-check__label">${esc(opt.label || '')}</span>`;
				h += '</label>';
			});
			h += '</div></div>';
			return h;
		}

		// ── Standard input types ──
		let h = `<div class="bzcc-field ${gridClass}">`;
		h += `<label class="bzcc-label">${label}${reqStar}</label>`;

		switch (f.type) {
			case 'textarea':
				h += `<textarea class="bzcc-textarea" placeholder="${ph}" rows="4" disabled></textarea>`;
				break;
			case 'select':
				h += '<select class="bzcc-select" disabled>';
				h += '<option value="">— Chọn —</option>';
				(f.options || []).forEach(opt => {
					h += `<option>${esc(opt.label || '')}</option>`;
				});
				h += '</select>';
				break;
			case 'radio':
				h += '<div class="bzcc-radio-group">';
				(f.options || []).forEach(opt => {
					h += `<label class="bzcc-radio-item"><input type="radio" disabled> <span class="bzcc-radio-label">${esc(opt.label || '')}</span></label>`;
				});
				h += '</div>';
				break;
			case 'checkbox':
				h += '<div class="bzcc-checkbox-group">';
				(f.options || []).forEach(opt => {
					h += `<label class="bzcc-checkbox-item"><input type="checkbox" disabled> <span class="bzcc-checkbox-label">${esc(opt.label || '')}</span></label>`;
				});
				h += '</div>';
				break;
			case 'rating': {
				const max = f.max || 5;
				h += '<div class="bzcc-rating">';
				for (let i = 1; i <= max; i++) {
					h += `<span class="bzcc-star${i <= 3 ? ' bzcc-star--active' : ''}">★</span>`;
				}
				h += '</div>';
				break;
			}
			case 'scale': {
				const min = f.min ?? 1;
				const max = f.max ?? 10;
				h += '<div class="bzcc-scale">';
				for (let i = min; i <= max; i++) {
					h += `<label class="bzcc-scale-item${i === 5 ? ' bzcc-scale-item--active' : ''}"><span>${i}</span></label>`;
				}
				h += '</div>';
				if (f.min_label || f.max_label) {
					h += `<div style="display:flex;justify-content:space-between;font-size:11px;color:#94a3b8;margin-top:4px;"><span>${esc(f.min_label || '')}</span><span>${esc(f.max_label || '')}</span></div>`;
				}
				break;
			}
			case 'range': {
				const min = f.min ?? 0;
				const max = f.max ?? 100;
				const mid = Math.round((min + max) / 2);
				h += `<div style="display:flex;align-items:center;gap:10px;">`;
				h += `<input type="range" min="${min}" max="${max}" value="${mid}" style="flex:1;accent-color:#6366f1;" disabled>`;
				h += `<span style="font-size:14px;font-weight:600;color:#6366f1;">${mid}</span>`;
				h += '</div>';
				if (f.min_label || f.max_label) {
					h += `<div style="display:flex;justify-content:space-between;font-size:11px;color:#94a3b8;margin-top:2px;"><span>${esc(f.min_label || '')}</span><span>${esc(f.max_label || '')}</span></div>`;
				}
				break;
			}
			case 'toggle':
				h += `<label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
					<span style="position:relative;width:44px;height:24px;background:#e2e8f0;border-radius:12px;display:inline-block;">
						<span style="position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:.2s;"></span>
					</span>
					<span style="font-size:13px;color:#64748b;">${ph || 'Bật / Tắt'}</span>
				</label>`;
				break;
			case 'image':
				h += `<div style="border:2px dashed #e2e8f0;border-radius:8px;padding:24px;text-align:center;color:#94a3b8;font-size:13px;">
					<div style="font-size:24px;margin-bottom:4px;">🖼️</div>
					Kéo thả hoặc nhấn để tải ảnh
				</div>`;
				break;
			case 'number':
				h += `<input type="number" class="bzcc-input" placeholder="${ph}" disabled>`;
				break;
			default:
				h += `<input type="text" class="bzcc-input" placeholder="${ph}" disabled>`;
				break;
		}

		h += '</div>';
		return h;
	}

	/* ═══════════════════════════════════════════════
	 *  Sync state → hidden textareas
	 * ═══════════════════════════════════════════════ */
	function syncAll() {
		// form_fields = flat array of ALL fields across all steps
		const allFields = [];
		steps.forEach((s, si) => {
			s.fields.forEach((f, fi) => {
				allFields.push({ ...f, sort_order: allFields.length + 1 });
			});
		});
		$hiddenFields.val(JSON.stringify(allFields));

		// wizard_steps: always save so step label/description persists
		const ws = steps.map(s => ({
			label:       s.label,
			description: s.description,
			fields:      s.fields.map(f => f.slug),
		}));
		$hiddenWizard.val(JSON.stringify(ws));

		// Update live preview
		renderPreview();
	}

	/* ═══════════════════════════════════════════════
	 *  Utilities
	 * ═══════════════════════════════════════════════ */
	function reindex(arr) {
		arr.forEach((f, i) => { f.sort_order = i + 1; });
	}

	function esc(str) {
		if (!str) return '';
		const div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/* ═══════════════════════════════════════════════
	 *  Boot
	 * ═══════════════════════════════════════════════ */
	$(function () { init(); });

})(jQuery);
