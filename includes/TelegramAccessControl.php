<?php
if (!defined('ABSPATH')) exit;

/**
 * Telegram Access Control Class
 * 
 * Handles file expiration, password protection, and download limits.
 * 
 * @version 2.5.0
 * @since 2.5.0
 */
class TelegramAccessControl {
    
    /**
     * Check if file has expired
     * 
     * @param int $file_id File ID
     * @return array ['expired' => bool, 'expiration_date' => string|null]
     */
    public static function check_expiration($file_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT expiration_date, is_active FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            return ['expired' => true, 'expiration_date' => null, 'reason' => 'File not found'];
        }
        
        // Check if file is inactive
        if (!$file->is_active) {
            return ['expired' => true, 'expiration_date' => $file->expiration_date, 'reason' => 'File is inactive'];
        }
        
        // Check expiration date
        if ($file->expiration_date) {
            $expiration = strtotime($file->expiration_date);
            $now = current_time('timestamp');
            
            if ($now > $expiration) {
                // Auto-deactivate expired file
                $wpdb->update(
                    $table,
                    ['is_active' => 0],
                    ['id' => $file_id],
                    ['%d'],
                    ['%d']
                );
                
                return [
                    'expired' => true,
                    'expiration_date' => $file->expiration_date,
                    'reason' => 'File has expired'
                ];
            }
        }
        
        return ['expired' => false, 'expiration_date' => $file->expiration_date];
    }
    
    /**
     * Verify password for protected file
     * 
     * @param int $file_id File ID
     * @param string $password Password to verify
     * @return array ['valid' => bool, 'message' => string]
     */
    public static function verify_password($file_id, $password) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT password_hash FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            return ['valid' => false, 'message' => 'File not found'];
        }
        
        // No password set - allow access
        if (empty($file->password_hash)) {
            return ['valid' => true, 'message' => 'No password required'];
        }
        
        // Verify password
        if (wp_check_password($password, $file->password_hash)) {
            return ['valid' => true, 'message' => 'Password correct'];
        }
        
        return ['valid' => false, 'message' => 'Incorrect password'];
    }
    
    /**
     * Check if file has reached download limit
     * 
     * @param int $file_id File ID
     * @return array ['limit_reached' => bool, 'current' => int, 'max' => int|null]
     */
    public static function check_download_limit($file_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT access_count, max_downloads FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            return ['limit_reached' => true, 'current' => 0, 'max' => 0, 'reason' => 'File not found'];
        }
        
        // No limit set
        if (is_null($file->max_downloads) || $file->max_downloads <= 0) {
            return ['limit_reached' => false, 'current' => $file->access_count, 'max' => null];
        }
        
        // Check if limit reached
        if ($file->access_count >= $file->max_downloads) {
            return [
                'limit_reached' => true,
                'current' => $file->access_count,
                'max' => $file->max_downloads,
                'reason' => 'Download limit reached'
            ];
        }
        
        return [
            'limit_reached' => false,
            'current' => $file->access_count,
            'max' => $file->max_downloads
        ];
    }
    
    /**
     * Increment access count
     * 
     * @param int $file_id File ID
     * @return bool Success
     */
    public static function increment_access_count($file_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET access_count = access_count + 1 WHERE id = %d",
            $file_id
        ));
        
        return $result !== false;
    }
    
    /**
     * Log file access
     * 
     * @param int $file_id File ID
     * @param string $action Action (download, view, etc.)
     * @param array $extra_data Extra data to log
     * @return int|false Log ID or false on failure
     */
    public static function log_access($file_id, $action = 'download', $extra_data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_access_logs';
        
        // Create table if doesn't exist
        self::create_access_logs_table();
        
        $data = [
            'file_id' => $file_id,
            'user_id' => get_current_user_id() ?: null,
            'action' => sanitize_text_field($action),
            'ip_address' => self::get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'access_time' => current_time('mysql'),
            'extra_data' => !empty($extra_data) ? json_encode($extra_data) : null
        ];
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get access logs for a file
     * 
     * @param int $file_id File ID
     * @param int $limit Limit number of logs
     * @return array Access logs
     */
    public static function get_access_logs($file_id, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_access_logs';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") != $table) {
            return [];
        }
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE file_id = %d ORDER BY access_time DESC LIMIT %d",
            $file_id,
            $limit
        ));
        
        return $logs ?: [];
    }
    
    /**
     * Check all access controls for a file
     * 
     * @param int $file_id File ID
     * @param string $password Password (if provided)
     * @return array ['allowed' => bool, 'reason' => string, 'data' => array]
     */
    public static function check_access($file_id, $password = '') {
        // Check expiration
        $expiration_check = self::check_expiration($file_id);
        if ($expiration_check['expired']) {
            return [
                'allowed' => false,
                'reason' => $expiration_check['reason'] ?? 'File has expired',
                'data' => $expiration_check
            ];
        }
        
        // Check download limit
        $limit_check = self::check_download_limit($file_id);
        if ($limit_check['limit_reached']) {
            return [
                'allowed' => false,
                'reason' => $limit_check['reason'] ?? 'Download limit reached',
                'data' => $limit_check
            ];
        }
        
        // Check password
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT password_hash FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if ($file && !empty($file->password_hash)) {
            if (empty($password)) {
                return [
                    'allowed' => false,
                    'reason' => 'Password required',
                    'data' => ['password_required' => true]
                ];
            }
            
            $password_check = self::verify_password($file_id, $password);
            if (!$password_check['valid']) {
                return [
                    'allowed' => false,
                    'reason' => 'Incorrect password',
                    'data' => $password_check
                ];
            }
        }
        
        return [
            'allowed' => true,
            'reason' => 'Access granted',
            'data' => [
                'expiration' => $expiration_check,
                'download_limit' => $limit_check
            ]
        ];
    }
    
    /**
     * Hash password for storage
     * 
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public static function hash_password($password) {
        return wp_hash_password($password);
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    public static function get_client_ip() {
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
            if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
                $server_value = sanitize_text_field(wp_unslash($_SERVER[$key]));
                $ips = explode(',', $server_value);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return 'Unknown';
    }
    
    /**
     * Create access logs table
     */
    private static function create_access_logs_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_access_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            file_id mediumint(9) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            action varchar(50) NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text NOT NULL,
            access_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            extra_data text DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY file_id (file_id),
            KEY user_id (user_id),
            KEY access_time (access_time)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Deactivate expired files (run by cron)
     * 
     * @return int Number of files deactivated
     */
    public static function deactivate_expired_files() {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $result = $wpdb->query(
            "UPDATE {$table} 
            SET is_active = 0 
            WHERE expiration_date IS NOT NULL 
            AND expiration_date < NOW() 
            AND is_active = 1"
        );
        
        return $result !== false ? $result : 0;
    }
    
    /**
     * Get file access control info
     * 
     * @param int $file_id File ID
     * @return array|null Access control info
     */
    public static function get_file_info($file_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT id, file_name, expiration_date, password_hash, max_downloads, access_count, is_active 
            FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            return null;
        }
        
        return [
            'id' => $file->id,
            'file_name' => $file->file_name,
            'expiration_date' => $file->expiration_date,
            'has_password' => !empty($file->password_hash),
            'max_downloads' => $file->max_downloads,
            'access_count' => $file->access_count,
            'is_active' => (bool) $file->is_active,
            'is_expired' => $file->expiration_date && strtotime($file->expiration_date) < current_time('timestamp'),
            'limit_reached' => $file->max_downloads && $file->access_count >= $file->max_downloads
        ];
    }
}
