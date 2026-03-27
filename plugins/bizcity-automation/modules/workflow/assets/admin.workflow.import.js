(function ($, app) {
	"use strict";

	function getTitleFromFilename(filename) {
		if (!filename) return '';
		return String(filename).replace(/\.[^.]+$/, '');
	}

	function ensureFileInput() {
		var $file = $('#waicImportWorkflowJsonFile');
		if ($file.length) return $file;
		// Fallback: create one if theme/template removed it
		$file = $('<input/>', {
			type: 'file',
			id: 'waicImportWorkflowJsonFile',
			accept: 'application/json,.json',
			style: 'display:none;'
		});
		$('body').append($file);
		return $file;
	}

	function normalizeWorkflowJsonText(text) {
		text = (text == null) ? '' : String(text);

		// Remove UTF-8 BOM if present
		text = text.replace(/^\uFEFF/, '');

		// Strip ```json fences if any
		var fence = text.match(/```(?:json)?\s*([\s\S]*?)\s*```/i);
		if (fence && fence[1]) {
			text = fence[1];
		}

		// Remove leading line comments (//...) and leading block comments (/*...*/)
		// (Only at the beginning to avoid breaking valid JSON content inside strings)
		text = text.replace(/^\s*(?:\/\/[^\n]*\r?\n)+/g, '');
		text = text.replace(/^\s*\/\*[\s\S]*?\*\/\s*/g, '');

		// Trim again
		text = text.trim();

		// If still has noise before JSON, cut from first { or [
		var iObj = text.indexOf('{');
		var iArr = text.indexOf('[');
		var start = -1;
		if (iObj !== -1 && iArr !== -1) start = Math.min(iObj, iArr);
		else start = (iObj !== -1) ? iObj : iArr;

		if (start > 0) {
			text = text.slice(start).trim();
		}

		return text;
	}

	function importJsonFromFile(file, $btn) {
		if (!file) return;

		var reader = new FileReader();
		reader.onload = function (ev) {
			var text = (ev && ev.target) ? ev.target.result : '';
			if (!text || typeof text !== 'string') {
				waicShowAlert('Invalid file content.');
				return;
			}

			text = normalizeWorkflowJsonText(text);

			var parsed;
			try {
				parsed = JSON.parse(text);
			} catch (err) {
				waicShowAlert('Invalid JSON. Please check your file.');
				return;
			}

			if (!parsed || typeof parsed !== 'object' || !parsed.nodes || !Array.isArray(parsed.nodes)) {
				waicShowAlert('Invalid workflow JSON: missing nodes[].');
				return;
			}

			var title = getTitleFromFilename(file.name) || 'Imported Workflow';

			$.sendFormWaic({
				elem: $btn && $btn.length ? $btn : null,
				data: {
					mod: 'workflow',
					action: 'importWorkflowJson',
					title: title,
					json: text, // CHANGED: send normalized JSON
					run: 1
				},
				onSuccess: function (res) {
					if (!res || res.error) return;
					if (res.data && res.data.taskUrl) {
						window.location.href = res.data.taskUrl;
					}
				}
			});
		};
		reader.readAsText(file);
	}

	function bootImportButton() {
		// Delegated handlers to work even if tabs/content are re-rendered
		$(document).on('click', '#waicImportWorkflowJsonBtn', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var $file = ensureFileInput();
			$file.data('waic-btn', $btn);
			$file.val('');
			$file.trigger('click');
		});

		$(document).on('change', '#waicImportWorkflowJsonFile', function () {
			var file = this.files && this.files[0] ? this.files[0] : null;
			var $btn = $(this).data('waic-btn');
			importJsonFromFile(file, $btn);
		});
	}

	$(document).ready(function () {
		bootImportButton();
	});

})(window.jQuery, window);
