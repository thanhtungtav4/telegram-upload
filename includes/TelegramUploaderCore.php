<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'GeneralSettings.php';
require_once plugin_dir_path(__FILE__) . 'FileSplitter.php';

class TelegramUploaderCore {
    private $token;
    private $chat_id;
    private $api_base;
    private $wpdb;
    private $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->token = GeneralSettings::get_token();
        $this->chat_id = GeneralSettings::get_chat_id();
        $this->api_base = GeneralSettings::get_api_base() ?: 'https://api.telegram.org';
        $this->table = $wpdb->prefix . 'telegram_uploaded_files';
    }

    public function upload($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return '<p class="text-red-600">Upload error</p>';
        }

        $maxSize = 49 * 1024 * 1024;

        if ($file['size'] > $maxSize) {
            return $this->uploadLargeFile($file);
        } else {
            return $this->uploadSingleFile($file);
        }
    }

    private function uploadLargeFile($file) {
        $splitter = new FileSplitter($file['tmp_name'], $file['name']);
        $parts = $splitter->split();
        $output = '';

        foreach ($parts as $part) {
            $result = $this->sendToTelegram($part['path'], $part['name']);
            if ($result && isset($result['result']['document']['file_id'])) {
                $this->saveToDatabase($part['name'], $part['size'], $result['result']['document']['file_id']);
            }
            unlink($part['path']);
        }

        $output .= '<p class="text-green-600">Large file split and uploaded in parts! Use ZIP tools to extract.</p>';
        return $output;
    }

    private function uploadSingleFile($file) {
        $result = $this->sendToTelegram($file['tmp_name'], $file['name']);

        if ($result && isset($result['result']['document']['file_id'])) {
            $save_result = $this->saveToDatabase($file['name'], $file['size'], $result['result']['document']['file_id']);
            
            if ($save_result === false) {
                // Database insert failed
                $error = $this->wpdb->last_error;
                return '<p class="text-red-600">Database error: ' . esc_html($error) . '</p>';
            }
            
            return '<p class="text-green-600">File uploaded and sent to Telegram!</p>';
        }

        // Telegram API failed
        $error_msg = isset($result['description']) ? $result['description'] : 'Unknown error';
        return '<p class="text-red-600">Telegram API error: ' . esc_html($error_msg) . '</p>';
    }

    private function sendToTelegram($path, $filename) {
        // Increase PHP timeout for large files
        set_time_limit(300); // 5 minutes
        ini_set('max_execution_time', 300);
        
        $post_fields = [
            'chat_id' => $this->chat_id,
            'document' => new CURLFile($path, mime_content_type($path), $filename)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$this->api_base}/bot{$this->token}/sendDocument");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        
        // CRITICAL: Increase timeout for large files
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes total timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // 30 seconds to connect
        
        // Enable progress tracking for large files (optional)
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        
        $result = curl_exec($ch);
        
        // Check for cURL errors
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            
            // Return error in Telegram API format
            return [
                'ok' => false,
                'description' => 'cURL error: ' . $error
            ];
        }
        
        curl_close($ch);

        return json_decode($result, true);
    }

    private function saveToDatabase($filename, $filesize, $file_id) {
        // Auto-detect category
        $category = TelegramCategories::auto_detect_category($filename);
        
        // Get category from POST if provided
        if (isset($_POST['telegram_category']) && !empty($_POST['telegram_category'])) {
            $category = sanitize_text_field($_POST['telegram_category']);
        }
        
        // Get tags from POST if provided
        $tags = '';
        if (isset($_POST['telegram_tags']) && !empty($_POST['telegram_tags'])) {
            $tags = sanitize_text_field($_POST['telegram_tags']);
        }
        
        // Get description from POST if provided
        $description = '';
        if (isset($_POST['telegram_description']) && !empty($_POST['telegram_description'])) {
            $description = sanitize_textarea_field($_POST['telegram_description']);
        }
        
        // Get access control fields from POST
        $expiration_date = null;
        if (isset($_POST['telegram_expiration']) && !empty($_POST['telegram_expiration'])) {
            // Convert datetime-local format to MySQL datetime
            $expiration_date = date('Y-m-d H:i:s', strtotime($_POST['telegram_expiration']));
        }
        
        $password_hash = null;
        if (isset($_POST['telegram_password']) && !empty($_POST['telegram_password'])) {
            $password_hash = wp_hash_password(sanitize_text_field($_POST['telegram_password']));
        }
        
        $max_downloads = null;
        if (isset($_POST['telegram_max_downloads']) && !empty($_POST['telegram_max_downloads'])) {
            $max_downloads = intval($_POST['telegram_max_downloads']);
            if ($max_downloads <= 0) $max_downloads = null;
        }
        
        // Check if access_count column exists (migration compatibility)
        $columns = $this->wpdb->get_col("DESCRIBE {$this->table}", 0);
        $has_access_count = in_array('access_count', $columns);
        
        // Prepare data array
        $data = [
            'file_name' => sanitize_file_name($filename),
            'file_size' => $filesize,
            'telegram_file_id' => sanitize_text_field($file_id),
            'file_time' => current_time('mysql'),
            'category' => $category,
            'tags' => $tags,
            'description' => $description,
            'expiration_date' => $expiration_date,
            'password_hash' => $password_hash,
            'max_downloads' => $max_downloads,
            'is_active' => 1
        ];
        
        // Add access_count only if column exists
        if ($has_access_count) {
            $data['access_count'] = 0;
        }
        
        $insert_result = $this->wpdb->insert($this->table, $data);
        
        // Log error for debugging
        if ($insert_result === false) {
            error_log('Telegram Upload DB Error: ' . $this->wpdb->last_error);
            error_log('Telegram Upload DB Query: ' . $this->wpdb->last_query);
        }
        
        // Return result for error checking
        return $insert_result;
    }

    // Public getters for display class
    public function get_wpdb() {
        return $this->wpdb;
    }

    public function get_table() {
        return $this->table;
    }

    public function get_token() {
        return $this->token;
    }

    public function get_api_base() {
        return $this->api_base;
    }
}
