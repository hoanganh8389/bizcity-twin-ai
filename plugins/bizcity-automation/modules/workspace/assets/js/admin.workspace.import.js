(function ($, app) {
	"use strict";

	function getTitleFromFilename(filename) {
		if (!filename) return '';
		return filename.replace(/\.[^.]+$/, '');
	}

	function WorkspaceImport() {
		this.$obj = this;
		return this.$obj;
	}

	WorkspaceImport.prototype.init = function () {
		var _this = this.$obj;
		_this.$btn = $('#waicImportWorkflowJsonBtn');
		_this.$file = $('#waicImportWorkflowJsonFile');

		if (!_this.$btn.length || !_this.$file.length) return;

		_this.$btn.on('click', function (e) {
			e.preventDefault();
			_this.$file.val('');
			_this.$file.trigger('click');
		});

		_this.$file.on('change', function () {
			var file = this.files && this.files[0] ? this.files[0] : null;
			if (!file) return;

			var reader = new FileReader();
			reader.onload = function (ev) {
				var text = (ev && ev.target) ? ev.target.result : '';
				if (!text || typeof text !== 'string') {
					waicShowAlert('Invalid file content.');
					return;
				}

				// Quick validate JSON format (server will validate again)
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
					elem: _this.$btn,
					data: {
						mod: 'workflow',
						action: 'importWorkflowJson',
						title: title,
						json: text,
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
		});
	};

	app.waicWorkspaceImport = new WorkspaceImport();
	$(document).ready(function () {
		app.waicWorkspaceImport.init();
	});

})(window.jQuery, window);
