<?php
/**
 * Bizcity Twin AI — WebChat Timeline Handler
 * Quản lý timeline view / Timeline view manager (Relevance AI style)
 *
 * - Display task execution steps / Hiển thị các bước thực hiện
 * - Track actions, tools, credits / Theo dõi hành động, công cụ, credits
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_WebChat_Timeline {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get timeline data for a session or task
     */
    public function get_timeline($session_id = '', $task_id = '') {
        $db = BizCity_WebChat_Database::instance();
        
        if (!empty($task_id)) {
            // Get specific task timeline
            return $this->format_task_timeline($db->get_task_timeline($task_id));
        }
        
        if (!empty($session_id)) {
            // Get all tasks for session
            $tasks = $db->get_session_tasks($session_id, 10);
            return $this->format_session_timeline($tasks);
        }
        
        return [];
    }
    
    /**
     * Format task timeline for display (Relevance AI style)
     */
    private function format_task_timeline($data) {
        if (!$data || !isset($data['task'])) {
            return null;
        }
        
        $task = $data['task'];
        $steps = $data['steps'] ?? [];
        
        $timeline = [
            'task_id' => $task->task_id,
            'task_name' => $task->task_name,
            'triggered_by' => $task->triggered_by,
            'status' => $task->task_status,
            'details' => [
                'created' => $task->triggered_by,
                'status' => $this->get_status_label($task->task_status),
                'date_created' => $this->format_date($task->started_at),
                'actions_used' => $task->actions_used,
                'credits_used' => $task->credits_used,
                'run_time' => $this->format_duration($task->run_time_seconds),
            ],
            'steps' => [],
        ];
        
        // Format steps
        foreach ($steps as $step) {
            $timeline['steps'][] = [
                'step_id' => $step->step_id,
                'type' => $step->step_type,
                'name' => $step->step_name,
                'status' => $step->step_status,
                'input' => $step->input_data ? json_decode($step->input_data, true) : null,
                'output' => $step->output_data ? json_decode($step->output_data, true) : null,
                'duration' => $this->format_duration_ms($step->duration_ms),
                'time' => $this->format_relative_time($step->created_at),
            ];
        }
        
        return $timeline;
    }
    
    /**
     * Format session timeline (list of tasks)
     */
    private function format_session_timeline($tasks) {
        $timeline = [];
        
        foreach ($tasks as $task) {
            $timeline[] = [
                'task_id' => $task->task_id,
                'task_name' => $task->task_name,
                'triggered_by' => $task->triggered_by,
                'status' => $this->get_status_label($task->task_status),
                'status_code' => $task->task_status,
                'date' => $this->format_relative_time($task->started_at),
                'actions' => $task->actions_used,
                'credits' => $task->credits_used,
            ];
        }
        
        return $timeline;
    }
    
    /**
     * Get status label with icon
     */
    private function get_status_label($status) {
        $labels = [
            'pending' => ['label' => 'Pending', 'icon' => '⏳'],
            'running' => ['label' => 'Running', 'icon' => '🔄'],
            'paused' => ['label' => 'Paused', 'icon' => '⏸️'],
            'completed' => ['label' => 'Completed', 'icon' => '✅'],
            'failed' => ['label' => 'Failed', 'icon' => '❌'],
        ];
        
        return $labels[$status] ?? ['label' => 'Unknown', 'icon' => '❓'];
    }
    
    /**
     * Format date
     */
    private function format_date($datetime) {
        if (empty($datetime)) return '';
        return date_i18n('M j Y g:i A', strtotime($datetime));
    }
    
    /**
     * Format relative time
     */
    private function format_relative_time($datetime) {
        if (empty($datetime)) return '';
        return human_time_diff(strtotime($datetime), current_time('timestamp')) . ' ago';
    }
    
    /**
     * Format duration (seconds)
     */
    private function format_duration($seconds) {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
    
    /**
     * Format duration (milliseconds)
     */
    private function format_duration_ms($ms) {
        if ($ms < 1000) {
            return $ms . 'ms';
        }
        return $this->format_duration(floor($ms / 1000));
    }
    
    /**
     * Create a new task and start timeline tracking
     */
    public function start_task($data) {
        $db = BizCity_WebChat_Database::instance();
        
        $task_id = $db->create_task([
            'session_id' => $data['session_id'] ?? '',
            'user_id' => $data['user_id'] ?? 0,
            'triggered_by' => $data['triggered_by'] ?? '',
            'task_name' => $data['task_name'] ?? 'WebChat Task',
            'workflow_id' => $data['workflow_id'] ?? 0,
        ]);
        
        // Add trigger step
        $db->add_task_step([
            'task_id' => $task_id,
            'step_type' => 'trigger',
            'step_name' => 'Triggered by ' . ($data['triggered_by'] ?? 'User'),
            'step_status' => 'completed',
            'input_data' => $data['input'] ?? null,
        ]);
        
        return $task_id;
    }
    
    /**
     * Add a step to task
     */
    public function add_step($task_id, $step_data) {
        $db = BizCity_WebChat_Database::instance();
        
        return $db->add_task_step([
            'task_id' => $task_id,
            'step_type' => $step_data['type'] ?? 'action',
            'step_name' => $step_data['name'] ?? '',
            'step_status' => $step_data['status'] ?? 'running',
            'input_data' => $step_data['input'] ?? null,
        ]);
    }
    
    /**
     * Complete a step
     */
    public function complete_step($step_id, $output = null, $status = 'completed') {
        $db = BizCity_WebChat_Database::instance();
        return $db->complete_task_step($step_id, $output, $status);
    }
    
    /**
     * Complete task
     */
    public function complete_task($task_id, $summary = []) {
        $db = BizCity_WebChat_Database::instance();
        
        // Update task stats
        $db->update_task($task_id, [
            'actions_used' => $summary['actions'] ?? 0,
            'credits_used' => $summary['credits'] ?? 0,
            'run_time_seconds' => $summary['run_time'] ?? 0,
        ]);
        
        // Mark as completed
        $db->complete_task($task_id, $summary['status'] ?? 'completed');
        
        // Add final response step
        if (!empty($summary['response'])) {
            $db->add_task_step([
                'task_id' => $task_id,
                'step_type' => 'response',
                'step_name' => 'Final Response',
                'step_status' => 'completed',
                'output_data' => ['message' => $summary['response']],
            ]);
        }
        
        return true;
    }
    
    /**
     * Get linked tools (for timeline sidebar)
     */
    public function get_linked_tools($task_id) {
        global $wpdb;
        $steps_table = $wpdb->prefix . 'bizcity_webchat_task_steps';
        $tools_table = $wpdb->prefix . 'bizcity_webchat_tools';
        
        // Get tools used in task steps
        $tool_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(meta, '$.tool_id')) 
             FROM {$steps_table} 
             WHERE task_id = %s AND step_type = 'tool'",
            $task_id
        ));
        
        if (empty($tool_ids)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($tool_ids), '%s'));
        $tools = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tools_table} WHERE tool_id IN ({$placeholders})",
            ...$tool_ids
        ));
        
        return array_map(function($tool) {
            return [
                'id' => $tool->tool_id,
                'name' => $tool->tool_name,
                'icon' => $tool->tool_icon,
                'type' => $tool->tool_type,
            ];
        }, $tools);
    }
}
