<?php
/**
 * Bizcity Twin AI — WebChat Timeline Template
 * Giao diện timeline / Timeline view template (Relevance AI style)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

$timeline = BizCity_WebChat_Timeline::instance();
$session_id = $atts['session_id'] ?? bizcity_webchat_get_session_id();
$task_id = $atts['task_id'] ?? '';

$data = $timeline->get_timeline($session_id, $task_id);
?>

<style>
.bizchat-timeline {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.bizchat-timeline-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 20px;
}

.bizchat-timeline-title {
    font-size: 20px;
    font-weight: 600;
    color: #1b1b23;
}

.bizchat-timeline-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
}

.bizchat-timeline-status.completed {
    background: #d1fae5;
    color: #047857;
}

.bizchat-timeline-status.running {
    background: #dbeafe;
    color: #1d4ed8;
}

.bizchat-timeline-status.failed {
    background: #fee2e2;
    color: #b91c1c;
}

.bizchat-timeline-status.paused {
    background: #fef3c7;
    color: #92400e;
}

.bizchat-timeline-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 12px;
    margin-bottom: 24px;
}

.bizchat-timeline-detail {
    display: flex;
    align-items: center;
    gap: 8px;
}

.bizchat-timeline-detail-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.bizchat-timeline-detail-label {
    font-size: 12px;
    color: #6b7280;
}

.bizchat-timeline-detail-value {
    font-size: 14px;
    font-weight: 500;
    color: #1b1b23;
}

/* Timeline Steps */
.bizchat-timeline-steps {
    position: relative;
}

.bizchat-timeline-step {
    display: flex;
    gap: 16px;
    padding: 16px 0;
    position: relative;
}

.bizchat-timeline-step:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 17px;
    top: 52px;
    bottom: 0;
    width: 2px;
    background: #e5e7eb;
}

.bizchat-timeline-step-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.bizchat-timeline-step.completed .bizchat-timeline-step-icon {
    background: #d1fae5;
}

.bizchat-timeline-step.running .bizchat-timeline-step-icon {
    background: #dbeafe;
}

.bizchat-timeline-step.failed .bizchat-timeline-step-icon {
    background: #fee2e2;
}

.bizchat-timeline-step-content {
    flex: 1;
    min-width: 0;
}

.bizchat-timeline-step-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.bizchat-timeline-step-name {
    font-weight: 500;
    color: #1b1b23;
}

.bizchat-timeline-step-time {
    font-size: 12px;
    color: #9ca3af;
}

.bizchat-timeline-step-body {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
}

.bizchat-timeline-step-message {
    font-size: 14px;
    line-height: 1.6;
    color: #374151;
}

.bizchat-timeline-step-meta {
    display: flex;
    gap: 16px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #f3f4f6;
}

.bizchat-timeline-step-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #6b7280;
}

/* Linked Tools */
.bizchat-timeline-tools {
    margin-top: 24px;
    padding: 16px;
    background: #f8f9fa;
    border-radius: 12px;
}

.bizchat-timeline-tools-title {
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 12px;
}

.bizchat-timeline-tool {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: #fff;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: background 0.2s;
}

.bizchat-timeline-tool:hover {
    background: #f3f4f6;
}

.bizchat-timeline-tool-icon {
    font-size: 20px;
}

.bizchat-timeline-tool-name {
    font-size: 14px;
    color: #1b1b23;
}

/* Empty State */
.bizchat-timeline-empty {
    text-align: center;
    padding: 48px 24px;
    color: #6b7280;
}

.bizchat-timeline-empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
}
</style>

