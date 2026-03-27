"use strict";
var waicAdminFormChanged = [];
window.onbeforeunload = function(){
	// If there are at lease one unsaved form - show message for confirnation for page leave
	if(waicAdminFormChanged.length)
		return 'Some changes were not-saved. Are you sure you want to leave?';
};
jQuery(document).ready(function(){
	waicInitOptions();
	waicInitTooltips();
	waicInitSliders();
	waicInitSettingsParents();
	waicInitMultySelects();
	waicInitDatePicker();

	jQuery('.wbw-plugin-loader').css('display', 'none');
	jQuery('.wbw-main').css('display', 'block');
	
	jQuery('.wbw-plugin .tooltipstered').removeAttr("title");
	jQuery('.waic-api-models-select').on('change', function() {
		var $this = jQuery(this),
			model = $this.val(),
			tokens = waicParseJSON($this.closest('.waic-api-models-block').attr('data-tokens')),
			$slider = jQuery('#waicApiTokens');
		if ($slider.length == 1 && model in tokens) {
			$slider.attr('data-max', tokens[model]);
			$slider.data('ionRangeSlider').update({max: tokens[model]});
		}
	});
	jQuery('#waicEngineSelect').on('change', function() {
		if ('open-ai' === jQuery(this).val()) {
			jQuery('#waicApiModel').trigger('change');
		} else if ('deep-seek' === jQuery(this).val()) {
			jQuery('#waicApiDeepSeekModel').trigger('change');
		} else {
			jQuery('#waicApiGeminiModel').trigger('change');
		}
	});
	jQuery('.wbw-head-btn').on('click', function() {
		var $nav = jQuery(this).closest('.wbw-header').find('.wbw-navigation');
		if ($nav.length) {
			if ($nav.hasClass('wbw-visible')) $nav.removeClass('wbw-visible');
			else $nav.addClass('wbw-visible');
		}
	});
	jQuery('.wbw-shortcode-field').on('click', function() {
		this.setSelectionRange(0, this.value.length);
	});
	jQuery('.waic-api-models-check').on('click', function() {
		var $this = jQuery(this),
			$wrapper = $this.closest('.wbw-settings-field'),
			provider = $wrapper.attr('data-select-value'),
			$models = $wrapper.find('select');
		jQuery.sendFormWaic({
			elem: $this,
			data: {
				mod: 'options',
				action: 'checkApiModels',
				provider: provider,
				api_key: jQuery('input.waic-fake-password[name="api[' + provider + '_api_key]"]').val(),
			},
			onSuccess: function(res) {
				if (!res.error && res.data && res.data.results) {
					var models = res.data.results.models || false,
						imgModels = res.data.results.img_models || false,
						tokens = res.data.results.tokens || false,
						$block = $this.closest('.waic-api-models-block'),
						curModel = $models.val();
					if (models) {
						$models.find('option').remove();
						for (var model in models) {
							$models.append(jQuery('<option></option>').attr('value', model).text(models[model]));
						}
						$models.val(curModel);
					}
					if (imgModels) {
						var $imgModels = $this.closest('.wbw-body-options-api').find('select[name="api[' + provider + '_img_model]"]');
						if ($imgModels) {
							var curModel = $imgModels.val();
							$imgModels.find('option').remove();
							for (var model in imgModels) {
								$imgModels.append(jQuery('<option></option>').attr('value', model).text(imgModels[model]));
							}	
							$imgModels.val(curModel);
						}
					}
					if (tokens && $block) {
						var curTokens = waicParseJSON($block.attr('data-tokens'));
						for (var m in tokens) {
							curTokens[m] = tokens[m];
						}
						$block.attr('data-tokens', JSON.stringify(curTokens));
					}
				}
			}
		});

	});
});
function waicInitOptions( selector ) {
	var container = selector ? selector : jQuery('.wbw-container');
	
	if (container.find('.wbw-menu-tabs').length) {
		var $tabsButtons = jQuery('.wbw-menu-tabs button.wbw-button'),
			$tabsContents = jQuery('.wbw-tabs-content .wbw-tab-content'),
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

			$tabsContents.filter($curTab).addClass('active').trigger('waic-tab-change');
		});
	}
	container.find('#wpbSaveApiKey').on('click',function(e) {
		e.preventDefault();
		var $btn = jQuery(this),
			$from = $btn.closest('form');
		jQuery.sendFormWaic({
			elem: $btn,
			data: {
				mod: 'options',
				action: 'saveApiKey',
				key: container.find('#wpbApiKeyField').val()
			},
			onSuccess: function(res) {
				if (!res.error) {
					location.reload();
				}
			}
		});
		return false;
	});
	/*container.find('.wbw-ws-block.active').on('click',function(e) {
		var $link = jQuery(this).find('a.wbw-feature-link');
		if ($link.length) window.location = $link.attr('href');
		return false;
	});*/
}
function waicInitSettingsParents( selector ) {
	var settingsValues = selector ? selector : jQuery('.wbw-content');

	settingsValues.on('change waic-change', 'input[type="checkbox"]', function () {
		var elem = jQuery(this),
			//valueWrapper = elem.closest('.options-value'),
			name = elem.attr('name'),
			block = settingsValues,
			childrens = block.find('.wbw-settings-form[data-parent-check="' + name + '"], .wbw-settings-field[data-parent-check="' + name + '"]');
		if(childrens.length > 0) {
			if(elem.is(':checked') /*&& (valueWrapper.length == 0 || !valueWrapper.hasClass('wbw-hidden'))*/) {
				childrens.removeClass('wbw-hidden');
				childrens.find('select,input[type="checkbox"]').trigger('waic-change');
			} else childrens.addClass('wbw-hidden');
		}
	});
	settingsValues.on('change waic-change', 'select', function () {
		var elem = jQuery(this),
			value = elem.val(),
			//hidden = elem.closest('.options-value').hasClass('wbw-hidden'),
			name = elem.attr('name'),
			block = settingsValues,
			subOptions = block.find('.wbw-settings-form[data-parent-select="' + name + '"], .wbw-settings-field[data-parent-select="' + name + '"], .wbw-section-options[data-parent-select="' + name + '"]');
		if(subOptions.length) {
			subOptions.addClass('wbw-hidden');
			subOptions.filter('[data-select-value*="'+value+'"]').removeClass('wbw-hidden');
		}
		var subOptions2 = block.find('.wbw-settings-form[data-parent-select2="' + name + '"]');
		if(subOptions2.length) {
			subOptions2.addClass('waic-hidden');
			subOptions2.filter('[data-select-value2*="'+value+'"]').removeClass('waic-hidden');
		}
	});
}
function waicInitMultySelects( parent ) {
	var parent = typeof parent == 'undefined' ? '.wbw-container' : parent;
	if ( typeof parent === 'string' ) parent = jQuery(parent);
	
	var multySelects = parent.find('select.wbw-chosen:not(.no-chosen)');
	if (multySelects.length) {
		multySelects.chosen({width: "100%"});
		multySelects.on('change', function (e, info) {
			if (info.selected) {
				var allSelected = this.querySelectorAll('option[selected]'),
					lastSelected = allSelected[allSelected.length - 1],
					selected = this.querySelector(`option[value="${info.selected}"]`);
				selected.setAttribute('selected', '');
				if (lastSelected) lastSelected.insertAdjacentElement('afterEnd', selected);
				else this.insertAdjacentElement('afterbegin', selected);
			} else {
				var removed = this.querySelector(`option[value="${info.deselected}"]`);
				removed.setAttribute('selected', false); // this step is required for Edge
				removed.removeAttribute('selected');
			}
			jQuery(this).trigger('chosen:updated');
		});
	}
}
function waicInitDatePicker( selector ) {
	var container = selector ? selector : jQuery('.wbw-container');
	container.find('.wbw-field-date:not(.hasDatepicker)').datepicker({
		changeMonth: true,
		changeYear: true,
		dateFormat: WAIC_DATA.dateFormat,
		showAnim: '',
	});
	var dtInputs = container.find('.wbw-field-datetime:not(.hasDatepicker)');
	if (dtInputs.length) {
		dtInputs.datetimepicker({
			changeMonth: true,
			changeYear: true,
			dateFormat: WAIC_DATA.dateFormat,
			timeFormat: WAIC_DATA.timeFormat,
			showAnim: '',
		});
	}
}
	
