<?php
/**
 * Workflow Action: Kling - Poll Status
 * 
 * Poll task status với auto-reschedule qua WP-Cron
 */

if (!defined('ABSPATH')) exit;

require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';

class WaicAction_kl_poll_status extends WaicAction {
    protected $_code  = 'kl_poll_status';
    protected $_order = 211;

    public function __construct($block = null) {
        $this->_name = __('Kling - Poll Status', 'bizcity-video-kling');
        $this->_desc = __('Kiểm tra trạng thái video định kỳ cho đến khi hoàn thành', 'bizcity-video-kling');
        $this->_sublabel = ['name'];
        $this->setBlock($block);

        // Register cron hook
        add_action('waic_kling_poll_event', [$this, 'handle_poll_event'], 10, 2);
    }

    public function getSettings() {
        if (empty($this->_settings)) $this->setSettings();
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = [
            'name' => [
                'type' => 'input',
                'label' => __('Tên Node', 'bizcity-video-kling'),
                'default' => 'Poll Status Kling',
            ],
            'api_key' => [
                'type' => 'input',
                'label' => __('API Key', 'bizcity-video-kling'),
                'default' => '',
            ],
            'endpoint' => [
                'type' => 'input',
                'label' => __('API Endpoint', 'bizcity-video-kling'),
                'default' => 'https://api.piapi.ai/api/v1',
            ],
            'job_id' => [
                'type' => 'input',
                'label' => __('Job ID', 'bizcity-video-kling'),
                'default' => '{{node#kling_create_job.job_id}}',
                'desc' => __('Lấy từ node Create Job trước đó', 'bizcity-video-kling'),
            ],
            'delay_seconds' => [
                'type' => 'select',
                'label' => __('Khoảng thời gian kiểm tra (giây)', 'bizcity-video-kling'),
                'default' => '15',
                'options' => [
                    '5'  => '5s',
                    '10' => '10s',
                    '15' => '15s',
                    '30' => '30s',
                    '60' => '1 phút',
                ],
            ],
            'max_wait_seconds' => [
                'type' => 'select',
                'label' => __('Thời gian chờ tối đa', 'bizcity-video-kling'),
                'default' => '900',
                'options' => [
                    '300'  => '5 phút',
                    '600'  => '10 phút',
                    '900'  => '15 phút',
                    '1800' => '30 phút',
                    '3600' => '1 giờ',
                ],
            ],
        ];
    }

    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'ok'           => __('OK (1/0)', 'bizcity-video-kling'),
            'job_id'       => __('Job ID', 'bizcity-video-kling'),
            'scheduled'    => __('Scheduled (1/0)', 'bizcity-video-kling'),
            'next_poll_in' => __('Next poll in seconds', 'bizcity-video-kling'),
            'message'      => __('Message', 'bizcity-video-kling'),
            'error'        => __('Error message', 'bizcity-video-kling'),
        );
        return $this->_variables;
    }

    public function getResults($taskId, $variables, $step = 0) {
        // Get settings with variable replacement
        $api_key = trim($this->replaceVariables($this->getParam('api_key'), $variables));
        if (empty($api_key)) {
            $api_key = get_option('bizcity_video_kling_api_key', '');
        }
        
        $endpoint = trim($this->replaceVariables($this->getParam('endpoint'), $variables));
        if (empty($endpoint)) {
            $endpoint = get_option('bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1');
        }

        $job_id = trim($this->replaceVariables($this->getParam('job_id'), $variables));
        
        if (empty($job_id)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => 'Missing job_id'),
                'error' => 'Missing job_id',
                'status' => 7,
            );
            return $this->_results;
        }

        $delay = max(5, (int)$this->getParam('delay_seconds', 15));
        $max_wait = max(60, (int)$this->getParam('max_wait_seconds', 900));

        $s = array(
            'api_key'  => $api_key,
            'endpoint' => $endpoint,
            'timeout'  => 60,
        );

        // Schedule first poll
        $this->schedule_poll($job_id, $delay, $max_wait, $s);

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'job_id' => $job_id,
                'scheduled' => 1,
                'next_poll_in' => $delay,
                'message' => sprintf(__('Đã lên lịch kiểm tra mỗi %d giây, tối đa %d giây', 'bizcity-video-kling'), $delay, $max_wait),
            ),
            'error' => '',
            'status' => 1,
        );
        return $this->_results;
    }

    /**
     * Schedule cron event để poll
     */
    protected function schedule_poll(string $job_id, int $delay, int $max_wait, array $settings) {
        $when = time() + $delay;

        // Lưu settings vào transient để cron handler dùng
        $key = waic_kling_job_key($job_id);
        $job = get_transient($key);
        
        if (!is_array($job)) {
            $job = ['job_id' => $job_id, 'created' => time()];
        }

        $job['_poll'] = [
            'delay' => $delay,
            'max_wait' => $max_wait,
            'settings' => [
                'api_key'  => $settings['api_key'] ?? '',
                'endpoint' => $settings['endpoint'] ?? '',
                'timeout'  => $settings['timeout'] ?? 60,
            ],
        ];
        set_transient($key, $job, 7200);

        // Schedule WP-Cron event
        wp_schedule_single_event($when, 'waic_kling_poll_event', [$job_id, $when]);

        waic_kling_log('poll.scheduled', ['job_id' => $job_id, 'in_seconds' => $delay]);
    }

    /**
     * Cron handler: Poll một lần, nếu chưa xong thì reschedule
     */
    public function handle_poll_event(string $job_id, int $scheduled_at) {
        $key = waic_kling_job_key($job_id);
        $job = get_transient($key);

        if (!is_array($job)) {
            waic_kling_log('poll.no_job', ['job_id' => $job_id]);
            return;
        }

        $task_id = $job['task_id'] ?? '';
        if (!$task_id) {
            waic_kling_log('poll.missing_task_id', ['job_id' => $job_id]);
            $job['status'] = 'error';
            $job['error']  = 'Missing task_id';
            set_transient($key, $job, 7200);
            return;
        }

        $created = (int)($job['created'] ?? time());
        $elapsed = time() - $created;

        $poll = $job['_poll'] ?? [];
        $delay = max(5, (int)($poll['delay'] ?? 15));
        $max_wait = max(60, (int)($poll['max_wait'] ?? 900));
        $settings = $poll['settings'] ?? [];

        waic_kling_log('poll.check', ['job_id' => $job_id, 'task_id' => $task_id, 'elapsed' => $elapsed]);

        // Call API to get status
        $r = waic_kling_get_task($settings, $task_id);
        
        if (!$r['ok']) {
            waic_kling_log('poll.api_error', $r);
            
            // Reschedule nếu còn thời gian
            if ($elapsed < $max_wait) {
                wp_schedule_single_event(time() + $delay, 'waic_kling_poll_event', [$job_id, time() + $delay]);
            } else {
                $job['status'] = 'timeout';
                $job['error']  = $r['error'] ?? 'Poll API error';
                set_transient($key, $job, 7200);
            }
            return;
        }

        $payload = $r['data'];
        $status  = waic_kling_normalize_status($payload);

        $job['raw_status'] = $payload;
        $job['status'] = $status ?: 'processing';
        $job['last_check'] = time();

        // Check if done
        if (in_array($status, ['succeeded', 'success', 'completed', 'done'], true)) {
            $video_url = waic_kling_extract_video_url($payload);
            if ($video_url) {
                $job['video_url'] = $video_url;
            }
            $job['done_at'] = time();
            $job['duration'] = $job['done_at'] - $created;
            
            waic_kling_log('poll.completed', [
                'job_id' => $job_id, 
                'video_url' => $job['video_url'] ?? null,
                'duration' => $job['duration'],
            ]);
            
            set_transient($key, $job, 7200);
            
            // Fire hook để các plugin khác có thể xử lý
            do_action('waic_kling_video_completed', $job_id, $job);
            return;
        }

        // Check if failed
        if (in_array($status, ['failed', 'error', 'canceled'], true)) {
            $job['done_at'] = time();
            $job['error'] = 'Task failed with status: ' . $status;
            
            waic_kling_log('poll.failed', ['job_id' => $job_id, 'status' => $status]);
            set_transient($key, $job, 7200);
            
            do_action('waic_kling_video_failed', $job_id, $job);
            return;
        }

        // Still processing, reschedule if within max_wait
        if ($elapsed < $max_wait) {
            wp_schedule_single_event(time() + $delay, 'waic_kling_poll_event', [$job_id, time() + $delay]);
            
            waic_kling_log('poll.rescheduled', [
                'job_id' => $job_id,
                'status' => $status,
                'elapsed' => $elapsed,
                'next_in' => $delay,
            ]);
            
            set_transient($key, $job, 7200);
        } else {
            // Timeout
            $job['status'] = 'timeout';
            $job['error']  = sprintf('Timeout after %d seconds', $elapsed);
            
            waic_kling_log('poll.timeout', ['job_id' => $job_id, 'elapsed' => $elapsed]);
            set_transient($key, $job, 7200);
            
            do_action('waic_kling_video_timeout', $job_id, $job);
        }
    }
}