<div class="bizchat-timeline">
    <?php if (empty($data)): ?>
        <div class="bizchat-timeline-empty">
            <div class="bizchat-timeline-empty-icon">📋</div>
            <p>Chưa có dữ liệu timeline.</p>
        </div>
    <?php elseif (!empty($task_id) && isset($data['task_id'])): ?>
        <!-- Single Task Timeline -->
        <div class="bizchat-timeline-header">
            <h2 class="bizchat-timeline-title"><?php echo esc_html($data['task_name'] ?? 'Task'); ?></h2>
            <span class="bizchat-timeline-status <?php echo esc_attr($data['status']); ?>">
                <?php echo esc_html($data['details']['status']['icon'] ?? ''); ?>
                <?php echo esc_html($data['details']['status']['label'] ?? $data['status']); ?>
            </span>
        </div>
        
        <div class="bizchat-timeline-details">
            <div class="bizchat-timeline-detail">
                <div class="bizchat-timeline-detail-icon">⚡</div>
                <div>
                    <div class="bizchat-timeline-detail-label">Triggered by</div>
                    <div class="bizchat-timeline-detail-value"><?php echo esc_html($data['triggered_by']); ?></div>
                </div>
            </div>
            <div class="bizchat-timeline-detail">
                <div class="bizchat-timeline-detail-icon">📅</div>
                <div>
                    <div class="bizchat-timeline-detail-label">Date created</div>
                    <div class="bizchat-timeline-detail-value"><?php echo esc_html($data['details']['date_created']); ?></div>
                </div>
            </div>
            <div class="bizchat-timeline-detail">
                <div class="bizchat-timeline-detail-icon">⚙️</div>
                <div>
                    <div class="bizchat-timeline-detail-label">Actions used</div>
                    <div class="bizchat-timeline-detail-value"><?php echo esc_html($data['details']['actions_used']); ?></div>
                </div>
            </div>
            <div class="bizchat-timeline-detail">
                <div class="bizchat-timeline-detail-icon">💰</div>
                <div>
                    <div class="bizchat-timeline-detail-label">Credits used</div>
                    <div class="bizchat-timeline-detail-value"><?php echo esc_html($data['details']['credits_used']); ?></div>
                </div>
            </div>
            <div class="bizchat-timeline-detail">
                <div class="bizchat-timeline-detail-icon">⏱️</div>
                <div>
                    <div class="bizchat-timeline-detail-label">Run time</div>
                    <div class="bizchat-timeline-detail-value"><?php echo esc_html($data['details']['run_time']); ?></div>
                </div>
            </div>
        </div>
        
        <div class="bizchat-timeline-steps">
            <?php foreach ($data['steps'] as $step): ?>
            <div class="bizchat-timeline-step <?php echo esc_attr($step['status']); ?>">
                <div class="bizchat-timeline-step-icon">
                    <?php
                    $icons = [
                        'trigger' => '⚡',
                        'action' => '▶️',
                        'response' => '💬',
                        'tool' => '🔧',
                        'hil' => '👤',
                    ];
                    echo $icons[$step['type']] ?? '•';
                    ?>
                </div>
                <div class="bizchat-timeline-step-content">
                    <div class="bizchat-timeline-step-header">
                        <span class="bizchat-timeline-step-name"><?php echo esc_html($step['name']); ?></span>
                        <span class="bizchat-timeline-step-time"><?php echo esc_html($step['time']); ?></span>
                    </div>
                    <?php if (!empty($step['output']['message'])): ?>
                    <div class="bizchat-timeline-step-body">
                        <div class="bizchat-timeline-step-message">
                            <?php echo wp_kses_post($step['output']['message']); ?>
                        </div>
                        <?php if (!empty($step['duration'])): ?>
                        <div class="bizchat-timeline-step-meta">
                            <div class="bizchat-timeline-step-meta-item">
                                ⏱️ <?php echo esc_html($step['duration']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
    <?php else: ?>
        <!-- Session Tasks List -->
        <div class="bizchat-timeline-header">
            <h2 class="bizchat-timeline-title">Timeline</h2>
        </div>
        
        <div class="bizchat-timeline-steps">
            <?php foreach ($data as $task): ?>
            <div class="bizchat-timeline-step <?php echo esc_attr($task['status_code']); ?>">
                <div class="bizchat-timeline-step-icon">
                    <?php echo esc_html($task['status']['icon'] ?? '📋'); ?>
                </div>
                <div class="bizchat-timeline-step-content">
                    <div class="bizchat-timeline-step-header">
                        <span class="bizchat-timeline-step-name"><?php echo esc_html($task['task_name']); ?></span>
                        <span class="bizchat-timeline-step-time"><?php echo esc_html($task['date']); ?></span>
                    </div>
                    <div class="bizchat-timeline-step-body">
                        <div class="bizchat-timeline-step-meta">
                            <div class="bizchat-timeline-step-meta-item">
                                👤 <?php echo esc_html($task['triggered_by']); ?>
                            </div>
                            <div class="bizchat-timeline-step-meta-item">
                                ⚙️ <?php echo esc_html($task['actions']); ?> actions
                            </div>
                            <div class="bizchat-timeline-step-meta-item">
                                💰 <?php echo esc_html($task['credits']); ?> credits
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