function waicInitTooltips( selector ) {
	var tooltipsterSettings = {
			contentAsHTML: true,
			interactive: true,
			speed: 0,
			delay: 200,
			maxWidth: 450
		},
		findPos = {
			'.wbw-tooltip:not(.tooltipstered)': 'top',
		},
		$findIn = selector ? (typeof selector === 'string' ? jQuery(selector) : selector) : false;
	for(var k in findPos) {
		if(typeof(k) === 'string') {
			var $tips = $findIn ? $findIn.find( k ) : jQuery(k).not('.no-tooltip');
			if($tips && $tips.length) {
				tooltipsterSettings.position = findPos[ k ];
				// Fallback for case if library was not loaded
				if(!$tips.tooltipster) continue;
				$tips.tooltipster( tooltipsterSettings );
			}
		}
	}
	if ($findIn) {
		$findIn.find('.tooltipstered').removeAttr('title');
	}
}
function waicInitSliders(selector) {
	var container = selector ? selector : jQuery('.wbw-content');
	container.find('.wbw-slider').each(function() {
		var $this = jQuery(this),
			$range = $this.find('input');
		$range.ionRangeSlider({
			//prettify: prettify
			disable: $range.hasClass('disabled')
		});
});
}
function waicInitColorPicker(selector) {
	var $findIn = selector ? jQuery(selector) : jQuery('.wbw-plugin');
	$findIn.find('.wbw-color-picker').each(function() {
		var $this = jQuery(this),
			colorArea = $this.find('.wbw-color-preview'),
			colorInput = $this.find('.wbw-color-input'),
			colorFilter = $this.parent().find('.wbw-color-filter'),
			isFilter = colorFilter.length && window.waicConvertHexToFilter ? true : false,
			curColor = colorInput.val(),
			timeoutSet = false;

		colorArea.ColorPicker({
			flat: false,
			onShow: function (colpkr) {
				jQuery(this).ColorPickerSetColor(colorInput.val());
				jQuery(colpkr).fadeIn(500);
				return false;
			},
			onHide: function (colpkr) {
				jQuery(colpkr).fadeOut(500);
				return false;
			},
			onChange: function (hsb, hex, rgb) {
				var self = this;
				curColor = hex;
				if(!timeoutSet) {
					setTimeout(function(){
						timeoutSet = false;
						jQuery(self).find('.colorpicker_submit').trigger('click');
					}, 500);
					timeoutSet = true;
				}
			},
			onSubmit: function(hsb, hex, rgb, el) {
				setColorPickerPreview(colorArea, '#' + curColor);
				if (isFilter) colorFilter.val(window.waicConvertHexToFilter.compute('#' + curColor));
				colorInput.val('#' + curColor).trigger('change');
			}
		});
		setColorPickerPreview(colorArea, colorInput.val());
	});
	$findIn.find('.wbw-color-input').on('change waic-color-change', function() {
		var $this = jQuery(this),
			value = $this.val(),
			$wrapper = $this.closest('.wbw-color-picker'),
			$filter = $wrapper.parent().find('.wbw-color-filter');
		setColorPickerPreview($wrapper.find('.wbw-color-preview'), value);
		if ($filter.length && window.waicConvertHexToFilter) $filter.val(window.waicConvertHexToFilter.compute(value));
	});
	function setColorPickerPreview(area, col) {
		area.css({'backgroundColor': col, 'border-color': waicGetColorPickerBorder(col)});
	}
}
function waicInitCheckAll(elem, preName) {
	if (typeof preName == 'undefined') var preName = 'waicCheck';
	var main = elem.find('.' + preName + 'All');
	if (main.length) {
		main.on('change', function(e) {
			e.preventDefault();
			elem.find('.' + preName + 'One').prop('checked', jQuery(this).is(':checked'));
		});
		elem.on('change', '.' + preName + 'One', function(e){
			e.preventDefault();
			if (!jQuery(this).is(':checked')) {
				main.prop('checked', false);
			}
		});
	}
}
function changeAdminFormWaic(formId) {
	if(jQuery.inArray(formId, waicAdminFormChanged) == -1)
		waicAdminFormChanged.push(formId);
}
function adminFormSavedWaic(formId) {
	if(waicAdminFormChanged.length) {
		for(var i in waicAdminFormChanged) {
			if(waicAdminFormChanged[i] == formId) {
				waicAdminFormChanged.pop(i);
			}
		}
	}
}
function checkAdminFormSaved() {
	if(waicAdminFormChanged.length) {
		if(!confirm('Some changes were not-saved. Are you sure you want to leave?')) {
			return false;
		}
		waicAdminFormChanged = [];	// Clear unsaved forms array - if user wanted to do this
	}
	return true;
}
function isAdminFormChanged(formId) {
	if(waicAdminFormChanged.length) {
		for(var i in waicAdminFormChanged) {
			if(waicAdminFormChanged[i] == formId) {
				return true;
			}
		}
	}
	return false;
}

