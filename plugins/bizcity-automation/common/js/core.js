"use strict";
if(typeof(WAIC_DATA) == 'undefined')
	var WAIC_DATA = {};
if(isNumber(WAIC_DATA.animationSpeed)) 
	WAIC_DATA.animationSpeed = parseInt(WAIC_DATA.animationSpeed);
else if(jQuery.inArray(WAIC_DATA.animationSpeed, ['fast', 'slow']) == -1)
	WAIC_DATA.animationSpeed = 'fast';
WAIC_DATA.showSubscreenOnCenter = parseInt(WAIC_DATA.showSubscreenOnCenter);
var sdLoaderImgWaic = '<img src="'+ WAIC_DATA.loader+ '" />';
var g_waicAnimationSpeed = 300;

jQuery.fn.showLoaderWaic = function() {
	return jQuery(this).html( sdLoaderImgWaic );
};
jQuery.fn.appendLoaderWaic = function() {
	jQuery(this).append( sdLoaderImgWaic );
};
jQuery.fn.tagName = function() {
	return this.get(0).tagName;
}
jQuery.fn.exists = function(){
	return (jQuery(this).size() > 0 ? true : false);
}
function isNumber(val) {
	return /^\d+/.test(val);
}
jQuery.fn.serializeAnythingWaic = function(addData, returnArray) {
	var toReturn = returnArray ? {} : [],
		els = jQuery(this).find(':input:not(.wbw-nosave)').get();
	jQuery.each(els, function() {
		var $this = jQuery(this),
			nosave = $this.closest('.wbw-nosave');
		if (nosave.length == 0 && this.name && !this.disabled && (/select|textarea/i.test(this.nodeName) || /checkbox|text|hidden|number|password/i.test(this.type))) {
			var val = $this.val();
			if(this.type == 'checkbox' && !this.checked) {
				val = 0;
			}
			if(returnArray) {
				toReturn[ this.name ] = val;
			} else
			if (Array.isArray(val)) {
				var name = encodeURIComponent(this.name);
				jQuery.each(val, function() {
					toReturn.push( name + "=" + encodeURIComponent( this ) );
				});
			} else {
				toReturn.push( encodeURIComponent(this.name) + "=" + encodeURIComponent( val ) );
			}
		}
	});
	if(typeof(addData) != 'undefined' && addData) {
		toReturn = jQuery.merge(toReturn, pushDataToParam(addData));
	}
	return returnArray ? toReturn : toReturn.join("&").replace(/%20/g, "+");
};
jQuery.sendFormWaic = function(params) {
	// Any html element can be used here
	return jQuery('<br />').sendFormWaic(params);
};
/**
 * Send form or just data to server by ajax and route response
 * @param string params.fid form element ID, if empty - current element will be used
 * @param string params.msgElID element ID to store result messages, if empty - element with ID "msg" will be used. Can be "noMessages" to not use this feature
 * @param function params.onSuccess funstion to do after success receive response. Be advised - "success" means that ajax response will be success
 * @param array params.data data to send if You don't want to send Your form data, will be set instead of all form data
 * @param array params.appendData data to append to sending request. In contrast to params.data will not erase form data
 * @param string params.inputsWraper element ID for inputs wraper, will be used if it is not a form
 * @param string params.clearMsg clear msg element after receive data, if is number - will use it to set time for clearing, else - if true - will clear msg element after 5 seconds
 */
