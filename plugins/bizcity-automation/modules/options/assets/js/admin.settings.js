(function ($, app) {
"use strict";
	function SettingsPage() {
		this.$obj = this;
		return this.$obj;
	}
	
	SettingsPage.prototype.init = function () {
		var _this = this.$obj;
		_this.isPro = WAIC_DATA.isPro == '1';
		_this.langSettings = waicParseJSON($('#waicLangSettingsJson').val());
		_this.content = $('.wbw-tabs-content');
		
		_this.eventsSettingsPage();
		if (typeof(_this.initPro) == 'function') _this.initPro();
	}
	
	SettingsPage.prototype.eventsSettingsPage = function () {
		var _this = this.$obj;
		_this.content.find('.wbw-button-save').click(function(e){
			e.preventDefault();
			var $btn = $(this),
				$from = $btn.closest('form');
			$.sendFormWaic({
				elem: $btn,
				data: {
					mod: 'options',
					action: 'saveOptions',
					group: $from.attr('data-group'),
					params: jsonInputsWaic($from, true),
				},
			});
			return false;
		});
		_this.content.find('#waicStartGeneration').click(function(e){
			e.preventDefault();
			var $btn = $(this),
				$from = $btn.closest('form');
			$.sendFormWaic({
				elem: $btn,
				data: {
					mod: 'workspace',
					action: 'runGeneration'
				},
			});
			return false;
		});
		_this.content.find('.wbw-button-cancel').click(function(e){
			e.preventDefault();
			location.reload();
			return false;
		});
		_this.content.find('.wbw-button-restore').click(function(e){
			e.preventDefault();
			waicShowConfirm(waicCheckSettings(_this.langSettings, 'confirm-restore'), 'waicSettingsPage', 'restoreOptions', $(this));
			return false;
		});
		_this.content.find('#waicGenarateMCPToken').click(function(e){
			e.preventDefault();
			const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
			var token = '';
			for (var i = 0; i < 32; i++) {
				token += chars.charAt(Math.floor(Math.random() * chars.length));
			}
			_this.content.find('#waicMCPToken').val(token);
			return false;
		});
		_this.content.find('#waicViewMCPToken').click(function(e){
			e.preventDefault();
			var $this = $(this),
				$token = _this.content.find('#waicMCPToken');
			if ($token.hasClass('waic-fake-password')) {
				$token.removeClass('waic-fake-password');
				$this.text(waicCheckSettings(_this.langSettings, 'btn-hide'));
			} else {
				$token.addClass('waic-fake-password');
				$this.text(waicCheckSettings(_this.langSettings, 'btn-view'));
			}
			return false;
		});
		
		var $instraction = _this.content.find('#waicMCPInstructions'),
			$tabsButtons = $instraction.find('.wbw-submenu-tabs button.wbw-button'),
			$tabsContents = jQuery('.wbw-subtabs-content .wbw-subtab-content'),
			$curTab = $tabsButtons.filter('.current');
		$tabsContents.filter($curTab.attr('data-content')).addClass('active');

		$tabsButtons.on('click', function (e) {
			e.preventDefault();
			var $this = jQuery(this),
				$curTab = $this.attr('data-content');

			$tabsContents.removeClass('active');
			$tabsButtons.removeClass('current');
			$this.addClass('current');
			$this.blur();

			$tabsContents.filter($curTab).addClass('active');//.trigger('waic-tab-change');
		});
	}
	SettingsPage.prototype.restoreOptions = function ($btn) {
		var $from = $btn.closest('form');
		$.sendFormWaic({
			elem: $btn,
			data: {
				mod: 'options',
				action: 'restoreOptions',
				group: $from.attr('data-group')
			},
			onSuccess: function(res) {
				if (!res.error) {
					location.reload();
				}
			}
		});
	}
	
	app.waicSettingsPage = new SettingsPage();

	$(document).ready(function () {
		app.waicSettingsPage.init();
	});

}(window.jQuery, window));
