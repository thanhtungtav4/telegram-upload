<?php
if (!defined('ABSPATH')) exit;

class TelegramDownloadProxy {
    
    /**
     * Initialize the download proxy
     */
    public static function init() {
        // Register AJAX endpoint for logged-in users
        add_action('wp_ajax_telegram_download_file', [__CLASS__, 'handle_download']);
        
        // Register AJAX endpoint for non-logged users (if you want public access)
        add_action('wp_ajax_nopriv_telegram_download_file', [__CLASS__, 'handle_download']);
        
        // Add rewrite rule for cleaner URLs (optional)
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect']);
    }
    
    /**
     * Add custom rewrite rules for download URLs
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule(
            '^telegram-file/([0-9]+)/?$',
            'index.php?telegram_file_id=$matches[1]',
            'top'
        );
    }
    
    /**
     * Add custom query vars
     */
    public static function add_query_vars($vars) {
        $vars[] = 'telegram_file_id';
        return $vars;
    }
    
    /**
     * Handle template redirect for custom download URL
     */
    public static function handle_template_redirect() {
        $file_id = get_query_var('telegram_file_id');
        
        if ($file_id) {
            self::serve_file($file_id);
            exit;
        }
    }
    
    /**
     * Handle AJAX download request
     */
    public static function handle_download() {
        // Verify nonce for security
        if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'telegram_download')) {
            wp_die('Security check failed', 'Unauthorized', ['response' => 403]);
        }
        
        $file_id = isset($_GET['file_id']) ? intval($_GET['file_id']) : 0;
        
        if (!$file_id) {
            wp_die('Invalid file ID', 'Bad Request', ['response' => 400]);
        }
        
        self::serve_file($file_id);
    }
    
    /**
     * Serve the file from Telegram
     * 
     * @param int $db_file_id Database file ID
     */
    private static function serve_file($db_file_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        // Get file info from database with caching
        $cache_key = 'tg_file_' . $db_file_id;
        $file_entry = wp_cache_get($cache_key);
        
        if (false === $file_entry) {
            $file_entry = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $db_file_id
            ));
            
            if ($file_entry) {
                wp_cache_set($cache_key, $file_entry, '', 3600); // Cache for 1 hour
            }
        }
        
        if (!$file_entry) {
            wp_die('File not found', 'Not Found', ['response' => 404]);
        }
        
        // ========================================
        // ACCESS CONTROL CHECKS
        // ========================================
        
        // Get password from request (if provided)
        $password = isset($_GET['password']) ? $_GET['password'] : (isset($_POST['password']) ? $_POST['password'] : '');
        
        // Check all access controls
        $access_check = TelegramAccessControl::check_access($db_file_id, $password);
        
        if (!$access_check['allowed']) {
            // If password is required but not provided, show password form
            if ($access_check['reason'] === 'Password required') {
                self::show_password_form($db_file_id, $file_entry->file_name);
                exit;
            }
            
            // If incorrect password, show password form again with error
            if ($access_check['reason'] === 'Incorrect password') {
                self::show_password_form($db_file_id, $file_entry->file_name, 'wrong_password');
                exit;
            }
            
            // For other access denials, show error page
            $error_message = $access_check['reason'];
            $error_title = 'Access Denied';
            
            if (strpos($access_check['reason'], 'expired') !== false) {
                $error_title = 'File Expired';
            } elseif (strpos($access_check['reason'], 'limit') !== false) {
                $error_title = 'Download Limit Reached';
            }
            
            wp_die($error_message, $error_title, ['response' => 403]);
        }
        
        // Log access attempt
        TelegramAccessControl::log_access($db_file_id, 'download', [
            'ip' => TelegramAccessControl::get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'has_password' => !empty($password)
        ]);
        
        // Increment access counter (separate from download_count for analytics)
        TelegramAccessControl::increment_access_count($db_file_id);
        
        // ========================================
        // END ACCESS CONTROL CHECKS
        // ========================================
        
        // Increment download counter
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET download_count = download_count + 1 WHERE id = %d",
            $db_file_id
        ));
        
        // Invalidate cache after updating counter
        wp_cache_delete($cache_key);
        
        // Track analytics
        self::track_download($db_file_id);
        
        // Get Telegram settings (cached by GeneralSettings)
        $token = GeneralSettings::get_token();
        $api_base = GeneralSettings::get_api_base() ?: 'https://api.telegram.org';
        
        if (!$token) {
            wp_die('Telegram token not configured', 'Configuration Error', ['response' => 500]);
        }
        
        // Get file path from Telegram with caching
        $file_path_cache_key = 'tg_file_path_' . $file_entry->telegram_file_id;
        $file_path = wp_cache_get($file_path_cache_key);
        
        if (false === $file_path) {
            $telegram_file_id = $file_entry->telegram_file_id;
            $get_info = wp_remote_get(
                "{$api_base}/bot{$token}/getFile?file_id={$telegram_file_id}",
                ['timeout' => 15]
            );
            
            if (is_wp_error($get_info)) {
                wp_die('Failed to get file info from Telegram', 'API Error', ['response' => 500]);
            }
            
            $info_body = json_decode(wp_remote_retrieve_body($get_info), true);
            
            if (empty($info_body['ok']) || !isset($info_body['result']['file_path'])) {
                // Get detailed error for debugging
                $error_details = '';
                if (isset($info_body['description'])) {
                    $error_details = ' Telegram error: ' . $info_body['description'];
                }
                if (isset($info_body['error_code'])) {
                    $error_details .= ' (Code: ' . $info_body['error_code'] . ')';
                }
                
                // For large files, use direct file_url if available
                if (!empty($file_entry->file_url)) {
                    // Use stored file URL directly (bypass getFile API)
                    $download_url = $file_entry->file_url;
                    
                    // Redirect directly to Telegram URL for large files
                    wp_redirect($download_url);
                    exit;
                }
                
                wp_die('Invalid response from Telegram API.' . $error_details, 'API Error', ['response' => 500]);
            }
            
            $file_path = $info_body['result']['file_path'];
            wp_cache_set($file_path_cache_key, $file_path, '', 86400); // Cache for 24 hours
        }
        
        $download_url = "{$api_base}/file/bot{$token}/{$file_path}";
        
        // ========================================
        // STREAM FILE WITH PROPER FILENAME USING CURL
        // We need to stream through PHP to set correct filename
        // Direct redirect would show Telegram's internal filename
        // ========================================
        
        // CRITICAL: Clear ALL output buffers BEFORE setting headers
        while (@ob_end_clean());
        
        // CRITICAL: Set headers FIRST before any output
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . sanitize_file_name($file_entry->file_name) . '"');
            header('Content-Transfer-Encoding: binary');
            
            if (isset($file_entry->file_size)) {
                header('Content-Length: ' . $file_entry->file_size);
            }
        }
        
        // Disable WordPress's default output
        remove_all_actions('shutdown');
        remove_all_actions('wp_footer');
        
        // Use cURL to stream file in chunks
        if (function_exists('curl_init')) {
            $ch = curl_init($download_url);
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_BUFFERSIZE => 8192,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                // Write directly to output
                CURLOPT_WRITEFUNCTION => function($ch, $data) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file data from Telegram API
                    echo $data;
                    if (function_exists('fastcgi_finish_request')) {
                        flush();
                    }
                    return strlen($data);
                }
            ]);
            
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Required for streaming large files with CURLOPT_WRITEFUNCTION
            $result = curl_exec($ch);
            
            if ($result === false) {
                $error = curl_error($ch);
                curl_close($ch);
                // Can't use wp_die here as headers already sent
                exit('Download failed: ' . $error);
            }
            
            curl_close($ch);
        } else {
            // Fallback: Load entire file into memory
            $response = wp_remote_get($download_url, [
                'timeout' => 300,
                'sslverify' => false
            ]);
            
            if (is_wp_error($response)) {
                exit('Download failed');
            }
            
            echo wp_remote_retrieve_body($response);
        }
        
        exit;
    }
    
    /**
     * Stream file from Telegram to browser (Optimized)
     * 
     * @param string $url Telegram file URL (with token)
     * @param string $filename Original filename
     * @param int $filesize File size in bytes
     */
    private static function stream_file($url, $filename, $filesize) {
        // Prevent any output before headers
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Increase limits for large files
        @set_time_limit(600); // 10 minutes
        @ini_set('memory_limit', '512M');
        
        // Set optimized headers for file download
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . $filesize);
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: bytes');
        
        // Use cURL for better performance and streaming control
        $success = self::stream_file_curl($url);
        
        if (!$success) {
            // Fallback to WordPress HTTP API
            wp_remote_get($url, [
                'timeout' => 600,
                'stream' => true,
                'filename' => 'php://output'
            ]);
        }
        
        exit;
    }
    
    /**
     * Stream file using cURL (Optimized for large files)
     * 
     * @param string $url Telegram file URL
     * @return bool Success status
     */
    private static function stream_file_curl($url) {
        if (!function_exists('curl_init')) {
            return false;
        }
        
        $ch = curl_init($url);
        
        // Optimized cURL options for streaming
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_BINARYTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 600, // 10 minutes
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_BUFFERSIZE => 8192, // 8KB chunks for efficient streaming
            CURLOPT_TCP_NODELAY => true,
            // Write directly to output with flush
            CURLOPT_WRITEFUNCTION => function($ch, $data) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file data from Telegram API
                echo $data;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                return strlen($data);
            }
        ]);
        
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- Required for streaming large files with CURLOPT_WRITEFUNCTION
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        return $result !== false;
    }
    
    /**
     * Generate a secure download URL
     * 
     * @param int $file_id Database file ID
     * @param bool $use_ajax Whether to use AJAX endpoint or pretty URL
     * @return string Download URL
     */
    public static function get_download_url($file_id, $use_ajax = true) {
        if ($use_ajax) {
            // Use AJAX endpoint with nonce
            return add_query_arg([
                'action' => 'telegram_download_file',
                'file_id' => $file_id,
                'nonce' => wp_create_nonce('telegram_download')
            ], admin_url('admin-ajax.php'));
        } else {
            // Use pretty URL (requires permalink structure)
            return home_url("/telegram-file/{$file_id}/");
        }
    }
    
    /**
     * Track download analytics
     * 
     * @param int $file_id File ID
     */
    private static function track_download($file_id) {
        global $wpdb;
        
        $analytics_table = $wpdb->prefix . 'telegram_download_analytics';
        $user_id = get_current_user_id(); // 0 if not logged in
        $ip_address = self::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'Unknown';
        
        $wpdb->insert(
            $analytics_table,
            [
                'file_id' => $file_id,
                'user_id' => $user_id ?: null,
                'download_time' => current_time('mysql'),
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
            ],
            ['%d', '%d', '%s', '%s', '%s']
        );
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private static function get_client_ip() {
        $ip_keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key]));
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Show password form for protected files
     * 
     * @param int $file_id File ID
     * @param string $file_name File name
     * @param string $error Error code (optional)
     */
    private static function show_password_form($file_id, $file_name, $error = '') {
        // Get current URL for form action (remove error param)
        $protocol = (isset($_SERVER['HTTPS']) && sanitize_text_field(wp_unslash($_SERVER['HTTPS'])) === 'on') ? "https" : "http";
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $current_url = $protocol . "://" . $host . $request_uri;
        // Remove password and error params
        $current_url = remove_query_arg(['password', 'error'], $current_url);
        
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Password Required - <?php echo esc_html($file_name); ?></title>
            <link rel="stylesheet" href="<?php echo esc_url(plugins_url('../assets/vendor/tailwind.min.css', __FILE__)); ?>">
        </head>
        <body class="bg-gray-50 flex items-center justify-center min-h-screen">
            <div class="bg-white rounded-lg shadow-lg p-8 max-w-md w-full">
                <div class="text-center mb-6">
                    <svg class="w-16 h-16 mx-auto text-purple-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                    </svg>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">🔒 Password Protected</h1>
                    <p class="text-gray-600">This file requires a password to download</p>
                </div>
                
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="flex items-center gap-3">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                        </svg>
                        <div>
                            <p class="font-medium text-gray-900"><?php echo esc_html($file_name); ?></p>
                            <p class="text-sm text-gray-500">File ID: #<?php echo esc_html($file_id); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php if ($error === 'wrong_password'): ?>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
                            </svg>
                            <p class="text-sm font-medium text-red-800">Incorrect password. Please try again.</p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="<?php echo esc_url($current_url); ?>" class="space-y-4">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Enter Password</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required 
                            autofocus
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                            placeholder="Enter your password"
                        />
                    </div>
                    
                    <button 
                        type="submit" 
                        class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition-colors"
                    >
                        Unlock & Download
                    </button>
                </form>
                
                <p class="text-center text-sm text-gray-500 mt-6">
                    Don't have the password? Contact the file owner.
                </p>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * Flush rewrite rules on activation
     */
    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }
    
    /**
     * Flush rewrite rules on deactivation
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