jQuery.fn.sendFormWaic = function(params) {
	var form = null;
	if(!params)
		params = {fid: false, msgElID: false, onSuccess: false};
	if(params.fid)
		form = jQuery('#'+ fid);
	else
		form = jQuery(this);
	
	/* This method can be used not only from form data sending, it can be used just to send some data and fill in response msg or errors*/
	var sentFromForm = (jQuery(form).tagName() == 'FORM'),
		data = new Array(),
		isForm = false;
	if(params.form) {
		data = params.form;
		isForm = true;
		if (params.appendData) {
			for (var i in params.appendData) {
				data.append(i, params.appendData[i]);
			}
		}
	} else {
		if(params.data) data = params.data;
		else if(sentFromForm) data = jQuery(form).serializeAnythingWaic();

		if(params.appendData) {
			var dataIsString = typeof(data) == 'string',
				addStrData = [];
			for(var i in params.appendData) {
				if(dataIsString) addStrData.push(i+ '='+ params.appendData[i]);
				else data[i] = params.appendData[i];
			}
			if(dataIsString) data += '&'+ addStrData.join('&');
		}
	}
	var msgEl = null;
	if(params.msgElID) {
		if(params.msgElID == 'noMessages') msgEl = false;
		else if(typeof(params.msgElID) == 'object') msgEl = params.msgElID;
		else msgEl = jQuery('#'+ params.msgElID);
	}
	if(typeof(params.inputsWraper) == 'string') {
		form = jQuery('#'+ params.inputsWraper);
		sentFromForm = true;
	}
	if(sentFromForm && form) {
		jQuery(form).find('*').removeClass('waicInputError');
	}
	if(msgEl && !params.btn) {
		jQuery(msgEl)
			.removeClass('waicSuccessMsg')
			.removeClass('waicErrorMsg');
		if(!params.btn) {
			jQuery(msgEl).showLoaderWaic();
		}
	} 
	if(params.btn) {
		jQuery(params.btn).attr('disabled', 'disabled');
		// Font awesome usage
		params.btnIconElement = jQuery(params.btn).find('.fa').size() ? jQuery(params.btn).find('.fa') : jQuery(params.btn);
		if(jQuery(params.btn).find('.fa').size()) {
			params.btnIconElement
				.data('prev-class', params.btnIconElement.attr('class'))
				.attr('class', 'fa fa-spinner fa-spin');
		}
	} else if(params.icon) {
		params.icon.attr('data-prev-class', params.icon.attr('class')).attr('class', 'fa fa-spinner fa-spin');
	} else if(params.elem) {
		params.elem.addClass('wbw-waiting');
		params.elem.attr('disabled', 'disabled');
		params.elem.attr('data-prev-text', params.elem.text()).html('<i class="fa fa-spinner fa-spin"></i>');
	} else if(params.imgBtn) {
		params.imgBtn.attr('disabled', 'disabled');
		params.imgBtn.find('img').addClass('wbw-hidden');
		jQuery('<i class="fa fa-spinner fa-spin"></i>').appendTo(params.imgBtn);
	}
	var url = '';
	if(typeof(params.url) != 'undefined')
		url = params.url;
	else if(typeof(ajaxurl) == 'undefined' || typeof(ajaxurl) !== 'string')
		url = WAIC_DATA.ajaxurl;
	else
		url = ajaxurl;
	
	jQuery('.waicErrorForField').hide(WAIC_DATA.animationSpeed);
	var dataType = params.dataType ? params.dataType : 'json';
	// Set plugin orientation
	if(isForm) {
		data.append('pl', WAIC_DATA.WAIC_CODE);
		data.append('reqType', 'ajax');
		if (WAIC_DATA.waicNonce) data.append('waicNonce', WAIC_DATA.waicNonce);
	} else {
		if(typeof(data) == 'string') {
			data += '&pl='+ WAIC_DATA.WAIC_CODE;
			data += '&reqType=ajax';
			if (WAIC_DATA.waicNonce) data += '&waicNonce='+ WAIC_DATA.waicNonce;
		} else {
			data['pl'] = WAIC_DATA.WAIC_CODE;
			data['reqType'] = 'ajax';
			if (WAIC_DATA.waicNonce) data['waicNonce'] = WAIC_DATA.waicNonce;
		}
	}
	var ajaxParams = {
		url: url,
		data: data,
		type: 'POST',
		dataType: dataType,
		success: function(res) {
			toeProcessAjaxResponseWaic(res, msgEl, form, sentFromForm, params);
			if(params.clearMsg) {
				setTimeout(function(){
					if(msgEl) jQuery(msgEl).animateClear();
				}, typeof(params.clearMsg) == 'boolean' ? 5000 : params.clearMsg);
			}
		},
		complete: function(res) {
			if(params.onComplete && typeof(params.onComplete) == 'function') {
				params.onComplete(res);
			}
		}
	};
	if (params.ajax) {
		for(var i in params.ajax) ajaxParams[i] = params.ajax[i];
	}
	
	jQuery.ajax(ajaxParams);
};
/**
 * Hide content in element and then clear it
 */
jQuery.fn.animateClear = function() {
	var newContent = jQuery('<span>'+ jQuery(this).html()+ '</span>');
	jQuery(this).html( newContent );
	jQuery(newContent).hide(WAIC_DATA.animationSpeed, function(){
		jQuery(newContent).remove();
	});
};