function waicGetTxtEditorVal(id) {
	if(typeof(tinyMCE) !== 'undefined' 
		&& tinyMCE.get( id ) 
		&& !jQuery('#'+ id).is(':visible') 
		&& tinyMCE.get( id ).getDoc 
		&& typeof(tinyMCE.get( id ).getDoc) == 'function' 
		&& tinyMCE.get( id ).getDoc()
	)
		return tinyMCE.get( id ).getContent();
	else
		return jQuery('#'+ id).val();
}
function waicSetTxtEditorVal(id, content) {
	if(typeof(tinyMCE) !== 'undefined' 
		&& tinyMCE 
		&& tinyMCE.get( id ) 
		&& !jQuery('#'+ id).is(':visible')
		&& tinyMCE.get( id ).getDoc 
		&& typeof(tinyMCE.get( id ).getDoc) == 'function' 
		&& tinyMCE.get( id ).getDoc()
	)
		tinyMCE.get( id ).setContent(content);
	else
		jQuery('#'+ id).val( content );
}
function waicCopyText(str) {
	var $temp = jQuery('<textarea>');
	$temp.val(str).css({ position: 'absolute', left: '-9999px' });
	jQuery('body').append($temp);
	$temp.select();
	document.execCommand('copy');
	$temp.remove();
}