(function ($) {
	"use strict";

	function safeFileName(str) {
		if (!str) return '';
		return String(str)
			.replace(/\s+/g, ' ')
			.trim()
			.replace(/[\\/:*?"<>|\u0000-\u001F]+/g, '-')
			.replace(/\.+$/g, '')
			.substring(0, 80);
	}

	function downloadTextFile(filename, text) {
		try {
			var blob = new Blob([text], { type: 'application/json;charset=utf-8' });
			var url = window.URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url;
			a.download = filename;
			a.style.display = 'none';
			document.body.appendChild(a);
			a.click();
			a.remove();
			setTimeout(function () {
				window.URL.revokeObjectURL(url);
			}, 500);
		} catch (e) {
			// Fallback: best-effort copy to clipboard using existing helper
			if (typeof window.waicCopyText === 'function') {
				window.waicCopyText(text);
				if (typeof window.waicShowAlert === 'function') {
					window.waicShowAlert('Không tải được file. JSON đã được copy vào clipboard.');
				}
			}
		}
	}

	function bootScenarioExport() {
		$(document).on('click', '.waicExportScenarioJsonBtn', function (e) {
			e.preventDefault();

			var $btn = $(this);
			var taskId = $btn.data('taskId') || $btn.data('task-id') || $btn.attr('data-task-id');
			if (!taskId) return;

			var title = $btn.data('title') || '';

			$.sendFormWaic({
				elem: $btn,
				data: {
					mod: 'workflow',
					action: 'getJSON',
					id: taskId
				},
				onSuccess: function (res) {
					if (!res || res.error) return;

					var json = res.data && res.data.json ? res.data.json : '';
					if (!json) {
						if (typeof window.waicShowAlert === 'function') {
							window.waicShowAlert('Không tìm thấy JSON để export.');
						}
						return;
					}

					var base = safeFileName(title) || ('workflow-' + taskId);
					downloadTextFile(base + '.json', json);
				}
			});
		});
	}

	$(document).ready(function () {
		bootScenarioExport();
	});

})(window.jQuery);
