<?php 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
$props = $this->props;
$tmpUrl = $props['tmp_url'];

/**
 * BizCity: Load danh sách kịch bản (scenarios) trực tiếp từ DB (multisite-safe theo prefix hiện tại)
 * - Lấy theo task_id từ waic_workflows, join waic_tasks để lấy title
 */
$bizcityScenarios = [];
try {
    global $wpdb;
    $tblWorkflows = $wpdb->prefix . WAIC_DB_PREF . 'workflows';
    $tblTasks     = $wpdb->prefix . WAIC_DB_PREF . 'tasks';

    #$existsW = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tblWorkflows));
    #$existsT = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tblTasks));

    #if ($existsW === $tblWorkflows && $existsT === $tblTasks) {
        $bizcityScenarios = $wpdb->get_results(
            "
            SELECT 
                w.task_id,
                MAX(w.updated) AS wf_updated,
                MAX(w.status)  AS wf_status,
                MAX(w.tr_code) AS tr_code,
                MAX(w.tr_hook) AS tr_hook,
                t.title        AS task_title,
                t.status       AS task_status,
                t.updated      AS task_updated
            FROM {$tblWorkflows} w
            LEFT JOIN {$tblTasks} t ON t.id = w.task_id
            GROUP BY w.task_id, t.title, t.status, t.updated
            ORDER BY COALESCE(MAX(w.updated), t.updated) DESC
            LIMIT 200
            ",
            ARRAY_A
        );
   # }
} catch (\Throwable $e) {
    $bizcityScenarios = [];
}
?>
<section class="wbw-body-workspace">

    <?php if (!empty($_GET['waic_notice']) && $_GET['waic_notice'] === 'scenario_deleted') { ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Scenario deleted successfully.', 'ai-copilot-content-generator'); ?></p>
        </div>
    <?php } ?>

    <div class="waic-tab-header waic-row-coltrols waic-wide" style="margin-top:14px;">
        <div class="waic-row-coltrol wbw-group-title">
            <?php esc_html_e('Scenarios', 'ai-copilot-content-generator'); ?>
        </div>
        <div class="waic-row-coltrol" style="display:flex; gap:8px; align-items:center;">
            <a href="<?php echo esc_url($props['new_url']); ?>" class="button button-primary">
                <?php esc_html_e('Add New', 'ai-copilot-content-generator'); ?>
            </a>
			<button type="button" id="waicImportWorkflowJsonBtn" class="button button-secondary">
				<?php esc_html_e('Import Template - Import JSON', 'ai-copilot-content-generator'); ?>
			</button>
			<input type="file" id="waicImportWorkflowJsonFile" accept="application/json,.json" style="display:none;" />
        </div>
    </div>

    <?php if (!empty($bizcityScenarios)) { ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-primary" style="width:90px;"><?php esc_html_e('Scenario ID', 'ai-copilot-content-generator'); ?></th>
                    <th scope="col" class="manage-column column-title"><?php esc_html_e('Scenario Name', 'ai-copilot-content-generator'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Trigger', 'ai-copilot-content-generator'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Hook', 'ai-copilot-content-generator'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Updated', 'ai-copilot-content-generator'); ?></th>
                    <th scope="col" class="manage-column" style="width:230px;"><?php esc_html_e('Actions', 'ai-copilot-content-generator'); ?></th>
                </tr>
            </thead>
            <tbody id="bizcityScenariosList">
                <?php foreach ($bizcityScenarios as $row) {
                    $taskId = (int)($row['task_id'] ?? 0);
                    if ($taskId <= 0) continue;

                    $title = !empty($row['task_title']) ? (string)$row['task_title'] : ('Scenario #' . $taskId);
                    $trCode = !empty($row['tr_code']) ? (string)$row['tr_code'] : '';
                    $trHook = !empty($row['tr_hook']) ? (string)$row['tr_hook'] : '';

                    $updated = !empty($row['wf_updated']) ? (string)$row['wf_updated'] : (!empty($row['task_updated']) ? (string)$row['task_updated'] : '');
                    $updatedHuman = $updated ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($updated))) : '—';

                    $editUrl = admin_url('admin.php?page=bizcity-workspace&tab=builder&task_id=' . $taskId);
                    if ( ! empty( $_GET['bizcity_iframe'] ) && $_GET['bizcity_iframe'] === '1' ) {
                        $editUrl .= '&bizcity_iframe=1';
                    }

                    $deleteUrl = wp_nonce_url(
                        admin_url('admin-post.php?action=waic_delete_scenario&task_id=' . $taskId),
                        'waic_delete_scenario_' . $taskId
                    );
                ?>
                    <tr>
                        <td class="column-primary"  data-colname="<?php echo esc_attr__('Scenario ID', 'ai-copilot-content-generator'); ?>">
                            <?php echo $taskId; ?>
                        </td>
                        <td data-colname="<?php echo esc_attr__('Scenario Name', 'ai-copilot-content-generator'); ?>">
                            <strong>
                                <a class="row-title" href="<?php echo esc_url($editUrl); ?>">
                                    <?php echo esc_html($title); ?>
                                </a>
                            </strong>
                            <button type="button" class="toggle-row">
                                <span class="screen-reader-text"><?php esc_html_e('Show more details', 'ai-copilot-content-generator'); ?></span>
                            </button>
                        </td>
                        <td data-colname="<?php echo esc_attr__('Trigger', 'ai-copilot-content-generator'); ?>">
                            <?php echo $trCode ? esc_html($trCode) : '—'; ?>
                        </td>
                        <!--- Hook -->
                        <td data-colname="<?php echo esc_attr__('Hook', 'ai-copilot-content-generator'); ?>">
                            <?php echo $trHook ? esc_html($trHook) : '—'; ?>
                        </td>
                        <td data-colname="<?php echo esc_attr__('Updated', 'ai-copilot-content-generator'); ?>">
                            <?php echo $updatedHuman; ?>
                        </td>
                        <td data-colname="<?php echo esc_attr__('Actions', 'ai-copilot-content-generator'); ?>">
                            <a href="<?php echo esc_url($editUrl); ?>" class="button button-small">
                                <?php esc_html_e('Edit', 'ai-copilot-content-generator'); ?>
                            </a>
                            <button
                                type="button"
                                class="button button-small waicExportScenarioJsonBtn"
                                data-task-id="<?php echo esc_attr($taskId); ?>"
                                data-title="<?php echo esc_attr($title); ?>">
                                <?php esc_html_e('Export JSON', 'ai-copilot-content-generator'); ?>
                            </button>
                            <a href="<?php echo esc_url($deleteUrl); ?>"
                               class="button button-small button-link-delete"
                               onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this scenario? This action cannot be undone.', 'ai-copilot-content-generator')); ?>');">
                                <?php esc_html_e('Delete', 'ai-copilot-content-generator'); ?>
                            </a>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } else { ?>
        <p style="margin-top:10px;">
            <?php esc_html_e('No scenarios yet. Click "Add New" to create your first scenario.', 'ai-copilot-content-generator'); ?>
        </p>
    <?php } ?>

    <div class="waic-tab-header waic-row-coltrols waic-wide" style="margin-top:18px;">
        <div class="waic-row-coltrol wbw-group-title">
            <?php esc_html_e('Or start from a template:', 'ai-copilot-content-generator'); ?>
        </div>
        <div class="waic-row-coltrol">
            <input type="search" id="waicSearchTemplate" placeholder="<?php echo esc_attr__('Search templates...', 'ai-copilot-content-generator'); ?>">
            <button class="wbw-button wbw-button-small" id="waicImportTemplate"><?php esc_html_e('Import Template', 'ai-copilot-content-generator'); ?></button>
        </div>
    </div>

    <ul class="wbw-ws-group" id="waicTemplatesList">
    <?php foreach ($props['templates'] as $key => $block) { ?>
        <li class="wbw-ws-block<?php echo empty($block['class']) ? '' : ' ' . esc_attr($block['class']); ?>">
            <a href="<?php echo $tmpUrl . '&task_id=' . esc_attr($key); ?>" class="wbw-feature-link">
                <div class="wbw-ws-block-in">
                    <div class="wbw-ws-block-text">
                        <div class="wbw-ws-title"><?php echo esc_html($block['title']); ?></div>
                        <div class="wbw-ws-desc"><?php echo esc_html($block['desc']); ?></div>
                    </div>
                </div>
            </a>
            <?php echo (empty($block['mode']) ? '<a href="#" class="waic-delete-template" data-id="' . esc_attr($key) . '"><i class="fa fa-close"></i></a>' : ''); ?>
        </li>
    <?php } ?>
    </ul>

    <div class="wbw-clear"></div>
</section>
