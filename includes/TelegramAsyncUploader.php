<?php
if (!defined('ABSPATH')) exit;

/**
 * Asynchronous Telegram Uploader
 * Handles large file uploads with chunking and retry mechanism
 */
class TelegramAsyncUploader {
    
    private $token;
    private $chat_id;
    private $api_base;
    private $wpdb;
    private $table;
    private $pending_table;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->token = GeneralSettings::get_token();
        $this->chat_id = GeneralSettings::get_chat_id();
        $this->api_base = GeneralSettings::get_api_base() ?: 'https://api.telegram.org';
        $this->table = $wpdb->prefix . 'telegram_uploaded_files';
        $this->pending_table = $wpdb->prefix . 'telegram_pending_uploads';
    }
    
    /**
     * Initialize async upload system
     */
    public static function init() {
        add_action('wp_ajax_telegram_async_upload', [__CLASS__, 'handle_async_upload']);
        add_action('wp_ajax_telegram_upload_status', [__CLASS__, 'handle_upload_status']);
        add_action('telegram_process_pending_upload', [__CLASS__, 'process_pending_upload'], 10, 1);
    }
    
    /**
     * Create pending uploads table
     */
    public static function create_pending_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_pending_uploads';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_path VARCHAR(500) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            progress INT DEFAULT 0,
            error_message TEXT NULL,
            retry_count INT DEFAULT 0,
            max_retries INT DEFAULT 3,
            metadata TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Handle async upload request
     */
    public static function handle_async_upload() {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        if (!isset($_FILES['file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Upload error']);
        }
        
        $instance = new self();
        
        // Move file to permanent location using WordPress file system
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/telegram-temp';
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $unique_name = uniqid('tg_', true) . '_' . sanitize_file_name($file['name']);
        $permanent_path = $temp_dir . '/' . $unique_name;
        
        // Use WordPress file system abstraction instead of move_uploaded_file()
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        
        // Copy uploaded file to permanent location
        if (!copy($file['tmp_name'], $permanent_path)) {
            wp_send_json_error(['message' => 'Failed to save file']);
        }
        
        // Remove temporary file
        @unlink($file['tmp_name']);
        
        // Store metadata
        $metadata = [
            'category' => isset($_POST['telegram_category']) ? sanitize_text_field($_POST['telegram_category']) : '',
            'tags' => isset($_POST['telegram_tags']) ? sanitize_text_field($_POST['telegram_tags']) : '',
            'description' => isset($_POST['telegram_description']) ? sanitize_textarea_field($_POST['telegram_description']) : '',
            'expiration_date' => isset($_POST['telegram_expiration']) ? sanitize_text_field($_POST['telegram_expiration']) : '',
            'password' => isset($_POST['telegram_password']) ? sanitize_text_field($_POST['telegram_password']) : '',
            'max_downloads' => isset($_POST['telegram_max_downloads']) ? intval($_POST['telegram_max_downloads']) : null,
        ];
        
        // Create pending upload record
        global $wpdb;
        $pending_table = $wpdb->prefix . 'telegram_pending_uploads';
        
        $wpdb->insert($pending_table, [
            'file_path' => $permanent_path,
            'file_name' => sanitize_file_name($file['name']),
            'file_size' => $file['size'],
            'status' => 'pending',
            'metadata' => json_encode($metadata),
            'created_at' => current_time('mysql')
        ]);
        
        $pending_id = $wpdb->insert_id;
        
        // Schedule background processing
        wp_schedule_single_event(time(), 'telegram_process_pending_upload', [$pending_id]);
        
        // Trigger immediate processing (non-blocking)
        if (function_exists('fastcgi_finish_request')) {
            // For FastCGI: Return response immediately, continue processing
            wp_send_json_success([
                'pending_id' => $pending_id,
                'status' => 'processing',
                'message' => 'Upload queued for processing'
            ]);
            
            fastcgi_finish_request();
            
            // Continue processing in background
            self::process_pending_upload($pending_id);
        } else {
            // For other setups: Return and rely on WP-Cron
            wp_send_json_success([
                'pending_id' => $pending_id,
                'status' => 'queued',
                'message' => 'Upload queued. Check status in a moment.'
            ]);
        }
    }
    
    /**
     * Process pending upload in background
     */
    public static function process_pending_upload($pending_id) {
        global $wpdb;
        $pending_table = $wpdb->prefix . 'telegram_pending_uploads';
        
        // Get pending upload
        $upload = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pending_table} WHERE id = %d",
            $pending_id
        ));
        
        if (!$upload || $upload->status === 'completed') {
            return;
        }
        
        // Update status to processing
        $wpdb->update(
            $pending_table,
            ['status' => 'processing', 'progress' => 10],
            ['id' => $pending_id],
            ['%s', '%d'],
            ['%d']
        );
        
        $instance = new self();
        
        try {
            // Upload to Telegram with retry
            $max_retries = 3;
            $retry_count = 0;
            $result = null;
            
            while ($retry_count < $max_retries) {
                $result = $instance->send_to_telegram_with_progress($upload->file_path, $upload->file_name, $pending_id);
                
                if ($result && isset($result['result']['document']['file_id'])) {
                    break;
                }
                
                $retry_count++;
                
                // Update retry count
                $wpdb->update(
                    $pending_table,
                    ['retry_count' => $retry_count],
                    ['id' => $pending_id],
                    ['%d'],
                    ['%d']
                );
                
                if ($retry_count < $max_retries) {
                    sleep(2 * $retry_count); // Exponential backoff
                }
            }
            
            if (!$result || !isset($result['result']['document']['file_id'])) {
                throw new Exception('Failed to upload to Telegram after ' . $max_retries . ' retries');
            }
            
            // Update progress
            $wpdb->update(
                $pending_table,
                ['progress' => 80],
                ['id' => $pending_id],
                ['%d'],
                ['%d']
            );
            
            // Save to database
            $metadata = json_decode($upload->metadata, true);
            $instance->save_to_database_from_pending($upload, $result['result']['document']['file_id'], $metadata);
            
            // Update status to completed
            $wpdb->update(
                $pending_table,
                [
                    'status' => 'completed',
                    'progress' => 100
                ],
                ['id' => $pending_id],
                ['%s', '%d'],
                ['%d']
            );
            
            // Clean up temp file
            @unlink($upload->file_path);
            
        } catch (Exception $e) {
            // Update status to failed
            $wpdb->update(
                $pending_table,
                [
                    'status' => 'failed',
                    'error_message' => $e->getMessage()
                ],
                ['id' => $pending_id],
                ['%s', '%s'],
                ['%d']
            );
        }
    }
    
    /**
     * Send to Telegram with progress tracking
     */
    private function send_to_telegram_with_progress($path, $filename, $pending_id) {
        global $wpdb;
        $pending_table = $wpdb->prefix . 'telegram_pending_uploads';
        
        $post_fields = [
            'chat_id' => $this->chat_id,
            'document' => new CURLFile($path, mime_content_type($path), $filename)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$this->api_base}/bot{$this->token}/sendDocument");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        // Progress tracking
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded, $upload_size, $uploaded) use ($pending_id, $wpdb, $pending_table) {
            if ($upload_size > 0) {
                $progress = min(70, (int)(($uploaded / $upload_size) * 70) + 10); // 10-80%
                
                $wpdb->update(
                    $pending_table,
                    ['progress' => $progress],
                    ['id' => $pending_id],
                    ['%d'],
                    ['%d']
                );
            }
            return 0;
        });
        
        $result = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return [
                'ok' => false,
                'description' => 'cURL error: ' . $error
            ];
        }
        
        curl_close($ch);
        return json_decode($result, true);
    }
    
    /**
     * Save to database from pending upload
     */
    private function save_to_database_from_pending($upload, $file_id, $metadata) {
        $expiration_date = null;
        if (!empty($metadata['expiration_date'])) {
            $expiration_date = date('Y-m-d H:i:s', strtotime($metadata['expiration_date']));
        }
        
        $password_hash = null;
        if (!empty($metadata['password'])) {
            $password_hash = wp_hash_password($metadata['password']);
        }
        
        $this->wpdb->insert(
            $this->table,
            [
                'file_name' => sanitize_file_name($upload->file_name),
                'file_size' => $upload->file_size,
                'telegram_file_id' => sanitize_text_field($file_id),
                'file_time' => current_time('mysql'),
                'category' => $metadata['category'],
                'tags' => $metadata['tags'],
                'description' => $metadata['description'],
                'expiration_date' => $expiration_date,
                'password_hash' => $password_hash,
                'max_downloads' => $metadata['max_downloads'],
                'access_count' => 0,
                'is_active' => 1
            ]
        );
    }
    
    /**
     * Handle upload status check
     */
    public static function handle_upload_status() {
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        $pending_id = isset($_GET['pending_id']) ? intval($_GET['pending_id']) : 0;
        
        if (!$pending_id) {
            wp_send_json_error(['message' => 'Invalid pending ID']);
        }
        
        global $wpdb;
        $pending_table = $wpdb->prefix . 'telegram_pending_uploads';
        
        $upload = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pending_table} WHERE id = %d",
            $pending_id
        ));
        
        if (!$upload) {
            wp_send_json_error(['message' => 'Upload not found']);
        }
        
        wp_send_json_success([
            'status' => $upload->status,
            'progress' => $upload->progress,
            'error_message' => $upload->error_message,
            'retry_count' => $upload->retry_count
        ]);
    }
    
    /**
     * Clean up old pending uploads (run via cron)
     */
    public static function cleanup_old_pending() {
        global $wpdb;
        $pending_table = $wpdb->prefix . 'telegram_pending_uploads';
        
        // Delete completed/failed uploads older than 24 hours
        $wpdb->query("
            DELETE FROM {$pending_table} 
            WHERE status IN ('completed', 'failed') 
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        // Delete pending uploads older than 1 hour (likely stuck)
        $wpdb->query("
            DELETE FROM {$pending_table} 
            WHERE status = 'pending' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    }
}
