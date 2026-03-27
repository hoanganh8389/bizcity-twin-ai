(function($, app) {
	"use strict";
	function HistoryPage() {
		this.$obj = this;
		return this.$obj;
	}
	
	HistoryPage.prototype.init = function () {
		var _this = this.$obj;
		_this.isPro = WAIC_DATA.isPro == '1';
		_this.actionTaskIds = false;
		_this.activeButton = false;
		_this.langSettings = waicParseJSON($('#waicLangSettingsJson').val());
		_this.waicHistoryTable = $('#waicHistoryList');
		
		_this.eventsHistoryPage();
		if (typeof(_this.initPro) == 'function') _this.initPro();
	}
	
	HistoryPage.prototype.eventsHistoryPage = function () {
		var _this = this.$obj,
			url = typeof(ajaxurl) == 'undefined' || typeof(ajaxurl) !== 'string' ? WAIC_DATA.ajaxurl : ajaxurl,
			strPerPage = ' ' + waicCheckSettings(_this.langSettings, 'lengthMenu');
		$.fn.dataTable.ext.classes.sPageButton = 'button button-small wbw-paginate';
		//$.fn.dataTable.ext.classes.sLengthSelect = 'woobewoo-flat-input';
		
		_this.waicHistoryTableObj = _this.waicHistoryTable.DataTable({
			serverSide: true,
			processing: true,
			ajax: {
				'url': url + '?mod=workspace&action=getHistoryList&pl=waic&reqType=ajax&waicNonce=' + WAIC_DATA.waicNonce,
				'type': 'POST',
				data: function (d) {
					d.feature = $('#waicFeaturesList').val();
					// Propagate iframe mode so server-side URLs include bizcity_iframe=1
					var params = new URLSearchParams(window.location.search);
					if (params.get('bizcity_iframe') === '1') {
						d.bizcity_iframe = '1';
					}
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
							waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-delete'), 'waicHistoryPage', 'doAction', 'deleteTasks');
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
							waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-publish'), 'waicHistoryPage', 'doAction', 'publishTasks');
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
							waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-unpublish'), 'waicHistoryPage', 'doAction', 'unpublishTasks');
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
					targets: [2,3]
				},
				{
					"orderable": false,
					targets: [0,8]
				}
			],
			order: [[ 6, 'desc' ]],
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

	}
	HistoryPage.prototype.setActionTaskIds = function () {
		var _this = this.$obj,
			ids = [];
		_this.waicHistoryTable.find('.waicCheckOne:checked').each(function () {
			ids.push($(this).attr('data-id'));
		});
		_this.actionTaskIds = ids.length ? ids : false;
	}
	HistoryPage.prototype.doAction = function ($action) {
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
	
	app.waicHistoryPage = new HistoryPage();

	$(document).ready(function () {
		app.waicHistoryPage.init();
	});

}(window.jQuery, window));
