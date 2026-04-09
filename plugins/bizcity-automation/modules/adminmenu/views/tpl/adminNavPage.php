<?php 
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$props = $this->props;

$bizcityLogoUrl = function_exists('home_url')
	? home_url('/uploads/sites/1258/2026/01/logo_bizcity.vn_.fw_.png')
	: '/uploads/sites/1258/2026/01/logo_bizcity.vn_.fw_.png';
?>

<style>
    /* BizCity admin style tweaks (lightweight override) */

    /* BizCity admin style tweaks (lightweight override) */

    /* Fallback variables (nếu theme/admin chưa define) */
    :root{
        --bc-card:#ffffff;
        --bc-border:#e6e8ee;
        --bc-radius:16px;
        --bc-shadow:0 10px 30px rgba(0,0,0,.06);
		font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif !important;
        
    }
	.wbw-plugin .wbw-content, .wbw-plugin {font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif !important;}


    /* FIX: thiếu dấu chấm ở selector */
    .wbw-wrap{
        font-family:"Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif !important;
        background:#f6f7fb;
		margin:0 !important;
		padding:0 !important;
    }

    /* Main card */
    .wbw-wrap .wbw-plugin.wbw-main{
        background:var(--bc-card) !important;
        /*border:1px solid var(--bc-border) !important;
        border-radius:var(--bc-radius) !important;
        box-shadow:var(--bc-shadow) !important;*/
        overflow:hidden;
        padding:0 !important;
        margin:0px !important;
    }
	.waic-flow-coltrols {gap:2px !important; padding:2px !important;}
	.waic-flow-title {max-width:100px !important;}
    /* Header (dark) */
    .wbw-wrap .wbw-header{
        background:#010a14 !important;
        border-bottom:0px solid #0c151e !important;
    }

    .wbw-wrap .wbw-head{padding:14px 18px; background: #0c151e !important}
    .wbw-wrap .wbw-logo img{border-radius:10px}

    /* Nav pills */
	.wbw-plugin .wbw-navigation {background: #0c151e !important}
	.bizcity-message-mode .wbw-navigation {display: none !important;}
    .wbw-wrap .wbw-navigation a{border-radius:12px}
	.wbw-plugin .wbw-content .wbw-head { padding:0px !important}
	.wbw-plugin .wbw-content {min-height:0px !important;}

    /* Content */
    .wbw-wrap .wbw-container{padding:18px}

    /* Footer */
    .wbw-wrap .wbw-footer{
        background:#fff;
        border-top:1px solid #e6e8ee;
        padding:12px 18px;
        color:#646970;
    }
    .wbw-wrap .wbw-footer {display:none; min-height:0px !important; padding:0px !important;}
    .wbw-wrap .wbw-footer a:hover{text-decoration:underline}
	.wbw-body-workspace .wbw-ws-block-create { border: 2px solid #3582c4cc !important; }
	.wbw-ws-block {min-height:40px !important;}
	.waic-flow-sidebar { right:0 !important; left:auto !important; width: 280px; box-shadow:var(--bc-shadow) !important;}
	
	.wbw-button-small {   min-width:10px !important; padding: 0px 10px !important; background-color: #0f208a !important;color: #ffffff !important; }
	.wbw-button-small:hover {background-color: #4355cf !important; cursor: pointer;color: #ffffff !important; }
    .wbw-button-main {background-color: #ff5c35 !important; }
	.waic-button-icon {background-color: #000000 !important; }
	.wbw-button-success {background-color: #28a745 !important;color: #ffffff !important; }
	.waic-button-icon img {width: 180%; height: auto; margin-left: -4px;}
	.wbw-button-success:hover {background-color: #7a0289 !important;}
	.waic-modal-header {padding: 10px 25px !important; }
	.waic-modal-footer {padding: 10px 25px !important; }
	.waic-dataflow-header {padding: 5px 15px !important; }
	.waic-dataflow-column {padding: 10px !important; padding-top:0px !important; }
	.column-header {border:0px solid #ffffff !important;  margin-bottom: 10px !important; padding:0px }
	.waic-variable-preview {position: relative !important;padding: 5px !important;}
	.waic-dataflow-column .column-header h5 {padding: 0px !important; padding-bottom:10px !important;  }
	.settings-form {gap:0px !important;}
	.draggable-variable-btn:hover {background-color: #0f208a !important; color: #ffffff !important; }
	.waic-sidebar-field input, .waic-sidebar-field textarea, .waic-sidebar-field select {
		border: 1px solid #d1d5db !important;
		border-radius: 8px !important;
		padding: 0px 10px !important;
		min-height: 32px !important;
	}

	.drag-active:before { padding: 2px !important; margin: 0px !important; margin-top: -2px; border:2px dashed #c9781b56 !important;  }
	.drag-active {background: #fff !important; }
	.drag-active:hover {background: #fff !important; cursor: pointer;}
	.drag-active.active {background: #fff !important; }
	.drag-active.selected {background: #fff !important;}

	#waic-workflow-root, #waic-workflow-root #waic-flow-canvas {
			height: 80vh !important;
		}

	.waic-flow-control {box-shadow:  var(--bc-shadow) !important;}
	 :root {
    --bc-card: #ffffff;
    --bc-border: #e6e8ee;
    --bc-radius: 16px;
    --bc-shadow: 0 10px 30px rgba(0, 0, 0, .2) !important;
	
	}
	.execution-status-success {
		border: 0px solid #28a745 !important;
	}	
	.wbw-button-red {
     animation: none !important;
	}
	.react-flow__node-action, .react-flow__node-logic, .react-flow__node-trigger {
		border-radius: 12px !important;
		box-shadow: var(--bc-shadow) !important;
		}
	.wbw-button {border-radius: 12px !important;}	
	.waic-flow-error-node {font-size: 20px !important;}
	/* Keep alignment */
    .wbw-wrap .wbw-plugin .wbw-navigation{margin-left:0 !important}
</style>

<div class="wbw-wrap<?php if ( ! empty( $_GET['bizcity_message'] ) ) echo ' bizcity-message-mode'; ?>">
	<div class="wbw-plugin wbw-main">
		<section class="wbw-content">
			<div class="wbw-header wbw-sticky">
				<div class="wbw-head">
					
					<nav class="wbw-navigation">
						<ul>
							<?php foreach ($props['tabs'] as $tabKey => $t) { ?>
								<?php 
								if (isset($t['hidden']) && $t['hidden']) {
									continue;
								}
								?>
								<li class="wbw-tab-nav <?php echo ( $props['activeTab'] == $tabKey ? 'active' : '' ); ?>">
									<a href="<?php echo esc_url($t['url']); ?>"><?php echo esc_html($t['label']); ?></a>
								</li>
							<?php } ?>
							
						</ul>
					</nav>
					<div class="wbw-info-right">
                        <?php
                        $promoMod = WaicFrame::_()->getModule('promo');
                        if ($promoMod && method_exists($promoMod, 'isEndGuide') && !$promoMod->isEndGuide()) { ?>
                            <img class="waic-start-guide" src="<?php echo esc_url( WAIC_IMG_PATH . '/guide.png'); ?>" alt="Hướng dẫn nhanh">
                        <?php } ?>
                    </div>
				</div>
				<?php if (!empty($props['bread'])) { ?>
				<nav class="wbw-bread-crumbs">
					<ul>
						<li><?php echo esc_html($props['tabs'][$props['activeTab']]['label']); ?></li>
						<?php if ($props['lastBread']) { ?>
							<li class="wbw-bread-separator">|</li>
							<li class="wbw-bread-last">
								<?php if ($props['lastBreadId']) { ?>
								<div id="<?php echo esc_attr($props['lastBreadId']); ?>">
									<?php 
								} 
								echo esc_html($props['lastBread']); 
								if ($props['lastBreadId']) { 
									?>
								</div>
								<?php } ?>
							</li>
						<?php } ?>
					</ul>
				</nav>
				<?php } ?>
			</div>
			<div class="wbw-container">
				<?php WaicHtml::echoEscapedHtml($props['content']); ?>
				<div class="wbw-clear"></div>
			</div>
			<div class="wbw-footer">
				<span class="wbw-footer-text">
					<?php echo esc_html__('BizCity Workflow — Công cụ tự động hoá trong quản trị.', 'ai-copilot-content-generator'); ?>
					<a href="https://bizcity.vn/" target="_blank" rel="noopener">bizcity.vn</a>
				</span>
			</div>
		</section>
	</div>
	<div class="wbw-plugin-loader">
		<div class="waic-loader">
			<div class="waic-loader-bar bar1"></div>
			<div class="waic-loader-bar bar2"></div>
		</div>
	</div>
</div>
<?php WaicHtml::echoEscapedHtml($props['guide']); ?>