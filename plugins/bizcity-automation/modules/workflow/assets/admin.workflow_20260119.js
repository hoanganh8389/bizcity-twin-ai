(function($, app) {
	"use strict";
	function WorkflowPage() {
		this.$obj = this;
		return this.$obj;
	}
	
	WorkflowPage.prototype.init = function () {
		var _this = this.$obj;
		_this.isPro = WAIC_DATA.isPro == '1';
		_this.actionTaskIds = false;
		_this.activeButton = false;
		_this.templatesList = $('#waicTemplatesList');
		_this.langSettings = waicParseJSON($('#waicLangSettingsJson').val());
		_this.integStatuses = waicParseJSON($('#waicIntegStatuses').val());
		_this.waicHistoryTable = $('#waicHistoryList');
		_this.integrationsList = $('.waic-section-integrations');
		_this.integrationTemplates = $('.wbw-body-intagrations .wbw-template');
		_this.integSettingsDialog = false;
		_this.currentIntegration = false;
		_this.currentAccountNum = -1;
		_this.currentAccountData = {};
		_this.integSaving = false;
		
		_this.eventsWorkflowPage();
		_this.initHistoryTable();
		
		if (typeof(_this.initPro) == 'function') _this.initPro();
	}
	
	WorkflowPage.prototype.eventsWorkflowPage = function () {
		var _this = this.$obj;
		_this.templatesList.find('.waic-delete-template').on('click', function(e) {
			e.preventDefault();
			var id = $(this).attr('data-id');
			if (id) {
				_this.activeButton = $(this).find('i');
				waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-tmp-delete'), 'waicWorkflowPage', 'deleteTemplate', id);
			}
			return false;
		});
		
		$('#waicImportTemplate').on('click', function(e) {
			e.preventDefault();
			_this.activeButton = $(this);
			var html = '<div class="waic-popup-form"><div class="waic-popup-label">' + waicCheckSettings(_this.langSettings, 'template-name') + ' *</div><div class="waic-popup-field"><input type="text" name="name"></div><div class="waic-popup-label">' + waicCheckSettings(_this.langSettings, 'template-desc') + '</div><div class="waic-popup-field"><textarea name="desc" rows="4"></textarea></div><div class="waic-popup-label">JSON *</div><div class="waic-popup-field"><textarea name="json" rows="4"></textarea></div></div>';
			waicShowConfirm(html, 'waicWorkflowPage', 'importTemplate', false, {'fields': true});
		});
		let timer;
		$('#waicSearchTemplate').on('input', function(e) {
			clearTimeout(timer);
			timer = setTimeout(() => {
				var search = $(this).val().toLowerCase();
				if (search.length) {
					_this.templatesList.find('li:not(.wbw-ws-plh)').each(function() {
						var $block = $(this);
						if ($block.find('.wbw-ws-title').text().toLowerCase().includes(search) || $block.find('.wbw-ws-desc').text().toLowerCase().includes(search)) $block.removeClass('wbw-hidden');
						else $block.addClass('wbw-hidden');
					});
				} else _this.templatesList.find('li').removeClass('wbw-hidden');
			}, 300);
		});
		
		
		_this.integrationsList.find('.waic-section-header').on('click', function(e){
			e.preventDefault();
			if (e.target && $(e.target).is('button')) return;
			var el = $(this),
				i = el.find('.waic-section-toggle i'),
				$wrapper = el.closest('.waic-section-integrations'),
				$section = el.closest('.waic-section'),
				$options = $section.find('.waic-section-options');

			if (i.hasClass('fa-chevron-down')) {
				$wrapper.find('.waic-section-toggle i.fa-chevron-up').each(function() {
					var $this = $(this);
					$this.removeClass('fa-chevron-up').addClass('fa-chevron-down');
					$this.closest('.waic-section').find('.waic-section-options').addClass('wbw-hidden');
				});
				i.removeClass('fa-chevron-down').addClass('fa-chevron-up');
				_this.createHtmlAccounts($section);
				$options.removeClass('wbw-hidden');
			} else {
				i.removeClass('fa-chevron-up').addClass('fa-chevron-down');
				$options.addClass('wbw-hidden');
			}
		});
		_this.integrationsList.on('click', '.waic-add-integration', function(e) {
			e.preventDefault();
			_this.currentIntegration = $(this).closest('.waic-section');
			_this.currentAccountNum = -1;
			_this.currentAccountData = {};
			_this.showIntegSettingsDialog();
		});
		$('#waicCategoriesList').on('change', function(e) {
			e.preventDefault();
			var category = $(this).val() || '';
			_this.integrationsList.find('.waic-section').removeClass('wbw-hidden');
			if (category.length) {
				_this.integrationsList.find('.waic-section[data-category!="' + category + '"]').addClass('wbw-hidden');
			}
		});
		_this.integrationsList.on('click', '.waic-account-coltrol', function(e) {
			e.preventDefault();
			var $this = $(this),
				$section = $this.closest('.waic-section'),
				$accounts = $section.find('input.waic-integ-accounts'),
				accounts = waicParseJSON($accounts.val()),
				action = $this.attr('data-action'),
				num = $this.closest('.waic-integ-account').attr('data-num');
			switch (action) {
				case 'edit':
					_this.currentIntegration = $section;
					_this.currentAccountNum = num;
					_this.currentAccountData = accounts[num] || {};
					_this.showIntegSettingsDialog();
					break;
				case 'delete':
					_this.setAccountData($section, num, false);
					break;
				case 'test':
					if (accounts[num]) {
						var account = accounts[num];
						account['_status'] = 0;
						_this.setAccountData($section, num, account);
					}
					break;
			}
		});
	}
	WorkflowPage.prototype.createHtmlAccounts = function ( $section ) {
		var _this = this.$obj,
			accounts = waicParseJSON($section.find('input.waic-integ-accounts').val()),
			$list = $section.find('.waic-accounts-list').html('');
		if (accounts.length) {
			var iName = $section.find('.waic-integ-name').text(),
				$tmp = _this.integrationTemplates.find('.waic-integ-account');
			for (var num = 0; num < accounts.length; num++) {
				var account = accounts[num],
					status = waicCheckSettings(account, '_status', 0),
					$newAccount = $tmp.clone();
				$newAccount.attr('data-num', num).find('.waic-account-status').addClass('waic-account-status' + status).text(waicCheckSettings(_this.integStatuses, status));
				$newAccount.find('.waic-account-name').text(waicCheckSettings(account, 'name', iName + ' ' + (num + 1)));
				$list.append($newAccount);
			} 
		} else {
			$list.append(_this.integrationTemplates.find('.waic-no-accounts').clone());
		}
	}
	WorkflowPage.prototype.setAccountData = function ( $section, num, $data ) {
		var _this = this.$obj,
			$accounts = $section.find('input.waic-integ-accounts'),
			accounts = waicParseJSON($accounts.val()),
			code = $section.attr('data-code');
		if ($data === false) accounts.splice(num, 1);
		else if (num == -1) accounts.push($data); 
		else accounts[num] = $data;
		$accounts.val(JSON.stringify([]));

		if ($section.find('.waic-section-options').hasClass('wbw-hidden')) $section.find('.waic-section-header').trigger('click');
		$section.find('.waic-accounts-list').html(_this.integrationTemplates.find('.waic-saving-accounts').clone());
		$.sendFormWaic({
			elem: _this.activeButton,
			data: {
				mod: 'workflow', 
				action: 'saveIntegration', 
				code: code,
				accounts: accounts
			},
			onSuccess: function(res) {
				if (res.data && res.data.accounts) {
					$accounts.val(JSON.stringify(res.data.accounts));
					_this.createHtmlAccounts($section);
				}
			}
		});
	}
	WorkflowPage.prototype.showIntegSettingsDialog = function () {
		var _this = this.$obj;
		
		if (!_this.integSettingsDialog) {
			_this.integSettingsDialog = $('#waicIntegSettingsDialog').removeClass('wbw-hidden').dialog({
				modal: true,
				autoOpen: false,
				position: {my: 'center', at: 'center', of: window},
				width: 'auto',
				height: 'auto',
				maxWidth: '700px',
				dialogClass: "wbw-plugin",
				buttons: [
					{
						text: waicCheckSettings(_this.langSettings, 'btn-save'),
						class: 'wbw-button wbw-button-form wbw-button-main',
						click: function() {
							jQuery(this).dialog('close');
							var params = {},
								$section = _this.currentIntegration;
							_this.integSettingsDialog.find('.waic-dialog-form').find('input, select').each(function() {
								var $this = $(this);
								params[$this.attr('name')] = $this.val();
							});
							_this.setAccountData($section, _this.currentAccountNum, params);
						},
					},
					{
						text: waicCheckSettings(_this.langSettings, 'btn-cancel'),
						class: 'wbw-button wbw-button-form wbw-button-minor',
						click: function() {
							jQuery(this).dialog('close');
						}
					}
				],
				open: function() {
					var $section = _this.currentIntegration,
						settings = waicParseJSON($section.find('input.waic-integ-settings').val()),
						params = _this.currentAccountData,
						$form = _this.integSettingsDialog.find('.waic-dialog-form').html(''),
						parents = [];

					$.each(settings, function (key, setting) {
						var $wrapper = $('<div class="wbw-settings-form row"></div>'),
							$label = $('<div class="wbw-settings-label col-3"></div>').text(setting.label),
							$field = $('<div class="wbw-settings-fields col-9"></div>'),
							value = params[key] || setting.default || '',
							$input;
						if (setting.show) {
							$.each(setting.show, function (parent, values) {
								if (!toeInArrayWaic(parent, parents)) parents.push(parent);
								$wrapper.attr('data-parent-select', parent);
								$wrapper.attr('data-select-value', values.join(' '));
							});
						}
						switch (setting.type) {
							case 'input':
								$input = $('<input>').attr('type', 'text').attr('name', key).attr('placeholder', setting.plh || '').val(value);
								if (setting.encrypt) $input.addClass('waic-fake-password');
								if (setting.readonly) $input.attr('readonly', 'readonly');
								break;
							case 'select':
								$input = $('<select></select>').attr('name', key);
								$.each(setting.options, function (val, text) {
									var $option = $('<option></option>').attr('value', val).text(text);
									if (val === value) {
											$option.attr('selected', 'selected');
									}
									$input.append($option);
								});
								break;
							case 'button':
								$input = $('<button>').addClass('wbw-button wbw-button-small waic-button-form').text(setting.btn_label);
								$input.on('click', function(e) {
									e.preventDefault();
									if (key == 'oauth2') _this.doAuth2Connect(setting);
								});
								break;
							case 'hidden':
								if (key == 'uniq_id' && value.length == 0) {
									value = Date.now().toString(36) + '-' + Math.random().toString(36).substr(2, 5);
								}
								$input = $('<input>').attr('type', 'hidden').attr('name', key).val(value);
								
								$form.append($input);
								break;
						}
						if ('hidden' != setting.type) {
							$field.append($input);
							$wrapper.append($label).append($field);
							$form.append($wrapper);
						}
					});
					if (parents.length) {
						waicInitSettingsParents($form);
						$.each(parents, function (i, key) {
							$form.find('select[name="' + key + '"]').trigger('waic-change');
						});
					}
					var status = params['_status'] || 0;
					if (status) {
						var $wrapper = $('<div class="wbw-settings-form row"></div>'),
							$label = $('<div class="wbw-settings-label col-3"></div>').text(waicCheckSettings(_this.langSettings, 'label-status')),
							$field = $('<div class="wbw-settings-fields col-9"></div>'),
							$status = $('<div class="waic-account-status"></div>').addClass('waic-account-status' + status).text(waicCheckSettings(_this.integStatuses, status)),
							error = params['_status_error'] || '';
						$field.append($status);
						$wrapper.append($label).append($field);
						$form.append($wrapper);
						if (error.length) {
							var $wrapper = $('<div class="wbw-settings-form row"></div>').append($('<div class="wbw-settings-label col-3"></div>')).append($('<div class="wbw-settings-fields col-9 waic-account-status-error"></div>').text(error));
							$form.append($wrapper);
						}
					}
					$form.css('max-height', ($(window).height()-180) + 'px');
					$form.css('width', ($(window).width()-10) + 'px');
					$form.css('max-width', '600px');
					_this.integSettingsDialog.dialog('option', 'position', { my: 'center', at: 'center', of: window });
					$(this).parent().find('.ui-dialog-buttonset button').removeClass('ui-button ui-corner-all ui-widget');
				}
			});
		}
		_this.integSettingsDialog.dialog('open');
	}
	WorkflowPage.prototype.doAuth2Connect = function (authData) {
		var _this = this.$obj,
			mode = _this.integSettingsDialog.find('.waic-dialog-form select[name="auth_mode"]').val();
		
		_this.integSettingsDialog.find('.waic-account-status, .waic-account-status-error').addClass('wbw-hidden');
		//_this.integSettingsDialog.find('.waic-dialog-form input[name="refresh_token"]').val('');
		
		if (mode === 'proxy') {
			fetch(authData.proxy, {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					"X-AOPS-Signature": authData.signature
				},
				body: authData.body
			})
			.then(resp => resp.json())
			.then(data => {
				if (data.auth_url) {
					window.open(data.auth_url, "aops_oauth", "width=500,height=600");
				} else if (data.message && $.sNotify) {
					$.sNotify({
						'icon': 'fa fa-exclamation-circle',
						'error': true,
						'content': '<span> '+data.message+'</span>',
						'delay' : 4500
					});
				}
			});
			window.addEventListener("message", function(event) {
				if (event.data.type === "aops_token") {
					if (event.data.error && event.data.error.length) {
						if ($.sNotify) {
							$.sNotify({
								'icon': 'fa fa-exclamation-circle',
								'error': true,
								'content': '<span> '+event.data.error+'</span>',
								'delay' : 4500
							});
						}
					} else {
						_this.integSettingsDialog.find('.waic-dialog-form input[name="access_code"]').val(event.data.token_package);
					}
				}
			});
		} else {
			 var authUrl = authData.link;
			_this.integSettingsDialog.find('.waic-dialog-form').find('input, select').each(function() {
				var $this = $(this);
				authUrl = authUrl.replace('{' + $this.attr('name') + '}', encodeURIComponent($this.val()));
			});
		
			window.open(authUrl, "google_oauth", "width=500,height=600");
		
			window.addEventListener("message", function(event) {
				if (event.data.type === "oauth_code") {
					_this.integSettingsDialog.find('.waic-dialog-form input[name="access_code"]').val(event.data.code);
				}
			});
		}
	}
	WorkflowPage.prototype.initHistoryTable = function () {
		var _this = this.$obj,
			url = typeof(ajaxurl) == 'undefined' || typeof(ajaxurl) !== 'string' ? WAIC_DATA.ajaxurl : ajaxurl,
			strPerPage = ' ' + waicCheckSettings(_this.langSettings, 'lengthMenu');
		$.fn.dataTable.ext.classes.sPageButton = 'button button-small wbw-paginate';
		//$.fn.dataTable.ext.classes.sLengthSelect = 'woobewoo-flat-input';
		
		_this.waicHistoryTableObj = _this.waicHistoryTable.DataTable({
			serverSide: true,
			processing: true,
			ajax: {
				'url': url + '?mod=workflow&action=getHistoryList&pl=waic&reqType=ajax&waicNonce=' + WAIC_DATA.waicNonce,
				'type': 'POST',
				data: function (d) {
					d.feature = $('#waicFeaturesList').val();
				}
			},
			lengthChange: true,
			lengthMenu: [ [10, 100, -1], [10 + strPerPage, 100 + strPerPage, "All"] ],
			paging: true,
			//dom: 'B<"pull-right"l>rtip',
			dom: 'Brt<"waic-table-pages"pl>',
			responsive: {details: {display: $.fn.dataTable.Responsive.display.childRowImmediate, type: ''}},
			autoWidth: false,
			buttons: [
				{
					text: waicCheckSettings(_this.langSettings, 'btn-delete'),
					className: 'wbw-button wbw-button-small disabled waic-delete-button waic-group-button',
					action: function (e, dt, node, config) {
						_this.setActionTaskIds();
						if (_this.actionTaskIds) {
							_this.activeButton = node;
							waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-delete'), 'waicWorkflowPage', 'doAction', 'deleteTasks');
						}
					}
				},
				{
					text: waicCheckSettings(_this.langSettings, 'btn-publish'),
					className: 'wbw-button wbw-button-small disabled waic-publish-button waic-group-button',
					action: function (e, dt, node, config) {
						_this.setActionTaskIds();
						if (_this.actionTaskIds) {
							_this.activeButton = node;
							waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-publish'), 'waicWorkflowPage', 'doAction', 'publishTasks');
						}
					}
				},
				{
					text: waicCheckSettings(_this.langSettings, 'btn-unpublish'),
					className: 'wbw-button wbw-button-small disabled waic-unpublish-button waic-group-button',
					action: function (e, dt, node, config) {
						_this.setActionTaskIds();
						if (_this.actionTaskIds) {
							_this.activeButton = node;
							waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-unpublish'), 'waicWorkflowPage', 'doAction', 'unpublishTasks');
						}
					}
				},
			],
			columnDefs: [
				{
					width: "20px",
					targets: [0,1]
				},
				{
					className: "dt-left",
					targets: [2]
				},
				{
					"orderable": false,
					targets: [0,7]
				}
			],
			order: [[ 5, 'desc' ]],
			language: {
				emptyTable: waicCheckSettings(_this.langSettings, 'emptyTable'),
				loadingRecords: '<div class="waic-leer-mini"></div>',
				paginate: {
					next: waicCheckSettings(_this.langSettings, 'pageNext'),
					previous: waicCheckSettings(_this.langSettings, 'pagePrev'),
					last: '<i class="fa fa-fw fa-angle-right">',
					first: '<i class="fa fa-fw fa-angle-left">'  
				},
				lengthMenu: ' _MENU_',
				processing: '<div class="waic-loader"><div class="waic-loader-bar bar1"></div><div class="waic-loader-bar bar2"></div></div>',
				//info: waicCheckSettings(_this.langSettings, 'info') + ' _START_ to _END_ of _TOTAL_',
				//search: '_INPUT_'
			},
			preDrawCallback: function (settings, json) {
				$('#waicHistoryList_wrapper .dt-buttons button').removeClass('dt-button');
				$('#waicHistoryList_wrapper .dt-processing').css('top', '70px');
				$('#waicHistoryList_wrapper .dt-processing > div:not(.waic-loader)').css('display', 'none');
			},
			drawCallback: function() {
				setTimeout(function () {
					$('#waicHistoryList_wrapper .dt-paging')[0].style.display = $('#waicHistoryList_wrapper .dt-paging button').length > 5 ? 'block' : 'none';
				}, 50);
				//$('#waicHistoryList_wrapper .dt-paging')[0].style.display = $('#waicHistoryList_wrapper .dt-paging button').length > 5 ? 'block' : 'none';

				_this.waicHistoryTable.find('.waicCheckAll').prop('checked', false);
				_this.groupButtons.addClass('disabled');
				waicInitTooltips(_this.waicHistoryTabl);
			}
		});
		$('#waicFeaturesList').appendTo('.dt-buttons').on('change', function(e) {
			_this.waicHistoryTableObj.ajax.reload();
		});

		waicInitCheckAll(_this.waicHistoryTable);
		
		_this.groupButtons = $('.waic-group-button');
		_this.waicHistoryTable.on('change', '.waicCheckAll, .waicCheckOne', function(e) {
			if (_this.waicHistoryTable.find('.waicCheckOne:checked').length) {
				_this.groupButtons.removeClass('disabled');
			} else {
				_this.groupButtons.addClass('disabled');
			}
		});
		_this.waicHistoryTable.on('click', '.waic-action-template', function(e) {
			var id = $(this).closest('tr').find('.waicCheckOne').attr('data-id');
			if (id) {
				_this.activeButton = $(this).find('i');
				var html = '<div class="waic-popup-form"><input type="hidden" name="task_id" value="' + id + '"><div class="waic-popup-label">' + waicCheckSettings(_this.langSettings, 'template-name') + ' *</div><div class="waic-popup-field"><input type="text" name="name"></div><div class="waic-popup-label">' + waicCheckSettings(_this.langSettings, 'template-desc') + '</div><div class="waic-popup-field"><textarea name="desc" rows="4"></textarea></div></div>';
				waicShowConfirm(html, 'waicWorkflowPage', 'createTemplate', false, {'fields': true});
			}
		});
		_this.waicHistoryTable.on('click', '.waic-action-export', function(e) {
			var id = $(this).closest('tr').find('.waicCheckOne').attr('data-id');
			if (id) {
				$.sendFormWaic({
					icon: $(this).find('i'),
					data: {
						mod: 'workflow', 
						action: 'getJSON', 
						id: id
					},
					onSuccess: function(res) {
						if (!res.error && res.data && res.data.json) {
							var html = '<div class="waic-popup-form"><div class="waic-popup-label">JSON</div><div class="waic-popup-field"><div class="waic-popup-field"><textarea name="json" rows="8">' + res.data.json + '</textarea></div></div>';
							waicShowConfirm(html, 'waicWorkflowPage', 'copyTextarea', res.data.json, {'ok': waicCheckSettings(_this.langSettings, 'btn-copy')});
						}
					}  
				});
			}
		});
	}
	WorkflowPage.prototype.setActionTaskIds = function () {
		var _this = this.$obj,
			ids = [];
		_this.waicHistoryTable.find('.waicCheckOne:checked').each(function () {
			ids.push($(this).attr('data-id'));
		});
		_this.actionTaskIds = ids.length ? ids : false;
	}
	WorkflowPage.prototype.copyTextarea = function (str) {
		waicCopyText(str);
	}
	WorkflowPage.prototype.createTemplate = function (params) {
		var _this = this.$obj;
		$.sendFormWaic({
			icon: _this.activeButton,
			data: {
				mod: 'workflow', 
				action: 'createTemplate', 
				params: params
			},
			onSuccess: function(res) {
				if (!res.error) {
					location.reload();
				}
			}
		});
	}
	WorkflowPage.prototype.importTemplate = function (params) {
		var _this = this.$obj;
		$.sendFormWaic({
			elem: _this.activeButton,
			data: {
				mod: 'workflow', 
				action: 'importTemplate', 
				params: params
			},
			onSuccess: function(res) {
				if (!res.error) {
					location.reload();
				}
			}
		});
	}
	WorkflowPage.prototype.deleteTemplate = function (id) {
		var _this = this.$obj;
		$.sendFormWaic({
			icon: _this.activeButton,
			data: {
				mod: 'workflow', 
				action: 'deleteTemplate', 
				id: id
			},
			onSuccess: function(res) {
				if (!res.error) {
					location.reload();
				}
			}  
		});
	}
	WorkflowPage.prototype.doAction = function ($action) {
		var _this = this.$obj,
			param = '';
		if ($action == 'deleteTasks') {
			if (window.waicLastPopup.find('input').is(':checked')) param = 'deleteContent';
		}

		if ($('.waic-group-button.wbw-waiting').length == 0) {
			$.sendFormWaic({
				elem: _this.activeButton,
				data: {
					mod: 'workspace', 
					action: $action, 
					ids: _this.actionTaskIds,
					param: param
				},
				onSuccess: function(res) {
					if (!res.error) {
						_this.waicHistoryTableObj.ajax.reload();
					}
				}
			});
		}
	}
	
	app.waicWorkflowPage = new WorkflowPage();

	$(document).ready(function () {
		app.waicWorkflowPage.init();
	});

}(window.jQuery, window));