jQuery.fn.animationDuration = function(seconds, isMili) {
	if(isMili) {
		seconds = parseFloat(seconds) / 1000;
	}
	var secondsStr = seconds+ 's';
	return jQuery(this).css({
		'webkit-animation-duration': secondsStr
	,	'-moz-animation-duration': secondsStr
	,	'-o-animation-duration': secondsStr
	,	'animation-duration': secondsStr
	});
};
function toeProcessAjaxResponseWaic(res, msgEl, form, sentFromForm, params) {
	if(typeof(params) == 'undefined')
		params = {};
	if(typeof(msgEl) == 'string')
		msgEl = jQuery('#'+ msgEl);
	if(msgEl)
		jQuery(msgEl).html('');
	if(params.btn) {
		jQuery(params.btn).removeAttr('disabled');
		if(params.btnIconElement) {
			params.btnIconElement.attr('class', params.btnIconElement.data('prev-class'));
		}
	} else if(params.icon) {
		params.icon.attr('class', params.icon.attr('data-prev-class'));
	} else if(params.elem) {
		params.elem.removeClass('wbw-waiting');
		params.elem.removeAttr('disabled');
		params.elem.text(params.elem.attr('data-prev-text'));
	} else if(params.imgBtn) {
		params.imgBtn.find('img').removeClass('wbw-hidden');
		params.imgBtn.find('.fa').remove();
		params.imgBtn.removeAttr('disabled');
	}
	if(typeof(res) == 'object') {
		if(res.error) {
			if(msgEl) {
				jQuery(msgEl)
					.removeClass('waicSuccessMsg')
					.addClass('waicErrorMsg');
			} else if (jQuery.sNotify) {
				var message = '';
				for(var i = 0; i < res.errors.length; i++) {
					message += res.errors[i] + '<br />';
				}
				jQuery.sNotify({
					'icon': 'fa fa-exclamation-circle',
					'error': true,
					'content': '<span> '+message+'</span>',
					'delay' : 4500
				});
			}
		} else if(res.messages.length) {
			if(msgEl) {
				jQuery(msgEl)
					.removeClass('waicErrorMsg')
					.addClass('waicSuccessMsg');
				for(var i = 0; i < res.messages.length; i++) {
					jQuery(msgEl).append(res.messages[i]).append('<br />');
				}
			} else if (jQuery.sNotify) {
				var message = '';
				for(var i = 0; i < res.messages.length; i++) {
					message += res.messages[i] + '<br />';
				}
				jQuery.sNotify({
					'icon': 'fa fa-check',
					'content': '<span> '+message+'</span>',
					'delay' : 2500
				});
			}
		}
	}
	if(params.onSuccess && typeof(params.onSuccess) == 'function') {
		params.onSuccess(res);
	}
}
function waicStrReplace(haystack, needle, replacement) { 
	var temp = haystack.split(needle); 
	return temp.join(replacement); 
}
function nameToClassId(name) {
	return waicStrReplace(
		waicStrReplace(name, ']', ''), 
			'[', ''
	);
}
function strpos( haystack, needle, offset){
	var i = haystack.indexOf( needle, offset ); // returns -1
	return i >= 0 ? i : false;
}
function extend(Child, Parent) {
	var F = function() { };
	F.prototype = Parent.prototype;
	Child.prototype = new F();
	Child.prototype.constructor = Child;
	Child.superclass = Parent.prototype;
}
function toeRedirect(url, newWnd) {
	if(newWnd) {
		var win = window.open(url, '_blank');
		if(win) {
			win.focus();
		} else	// Browser blocked new window showing
			document.location.href = url;
	} else {
		document.location.href = url;
	}
}
function toeReload(url) {
	if(url)
		toeRedirect(url);
	document.location.reload();
}
function toeInArrayWaic(needle, haystack) {
	if(haystack) {
		for(var i in haystack) {
			if(haystack[i] == needle)
				return true;
		}
	}
	return false;
}
function callUserFuncArray(cb, parameters) {
	// http://kevin.vanzonneveld.net
	// +   original by: Thiago Mata (http://thiagomata.blog.com)
	// +   revised  by: Jon Hohle
	// +   improved by: Brett Zamir (http://brett-zamir.me)
	// +   improved by: Diplom@t (http://difane.com/)
	// +   improved by: Brett Zamir (http://brett-zamir.me)
	// *     example 1: call_user_func_array('isNaN', ['a']);
	// *     returns 1: true
	// *     example 2: call_user_func_array('isNaN', [1]);
	// *     returns 2: false
	var func;
	if (typeof cb === 'string') {
		func = (typeof this[cb] === 'function') ? this[cb] : func = (new Function(null, 'return ' + cb))();
	}
	else if (Object.prototype.toString.call(cb) === '[object Array]') {
		func = (typeof cb[0] == 'string') ? eval(cb[0] + "['" + cb[1] + "']") : func = cb[0][cb[1]];
	}
	else if (typeof cb === 'function') {
		func = cb;
	}
	if (typeof func !== 'function') {
		throw new Error(func + ' is not a valid function');
	}
	return (typeof cb[0] === 'string') ? func.apply(eval(cb[0]), parameters) : (typeof cb[0] !== 'object') ? func.apply(null, parameters) : func.apply(cb[0], parameters);
}
function waicGetStyleSheetRule(sheetId, rule, isLike) {
	var obj = document.getElementById(sheetId),
		sheet = obj.sheet || obj.styleSheet,
		rules = sheet.cssRules || sheet.rules,
		isLike = typeof isLike == 'undefined' ? false : isLike;
	for (var r = 0; r < rules.length; r++) {
		if(isLike) {
			if(rules[r].selectorText.indexOf(rule) === 0) return rules[r];
		} else if(rules[r].selectorText == rule) return rules[r];
	}
	return false;
}
function waicCreateStyleElem(id) {
	if (jQuery('style#' + id).length == 0) {
		jQuery('head').append(jQuery('<style/>', { id: id }));
	}
}
function waicDisableStyleElem(id, mode) {
	var obj = document.getElementById(id),
		sheet = obj.sheet || obj.styleSheet;
	sheet.disabled = mode;
}
function waicGetCssText(rule) {
	var value = rule.cssText ? rule.cssText : rule.style.cssText;
	if (typeof(value) == 'undefined' || value.length == 0 || value.indexOf('@import') >= 0) return ''; 
	if(value.indexOf(rule.selectorText) == -1) {
		value = rule.selectorText + '{' + value + '}';
	}
	return value;
}
function waicStyleSheetImportFF(sheetId, family) {
	if (!family || family.length == 0 || family == 'inherit') return;
	var obj = document.getElementById(sheetId);
	if (!obj) return;
	var sheet = obj.sheet || obj.styleSheet;
	sheet.insertRule('@import url("//fonts.googleapis.com/css?family=' + family.replace(/ /g, '+') + '"); ', 0);
}
function waicSetStyleSheetRules(sheetId, selectors) {
	var obj = document.getElementById(sheetId);
	if (!obj) return;
	var sheet = obj.sheet || obj.styleSheet,
		rules = sheet.cssRules || sheet.rules;

	for (var i = 0; i < selectors.length; i++) {
		var selector = selectors[i].selector,
			param = selectors[i].param,
			value = selectors[i].value,
			newRules = typeof(value) == 'string' && value.length ? param + ':' + (param == 'font-family' ? '"' + value + '"' : value) + ';' : '',
			found = -1;

		for (var r = 0; r < rules.length; r++) {
			if (rules[r].selectorText == selector) {
				found = r;
				var curCss = waicGetCssText(rules[r]),
					curRules = curCss.substring(curCss.indexOf('{') + 1, curCss.lastIndexOf('}')).split(';');

				for (var c = 0; c < curRules.length; c++) {
					var rulePaar = curRules[c].split(':');
					if (rulePaar.length == 2) {
						if (rulePaar[0].trim() != param) {
							newRules += curRules[c] + ';';
						}
					}
				}
				break;
			}
		}
		if (found >= 0) {
			sheet.deleteRule(found);
		}
		if (newRules.length) {
			sheet.insertRule(selector + '{' + newRules + '}', rules.length);
		}
	}
}
function waicGetStyleSheetRules(sheetId, add, remove, controlMode) {
	var obj = document.getElementById(sheetId);
	if (!obj) return;
	var sheet = obj.sheet || obj.styleSheet,
		add = typeof(add) == 'undefined' ? '' : add,
		remove = typeof(remove) == 'undefined' ? false : remove,
		controlMode = typeof(controlMode) == 'undefined' ? true : controlMode,
		css = '';
	if (!controlMode || !sheet.disabled) {
		var rules = sheet.cssRules || sheet.rules;
		if (rules) {
			for(var r = 0; r < rules.length; r++) {
				var str = waicGetCssText(rules[r]);
				if (str.length) css += (remove ? str.replace(remove, add) : add + str);
			}
		}
	}
	return css;
}
function waicGetColorPickerBorder(col) {
	if(typeof col !== 'undefined' && col.length >= 7) {
		var rgb = (/^#[0-9A-F]{6}$/i.test(col))
			? [0, parseInt(col.substring(1,3),16), parseInt(col.substring(3,5),16), parseInt(col.substring(5,7),16)]
			: col.replace(/\s/g,'').match(/^rgba?\((\d+),(\d+),(\d+)/i);
		if(rgb && rgb.length >= 4 && (1 - (0.299 * rgb[1] + 0.587 * rgb[2] + 0.114 * rgb[3]) / 255) > 0.1) return col;
	}
	return '#566aa3';
}
function waicGetColorWeb(col, arr, def) {
	if(typeof col !== 'undefined'){
		if(col[0] == "#") return col;
		if(col.indexOf('rgb') != -1) {
			var withA = col.indexOf('rgba') != -1,
				rgb = withA ? col.replace(/\s/g,'').match(/^rgba?\((\d+),(\d+),(\d+),(\d+)/i) : col.replace(/\s/g,'').match(/^rgba?\((\d+),(\d+),(\d+)/i);
			if(rgb.length >= 4) {
				if (arr) return {r: rgb[1], g: rgb[2], b: rgb[3]};
				var a = withA ? rgb[3] : 1,
					bg = (1 - a) * 255,
					r = rgb[1] * a + bg,
					g = rgb[2] * a + bg,
					b = rgb[3] * a + bg,
					res = (b | (g << 8) | (r << 16)).toString(16);
				return '#' + '0'.repeat(6 - res.length) + res;
			}
		}
	}
	return def ? def : '#000';
}
function waicLightenDarkenColor(col, amt) {
	var usePound = false,
		r = 255,
		g = 255,
		b = 255;
	if(typeof col !== 'undefined' && col.length >= 7) {
		if(col.indexOf('rgb') == -1) {
			if(col[0] == "#") {
				col = col.slice(1);
				usePound = true;
			}
			var num = parseInt(col, 16);
			r = (num >> 16);
			b = ((num >> 8) & 0x00FF);
			g = (num & 0x0000FF);
		} else {
			var withA = col.indexOf('rgba') != -1,
				rgb = withA ? col.replace(/\s/g,'').match(/^rgba?\((\d+),(\d+),(\d+),(\d+)/i) : col.replace(/\s/g,'').match(/^rgba?\((\d+),(\d+),(\d+)/i);
			if(rgb.length >= 4) {
				var a = withA ? rgb[3] : 1,
					bg = (1 - a) * 255;
				r = rgb[1] * a + bg;
				g = rgb[2] * a + bg;
				b = rgb[3] * a + bg;
			}
			usePound = true;
		}
	}
	r = r + amt;
	b = b + amt;
	g = g + amt;
	if(r > 255) r = 255;
	else if(r < 0) r = 0;
	if(b > 255) b = 255;
	else if(b < 0) b = 0;
	if(g > 255) g = 255;
	else if(g < 0) g = 0;
	var res = (g | (b << 8) | (r << 16)).toString(16);
	return (usePound?"#":"") + '0'.repeat(6 - res.length) + res;
}
function waicHexToRgbA(hex, alfa){
    var c, a = typeof alfa == 'undefined' ? 1 : alfa;
    if(/^#([A-Fa-f0-9]{3}){1,2}$/.test(hex)){
        c= hex.substring(1).split('');
        if(c.length== 3){
            c= [c[0], c[0], c[1], c[1], c[2], c[2]];
        }
        c= '0x'+c.join('');
        return 'rgba('+[(c>>16)&255, (c>>8)&255, c&255].join(',')+','+a+')';
    }
    return hex;
}
function waicParseJSON(elem, arr) {
	try {
		var obj = JSON.parse(elem);
	} catch(e) {
		var obj = arr ? [] : {};
	}
	return obj;
}
function waicCheckSettings(settings, key, def) {
	if (typeof def == 'undefined') var def = '';
	return (settings[key]) ? settings[key] : def;
}
function waicShowConfirm(text, callClass, callFunc, param, opts) {
	var options = {'ok': 'Ok', 'cancel': 'Cancel'};
	if (typeof(opts) != 'undefined') {
		for (var k in options) {
			if (k in opts) options[k] = opts[k];
		}
	} else var opts = {};
	var $popup = jQuery('<div title="AI Copilot - Content Generator">').html(text).appendTo('body');
	window.waicLastPopup = $popup;
	$popup.dialog({
		modal: true,
		position: {my: 'center', at: 'center', of: window},
		width: 500,
		height: 'auto',
		modal: true,
		dialogClass: 'wbw-plugin',
		classes: {
			'ui-dialog': 'wbw-plugin'
		},
		buttons: [
			{
				text: options['ok'],
				class: 'wbw-button wbw-button-form wbw-button-main',
				click: function() {
					if ('fields' in opts && opts.fields) param = jsonInputsWaic(jQuery(this));
					jQuery(this).dialog('close');
					if (callClass in window && callFunc in window[callClass]) window[callClass][callFunc](param);
				},
			},
			{
				text: options['cancel'],
				class: 'wbw-button wbw-button-form wbw-button-minor',
				click: function() {
					jQuery(this).dialog('close');
				}
			}
		],
		open: function() {
			jQuery(this).parent().find('.ui-dialog-buttonset button').removeClass('ui-button ui-corner-all ui-widget');
		}
	});
}
function waicShowAlert(text) {
	jQuery('<div title="AI Copilot - Content Generator">').html(text).appendTo('body').dialog({
		modal: true,
		width: '500px',
		dialogClass: "wbw-plugin",
		buttons: [
			{
				text: 'Ok',
				class: 'button button-secondary',
				click: function() {
					jQuery(this).dialog('close');
				},
			}			
		]
	});
}
function jsonInputsWaic(parent, obj) {
	var obj = obj ? true : false,
		data = {},
		serialized = [];
	function buildInputObject(arr, val) {
		if (arr.length < 1) return val;  
		var objkey = arr[0],
			result = {};
		if (objkey.slice(-1) == ']') objkey = objkey.slice(0, -1);  
		if (arr.length == 1) result[objkey] = val;
		else {
			arr.shift();
			var nestedVal = buildInputObject(arr,val);
			result[objkey] = nestedVal;
		}
		return result;
	}
	if (parent) {
		// Manage fields allowing multiple values first (they contain "[]" in their name)
		if ( typeof parent === 'string' ) parent = jQuery(parent);
		jQuery.each(parent.find(':input:not(.wbw-nosave)').serializeArray(), function(key, field) {
			if (field.name.indexOf('[]') < 0) {
				serialized.push(field);
				return true;
			}
			var fieldName = field.name.split('[]')[0],
				hasValue = false;
			jQuery.each(serialized, function(sKey, sField) {
				if (sField.name === fieldName) {
					hasValue = true;
					serialized[sKey]['value'].push(field.value);
				}
			});
			if (!hasValue) {
				serialized.push({'name': fieldName, 'value': [field.value]});
			}
		});
		jQuery.each(serialized, function() {
			var val = this.value,
				c = this.name.split('['),
				a = buildInputObject(c, val);
			jQuery.extend(true, data, a);
		});
	}

	return obj ? data : JSON.stringify(data);
}
function waicApplyHookAction(_this, func, arg, params) {
	if (typeof(_this[func]) == 'function') _this[func](arg, params);
}
function waicApplyHookFilter(_this, func, arg, params) {
	if (typeof(_this[func]) == 'function') return _this[func](arg, params);
	return arg;
}
function waicIsEmptyValue(value) {
	switch(typeof(value)) {
		case 'undefined':
			return true;
			break;
		case 'object':
			if (jQuery.isEmptyObject(value)) return true;
			break;
		case 'number':
			if (value == 0) return true;
			break;
		default:
			if (value == '' || value == null) return true;
			break;
	}
	return false;
}
function waicNextVisibleIndex($elem, index) {
	if(index >= $elem.length) return -1;

	if(!$elem.eq(index).is(':visible')) {
		index++;
		index = waicNextVisibleIndex($elem, index);
	}
	return index;
}
function waicGetAjaxUrl() {
	return typeof(ajaxurl) == 'undefined' || typeof(ajaxurl) !== 'string' ? WAIC_DATA.ajaxurl : ajaxurl;
}
function waicGetUrlParameter(name) {
	var searchUrl = window.location.search;
	name = name.replace(/[\[]/, "\\[").replace(/[\]]/, "\\]");
	var regex = new RegExp("[\\?&]" + name + "=([^&#]*)"),
		results = regex.exec(searchUrl);
	return results === null ? "" : decodeURIComponent(results[1].replace(/\+/g, " "));
}
