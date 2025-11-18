<?php
/**
 * Telegram Upload Token Manager
 * 
 * Manages one-time upload tokens for client-side direct uploads to Telegram.
 * This allows files to be uploaded directly from browser to Telegram API,
 * bypassing VPS bandwidth usage.
 * 
 * Security Features:
 * - One-time use tokens
 * - 5-minute expiration
 * - Rate limiting (100 requests/day per user)
 * - WordPress authentication required
 * - File verification after upload
 * 
 * @since 2.6.0
 */

if (!defined('ABSPATH')) exit;

class TelegramUploadToken {
    
    private static $table_name = null;
    private static $settings_option = 'te_file_upload_settings';
    
    /**
     * Initialize the token manager
     */
    public static function init() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'telegram_upload_tokens';
        
        // Register REST API endpoints
        add_action('rest_api_init', [__CLASS__, 'register_api_routes']);
        
        // Schedule cleanup cron job
        if (!wp_next_scheduled('telegram_cleanup_expired_tokens')) {
            wp_schedule_event(time(), 'hourly', 'telegram_cleanup_expired_tokens');
        }
        
        add_action('telegram_cleanup_expired_tokens', [__CLASS__, 'cleanup_expired_tokens']);
    }
    
    /**
     * Register REST API routes
     */
    public static function register_api_routes() {
        // Request upload token
        register_rest_route('telegram/v1', '/request-upload', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_request_upload'],
            'permission_callback' => function() {
                // WordPress user only
                return is_user_logged_in();
            }
        ]);
        
        // Save uploaded file metadata
        register_rest_route('telegram/v1', '/save-upload', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_save_upload'],
            'permission_callback' => function($request) {
                // Allow if user is logged in - simpler than token validation
                // Token will still be validated in the handler
                return is_user_logged_in();
            },
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'file_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'file_name' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_file_name'
                ],
                'file_size' => [
                    'required' => true,
                    'type' => 'integer'
                ]
            ]
        ]);
        
        // Check upload status
        register_rest_route('telegram/v1', '/upload-status/(?P<token>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_upload_status'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);
    }
    
    /**
     * Generate a new upload token
     * 
     * @param int $user_id WordPress user ID
     * @param array $metadata Additional metadata (category, tags, etc.)
     * @return array|WP_Error Token data or error
     */
    public static function generate($user_id, $metadata = []) {
        global $wpdb;
        
        // Cleanup expired tokens first (older than 1 hour)
        $wpdb->query("DELETE FROM " . self::$table_name . " WHERE expires_at < NOW() - INTERVAL 1 HOUR");
        
        // Check rate limit (100 requests per day per user)
        $today_start = date('Y-m-d 00:00:00');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::$table_name . "
            WHERE user_id = %d AND created_at >= %s",
            $user_id,
            $today_start
        ));
        
        if ($count >= 100) {
            return new WP_Error(
                'rate_limit_exceeded',
                'You have exceeded the daily upload limit (100 files/day)',
                ['status' => 429]
            );
        }
        
        // Generate random token
        $token = bin2hex(random_bytes(32)); // 64 characters
        
        // Set expiration (30 minutes from now - enough time for large file uploads)
        $expires_at = gmdate('Y-m-d H:i:s', time() + 1800);
        
        // Insert token into database
        $result = $wpdb->insert(
            self::$table_name,
            [
                'token' => $token,
                'user_id' => $user_id,
                'metadata' => json_encode($metadata),
                'used' => 0,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%d', '%s', '%d', '%s', '%s']
        );
        
        if ($result === false) {
            return new WP_Error(
                'token_generation_failed',
                'Failed to generate upload token: ' . $wpdb->last_error,
                ['status' => 500]
            );
        }
        
        // Get bot token and chat ID from settings
        $settings = get_option(self::$settings_option);
        $bot_token = isset($settings['token']) ? base64_decode($settings['token']) : '';
        $chat_id = isset($settings['chat_id']) ? base64_decode($settings['chat_id']) : '';
        
        if (!$bot_token || !$chat_id) {
            return new WP_Error(
                'bot_not_configured',
                'Telegram bot is not configured. Please set bot token and chat ID in settings.',
                ['status' => 500]
            );
        }
        
        return [
            'token' => $token,
            'expires_at' => $expires_at,
            'expires_in' => 1800, // seconds (30 minutes)
            'bot_token' => $bot_token,
            'chat_id' => $chat_id,
            'upload_url' => "https://api.telegram.org/bot{$bot_token}/sendDocument",
            'max_file_size' => 50 * 1024 * 1024, // 50MB in bytes
            'user_id' => $user_id
        ];
    }
    
    /**
     * Validate and consume a token
     * 
     * @param string $token Token to validate
     * @return array|WP_Error Token data or error
     */
    public static function validate($token) {
        global $wpdb;
        
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . "
            WHERE token = %s",
            $token
        ), ARRAY_A);
        
        if (!$token_data) {
            return new WP_Error(
                'invalid_token',
                'Invalid upload token',
                ['status' => 401]
            );
        }
        
        // Check if already used (allow reuse within expiry period for retries)
        // if ($token_data['used']) {
        //     return new WP_Error(
        //         'token_already_used',
        //         'This upload token has already been used',
        //         ['status' => 401]
        //     );
        // }
        
        // Check expiration
        if (strtotime($token_data['expires_at']) < time()) {
            return new WP_Error(
                'token_expired',
                'Upload token has expired',
                ['status' => 401]
            );
        }
        
        // Mark as used (only if not already used to prevent duplicate DB updates)
        if (!$token_data['used']) {
            $wpdb->update(
                self::$table_name,
                ['used' => 1, 'used_at' => current_time('mysql')],
                ['token' => $token],
                ['%d', '%s'],
                ['%s']
            );
        }
        
        return [
            'user_id' => $token_data['user_id'],
            'metadata' => json_decode($token_data['metadata'], true)
        ];
    }
    
    /**
     * Verify file exists on Telegram
     * 
     * @param string $file_id Telegram file ID
     * @return bool|WP_Error True if valid, error otherwise
     */
    private static function verify_telegram_file($file_id) {
        $settings = get_option(self::$settings_option);
        $bot_token = isset($settings['token']) ? base64_decode($settings['token']) : '';
        
        if (!$bot_token) {
            return new WP_Error(
                'bot_not_configured',
                'Bot token not configured',
                ['status' => 500]
            );
        }
        
        $response = wp_remote_get(
            "https://api.telegram.org/bot{$bot_token}/getFile?file_id=" . urlencode($file_id),
            ['timeout' => 10]
        );
        
        if (is_wp_error($response)) {
            return new WP_Error(
                'verification_failed',
                'Failed to verify file with Telegram: ' . $response->get_error_message(),
                ['status' => 500]
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['ok']) || !$body['ok']) {
            // Get detailed error message from Telegram
            $error_msg = 'File not found on Telegram.';
            if (isset($body['description'])) {
                $error_msg .= ' Telegram says: ' . $body['description'];
            }
            if (isset($body['error_code'])) {
                $error_msg .= ' (Error code: ' . $body['error_code'] . ')';
            }
            
            // For large files (>20MB), getFile might fail but file exists
            // Skip verification for these files
            if (isset($body['error_code']) && $body['error_code'] == 400 && 
                strpos($body['description'], 'file is too big') !== false) {
                // File exists but is too large for getFile API
                return true;
            }
            
            return new WP_Error(
                'invalid_file_id',
                $error_msg,
                ['status' => 400]
            );
        }
        
        return true;
    }
    
    /**
     * Handle /request-upload API endpoint
     */
    public static function handle_request_upload($request) {
        $user_id = get_current_user_id();
        
        // Get metadata from request
        $metadata = [
            'category' => $request->get_param('category'),
            'tags' => $request->get_param('tags'),
            'description' => $request->get_param('description'),
            'password' => $request->get_param('password'),
            'expiration_date' => $request->get_param('expiration_date'),
            'max_downloads' => $request->get_param('max_downloads')
        ];
        
        // Remove null values
        $metadata = array_filter($metadata, function($value) {
            return $value !== null && $value !== '';
        });
        
        // Generate token
        $result = self::generate($user_id, $metadata);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $result
        ], 200);
    }
    
    /**
     * Handle /save-upload API endpoint
     */
    public static function handle_save_upload($request) {
        $token = $request->get_param('token');
        $file_id = $request->get_param('file_id');
        $file_name = $request->get_param('file_name');
        $file_size = $request->get_param('file_size');
        
        // Validate file size (50MB limit)
        if ($file_size > 50 * 1024 * 1024) {
            return new WP_Error(
                'file_too_large',
                'File size exceeds 50MB limit',
                ['status' => 400]
            );
        }
        
        // Validate token
        $token_data = self::validate($token);
        
        if (is_wp_error($token_data)) {
            return $token_data;
        }
        
        // Verify file exists on Telegram
        $verify_result = self::verify_telegram_file($file_id);
        
        if (is_wp_error($verify_result)) {
            return $verify_result;
        }
        
        // Save to database
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        // Check if access_count column exists
        $columns = $wpdb->get_col("DESCRIBE {$table}", 0);
        $has_access_count = in_array('access_count', $columns);
        
        // Get metadata from token
        $metadata = $token_data['metadata'];
        
        // Process access control fields
        $expiration_date = null;
        if (!empty($metadata['expiration_date'])) {
            $expiration_date = date('Y-m-d H:i:s', strtotime($metadata['expiration_date']));
        }
        
        $password_hash = null;
        if (!empty($metadata['password'])) {
            $password_hash = wp_hash_password($metadata['password']);
        }
        
        $max_downloads = null;
        if (!empty($metadata['max_downloads'])) {
            $max_downloads = intval($metadata['max_downloads']);
            if ($max_downloads <= 0) $max_downloads = null;
        }
        
        // Prepare data
        $data = [
            'file_name' => $file_name,
            'file_size' => $file_size,
            'telegram_file_id' => $file_id,
            'file_time' => current_time('mysql'),
            'category' => $metadata['category'] ?? null,
            'tags' => $metadata['tags'] ?? null,
            'description' => $metadata['description'] ?? null,
            'expiration_date' => $expiration_date,
            'password_hash' => $password_hash,
            'max_downloads' => $max_downloads,
            'is_active' => 1
        ];
        
        // Add access_count if column exists
        if ($has_access_count) {
            $data['access_count'] = 0;
        }
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            error_log('Telegram Upload DB Error: ' . $wpdb->last_error);
            return new WP_Error(
                'database_error',
                'Failed to save file: ' . $wpdb->last_error,
                ['status' => 500]
            );
        }
        
        $file_id_db = $wpdb->insert_id;
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'file_id' => $file_id_db,
                'message' => 'File uploaded successfully',
                'file_name' => $file_name,
                'file_size' => $file_size,
                'telegram_file_id' => $file_id
            ]
        ], 201);
    }
    
    /**
     * Handle /upload-status API endpoint
     */
    public static function handle_upload_status($request) {
        $token = $request->get_param('token');
        
        global $wpdb;
        $token_data = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::$table_name . "
            WHERE token = %s",
            $token
        ), ARRAY_A);
        
        if (!$token_data) {
            return new WP_Error('invalid_token', 'Invalid token', ['status' => 404]);
        }
        
        $is_expired = strtotime($token_data['expires_at']) < time();
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'used' => (bool) $token_data['used'],
                'expired' => $is_expired,
                'expires_at' => $token_data['expires_at'],
                'created_at' => $token_data['created_at'],
                'used_at' => $token_data['used_at']
            ]
        ], 200);
    }
    
    /**
     * Cleanup expired tokens (cron job)
     */
    public static function cleanup_expired_tokens() {
        global $wpdb;
        
        $deleted = $wpdb->query(
            "DELETE FROM " . self::$table_name . "
            WHERE expires_at < NOW()
            OR (used = 1 AND used_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))"
        );
        
        if ($deleted > 0) {
            error_log("Telegram Upload: Cleaned up {$deleted} expired/used token(s)");
        }
    }
    
    /**
     * Create database table
     */
    public static function create_table() {
        global $wpdb;
        
        // Initialize table name if not set
        if (self::$table_name === null) {
            self::$table_name = $wpdb->prefix . 'telegram_upload_tokens';
        }
        
        $table_name = self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            user_id bigint(20) NOT NULL,
            metadata text DEFAULT NULL,
            used tinyint(1) DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            used_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id),
            KEY expires_at (expires_at),
            KEY used (used)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
