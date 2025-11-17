<?php
if (!defined('ABSPATH')) exit;

/**
 * Telegram Upload API
 * Provides REST API endpoints for uploading files
 * 
 * Endpoints:
 * POST /wp-json/telegram-upload/v1/upload
 * GET  /wp-json/telegram-upload/v1/files
 * GET  /wp-json/telegram-upload/v1/files/{id}
 * DELETE /wp-json/telegram-upload/v1/files/{id}
 */
class TelegramUploadAPI {
    
    private $core;
    private $namespace = 'telegram-upload/v1';
    
    public function __construct($core) {
        $this->core = $core;
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Upload file endpoint
        register_rest_route($this->namespace, '/upload', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_upload'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'file' => [
                    'required' => false,
                    'description' => 'File to upload (multipart/form-data)',
                ],
                'file_url' => [
                    'required' => false,
                    'description' => 'URL of file to download and upload',
                    'validate_callback' => function($param) {
                        return filter_var($param, FILTER_VALIDATE_URL);
                    }
                ],
            ]
        ]);
        
        // Get all files endpoint
        register_rest_route($this->namespace, '/files', [
            'methods' => 'GET',
            'callback' => [$this, 'get_files'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'limit' => [
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ],
                'offset' => [
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ]
        ]);
        
        // Get single file endpoint
        register_rest_route($this->namespace, '/files/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_file'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ]
        ]);
        
        // Delete file endpoint
        register_rest_route($this->namespace, '/files/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_file'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ]
        ]);
        
        // Get API stats endpoint
        register_rest_route($this->namespace, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
        
        // Generate API key endpoint
        register_rest_route($this->namespace, '/generate-key', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_api_key'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);
    }
    
    /**
     * Check if user has permission (API key or logged in)
     */
    public function check_permission($request) {
        // Check if user is logged in
        if (is_user_logged_in() && current_user_can('upload_files')) {
            return true;
        }
        
        // Check API key in header or query parameter
        $api_key = $request->get_header('X-API-Key') ?: $request->get_param('api_key');
        
        if (empty($api_key)) {
            return new WP_Error(
                'no_api_key',
                'API key required. Use X-API-Key header or api_key parameter.',
                ['status' => 401]
            );
        }
        
        // Validate API key
        $stored_key = get_option('telegram_upload_api_key');
        
        if (empty($stored_key)) {
            return new WP_Error(
                'api_not_configured',
                'API key not configured. Admin must generate an API key first.',
                ['status' => 403]
            );
        }
        
        if (!hash_equals($stored_key, $api_key)) {
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key.',
                ['status' => 403]
            );
        }
        
        return true;
    }
    
    /**
     * Check if user has admin permission
     */
    public function check_admin_permission($request) {
        return current_user_can('manage_options');
    }
    
    /**
     * Handle file upload via API
     */
    public function handle_upload($request) {
        $files = $request->get_file_params();
        $file_url = $request->get_param('file_url');
        
        // Get access control parameters (v2.5.0)
        $expiration = $request->get_param('expiration_date');
        $password = $request->get_param('password');
        $max_downloads = $request->get_param('max_downloads');
        $category_id = $request->get_param('category_id');
        
        // Upload from multipart file
        if (!empty($files['file'])) {
            return $this->upload_multipart_file($files['file'], [
                'expiration_date' => $expiration,
                'password' => $password,
                'max_downloads' => $max_downloads,
                'category_id' => $category_id,
            ]);
        }
        
        // Upload from URL
        if (!empty($file_url)) {
            return $this->upload_from_url($file_url, [
                'expiration_date' => $expiration,
                'password' => $password,
                'max_downloads' => $max_downloads,
                'category_id' => $category_id,
            ]);
        }
        
        return new WP_Error(
            'no_file',
            'No file provided. Use either "file" (multipart) or "file_url" parameter.',
            ['status' => 400]
        );
    }
    
    /**
     * Upload file from multipart/form-data
     */
    private function upload_multipart_file($file, $access_params = []) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error(
                'upload_error',
                'File upload failed: ' . $this->get_upload_error_message($file['error']),
                ['status' => 400]
            );
        }
        
        // Validate file size
        $max_size = 50 * 1024 * 1024; // 50MB
        if ($file['size'] > $max_size) {
            return new WP_Error(
                'file_too_large',
                'File size exceeds 50MB limit. File will be split into parts.',
                ['status' => 400]
            );
        }
        
        // Simulate $_POST for access control parameters (v2.5.0)
        if (!empty($access_params['expiration_date'])) {
            $_POST['telegram_expiration'] = $access_params['expiration_date'];
        }
        if (!empty($access_params['password'])) {
            $_POST['telegram_password'] = $access_params['password'];
        }
        if (!empty($access_params['max_downloads'])) {
            $_POST['telegram_max_downloads'] = $access_params['max_downloads'];
        }
        if (!empty($access_params['category_id'])) {
            $_POST['telegram_category'] = $access_params['category_id'];
        }
        
        // Upload to Telegram
        $result = $this->core->upload($file);
        
        // Check if upload was successful
        if (strpos($result, 'text-green-600') !== false || strpos($result, 'uploaded') !== false) {
            // Get the last uploaded file
            global $wpdb;
            $table = $this->core->get_table();
            $last_file = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1");
            
            if ($last_file) {
                // Build access control info for response
                $access_info = [];
                if (!empty($last_file->expiration_date)) {
                    $access_info['expiration_date'] = $last_file->expiration_date;
                    $access_info['expires_in_days'] = ceil((strtotime($last_file->expiration_date) - time()) / 86400);
                }
                if (!empty($last_file->password_hash)) {
                    $access_info['password_protected'] = true;
                }
                if (!empty($last_file->max_downloads)) {
                    $access_info['max_downloads'] = (int) $last_file->max_downloads;
                    $access_info['downloads_remaining'] = (int) $last_file->max_downloads - (int) $last_file->access_count;
                }
                if (!empty($last_file->is_active)) {
                    $access_info['is_active'] = (bool) $last_file->is_active;
                }
                
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'File uploaded successfully',
                    'file' => [
                        'id' => $last_file->id,
                        'name' => $last_file->file_name,
                        'size' => $last_file->file_size,
                        'size_formatted' => size_format($last_file->file_size),
                        'uploaded_at' => $last_file->file_time,
                        'telegram_file_id' => $last_file->telegram_file_id,
                        'download_url' => TelegramDownloadProxy::get_download_url($last_file->id, true),
                        'download_count' => isset($last_file->download_count) ? $last_file->download_count : 0,
                        'access_count' => isset($last_file->access_count) ? (int) $last_file->access_count : 0,
                        'access_control' => $access_info,
                        'shortcode' => '[telegram_file id="' . $last_file->id . '"]',
                    ]
                ], 201);
            }
        }
        
        return new WP_Error(
            'upload_failed',
            'Failed to upload file to Telegram: ' . strip_tags($result),
            ['status' => 500]
        );
    }
    
    /**
     * Upload file from URL
     */
    private function upload_from_url($url, $access_params = []) {
        // Download file to temp location
        $temp_file = download_url($url);
        
        if (is_wp_error($temp_file)) {
            return new WP_Error(
                'download_failed',
                'Failed to download file from URL: ' . $temp_file->get_error_message(),
                ['status' => 400]
            );
        }
        
        // Get filename from URL
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if (empty($filename)) {
            $filename = 'download_' . time();
        }
        
        // Create file array similar to $_FILES
        $file = [
            'name' => $filename,
            'tmp_name' => $temp_file,
            'size' => filesize($temp_file),
            'error' => UPLOAD_ERR_OK,
        ];
        
        // Upload the file with access control parameters
        $result = $this->upload_multipart_file($file, $access_params);
        
        // Clean up temp file
        @unlink($temp_file);
        
        return $result;
    }
    
    /**
     * Get all files with pagination and search
     */
    public function get_files($request) {
        global $wpdb;
        $table = $this->core->get_table();
        
        $limit = $request->get_param('limit');
        $offset = $request->get_param('offset');
        $search = $request->get_param('search');
        
        // Build query
        $where = '';
        $params = [];
        
        if (!empty($search)) {
            $where = "WHERE file_name LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where}",
            ...$params
        ));
        
        // Get files
        $query = "SELECT * FROM {$table} {$where} ORDER BY file_time DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        
        $files = $wpdb->get_results($wpdb->prepare($query, ...$params));
        
        // Format files
        $formatted_files = array_map(function($file) {
            // Build access control info
            $access_info = [];
            if (!empty($file->expiration_date)) {
                $access_info['expiration_date'] = $file->expiration_date;
                $access_info['expires_in_days'] = ceil((strtotime($file->expiration_date) - time()) / 86400);
                $access_info['is_expired'] = strtotime($file->expiration_date) < time();
            }
            if (!empty($file->password_hash)) {
                $access_info['password_protected'] = true;
            }
            if (!empty($file->max_downloads)) {
                $access_info['max_downloads'] = (int) $file->max_downloads;
                $access_info['downloads_remaining'] = max(0, (int) $file->max_downloads - (int) $file->access_count);
                $access_info['limit_reached'] = (int) $file->access_count >= (int) $file->max_downloads;
            }
            $access_info['is_active'] = isset($file->is_active) ? (bool) $file->is_active : true;
            
            return [
                'id' => $file->id,
                'name' => $file->file_name,
                'size' => $file->file_size,
                'size_formatted' => size_format($file->file_size),
                'uploaded_at' => $file->file_time,
                'telegram_file_id' => $file->telegram_file_id,
                'download_url' => TelegramDownloadProxy::get_download_url($file->id, true),
                'download_count' => isset($file->download_count) ? $file->download_count : 0,
                'access_count' => isset($file->access_count) ? (int) $file->access_count : 0,
                'access_control' => $access_info,
                'category_id' => isset($file->category_id) ? (int) $file->category_id : null,
                'shortcode' => '[telegram_file id="' . $file->id . '"]',
            ];
        }, $files);
        
        return new WP_REST_Response([
            'success' => true,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset,
            'files' => $formatted_files,
        ], 200);
    }
    
    /**
     * Get single file by ID
     */
    public function get_file($request) {
        global $wpdb;
        $table = $this->core->get_table();
        
        $id = $request->get_param('id');
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));
        
        if (!$file) {
            return new WP_Error(
                'file_not_found',
                'File not found',
                ['status' => 404]
            );
        }
        
        // Build access control info
        $access_info = [];
        if (!empty($file->expiration_date)) {
            $access_info['expiration_date'] = $file->expiration_date;
            $access_info['expires_in_days'] = ceil((strtotime($file->expiration_date) - time()) / 86400);
            $access_info['is_expired'] = strtotime($file->expiration_date) < time();
        }
        if (!empty($file->password_hash)) {
            $access_info['password_protected'] = true;
        }
        if (!empty($file->max_downloads)) {
            $access_info['max_downloads'] = (int) $file->max_downloads;
            $access_info['downloads_remaining'] = max(0, (int) $file->max_downloads - (int) $file->access_count);
            $access_info['limit_reached'] = (int) $file->access_count >= (int) $file->max_downloads;
        }
        $access_info['is_active'] = isset($file->is_active) ? (bool) $file->is_active : true;
        
        return new WP_REST_Response([
            'success' => true,
            'file' => [
                'id' => $file->id,
                'name' => $file->file_name,
                'size' => $file->file_size,
                'size_formatted' => size_format($file->file_size),
                'uploaded_at' => $file->file_time,
                'telegram_file_id' => $file->telegram_file_id,
                'download_url' => TelegramDownloadProxy::get_download_url($file->id, true),
                'download_count' => isset($file->download_count) ? $file->download_count : 0,
                'access_count' => isset($file->access_count) ? (int) $file->access_count : 0,
                'access_control' => $access_info,
                'category_id' => isset($file->category_id) ? (int) $file->category_id : null,
                'shortcode' => '[telegram_file id="' . $file->id . '"]',
            ]
        ], 200);
    }
    
    /**
     * Delete file by ID
     */
    public function delete_file($request) {
        global $wpdb;
        $table = $this->core->get_table();
        
        $id = $request->get_param('id');
        
        // Check if file exists
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));
        
        if (!$file) {
            return new WP_Error(
                'file_not_found',
                'File not found',
                ['status' => 404]
            );
        }
        
        // Delete from database
        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
        
        if ($deleted === false) {
            return new WP_Error(
                'delete_failed',
                'Failed to delete file',
                ['status' => 500]
            );
        }
        
        // Clear cache
        wp_cache_delete('tg_file_' . $id);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'File deleted successfully',
            'file' => [
                'id' => $file->id,
                'name' => $file->file_name,
            ]
        ], 200);
    }
    
    /**
     * Get upload statistics
     */
    public function get_stats($request) {
        global $wpdb;
        $table = $this->core->get_table();
        
        $total_files = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $total_size = $wpdb->get_var("SELECT SUM(file_size) FROM {$table}");
        $total_downloads = $wpdb->get_var("SELECT SUM(download_count) FROM {$table}");
        $files_with_downloads = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE download_count > 0");
        
        // Get most downloaded files
        $popular_files = $wpdb->get_results(
            "SELECT id, file_name, download_count FROM {$table} 
            WHERE download_count > 0 
            ORDER BY download_count DESC 
            LIMIT 10"
        );
        
        // Get recent uploads
        $recent_files = $wpdb->get_results(
            "SELECT id, file_name, file_time FROM {$table} 
            ORDER BY file_time DESC 
            LIMIT 10"
        );
        
        return new WP_REST_Response([
            'success' => true,
            'stats' => [
                'total_files' => (int) $total_files,
                'total_size' => (int) $total_size,
                'total_size_formatted' => size_format($total_size),
                'total_downloads' => (int) $total_downloads,
                'files_with_downloads' => (int) $files_with_downloads,
                'average_downloads' => $total_files > 0 ? round($total_downloads / $total_files, 2) : 0,
            ],
            'popular_files' => array_map(function($file) {
                return [
                    'id' => $file->id,
                    'name' => $file->file_name,
                    'download_count' => $file->download_count,
                ];
            }, $popular_files),
            'recent_files' => array_map(function($file) {
                return [
                    'id' => $file->id,
                    'name' => $file->file_name,
                    'uploaded_at' => $file->file_time,
                ];
            }, $recent_files),
        ], 200);
    }
    
    /**
     * Generate new API key
     */
    public function generate_api_key($request) {
        // Generate secure random key
        $api_key = bin2hex(random_bytes(32));
        
        // Save to database
        update_option('telegram_upload_api_key', $api_key);
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'API key generated successfully',
            'api_key' => $api_key,
            'instructions' => [
                'Use this key in X-API-Key header or api_key parameter',
                'Keep this key secure - it grants upload access',
                'Example: curl -H "X-API-Key: ' . $api_key . '" ...',
            ]
        ], 200);
    }
    
    /**
     * Get upload error message
     */
    private function get_upload_error_message($error_code) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        ];
        
        return isset($errors[$error_code]) ? $errors[$error_code] : 'Unknown error';
    }
}
