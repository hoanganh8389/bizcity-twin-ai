/**
 * BizCity Code Builder — Admin JS
 */
(function () {
	'use strict';

	const config = window.bzcode || {};
	const API    = config.rest_url || '/wp-json/bzcode/v1';
	const nonce  = config.nonce || '';

	async function loadProjects() {
		const tbody = document.querySelector('#bzcode-projects-table tbody');
		if (!tbody) return;

		try {
			const res = await fetch(`${API}/projects`, {
				headers: { 'X-WP-Nonce': nonce },
			});
			const json = await res.json();
			if (!json.ok || !json.data.length) {
				tbody.innerHTML = '<tr><td colspan="5">Chưa có dự án nào.</td></tr>';
				return;
			}

			const stackLabels = {
				html_tailwind: 'HTML + Tailwind',
				html_css: 'HTML + CSS',
				react_tailwind: 'React + Tailwind',
				vue_tailwind: 'Vue + Tailwind',
				bootstrap: 'Bootstrap 5',
			};

			tbody.innerHTML = json.data.map(p => `
				<tr>
					<td><a href="${config.editor_url || '/tool-code/'}project/${p.id}/"><strong>${escapeHtml(p.title || 'Untitled')}</strong></a></td>
					<td>${stackLabels[p.stack] || p.stack}</td>
					<td>${p.status}</td>
					<td>${p.updated_at}</td>
					<td>
						<a href="${config.editor_url || '/tool-code/'}project/${p.id}/" class="button">Edit</a>
						<button class="button" onclick="window.__bzcode_deleteProject(${p.id})">Delete</button>
					</td>
				</tr>
			`).join('');
		} catch (err) {
			tbody.innerHTML = '<tr><td colspan="5">Lỗi tải dữ liệu.</td></tr>';
		}
	}

	window.__bzcode_deleteProject = async function (id) {
		if (!confirm('Xóa dự án này?')) return;
		await fetch(`${API}/project/${id}`, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': nonce },
		});
		loadProjects();
	};

	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}

	document.addEventListener('DOMContentLoaded', loadProjects);
})();
