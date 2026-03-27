(function($) {
"use strict";
	$.sNotify = function(options) {
		if (!this.length) {
			return this;
		}
		var $wrapper = $('<div class="s-notify">').css({
			position: 'fixed',
			display: 'none',
			top: '3.3em',
			padding: '1em',
			'z-index': '10000',
			'background-color': 'white',
			'box-shadow': '0px 0px 6px 0px rgba(0,0,0,0.1)'
		});

		$wrapper.wrapInner(this);
		$wrapper.appendTo('body');
		
		if (options.left) {
			if (options.left == 'center') $wrapper.css({transform: 'translate(-50%, 0)',left: '50%'});
		} else $wrapper.css({right: '1.7em'});
		if (options.icon) {
			$('<i class="notify-icon"/>').addClass(options.icon).css(options.error ? {color: '#ff0000', 'font-size': '20px'} : {'font-size': '20px'}).appendTo($wrapper);
		}
		if (options.content) {
			$('<div class="notify-content"></div>').css({display: 'inline-block', margin: '0 5px'}).wrapInner(options.content).appendTo($wrapper);
		}
		setTimeout(function() {
			$wrapper.fadeIn();
			if (options.delay) {
				setTimeout(function() {
					$wrapper.fadeOut(function() {
						$wrapper.remove();
					});
				}, options.delay);
			}
		}, 200);
		return $.extend($wrapper, {
			close: function(timeout) {
				setTimeout(function() {
					$wrapper.fadeOut(function() {
						$wrapper.remove();
					});
				}, timeout || '0');
			},
			update: function(content, icon) {
				this.find('.notify-content').empty().append(content);
				if (icon) {
					this.find('i').removeClass().addClass(icon);
				}
				return this;
			}
		});
	};
})(jQuery);
