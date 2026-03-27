/**
 * BizCity Tarot – Admin JS
 * (Main crawl logic is inline in admin-crawl.php)
 */
( function ( $ ) {
    'use strict';

    // Confirm before bulk operations
    $( document ).on( 'click', '.bct-bulk-action', function ( e ) {
        if ( ! confirm( 'Xác nhận thực hiện thao tác này?' ) ) {
            e.preventDefault();
        }
    } );

} )( jQuery );
