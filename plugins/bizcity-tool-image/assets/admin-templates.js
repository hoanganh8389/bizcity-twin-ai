/**
 * BizCity Tool Image — Admin Templates JS
 *
 * Form Fields Builder with same UX as Content Creator:
 * Palette (left) + Canvas (center) + Live Preview (right)
 *
 * Image-specific field types: image_upload, card_radio, model_picker,
 * size_picker, multi_reference_images, quick_suggest, + standard types.
 */
(function ($) {
  'use strict';

  /* ──────────── Field type definitions ──────────── */
  const FIELD_TYPES = {
    // Input fields
    text:         { icon: '✏️',  label: 'Text',           group: 'input',  hasOptions: false },
    textarea:     { icon: '📝',  label: 'Textarea',        group: 'input',  hasOptions: false },
    number:       { icon: '🔢',  label: 'Number',          group: 'input',  hasOptions: false },
    color_picker: { icon: '🎨',  label: 'Color Picker',    group: 'input',  hasOptions: false },
    // Choice fields
    card_radio:    { icon: '🃏', label: 'Card Radio',      group: 'choice', hasOptions: true,  hasPromptHint: true },
    quick_suggest: { icon: '💊', label: 'Quick Suggest',   group: 'choice', hasOptions: true  },
    select:        { icon: '📋', label: 'Select',          group: 'choice', hasOptions: true  },
    radio:         { icon: '🔘', label: 'Radio',           group: 'choice', hasOptions: true  },
    // Image-specific
    image_upload:           { icon: '🖼️', label: 'Image Upload',            group: 'image' },
    model_picker:           { icon: '🧑', label: 'Model Picker',            group: 'image' },
    size_picker:            { icon: '📐', label: 'Size Picker',             group: 'image', hasOptions: true },
    multi_reference_images: { icon: '📸', label: 'Multi Reference Images',  group: 'image' },
    // Layout
    heading: { icon: '🏷️', label: 'Heading', group: 'layout' },
  };

  const GRID_OPTIONS = [
    { value: 'full', label: 'Full width' },
    { value: 'half', label: 'Half width' },
  ];

  /* ──────────── State ──────────── */
  let fields      = [];
  let activeField = -1;
  let dragSrc     = -1;

  /* ──────────── DOM refs ──────────── */
  let $mount, $hiddenJson;

  /* ═══════════════════════════════════════════════
   *  Init
   * ═══════════════════════════════════════════════ */
  function init() {
    $mount     = $('#bztimg-form-builder');
    $hiddenJson = $('#form_fields_json');

    if ($mount.length && $hiddenJson.length) {
      try { fields = JSON.parse($hiddenJson.val().trim()) || []; } catch(e) { fields = []; }
      if (!Array.isArray(fields)) fields = [];
      render();
      syncJSON();
    }

    // Thumbnail upload
    $('#bztimg-upload-thumbnail').on('click', openMediaPicker);

    // Import button
    $('#bztimg-import-btn').on('click', triggerImport);

    // Auto-slug from title
    $('#title').on('blur', function () {
      const $slug = $('#slug');
      if (!$slug.val()) $slug.val(slugify($(this).val()));
    });

    // Sync hidden on form submit
    $('.bztimg-tpl-form').on('submit', function () { syncJSON(); });
  }

  /* ═══════════════════════════════════════════════
   *  Full render
   * ═══════════════════════════════════════════════ */
  function render() {
    let html  = '<div class="fb-layout">';
    html     += '<div class="fb-layout__builder"><div class="fb-container">';
    html     += renderPalette();
    html     += renderCanvas();
    html     += '</div></div>';
    html     += '<div class="fb-layout__preview"><div class="fb-preview-wrap">';
    html     += '<div class="fb-preview-header">'
              + '<span class="fb-preview-header__icon">👁️</span>'
              + '<span class="fb-preview-header__title">Live Preview</span>'
              + '<span class="fb-preview-header__badge">Frontend</span>'
              + '</div>';
    html     += '<div class="fb-preview" id="bztfbPreview"></div>';
    html     += '</div></div>';
    html     += '</div>';

    $mount.html(html);
    bindEvents();
    renderPreview();
  }

  /* ──────────── Palette ──────────── */
  function renderPalette() {
    const groups = [
      { key: 'input',  title: 'Trường nhập liệu',    layout: false },
      { key: 'choice', title: 'Lựa chọn',             layout: true  },
      { key: 'image',  title: 'Image Tool',           layout: true  },
      { key: 'layout', title: 'Bố cục',               layout: true  },
    ];
    let h = '<div class="fb-palette">';
    groups.forEach(function (g) {
      h += '<p class="fb-palette__title' + (g.layout ? ' fb-palette__title--layout' : '') + '">' + g.title + '</p>';
      h += '<div class="fb-palette__grid">';
      for (const [type, def] of Object.entries(FIELD_TYPES)) {
        if (def.group !== g.key) continue;
        h += '<div class="fb-palette__item' + (g.layout ? ' fb-palette__item--layout' : '') + '" data-type="' + type + '">'
           + '<span class="fb-palette__icon">' + def.icon + '</span>' + def.label
           + '</div>';
      }
      h += '</div>';
    });
    h += '</div>';
    return h;
  }

  /* ──────────── Canvas ──────────── */
  function renderCanvas() {
    let h = '<div class="fb-canvas">';
    h += '<div class="fb-canvas__header">';
    h += '<h3 class="fb-canvas__title">Form Fields <span class="fb-canvas__count">' + fields.length + '</span></h3>';
    h += '<div class="fb-canvas__actions">'
       + '<button type="button" class="fb-canvas__btn" data-action="collapse-all">Thu gọn</button>'
       + '</div>';
    h += '</div>';
    h += '<div class="fb-fields" id="bztfbFields">';
    if (fields.length === 0) {
      h += '<div class="fb-fields--empty">'
         + '<span class="fb-fields--empty__icon">📋</span>'
         + '<span>Chưa có trường. Bấm palette bên trái để thêm.</span>'
         + '</div>';
    } else {
      fields.forEach(function (f, i) { h += renderFieldCard(f, i); });
    }
    h += '</div></div>';
    return h;
  }

  /* ──────────── Field card ──────────── */
  function renderFieldCard(f, i) {
    const def       = FIELD_TYPES[f.type] || { icon: '📄', label: f.type, group: 'input' };
    const isLayout  = (def.group === 'layout' || def.group === 'image');
    const isActive  = (i === activeField);
    const reqBadge  = f.required ? '<span class="fb-field__required">*</span>' : '';

    let h = '<div class="fb-field'
           + (isActive  ? ' fb-field--active'  : '')
           + (isLayout  ? ' fb-field--layout'  : '')
           + '" data-index="' + i + '" draggable="true">';

    h += '<div class="fb-field__header">'
       + '<span class="fb-field__drag">⠿</span>'
       + '<span class="fb-field__type-icon">' + def.icon + '</span>'
       + '<div class="fb-field__info">'
       + '<div class="fb-field__label">' + esc(f.label) + ' ' + reqBadge + '</div>'
       + '<div class="fb-field__meta">'
       + '<span>' + def.label + '</span><span>' + esc(f.slug) + '</span>'
       + '<span>' + (f.grid === 'half' ? '½' : '1/1') + '</span>'
       + '</div></div>'
       + '<div class="fb-field__actions">'
       + '<button type="button" class="fb-field__btn fb-field__btn--duplicate" data-action="duplicate" data-index="' + i + '" title="Nhân bản">📋</button>'
       + '<button type="button" class="fb-field__btn fb-field__btn--delete" data-action="delete" data-index="' + i + '" title="Xóa">🗑️</button>'
       + '</div></div>';

    h += '<div class="fb-field__editor">' + renderEditor(f, i, def) + '</div>';
    h += '</div>';
    return h;
  }

  /* ──────────── Inline editor ──────────── */
  function renderEditor(f, i, def) {
    let h = '';

    // Label + Slug (always)
    h += '<div class="fb-editor-row">'
       + '<div class="fb-editor-group"><label>Label</label><input type="text" data-field="label" data-index="' + i + '" value="' + esc(f.label) + '"></div>'
       + '<div class="fb-editor-group"><label>Slug</label><input type="text" data-field="slug" data-index="' + i + '" value="' + esc(f.slug) + '"></div>'
       + '</div>';

    // Type + Grid
    let typeOpts = '';
    for (const [t, d] of Object.entries(FIELD_TYPES)) {
      typeOpts += '<option value="' + t + '"' + (t === f.type ? ' selected' : '') + '>' + d.icon + ' ' + d.label + '</option>';
    }
    let gridOpts = GRID_OPTIONS.map(function (g) {
      return '<option value="' + g.value + '"' + (g.value === f.grid ? ' selected' : '') + '>' + g.label + '</option>';
    }).join('');

    h += '<div class="fb-editor-row">'
       + '<div class="fb-editor-group"><label>Type</label><select data-field="type" data-index="' + i + '">' + typeOpts + '</select></div>'
       + '<div class="fb-editor-group"><label>Grid</label><select data-field="grid" data-index="' + i + '">' + gridOpts + '</select></div>'
       + '</div>';

    // Type-specific extra fields
    if (f.type === 'heading') {
      h += '<div class="fb-editor-row fb-editor-row--full"><div class="fb-editor-group"><label>Mô tả <small>(hiển thị bên dưới)</small></label>'
         + '<input type="text" data-field="description" data-index="' + i + '" value="' + esc(f.description || '') + '" placeholder="Mô tả ngắn..."></div></div>';

    } else if (f.type === 'model_picker') {
      h += '<div class="fb-editor-row fb-editor-row--full"><div class="fb-editor-group"><label>Help text</label>'
         + '<input type="text" data-field="help" data-index="' + i + '" value="' + esc(f.help || '') + '" placeholder="Hướng dẫn cho user..."></div></div>';
      h += '<div class="fb-toggle-row">'
         + '<label class="fb-toggle"><input type="checkbox" data-field="allow_custom_upload" data-index="' + i + '"' + (f.allow_custom_upload ? ' checked' : '') + '> Cho phép upload ảnh mẫu riêng</label>'
         + '<label class="fb-toggle"><input type="checkbox" data-field="required" data-index="' + i + '"' + (f.required ? ' checked' : '') + '> Bắt buộc</label>'
         + '</div>';

    } else if (f.type === 'image_upload') {
      h += '<div class="fb-editor-row fb-editor-row--full"><div class="fb-editor-group"><label>Help text</label>'
         + '<input type="text" data-field="help" data-index="' + i + '" value="' + esc(f.help || '') + '" placeholder="VD: Upload ảnh đã xóa nền..."></div></div>';
      h += '<div class="fb-toggle-row"><label class="fb-toggle"><input type="checkbox" data-field="required" data-index="' + i + '"' + (f.required ? ' checked' : '') + '> Bắt buộc</label></div>';

    } else if (f.type === 'multi_reference_images') {
      h += '<div class="fb-editor-row">'
         + '<div class="fb-editor-group"><label>Help text</label><input type="text" data-field="help" data-index="' + i + '" value="' + esc(f.help || '') + '"></div>'
         + '<div class="fb-editor-group"><label>Max ảnh</label><input type="number" data-field="max_images" data-index="' + i + '" value="' + (f.max_images || 3) + '" min="1" max="5"></div>'
         + '</div>';

    } else if (def.group === 'input' || def.group === 'choice') {
      h += '<div class="fb-editor-row">'
         + '<div class="fb-editor-group"><label>Placeholder</label><input type="text" data-field="placeholder" data-index="' + i + '" value="' + esc(f.placeholder || '') + '"></div>'
         + '<div class="fb-editor-group"><label>Help text</label><input type="text" data-field="help" data-index="' + i + '" value="' + esc(f.help || '') + '"></div>'
         + '</div>';
      h += '<div class="fb-toggle-row"><label class="fb-toggle"><input type="checkbox" data-field="required" data-index="' + i + '"' + (f.required ? ' checked' : '') + '> Bắt buộc</label></div>';
    }

    // Options editor
    if (def.hasOptions || f.type === 'size_picker') {
      h += renderOptionsEditor(f, i);
    }

    return h;
  }

  /* ──────────── Options editor ──────────── */
  function renderOptionsEditor(f, i) {
    const opts         = f.options || [];
    const hasIcon      = (f.type === 'card_radio' || f.type === 'quick_suggest' || f.type === 'size_picker');
    const hasPrompt    = (f.type === 'card_radio');
    let h = '<div class="fb-options"><div class="fb-options__title">Tùy chọn</div><div class="fb-options__list">';
    opts.forEach(function (opt, oi) {
      h += '<div class="fb-option-row bztfb-opt-row" data-field-index="' + i + '" data-opt-index="' + oi + '">';
      if (hasIcon) h += '<input type="text" data-role="opt-icon" placeholder="Icon" value="' + esc(opt.icon || '') + '" style="width:50px;">';
      h += '<input type="text" data-role="opt-value" placeholder="Giá trị" value="' + esc(opt.value || '') + '">';
      h += '<input type="text" data-role="opt-label" placeholder="Nhãn" value="' + esc(opt.label || '') + '">';
      if (hasPrompt) h += '<input type="text" data-role="opt-prompt" placeholder="Prompt hint" value="' + esc(opt.prompt_hint || '') + '" style="flex:2;">';
      h += '<button type="button" class="fb-option-row__btn" data-action="remove-opt" data-field-index="' + i + '" data-opt-index="' + oi + '">✕</button>';
      h += '</div>';
    });
    h += '</div>';
    h += '<button type="button" class="fb-options__add" data-action="add-opt" data-index="' + i + '">＋ Thêm tùy chọn</button>';
    h += '</div>';
    return h;
  }

  /* ═══════════════════════════════════════════════
   *  Events
   * ═══════════════════════════════════════════════ */
  function bindEvents() {
    const $flds = $('#bztfbFields');

    // Palette → add field
    $mount.find('.fb-palette__item').on('click', function () {
      addField($(this).data('type'));
    });

    // Canvas actions
    $mount.on('click', '[data-action="collapse-all"]', function () {
      activeField = -1; render();
    });

    $mount.on('click', '[data-action="delete"]', function (e) {
      e.stopPropagation();
      const idx = parseInt($(this).data('index'));
      fields.splice(idx, 1);
      if (activeField >= fields.length) activeField = -1;
      render(); syncJSON();
    });

    $mount.on('click', '[data-action="duplicate"]', function (e) {
      e.stopPropagation();
      const idx  = parseInt($(this).data('index'));
      const copy = JSON.parse(JSON.stringify(fields[idx]));
      copy.slug  = copy.slug + '_copy';
      fields.splice(idx + 1, 0, copy);
      activeField = idx + 1;
      render(); syncJSON();
    });

    // Field header → toggle editor
    $mount.on('click', '.fb-field__header', function (e) {
      if ($(e.target).closest('.fb-field__actions').length) return;
      const idx   = parseInt($(this).closest('.fb-field').data('index'));
      activeField = (activeField === idx) ? -1 : idx;
      render();
    });

    // Editor field changes → live sync
    $mount.on('change input', '.fb-field__editor [data-field]', function () {
      const fi  = parseInt($(this).data('index'));
      const key = $(this).data('field');
      if (fi < 0 || fi >= fields.length) return;
      if ($(this).is(':checkbox')) {
        fields[fi][key] = $(this).is(':checked');
      } else if (key === 'max_images') {
        fields[fi][key] = Math.max(1, parseInt($(this).val()) || 3);
      } else {
        fields[fi][key] = $(this).val();
      }
      syncJSON();
      renderPreview();
    });

    // Options: add
    $mount.on('click', '[data-action="add-opt"]', function () {
      const fi = parseInt($(this).data('index'));
      if (!fields[fi]) return;
      if (!fields[fi].options) fields[fi].options = [];
      const n  = fields[fi].options.length + 1;
      fields[fi].options.push({ value: 'opt' + n, label: 'Tùy chọn ' + n, icon: '' });
      activeField = fi;
      render(); syncJSON();
    });

    // Options: remove
    $mount.on('click', '[data-action="remove-opt"]', function () {
      const fi = parseInt($(this).data('field-index'));
      const oi = parseInt($(this).data('opt-index'));
      if (!fields[fi]) return;
      fields[fi].options.splice(oi, 1);
      activeField = fi;
      render(); syncJSON();
    });

    // Options: edit inline
    $mount.on('change input', '.bztfb-opt-row [data-role]', function () {
      const fi   = parseInt($(this).closest('.bztfb-opt-row').data('field-index'));
      const oi   = parseInt($(this).closest('.bztfb-opt-row').data('opt-index'));
      const role = $(this).data('role');
      if (!fields[fi] || !fields[fi].options[oi]) return;
      const roleMap = { 'opt-value': 'value', 'opt-label': 'label', 'opt-icon': 'icon', 'opt-prompt': 'prompt_hint' };
      if (roleMap[role]) fields[fi].options[oi][roleMap[role]] = $(this).val();
      syncJSON();
    });

    // Drag-drop reorder
    $flds.on('dragstart', '.fb-field', function (e) {
      dragSrc = parseInt($(this).data('index'));
      e.originalEvent.dataTransfer.effectAllowed = 'move';
    });
    $flds.on('dragover', '.fb-field', function (e) {
      e.preventDefault();
      $(this).addClass('fb-field--dragover');
    });
    $flds.on('dragleave', '.fb-field', function () {
      $(this).removeClass('fb-field--dragover');
    });
    $flds.on('drop', '.fb-field', function (e) {
      e.preventDefault();
      $(this).removeClass('fb-field--dragover');
      const dest = parseInt($(this).data('index'));
      if (dragSrc < 0 || dragSrc === dest) return;
      const moved = fields.splice(dragSrc, 1)[0];
      fields.splice(dest, 0, moved);
      activeField = dest;
      render(); syncJSON();
    });
  }

  /* ═══════════════════════════════════════════════
   *  Add field to list
   * ═══════════════════════════════════════════════ */
  function addField(type) {
    const def   = FIELD_TYPES[type] || FIELD_TYPES.text;
    const idx   = fields.length + 1;
    const slug  = type + '_' + idx;

    const field = {
      slug, type,
      label:       def.label + ' ' + idx,
      placeholder: '',
      required:    false,
      grid:        'full',
      sort_order:  fields.length,
      help:        '',
    };

    // Type defaults
    if (def.hasOptions || type === 'size_picker') {
      if (type === 'card_radio') {
        field.options = [
          { value: 'opt1', label: '🎯 Tùy chọn 1', icon: '🎯', prompt_hint: '' },
          { value: 'opt2', label: '📦 Tùy chọn 2', icon: '📦', prompt_hint: '' },
        ];
      } else if (type === 'quick_suggest') {
        field.options = [
          { value: 'opt1', label: '🎯 Gợi ý 1', icon: '🎯' },
          { value: 'opt2', label: '📦 Gợi ý 2', icon: '📦' },
        ];
      } else if (type === 'size_picker') {
        field.options = [
          { value: '1024x1536', label: '2:3 Dọc',    icon: '📱', recommended: true },
          { value: '1024x1024', label: '1:1 Vuông',  icon: '⬛' },
          { value: '768x1344',  label: '9:16 Story', icon: '📲' },
          { value: '1536x1024', label: '3:2 Ngang',  icon: '🖥️' },
        ];
      } else {
        field.options = [
          { value: 'opt1', label: 'Tùy chọn 1' },
          { value: 'opt2', label: 'Tùy chọn 2' },
        ];
      }
    }

    if (type === 'model_picker') {
      field.label              = 'Chọn mẫu người';
      field.slug               = 'model_template_id';
      field.help               = 'Chọn model (tối đa 5 mẫu)';
      field.allow_custom_upload = false;
      field.required           = true;
    }
    if (type === 'image_upload') {
      field.label    = 'Upload ảnh';
      field.help     = 'Upload ảnh sản phẩm. Nên dùng ảnh đã xóa nền.';
      field.required = true;
    }
    if (type === 'multi_reference_images') {
      field.label       = 'Ảnh tham khảo bổ sung';
      field.help        = 'Ghép nhiều ảnh tham khảo để AI kết hợp';
      field.max_images  = 3;
      field.image_roles = [
        { slug: 'model_ref', label: 'Mẫu người',    icon: '👤', help: '' },
        { slug: 'style_ref', label: 'Phong cách',   icon: '📸', help: '' },
        { slug: 'scene_ref', label: 'Bối cảnh',     icon: '🌿', help: '' },
      ];
    }
    if (type === 'heading') {
      field.label       = 'Section ' + idx;
      field.description = '';
    }

    fields.push(field);
    activeField = fields.length - 1;
    render();
    syncJSON();
  }

  /* ═══════════════════════════════════════════════
   *  Live Preview
   * ═══════════════════════════════════════════════ */
  function renderPreview() {
    const $p = $('#bztfbPreview');
    if (!$p.length) return;

    let h = '<div class="bztfb-preview-fields">';

    fields.forEach(function (f) {
      const def     = FIELD_TYPES[f.type] || {};
      const reqStar = f.required ? '<span class="bztfb-req">*</span>' : '';

      if (f.type === 'heading') {
        h += '<div class="bztfb-heading">' + esc(f.label) + '</div>';

      } else if (f.type === 'image_upload') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>'
           + '<div class="bztfb-dz">🖼️ Chọn ảnh</div></div>';

      } else if (f.type === 'multi_reference_images') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + '</label>'
           + '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;">';
        (f.image_roles || []).forEach(function (r) {
          h += '<div style="border:1.5px dashed #e2e8f0;border-radius:7px;padding:10px 4px;text-align:center;font-size:10px;color:#94a3b8;">'
             + '<div style="font-size:20px;">' + esc(r.icon || '📸') + '</div>' + esc(r.label) + '</div>';
        });
        h += '</div></div>';

      } else if (f.type === 'model_picker') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>';
        h += '<div class="bztfb-model-grid">';
        for (let k = 0; k < 3; k++) {
          h += '<div class="bztfb-model-slot"><div class="bztfb-model-slot-ico">🧑</div>Model</div>';
        }
        h += '</div></div>';

      } else if (f.type === 'card_radio') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>';
        h += '<div class="bztfb-cards">';
        (f.options || []).slice(0, 4).forEach(function (opt) {
          h += '<div class="bztfb-card">'
             + '<div class="bztfb-card-icon">' + esc(opt.icon || '📌') + '</div>'
             + esc(opt.label) + '</div>';
        });
        h += '</div></div>';

      } else if (f.type === 'quick_suggest' || f.type === 'size_picker') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>';
        h += '<div class="bztfb-pills">';
        (f.options || []).slice(0, 5).forEach(function (opt, k) {
          const sel = (k === 0) ? 'background:#ede9fe;border-color:#6366f1;color:#4c1d95;' : '';
          h += '<span class="bztfb-pill" style="' + sel + '">' + esc((opt.icon ? opt.icon + ' ' : '') + opt.label) + '</span>';
        });
        h += '</div></div>';

      } else if (f.type === 'textarea') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>'
           + '<textarea class="bztfb-inp" rows="2" placeholder="' + esc(f.placeholder || '') + '" disabled></textarea></div>';

      } else if (f.type === 'select') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>'
           + '<select class="bztfb-inp" disabled><option>' + esc(f.placeholder || '— Chọn —') + '</option>'
           + (f.options || []).map(function (o) { return '<option>' + esc(o.label) + '</option>'; }).join('')
           + '</select></div>';

      } else if (f.type === 'radio') {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>';
        (f.options || []).forEach(function (opt) {
          h += '<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:default;">'
             + '<input type="radio" disabled>' + esc(opt.label) + '</label>';
        });
        h += '</div>';

      } else {
        h += '<div class="bztfb-f"><label class="bztfb-lbl">' + esc(f.label) + reqStar + '</label>'
           + '<input type="' + (f.type === 'number' ? 'number' : 'text') + '" class="bztfb-inp" '
           + 'placeholder="' + esc(f.placeholder || '') + '" disabled></div>';
      }
    });

    h += '</div>';
    $p.html(h);
  }

  /* ═══════════════════════════════════════════════
   *  Sync JSON to hidden input
   * ═══════════════════════════════════════════════ */
  function syncJSON() {
    $hiddenJson.val(JSON.stringify(fields));
  }

  /* ═══════════════════════════════════════════════
   *  Thumbnail Upload
   * ═══════════════════════════════════════════════ */
  function openMediaPicker() {
    if (typeof wp === 'undefined' || !wp.media) return;
    const frame = wp.media({
      title: 'Chọn ảnh thumbnail',
      multiple: false,
      library: { type: 'image' },
    });
    frame.on('select', function () {
      const att = frame.state().get('selection').first().toJSON();
      $('#thumbnail_url').val(att.url);
      $('#bztimg-thumbnail-preview').html(
        '<img src="' + att.url + '" style="max-width:100%;border-radius:8px;" />'
      );
    });
    frame.open();
  }

  /* ═══════════════════════════════════════════════
   *  Import JSON
   * ═══════════════════════════════════════════════ */
  function triggerImport() {
    const jsonText = $('#bztimg-import-json').val ? $('#bztimg-import-json').val().trim() : '';
    if (!jsonText) {
      // Fallback: file picker
      const input = document.createElement('input');
      input.type = 'file';
      input.accept = '.json,application/json';
      input.onchange = function (e) {
        const f = e.target.files[0];
        if (!f) return;
        const reader = new FileReader();
        reader.onload = function (ev) {
          doImportData(ev.target.result);
        };
        reader.readAsText(f);
      };
      input.click();
      return;
    }
    doImportData(jsonText);
  }

  function doImport() {
    const jsonText = $('#bztimg-import-json').val().trim();
    if (!jsonText) return alert('Vui lòng paste JSON data.');
    doImportData(jsonText);
  }

  function doImportData(jsonText) {
    let data;
    try { data = JSON.parse(jsonText); } catch(e) { return alert('JSON không hợp lệ: ' + e.message); }

    // bztimg_template schema or array of templates → AJAX import
    if (Array.isArray(data) || (data._meta && data._meta.schema === 'bztimg_template')) {
      $.post(BZTIMG_TPL.ajax_url, {
        action: 'bztimg_import',
        nonce:  BZTIMG_TPL.import_nonce,
        json_data: jsonText,
      }, function (res) {
        if (res.success) {
          alert('✅ Import thành công: ' + res.data.imported + ' templates');
          location.reload();
        } else {
          alert('❌ Lỗi: ' + ((res.data && res.data.message) || 'Unknown error'));
        }
      }).fail(function (xhr) {
        alert('❌ Lỗi kết nối: ' + (xhr.statusText || 'error'));
      });
    } else if (data.form_fields || (data.template && data.template.form_fields)) {
      // Single template JSON → load form_fields into builder
      const ff = data.form_fields
        ? (typeof data.form_fields === 'string' ? JSON.parse(data.form_fields) : data.form_fields)
        : (typeof data.template.form_fields === 'string' ? JSON.parse(data.template.form_fields) : data.template.form_fields);
      if (Array.isArray(ff)) {
        fields = ff;
        render();
        syncJSON();
      }
    } else {
      alert('❌ Không nhận diện được format JSON. Cần _meta.schema=bztimg_template hoặc form_fields array.');
    }
  }

  /* ─── Helpers ─── */
  function esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function slugify(str) {
    return str.toLowerCase()
      .replace(/[àáạảãâầấậẩẫăằắặẳẵ]/g,'a').replace(/[èéẹẻẽêềếệểễ]/g,'e')
      .replace(/[ìíịỉĩ]/g,'i').replace(/[òóọỏõôồốộổỗơờớợởỡ]/g,'o')
      .replace(/[ùúụủũưừứựửữ]/g,'u').replace(/[ỳýỵỷỹ]/g,'y').replace(/đ/g,'d')
      .replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  }

  /* ─── Boot ─── */
  /* ─── Boot ─── */
  $(document).ready(init);

})(jQuery);
