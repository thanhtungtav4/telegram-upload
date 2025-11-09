<?php
if (!defined('ABSPATH')) exit;

class TelegramShortcodes {
    
    /**
     * Initialize shortcodes
     */
    public static function init() {
        add_shortcode('telegram_file', [__CLASS__, 'render_file_button']);
        add_shortcode('telegram_files', [__CLASS__, 'render_files_list']);
        add_shortcode('telegram_latest', [__CLASS__, 'render_latest_files']);
        
        // Enqueue styles and scripts for shortcodes
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }
    
    /**
     * Enqueue styles and scripts for frontend shortcodes
     */
    public static function enqueue_assets() {
        if (!is_admin()) {
            wp_register_style(
                'telegram-upload-shortcode',
                plugins_url('assets/shortcode-styles.css', dirname(__FILE__)),
                [],
                '1.0.0'
            );
            
            wp_register_script(
                'telegram-upload-shortcode',
                plugins_url('assets/shortcode-scripts.js', dirname(__FILE__)),
                [],
                '1.0.0',
                true
            );
        }
    }
    
    /**
     * Render a single file download button
     * 
     * Usage: [telegram_file id="123" text="Download File" class="my-btn" style="primary"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_file_button($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'text' => 'Download',
            'class' => '',
            'style' => 'primary', // primary, secondary, outline, minimal
            'show_icon' => 'yes',
            'show_size' => 'no',
            'target' => '_blank'
        ], $atts);
        
        $file_id = intval($atts['id']);
        if (!$file_id) {
            return '<p style="color: red;">Error: File ID is required</p>';
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $file = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $file_id
        ));
        
        if (!$file) {
            return '<p style="color: red;">Error: File not found</p>';
        }
        
        // Enqueue styles and scripts
        wp_enqueue_style('telegram-upload-shortcode');
        wp_enqueue_script('telegram-upload-shortcode');
        
        // Generate download URL
        $download_url = TelegramDownloadProxy::get_download_url($file_id, true);
        
        // Build button classes
        $btn_classes = ['tg-download-btn', 'tg-style-' . esc_attr($atts['style'])];
        if ($atts['class']) {
            $btn_classes[] = esc_attr($atts['class']);
        }
        
        // Build button content
        $btn_content = '';
        
        // Check access control and add indicators
        $access_info = TelegramAccessControl::get_file_info($file_id);
        $access_indicators = '';
        $has_password = false;
        
        if ($access_info) {
            // Add lock icon if password protected
            if ($access_info['has_password']) {
                $has_password = true;
                $access_indicators .= '<svg class="tg-lock-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:16px;height:16px;margin-right:4px;"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/></svg>';
            }
            
            // Add expiration badge if expiring soon
            if ($access_info['is_expired']) {
                $access_indicators .= '<span class="tg-expired-badge" style="color:#ef4444;font-size:12px;margin-left:4px;">⏰ Expired</span>';
            } elseif ($access_info['expiration_date']) {
                $expiration_time = strtotime($access_info['expiration_date']);
                $days_left = ceil(($expiration_time - current_time('timestamp')) / 86400);
                if ($days_left <= 7) {
                    $access_indicators .= '<span class="tg-expiring-badge" style="color:#f59e0b;font-size:12px;margin-left:4px;">⏳ ' . $days_left . 'd</span>';
                }
            }
            
            // Add limit indicator
            if ($access_info['limit_reached']) {
                $access_indicators .= '<span class="tg-limit-badge" style="color:#ef4444;font-size:12px;margin-left:4px;">🚫 Limit</span>';
            }
        }
        
        // Add icon
        if ($atts['show_icon'] === 'yes') {
            $btn_content .= '<svg class="tg-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>';
            // Add loading spinner (hidden by default)
            $btn_content .= '<svg class="tg-loading-spinner" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
        }
        
        // Add text wrapper
        $btn_content .= '<span class="tg-text">';
        
        // Add access indicators before text
        $btn_content .= $access_indicators;
        
        // Button text
        $btn_content .= esc_html($atts['text']);
        
        // Add file size
        if ($atts['show_size'] === 'yes') {
            $btn_content .= ' <span class="tg-size">(' . size_format($file->file_size) . ')</span>';
        }
        
        $btn_content .= '</span>';
        
        // If file has password, remove download attribute and handle click with JavaScript
        $extra_attrs = '';
        if ($has_password) {
            // Don't add download attribute for password-protected files
            $download_attr = '';
            // Change target to _self to stay on same page
            $target = '_self';
        } else {
            // For non-protected files, add download attribute
            $download_attr = 'download="' . esc_attr($file->file_name) . '"';
            $target = esc_attr($atts['target']);
        }
        
        // Build final HTML
        return sprintf(
            '<a href="%s" class="%s" target="%s" %s title="%s">%s</a>',
            esc_url($download_url),
            implode(' ', $btn_classes),
            $target,
            $download_attr,
            esc_attr('Download ' . $file->file_name),
            $btn_content
        );
    }
    
    /**
     * Render a list of files with download buttons
     * 
     * Usage: [telegram_files ids="1,2,3" layout="grid" columns="3"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_files_list($atts) {
        $atts = shortcode_atts([
            'ids' => '',
            'layout' => 'list', // list, grid, table
            'columns' => '3',
            'show_date' => 'yes',
            'show_size' => 'yes',
            'show_icon' => 'yes',
            'style' => 'primary'
        ], $atts);
        
        if (empty($atts['ids'])) {
            return '<p style="color: red;">Error: File IDs are required</p>';
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        // Parse IDs
        $ids = array_map('intval', explode(',', $atts['ids']));
        $ids = array_filter($ids);
        
        if (empty($ids)) {
            return '<p style="color: red;">Error: Invalid file IDs</p>';
        }
        
        // Get files
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $files = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY FIELD(id, {$placeholders})",
            array_merge($ids, $ids)
        ));
        
        if (empty($files)) {
            return '<p>No files found</p>';
        }
        
        // Enqueue styles
        wp_enqueue_style('telegram-upload-shortcode');
        
        // Render based on layout
        $output = '';
        
        switch ($atts['layout']) {
            case 'grid':
                $output = self::render_grid_layout($files, $atts);
                break;
            case 'table':
                $output = self::render_table_layout($files, $atts);
                break;
            case 'list':
            default:
                $output = self::render_list_layout($files, $atts);
                break;
        }
        
        return $output;
    }
    
    /**
     * Render latest files
     * 
     * Usage: [telegram_latest limit="5" layout="list"]
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render_latest_files($atts) {
        $atts = shortcode_atts([
            'limit' => '5',
            'layout' => 'list',
            'columns' => '3',
            'show_date' => 'yes',
            'show_size' => 'yes',
            'show_icon' => 'yes',
            'style' => 'primary',
            'order' => 'DESC', // DESC or ASC
            'category' => '' // Filter by category
        ], $atts);
        
        global $wpdb;
        $table = $wpdb->prefix . 'telegram_uploaded_files';
        
        $limit = intval($atts['limit']);
        $order = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Build query with category filter if provided
        if (!empty($atts['category'])) {
            $category = sanitize_text_field($atts['category']);
            $files = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE category = %s ORDER BY file_time {$order} LIMIT %d",
                $category,
                $limit
            ));
        } else {
            $files = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY file_time {$order} LIMIT %d",
                $limit
            ));
        }
        
        if (empty($files)) {
            return '<p>No files available</p>';
        }
        
        // Enqueue styles
        wp_enqueue_style('telegram-upload-shortcode');
        
        // Render based on layout
        $output = '';
        
        switch ($atts['layout']) {
            case 'grid':
                $output = self::render_grid_layout($files, $atts);
                break;
            case 'table':
                $output = self::render_table_layout($files, $atts);
                break;
            case 'list':
            default:
                $output = self::render_list_layout($files, $atts);
                break;
        }
        
        return $output;
    }
    
    /**
     * Render files in list layout
     */
    private static function render_list_layout($files, $atts) {
        $output = '<div class="tg-files-list">';
        
        foreach ($files as $file) {
            $download_url = TelegramDownloadProxy::get_download_url($file->id, true);
            
            $output .= '<div class="tg-file-item">';
            
            if ($atts['show_icon'] === 'yes') {
                $output .= '<div class="tg-file-icon">';
                $output .= '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                $output .= '</div>';
            }
            
            $output .= '<div class="tg-file-info">';
            $output .= '<div class="tg-file-name">' . esc_html($file->file_name) . '</div>';
            
            $meta = [];
            if ($atts['show_size'] === 'yes') {
                $meta[] = size_format($file->file_size);
            }
            if ($atts['show_date'] === 'yes') {
                $meta[] = date_i18n(get_option('date_format'), strtotime($file->file_time));
            }
            
            if (!empty($meta)) {
                $output .= '<div class="tg-file-meta">' . implode(' • ', $meta) . '</div>';
            }
            
            $output .= '</div>';
            
            $output .= '<a href="' . esc_url($download_url) . '" class="tg-download-btn tg-style-' . esc_attr($atts['style']) . '" download="' . esc_attr($file->file_name) . '">';
            $output .= '<svg class="tg-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>';
            $output .= '<span>Download</span>';
            $output .= '</a>';
            
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render files in grid layout
     */
    private static function render_grid_layout($files, $atts) {
        $columns = intval($atts['columns']);
        $columns = max(1, min(6, $columns)); // Between 1-6 columns
        
        $output = '<div class="tg-files-grid tg-cols-' . $columns . '">';
        
        foreach ($files as $file) {
            $download_url = TelegramDownloadProxy::get_download_url($file->id, true);
            
            $output .= '<div class="tg-grid-item">';
            
            if ($atts['show_icon'] === 'yes') {
                $output .= '<div class="tg-grid-icon">';
                $output .= '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                $output .= '</div>';
            }
            
            $output .= '<div class="tg-grid-name">' . esc_html($file->file_name) . '</div>';
            
            $meta = [];
            if ($atts['show_size'] === 'yes') {
                $meta[] = size_format($file->file_size);
            }
            if ($atts['show_date'] === 'yes') {
                $meta[] = date_i18n('M j, Y', strtotime($file->file_time));
            }
            
            if (!empty($meta)) {
                $output .= '<div class="tg-grid-meta">' . implode(' • ', $meta) . '</div>';
            }
            
            $output .= '<a href="' . esc_url($download_url) . '" class="tg-download-btn tg-style-' . esc_attr($atts['style']) . '" download="' . esc_attr($file->file_name) . '">';
            $output .= '<svg class="tg-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>';
            $output .= '<span>Download</span>';
            $output .= '</a>';
            
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Render files in table layout
     */
    private static function render_table_layout($files, $atts) {
        $output = '<div class="tg-files-table-wrap">';
        $output .= '<table class="tg-files-table">';
        $output .= '<thead><tr>';
        $output .= '<th>File Name</th>';
        
        if ($atts['show_size'] === 'yes') {
            $output .= '<th>Size</th>';
        }
        if ($atts['show_date'] === 'yes') {
            $output .= '<th>Date</th>';
        }
        
        $output .= '<th>Action</th>';
        $output .= '</tr></thead>';
        $output .= '<tbody>';
        
        foreach ($files as $file) {
            $download_url = TelegramDownloadProxy::get_download_url($file->id, true);
            
            $output .= '<tr>';
            
            $output .= '<td>';
            if ($atts['show_icon'] === 'yes') {
                $output .= '<svg class="tg-table-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
            }
            $output .= esc_html($file->file_name);
            $output .= '</td>';
            
            if ($atts['show_size'] === 'yes') {
                $output .= '<td>' . size_format($file->file_size) . '</td>';
            }
            if ($atts['show_date'] === 'yes') {
                $output .= '<td>' . date_i18n(get_option('date_format'), strtotime($file->file_time)) . '</td>';
            }
            
            $output .= '<td>';
            $output .= '<a href="' . esc_url($download_url) . '" class="tg-download-btn tg-style-' . esc_attr($atts['style']) . '" download="' . esc_attr($file->file_name) . '">';
            $output .= '<svg class="tg-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>';
            $output .= '<span>Download</span>';
            $output .= '</a>';
            $output .= '</td>';
            
            $output .= '</tr>';
        }
        
        $output .= '</tbody></table>';
        $output .= '</div>';
        
        return $output;
    }
}
