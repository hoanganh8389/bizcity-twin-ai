<?php
/**
 * Workflow Action: Kling - Fetch Video
 * 
 * Download video về Media Library hoặc upload lên R2
 */

if (!defined('ABSPATH')) exit;

require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';

class WaicAction_kl_fetch_video extends WaicAction {
    protected $_code  = 'kl_fetch_video';
    protected $_order = 212;

    public function __construct($block = null) {
        $this->_name = __('Kling - Fetch Video', 'bizcity-video-kling');
        $this->_desc = __('Tải video về Media Library hoặc upload lên R2', 'bizcity-video-kling');
        $this->_sublabel = ['name'];
        $this->setBlock($block);
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
                'default' => 'Tải Video Kling',
            ],
            'job_id' => [
                'type' => 'input',
                'label' => __('Job ID', 'bizcity-video-kling'),
                'default' => '{{node#kling_create_job.job_id}}',
            ],
            'mode' => [
                'type' => 'select',
                'label' => __('Chế độ lưu trữ', 'bizcity-video-kling'),
                'default' => 'media',
                'options' => [
                    'url'   => __('Chỉ trả về URL gốc', 'bizcity-video-kling'),
                    'media' => __('Tải về Media Library', 'bizcity-video-kling'),
                    'r2'    => __('Upload lên R2', 'bizcity-video-kling'),
                ],
            ],
            'filename' => [
                'type' => 'input',
                'label' => __('Tên file', 'bizcity-video-kling'),
                'default' => 'kling-{{node#kling_create_job.job_id}}.mp4',
                'desc' => __('Có thể dùng {{variable}}', 'bizcity-video-kling'),
            ],
            'timeout' => [
                'type' => 'input',
                'label' => __('Download timeout (giây)', 'bizcity-video-kling'),
                'default' => '300',
            ],
        ];
    }

    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'ok'            => __('OK (1/0)', 'bizcity-video-kling'),
            'job_id'        => __('Job ID', 'bizcity-video-kling'),
            'attachment_id' => __('Attachment ID', 'bizcity-video-kling'),
            'media_url'     => __('Media URL', 'bizcity-video-kling'),
            'video_url'     => __('Video URL', 'bizcity-video-kling'),
            'r2_url'        => __('R2 URL', 'bizcity-video-kling'),
            'mode'          => __('Mode', 'bizcity-video-kling'),
            'message'       => __('Message', 'bizcity-video-kling'),
            'error'         => __('Error message', 'bizcity-video-kling'),
        );
        return $this->_variables;
    }

    public function getResults($taskId, $variables, $step = 0) {
        $job_id = trim($this->replaceVariables($this->getParam('job_id'), $variables));
        
        if (empty($job_id)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => 'Missing job_id'),
                'error' => 'Missing job_id',
                'status' => 7,
            );
            return $this->_results;
        }

        // Get job from transient
        $key = waic_kling_job_key($job_id);
        $job = get_transient($key);
        
        if (!is_array($job)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => 'Job not found'),
                'error' => 'Job not found in transient',
                'status' => 7,
            );
            return $this->_results;
        }

        $status = strtolower((string)($job['status'] ?? ''));
        
        // Check if job is completed
        if (!in_array($status, array('succeeded', 'success', 'completed', 'done'), true)) {
            $this->_results = array(
                'result' => array(
                    'ok' => 0,
                    'job_id' => $job_id,
                    'status' => $status,
                    'error' => 'Job not completed yet',
                ),
                'error' => sprintf(__('Job %s chưa hoàn thành (status: %s)', 'bizcity-video-kling'), $job_id, $status),
                'status' => 7,
            );
            return $this->_results;
        }

        // Extract video URL
        $video_url = $job['video_url'] ?? null;
        
        if (!$video_url && isset($job['raw_status'])) {
            $video_url = waic_kling_extract_video_url((array)$job['raw_status']);
        }
        
        if (!$video_url) {
            $this->_results = array(
                'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => 'Missing video_url'),
                'error' => 'Missing video_url',
                'status' => 7,
            );
            return $this->_results;
        }

        $mode = $this->getParam('mode', 'media');
        
        // Mode: URL only
        if ($mode === 'url') {
            waic_kling_log('fetch.url_only', array('job_id' => $job_id, 'video_url' => $video_url));
            
            $this->_results = array(
                'result' => array(
                    'ok' => 1,
                    'job_id' => $job_id,
                    'video_url' => $video_url,
                    'mode' => 'url',
                    'message' => __('Đã lấy video URL', 'bizcity-video-kling'),
                ),
                'error' => '',
                'status' => 1,
            );
            return $this->_results;
        }

        // Prepare filename
        $filename = trim($this->replaceVariables($this->getParam('filename'), $variables));
        if (empty($filename)) {
            $filename = 'kling-' . $job_id . '.mp4';
        }
        $filename = sanitize_file_name($filename);
        
        // Ensure .mp4 extension
        if (!preg_match('/\.mp4$/i', $filename)) {
            $filename .= '.mp4';
        }

        // Mode: R2
        if ($mode === 'r2') {
            waic_kling_log('fetch.r2_upload', array('job_id' => $job_id, 'filename' => $filename));
            
            $r2_result = waic_kling_upload_video_to_r2($video_url, $filename);
            
            if (!$r2_result['ok']) {
                waic_kling_log('fetch.r2_error', $r2_result);
                $this->_results = array(
                    'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => $r2_result['error'] ?? 'R2 upload failed'),
                    'error' => $r2_result['error'] ?? 'R2 upload failed',
                    'status' => 7,
                );
                return $this->_results;
            }

            // Update job with R2 info
            $job['r2_url'] = $r2_result['r2_url'];
            $job['r2_uploaded_at'] = time();
            set_transient($key, $job, 7200);

            waic_kling_log('fetch.r2_success', array('job_id' => $job_id, 'r2_url' => $r2_result['r2_url']));

            $this->_results = array(
                'result' => array(
                    'ok' => 1,
                    'job_id' => $job_id,
                    'r2_url' => $r2_result['r2_url'],
                    'video_url' => $video_url,
                    'mode' => 'r2',
                    'message' => __('Đã upload video lên R2', 'bizcity-video-kling'),
                ),
                'error' => '',
                'status' => 1,
            );
            return $this->_results;
        }

        // Mode: Media Library (default)
        waic_kling_log('fetch.media_download', array('job_id' => $job_id, 'filename' => $filename));
        
        $dl = waic_kling_download_video_to_media($video_url, $filename);
        
        if (!$dl['ok']) {
            waic_kling_log('fetch.media_error', $dl);
            $this->_results = array(
                'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => $dl['error'] ?? 'Media download failed'),
                'error' => $dl['error'] ?? 'Media download failed',
                'status' => 7,
            );
            return $this->_results;
        }

        // Update job with media info
        $job['media_attachment_id'] = $dl['attachment_id'];
        $job['media_url'] = $dl['media_url'];
        $job['downloaded_at'] = time();
        set_transient($key, $job, 7200);

        waic_kling_log('fetch.media_success', array(
            'job_id' => $job_id,
            'attachment_id' => $dl['attachment_id'],
            'media_url' => $dl['media_url'],
        ));

        // Fire hook
        do_action('waic_kling_video_downloaded', $job_id, $job, $dl['attachment_id']);

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'job_id' => $job_id,
                'attachment_id' => $dl['attachment_id'],
                'media_url' => $dl['media_url'],
                'video_url' => $video_url,
                'mode' => 'media',
                'message' => sprintf(__('Đã tải video vào Media Library (ID: %d)', 'bizcity-video-kling'), $dl['attachment_id']),
            ),
            'error' => '',
            'status' => 1,
        );
        return $this->_results;
    }
}
