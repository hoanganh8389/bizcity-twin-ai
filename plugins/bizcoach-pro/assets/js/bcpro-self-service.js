/**
 * BizCoach Pro — Self-Service Astrology Profile
 * Alpine.js component · PHASE-A A-FE-1 (2026-06-05)
 *
 * State machine:
 *   idle / list → form_create → loading → list
 *                form_edit   → loading → list
 *                chart_view
 */
function bcproSelfService() {
    return {
        // State
        view: 'list',       // 'list' | 'form' | 'chart'
        loading: true,
        saving: false,
        generating: null,   // coachee_id being generated
        error: null,
        copyToast: false,

        // Data
        profiles: [],
        editing: null,      // coachee_id being edited (null = create)
        currentProfile: null,
        chartSummaryHtml: '',

        // Form model
        form: {
            full_name: '',
            dob: '',
            birth_time: '',
            birth_place: '',
            chart_type: 'western',
            phone: '',
        },

        /* ── Init ─────────────────────────────────────────── */
        async init() {
            await this.loadProfiles();
        },

        /* ── API helpers ──────────────────────────────────── */
        async apiFetch(path, options) {
            const url = (window.bcproSS ? window.bcproSS.restBase : '/wp-json/bizcity-bizcoach/v1/me') + path;
            const nonce = window.bcproSS ? window.bcproSS.nonce : '';
            const defaults = {
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
            };
            const merged = Object.assign({}, defaults, options || {});
            if (merged.body && typeof merged.body === 'object') {
                merged.body = JSON.stringify(merged.body);
            }
            try {
                const resp = await fetch(url, merged);
                const data = await resp.json();
                if (!resp.ok || !data.success) {
                    return { ok: false, error: data };
                }
                return { ok: true, data };
            } catch (e) {
                return { ok: false, error: { message: 'Lỗi kết nối: ' + e.message, hint: 'Kiểm tra mạng và thử lại.' } };
            }
        },

        /* ── Load profiles ────────────────────────────────── */
        async loadProfiles() {
            this.loading = true;
            this.error = null;
            const result = await this.apiFetch('/profiles', { method: 'GET' });
            this.loading = false;
            if (result.ok) {
                this.profiles = result.data.profiles || [];
            } else {
                this.error = {
                    message: (result.error && result.error.message) ? result.error.message : 'Không thể tải hồ sơ.',
                    hint: 'Thử tải lại trang.',
                };
            }
        },

        /* ── Navigation ───────────────────────────────────── */
        goList() {
            this.view = 'list';
            this.editing = null;
            this.currentProfile = null;
            this.chartSummaryHtml = '';
            this.error = null;
            this.resetForm();
        },

        openCreate() {
            this.resetForm();
            this.editing = null;
            this.view = 'form';
        },

        openEdit(profile) {
            this.form.full_name   = profile.full_name   || '';
            this.form.dob         = profile.dob         || '';
            this.form.birth_time  = profile.birth_time  || '';
            this.form.birth_place = profile.birth_place || '';
            this.form.chart_type  = profile.chart_type  || 'western';
            this.form.phone       = profile.phone        || '';
            this.editing          = profile.coachee_id;
            this.view             = 'form';
        },

        viewChart(profile) {
            this.currentProfile = profile;
            // Render summary as simple paragraphs (llm_report not yet fetched here,
            // use chart summary as placeholder — extend in Sprint B)
            this.chartSummaryHtml = '<p><em>Biểu đồ đã được tạo. Nhấn "Mở trang chia sẻ" để xem chi tiết.</em></p>';
            this.view = 'chart';
        },

        /* ── Submit form (create or edit) ─────────────────── */
        async submitForm() {
            this.saving = true;
            this.error = null;

            let result;
            if (this.editing) {
                result = await this.apiFetch('/profiles/' + this.editing, {
                    method: 'PATCH',
                    body: this.form,
                });
            } else {
                result = await this.apiFetch('/profiles', {
                    method: 'POST',
                    body: this.form,
                });
            }

            this.saving = false;
            if (result.ok) {
                await this.loadProfiles();
                this.goList();
            } else {
                this.error = {
                    message: (result.error && result.error.message) ? result.error.message : 'Lỗi khi lưu hồ sơ.',
                    hint: (result.error && result.error.data && result.error.data.hint) ? result.error.data.hint : 'Kiểm tra lại thông tin và thử lại.',
                };
            }
        },

        /* ── Delete ───────────────────────────────────────── */
        async confirmDelete(profile) {
            if (!confirm('Xóa hồ sơ "' + (profile.full_name || 'này') + '"?\nHành động này không thể khôi phục.')) {
                return;
            }
            this.error = null;
            const result = await this.apiFetch('/profiles/' + profile.coachee_id, { method: 'DELETE' });
            if (result.ok) {
                await this.loadProfiles();
            } else {
                this.error = {
                    message: 'Không thể xóa hồ sơ.',
                    hint: 'Thử lại hoặc liên hệ quản trị viên.',
                };
            }
        },

        /* ── Generate chart ───────────────────────────────── */
        async generateChart(profile) {
            this.generating = profile.coachee_id;
            this.error = null;
            const result = await this.apiFetch('/profiles/' + profile.coachee_id + '/generate-chart', {
                method: 'POST',
                body: { chart_type: profile.chart_type || 'western' },
            });
            this.generating = null;
            if (result.ok) {
                await this.loadProfiles();
                // Find updated profile and open chart view
                const updated = this.profiles.find(
                    p => p.coachee_id === profile.coachee_id && p.chart_type === profile.chart_type
                );
                if (updated) {
                    this.viewChart(updated);
                }
            } else {
                this.error = {
                    message: (result.error && result.error.message) ? result.error.message : 'Lỗi tạo biểu đồ.',
                    hint: 'Thử lại sau vài phút.',
                };
            }
        },

        /* ── Share link ───────────────────────────────────── */
        async copyShare(profile) {
            if (!profile) { return; }
            let url = profile.share_url || '';
            if (!url) {
                const result = await this.apiFetch('/profiles/' + profile.coachee_id + '/share-link?chart_type=' + (profile.chart_type || 'western'));
                if (result.ok) {
                    url = result.data.url || '';
                    // Update in profiles list
                    const idx = this.profiles.findIndex(p => p.coachee_id === profile.coachee_id && p.chart_type === profile.chart_type);
                    if (idx > -1) {
                        this.profiles[idx].share_url = url;
                    }
                }
            }
            if (url && navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {
                    this.copyToast = true;
                    setTimeout(() => { this.copyToast = false; }, 2500);
                });
            }
        },

        /* ── Helpers ──────────────────────────────────────── */
        resetForm() {
            this.form = { full_name: '', dob: '', birth_time: '', birth_place: '', chart_type: 'western', phone: '' };
        },

        chartLabel(type) {
            const map = { western: '🌟 Western', vedic: '🕉️ Vedic', chinese: '☯️ BaZi' };
            return map[type] || type;
        },
    };
}
