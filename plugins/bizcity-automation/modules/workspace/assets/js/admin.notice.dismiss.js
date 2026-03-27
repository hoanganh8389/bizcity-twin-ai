"use strict";
jQuery(document).ready(function(){
	jQuery(document).on('click', '.waic-notice-dismiss .notice-dismiss', function(){
		jQuery.sendFormWaic({
			data: {mod: 'workspace', action: 'dismissNotice', 'slug': jQuery(this).closest('.waic-notice-dismiss').attr('data-slug')}
		});
	});
	jQuery(document).on('click', '.waic-notice-dismiss .button-dismiss', function(){
		jQuery(this).closest('.waic-notice-dismiss').find('.notice-dismiss').trigger('click');
	});
});