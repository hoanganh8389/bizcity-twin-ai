/**
 * Bizcity Legal Knowledge Graph — D3.js v7 Visualization
 *
 * @package    Bizcity_Twin_AI
 * @since      5.2.2 (2026-04-23)
 *
 * Depends on: d3 (v7), window.bizLegalGraph = { restBase, restNonce, graphId, entityColors }
 */

/* global d3, bizLegalGraph */
( function ( d3, cfg ) {
    'use strict';

    if ( typeof d3 === 'undefined' ) {
        document.getElementById( 'blg-graph-loading' ).textContent = '❌ D3.js không tải được.';
        return;
    }

    // ── Constants ─────────────────────────────────────────────────────────

    const NODE_RADIUS   = 12;
    const MAX_NODES_DEFAULT = 200;

    // ── State ─────────────────────────────────────────────────────────────

    let simulation  = null;
    let zoomBehavior = null;
    let currentGraphData = null;
    let selectedNodeId   = null;
    let activeTab = 'neighbors';
    let searchTimeout = null;

    // ── DOM refs ──────────────────────────────────────────────────────────

    const $svg          = document.getElementById( 'blg-graph-svg' );
    const $loading      = document.getElementById( 'blg-graph-loading' );
    const $inspDefault  = document.getElementById( 'blg-inspector-default' );
    const $inspDetail   = document.getElementById( 'blg-inspector-detail' );
    const $inspLoading  = document.getElementById( 'blg-inspector-loading' );
    const $inspHeader   = document.getElementById( 'blg-inspector-header' );
    const $neighborsList = document.getElementById( 'blg-neighbors-list' );
    const $chunksList   = document.getElementById( 'blg-chunks-list' );
    const $buildStatus  = document.getElementById( 'blg-build-status' );
    const $searchInput  = document.getElementById( 'blg-search-input' );
    const $filterType   = document.getElementById( 'blg-filter-type' );
    const $filterRelation = document.getElementById( 'blg-filter-relation' );

    // ── Helpers ───────────────────────────────────────────────────────────

    function nodeColor( type ) {
        return cfg.entityColors[ type ] || '#9ca3af';
    }

    function apiFetch( path, options ) {
        const url    = cfg.restBase + ( path.startsWith( '/' ) ? path : '/' + path );
        const headers = {
            'Content-Type': 'application/json',
            'X-WP-Nonce':   cfg.restNonce,
        };
        return fetch( url, Object.assign( { headers }, options ) )
            .then( r => r.json() );
    }

    function showLoading( msg ) {
        $loading.textContent = msg || '⏳ Đang tải...';
        $loading.style.display = 'flex';
    }

    function hideLoading() {
        $loading.style.display = 'none';
    }

    // ── D3 setup ──────────────────────────────────────────────────────────

    const svg = d3.select( $svg );
    const rootG = svg.append( 'g' ).attr( 'class', 'blg-root' );

    // Arrow marker
    svg.append( 'defs' ).append( 'marker' )
        .attr( 'id', 'blg-arrow' )
        .attr( 'viewBox', '0 -5 10 10' )
        .attr( 'refX', NODE_RADIUS + 10 )
        .attr( 'refY', 0 )
        .attr( 'markerWidth',  6 )
        .attr( 'markerHeight', 6 )
        .attr( 'orient', 'auto' )
        .append( 'path' )
        .attr( 'd', 'M0,-5L10,0L0,5' )
        .attr( 'fill', '#6b7280' );

    // Zoom
    zoomBehavior = d3.zoom()
        .scaleExtent( [ 0.1, 5 ] )
        .on( 'zoom', ( e ) => rootG.attr( 'transform', e.transform ) );

    svg.call( zoomBehavior );

    // ── Graph render ──────────────────────────────────────────────────────

    function renderGraph( data ) {
        currentGraphData = data;

        rootG.selectAll( '*' ).remove();

        if ( ! data.nodes || data.nodes.length === 0 ) {
            hideLoading();
            $loading.textContent = '📭 Graph trống — chưa có node nào.';
            $loading.style.display = 'flex';
            return;
        }

        const width  = $svg.clientWidth  || 900;
        const height = $svg.clientHeight || 600;

        svg.attr( 'viewBox', `0 0 ${width} ${height}` );

        // Edge layer
        const edgeG = rootG.append( 'g' ).attr( 'class', 'blg-edges' );
        const link  = edgeG.selectAll( 'line' )
            .data( data.edges )
            .enter().append( 'line' )
            .attr( 'class', 'blg-edge' )
            .attr( 'marker-end', 'url(#blg-arrow)' )
            .attr( 'stroke-width', d => Math.max( 1, ( d.confidence || 0.5 ) * 3 ) )
            .attr( 'stroke', d => d.verified ? '#22c55e' : '#9ca3af' )
            .attr( 'stroke-dasharray', d => d.verified ? '' : '4 3' );

        // Edge label layer
        const edgeLabelG = rootG.append( 'g' ).attr( 'class', 'blg-edge-labels' );
        const edgeLabel = edgeLabelG.selectAll( 'text' )
            .data( data.edges )
            .enter().append( 'text' )
            .attr( 'class', 'blg-edge-label' )
            .text( d => ( d.relation || '' ).replace( /_/g, ' ' ) );

        // Node layer
        const nodeG = rootG.append( 'g' ).attr( 'class', 'blg-nodes' );
        const drag  = d3.drag()
            .on( 'start', onDragStart )
            .on( 'drag',  onDrag )
            .on( 'end',   onDragEnd );

        const node = nodeG.selectAll( 'g' )
            .data( data.nodes )
            .enter().append( 'g' )
            .attr( 'class', 'blg-node' )
            .attr( 'data-id', d => d.id )
            .call( drag )
            .on( 'click', ( event, d ) => {
                event.stopPropagation();
                selectNode( d.db_id );
            } )
            .on( 'mouseenter', ( event, d ) => showNodeTooltip( event, d ) )
            .on( 'mouseleave', hideNodeTooltip );

        node.append( 'circle' )
            .attr( 'r', d => NODE_RADIUS + Math.min( 6, Math.sqrt( d.weight || 1 ) ) )
            .attr( 'fill',   d => nodeColor( d.type ) )
            .attr( 'stroke', '#1f2937' )
            .attr( 'stroke-width', 1.5 );

        node.append( 'text' )
            .attr( 'class', 'blg-node-label' )
            .attr( 'dy', 4 )
            .text( d => truncateLabel( d.label, 18 ) );

        // Simulation
        if ( simulation ) simulation.stop();

        simulation = d3.forceSimulation( data.nodes )
            .force( 'link',   d3.forceLink( data.edges ).id( d => d.id ).distance( 80 ).strength( 0.8 ) )
            .force( 'charge', d3.forceManyBody().strength( -200 ) )
            .force( 'center', d3.forceCenter( width / 2, height / 2 ) )
            .force( 'collide', d3.forceCollide( NODE_RADIUS + 8 ) )
            .on( 'tick', () => {
                link
                    .attr( 'x1', d => d.source.x )
                    .attr( 'y1', d => d.source.y )
                    .attr( 'x2', d => d.target.x )
                    .attr( 'y2', d => d.target.y );

                edgeLabel
                    .attr( 'x', d => ( d.source.x + d.target.x ) / 2 )
                    .attr( 'y', d => ( d.source.y + d.target.y ) / 2 );

                node.attr( 'transform', d => `translate(${d.x},${d.y})` );
            } );

        hideLoading();

        // Re-highlight selected if still in data
        if ( selectedNodeId ) {
            highlightNode( selectedNodeId );
        }
    }

    // ── Drag handlers ──────────────────────────────────────────────────────

    function onDragStart( event, d ) {
        if ( ! event.active ) simulation.alphaTarget( 0.3 ).restart();
        d.fx = d.x;
        d.fy = d.y;
    }

    function onDrag( event, d ) {
        d.fx = event.x;
        d.fy = event.y;
    }

    function onDragEnd( event, d ) {
        if ( ! event.active ) simulation.alphaTarget( 0 );
        d.fx = null;
        d.fy = null;
    }

    // ── Node tooltip ──────────────────────────────────────────────────────

    const tooltip = d3.select( 'body' ).append( 'div' )
        .attr( 'class', 'blg-tooltip' )
        .style( 'display', 'none' );

    function showNodeTooltip( event, d ) {
        tooltip
            .html( `<strong>${escHtml( d.label )}</strong><br><em>${d.type}</em><br>weight: ${d.weight || 0}` )
            .style( 'display', 'block' )
            .style( 'left', ( event.pageX + 12 ) + 'px' )
            .style( 'top',  ( event.pageY - 8 ) + 'px' );
    }

    function hideNodeTooltip() {
        tooltip.style( 'display', 'none' );
    }

    // ── Node highlight ────────────────────────────────────────────────────

    function highlightNode( dbId ) {
        const nodeDbId = String( dbId );
        svg.selectAll( '.blg-node circle' )
            .attr( 'stroke', n => String( n.db_id ) === nodeDbId ? '#fbbf24' : '#1f2937' )
            .attr( 'stroke-width', n => String( n.db_id ) === nodeDbId ? 3 : 1.5 );
    }

    // ── Inspector pane ────────────────────────────────────────────────────

    function showInspectorDefault() {
        $inspDefault.style.display = '';
        $inspDetail.style.display  = 'none';
        $inspLoading.style.display = 'none';
        selectedNodeId = null;
        svg.selectAll( '.blg-node circle' )
            .attr( 'stroke', '#1f2937' )
            .attr( 'stroke-width', 1.5 );
    }

    function selectNode( dbId ) {
        if ( selectedNodeId === dbId ) return;
        selectedNodeId = dbId;
        $inspDefault.style.display  = 'none';
        $inspDetail.style.display   = 'none';
        $inspLoading.style.display  = '';
        highlightNode( dbId );

        apiFetch( '/node/' + dbId )
            .then( data => renderNodeInspector( data ) )
            .catch( err => {
                $inspLoading.style.display = 'none';
                $inspDetail.style.display  = '';
                $inspHeader.innerHTML = '<p>❌ Lỗi tải chi tiết node.</p>';
                console.error( err );
            } );
    }

    function renderNodeInspector( d ) {
        $inspLoading.style.display = 'none';

        const colorHex = nodeColor( d.type );
        $inspHeader.innerHTML = `
            <h3 class="blg-insp-title" style="border-left: 4px solid ${escHtml(colorHex)}">
                ${escHtml( d.label )}
            </h3>
            <p class="blg-insp-meta">
                <span class="blg-badge" style="background:${escHtml(colorHex)}">${escHtml(d.type)}</span>
                &nbsp;weight: <strong>${d.weight || 0}</strong>
                &nbsp;doc_id: <strong>${d.doc_id || '—'}</strong>
            </p>
        `;

        // neighbors tab
        const neighbors = d.neighbors || [];
        if ( neighbors.length === 0 ) {
            $neighborsList.innerHTML = '<p class="blg-empty">Không có quan hệ nào.</p>';
        } else {
            $neighborsList.innerHTML = neighbors.map( n => `
                <div class="blg-neighbor-row" data-db-id="${escAttr(n.node_db_id)}" tabindex="0" role="button">
                    <span class="blg-neighbor-dir blg-neighbor-${escAttr(n.direction)}">${n.direction === 'out' ? '→' : '←'}</span>
                    <span class="blg-neighbor-rel">${escHtml( (n.relation||'').replace(/_/g,' ') )}</span>
                    <span class="blg-neighbor-label">${escHtml( n.label )}</span>
                    <span class="blg-badge blg-badge-sm" style="background:${escHtml(nodeColor(n.type))}">${escHtml(n.type)}</span>
                    ${n.verified ? '<span class="blg-verified-badge">✓</span>' : ''}
                </div>
            ` ).join( '' );

            $neighborsList.querySelectorAll( '.blg-neighbor-row' ).forEach( row => {
                row.addEventListener( 'click', () => selectNode( parseInt( row.dataset.dbId, 10 ) ) );
            } );
        }

        // chunks tab
        const chunks = d.chunks_preview || [];
        if ( chunks.length === 0 ) {
            $chunksList.innerHTML = '<p class="blg-empty">Không có chunk nào.</p>';
        } else {
            $chunksList.innerHTML = chunks.map( c => `
                <div class="blg-chunk-card">
                    <p class="blg-chunk-text">${escHtml( c.text.substring(0, 300) )}${c.text.length > 300 ? '…' : ''}</p>
                    <p class="blg-chunk-meta">doc_id: <strong>${escHtml( String(c.doc_id||'') )}</strong></p>
                </div>
            ` ).join( '' );
        }

        $inspDetail.style.display = '';
        switchTab( activeTab );
    }

    // ── Tabs ──────────────────────────────────────────────────────────────

    document.querySelectorAll( '.blg-tab-btn' ).forEach( btn => {
        btn.addEventListener( 'click', () => switchTab( btn.dataset.tab ) );
    } );

    function switchTab( tab ) {
        activeTab = tab;
        document.querySelectorAll( '.blg-tab-btn' ).forEach( b => {
            b.classList.toggle( 'active', b.dataset.tab === tab );
        } );
        document.getElementById( 'blg-tab-neighbors' ).style.display = tab === 'neighbors' ? '' : 'none';
        document.getElementById( 'blg-tab-chunks' ).style.display    = tab === 'chunks'    ? '' : 'none';
    }

    // ── Zoom controls ─────────────────────────────────────────────────────

    document.getElementById( 'blg-zoom-in' ).addEventListener( 'click', () => {
        svg.transition().call( zoomBehavior.scaleBy, 1.4 );
    } );
    document.getElementById( 'blg-zoom-out' ).addEventListener( 'click', () => {
        svg.transition().call( zoomBehavior.scaleBy, 0.7 );
    } );
    document.getElementById( 'blg-zoom-reset' ).addEventListener( 'click', () => {
        svg.transition().call( zoomBehavior.transform, d3.zoomIdentity );
    } );

    // Click backdrop → deselect
    svg.on( 'click', () => showInspectorDefault() );

    // ── Load graph ────────────────────────────────────────────────────────

    function loadGraph() {
        showLoading( '⏳ Đang tải graph…' );

        const params = new URLSearchParams( {
            graph_id:  cfg.graphId || '',
            max_nodes: MAX_NODES_DEFAULT,
        } );
        if ( $filterType.value )     params.set( 'type',     $filterType.value );
        if ( $filterRelation.value ) params.set( 'relation', $filterRelation.value );

        apiFetch( '?' + params.toString() )
            .then( data => {
                if ( data.code ) {
                    $loading.textContent = '❌ Lỗi: ' + ( data.message || data.code );
                    $loading.style.display = 'flex';
                    return;
                }
                renderGraph( data );
            } )
            .catch( err => {
                $loading.textContent = '❌ Không kết nối được tới REST API.';
                $loading.style.display = 'flex';
                console.error( err );
            } );
    }

    // ── Search ────────────────────────────────────────────────────────────

    $searchInput.addEventListener( 'input', () => {
        clearTimeout( searchTimeout );
        const q = $searchInput.value.trim();
        if ( ! q ) {
            // Remove search highlights
            svg.selectAll( '.blg-node' ).classed( 'blg-node-dimmed', false );
            return;
        }
        searchTimeout = setTimeout( () => {
            const params = new URLSearchParams( { q, limit: 20 } );
            if ( cfg.graphId ) params.set( 'graph_id', cfg.graphId );

            apiFetch( '/search?' + params.toString() )
                .then( results => highlightSearchResults( results ) )
                .catch( console.error );
        }, 400 );
    } );

    function highlightSearchResults( results ) {
        if ( ! Array.isArray( results ) ) return;
        const matchIds = new Set( results.map( r => String( r.db_id ) ) );

        svg.selectAll( '.blg-node' )
            .classed( 'blg-node-dimmed',    n => ! matchIds.has( String( n.db_id ) ) )
            .classed( 'blg-node-highlight', n =>   matchIds.has( String( n.db_id ) ) );
    }

    // ── Build batch ───────────────────────────────────────────────────────

    document.getElementById( 'blg-btn-build' ).addEventListener( 'click', () => {
        $buildStatus.textContent = '⏳ Đang build…';

        apiFetch( '/build-batch', {
            method: 'POST',
            body:   JSON.stringify( { batch: 10 } ),
        } )
        .then( data => {
            if ( data.success === false || data.code ) {
                $buildStatus.textContent = '❌ ' + ( data.message || data.code );
            } else {
                $buildStatus.textContent = `✅ +${data.processed||0} triples, skipped ${data.skipped||0} chunks`;
                loadGraph();
            }
        } )
        .catch( () => { $buildStatus.textContent = '❌ Lỗi kết nối.'; } );
    } );

    // ── Events ────────────────────────────────────────────────────────────

    document.getElementById( 'blg-btn-reload' ).addEventListener( 'click', loadGraph );
    $filterType.addEventListener( 'change', loadGraph );
    $filterRelation.addEventListener( 'change', loadGraph );

    // ── Utils ──────────────────────────────────────────────────────────────

    function truncateLabel( str, max ) {
        if ( ! str ) return '';
        return str.length > max ? str.substring( 0, max ) + '…' : str;
    }

    function escHtml( str ) {
        const d = document.createElement( 'div' );
        d.appendChild( document.createTextNode( String( str ) ) );
        return d.innerHTML;
    }

    function escAttr( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }

    // ── Boot ──────────────────────────────────────────────────────────────

    // Scripts enqueued in footer: DOM is already ready.
    // Use DOMContentLoaded as fallback for edge cases where it still fires.
    let _booted = false;
    function boot() {
        if ( _booted ) return;
        _booted = true;
        loadGraph();
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }

} )( window.d3, window.bizLegalGraph || {} );
