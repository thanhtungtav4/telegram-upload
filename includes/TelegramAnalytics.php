<?php
if (!defined('ABSPATH')) exit;

/**
 * Telegram Upload Analytics
 * Track and display download analytics
 */
class TelegramAnalytics {
    
    private $wpdb;
    private $analytics_table;
    private $files_table;
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->analytics_table = $wpdb->prefix . 'telegram_download_analytics';
        $this->files_table = $wpdb->prefix . 'telegram_uploaded_files';
    }
    
    /**
     * Initialize analytics menu and hooks
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_analytics_menu']);
        add_action('wp_ajax_telegram_export_analytics', [__CLASS__, 'export_analytics_ajax']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_analytics_scripts']);
    }
    
    /**
     * Enqueue Chart.js for analytics page
     */
    public static function enqueue_analytics_scripts($hook) {
        // Check if we're on the analytics page
        // Use $_GET check as fallback since hook name may vary
        $is_analytics_page = (
            $hook === 'telegram-file-upload_page_telegram-analytics' ||
            (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'telegram-analytics')
        );
        
        if (!$is_analytics_page) {
            return;
        }
        
        wp_enqueue_style(
            'telefiup-analytics-css',
            plugins_url('../assets/analytics-dashboard.css', __FILE__),
            array(),
            '2.6.1'
        );
        
        wp_enqueue_script(
            'telefiup-chartjs',
            plugins_url('../assets/vendor/chart.min.js', __FILE__),
            array(),
            '3.9.1',
            true
        );
        
        wp_enqueue_script(
            'telefiup-analytics-js',
            plugins_url('../assets/analytics-dashboard.js', __FILE__),
            array('telefiup-chartjs'),
            '2.6.1',
            true
        );
    }
    
    /**
     * Add analytics submenu page
     */
    public static function add_analytics_menu() {
        add_submenu_page(
            'telegram-file-upload',
            'Analytics Dashboard',
            '📊 Analytics',
            'manage_options',
            'telegram-analytics',
            [__CLASS__, 'render_analytics_page']
        );
    }
    
    /**
     * Get total downloads count
     */
    public function get_total_downloads() {
        return $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->analytics_table}");
    }
    
    /**
     * Get unique downloaders count
     */
    public function get_unique_downloaders() {
        return $this->wpdb->get_var("SELECT COUNT(DISTINCT ip_address) FROM {$this->analytics_table}");
    }
    
    /**
     * Get top downloaded files
     * 
     * @param int $limit Number of results
     * @return array Top files
     */
    public function get_top_files($limit = 10) {
        $sql = "SELECT 
                    f.id, 
                    f.file_name, 
                    f.file_size,
                    f.download_count,
                    COUNT(a.id) as analytics_downloads
                FROM {$this->files_table} f
                LEFT JOIN {$this->analytics_table} a ON f.id = a.file_id
                GROUP BY f.id
                ORDER BY f.download_count DESC
                LIMIT %d";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $limit));
    }
    
    /**
     * Get recent downloads
     * 
     * @param int $limit Number of results
     * @return array Recent downloads
     */
    public function get_recent_downloads($limit = 20) {
        $sql = "SELECT 
                    a.id,
                    a.file_id,
                    a.download_time,
                    a.ip_address,
                    a.user_agent,
                    f.file_name,
                    u.display_name as user_name
                FROM {$this->analytics_table} a
                LEFT JOIN {$this->files_table} f ON a.file_id = f.id
                LEFT JOIN {$this->wpdb->users} u ON a.user_id = u.ID
                ORDER BY a.download_time DESC
                LIMIT %d";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $limit));
    }
    
    /**
     * Get downloads by date (last 30 days)
     * 
     * @return array Downloads per day
     */
    public function get_downloads_by_date($days = 30) {
        $sql = "SELECT 
                    DATE(download_time) as date,
                    COUNT(*) as downloads
                FROM {$this->analytics_table}
                WHERE download_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY DATE(download_time)
                ORDER BY date ASC";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $days));
    }
    
    /**
     * Get downloads by user
     * 
     * @param int $limit Number of results
     * @return array Top users
     */
    public function get_top_users($limit = 10) {
        $sql = "SELECT 
                    a.user_id,
                    u.display_name,
                    u.user_email,
                    COUNT(*) as downloads
                FROM {$this->analytics_table} a
                LEFT JOIN {$this->wpdb->users} u ON a.user_id = u.ID
                WHERE a.user_id IS NOT NULL
                GROUP BY a.user_id
                ORDER BY downloads DESC
                LIMIT %d";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $limit));
    }
    
    /**
     * Get downloads for specific file
     * 
     * @param int $file_id File ID
     * @return array Download records
     */
    public function get_file_analytics($file_id) {
        $sql = "SELECT 
                    a.*,
                    u.display_name as user_name,
                    u.user_email
                FROM {$this->analytics_table} a
                LEFT JOIN {$this->wpdb->users} u ON a.user_id = u.ID
                WHERE a.file_id = %d
                ORDER BY a.download_time DESC";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $file_id));
    }
    
    /**
     * Export analytics to CSV
     * 
     * @param string $type Export type (all, top_files, recent)
     */
    public static function export_analytics_ajax() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        check_ajax_referer('telegram_export_analytics', 'nonce');
        
        $analytics = new self();
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
        
        // Prepare filename
        $filename = 'telegram-analytics-' . date('Y-m-d-His') . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        switch ($type) {
            case 'top_files':
                fputcsv($output, ['File Name', 'File Size', 'Total Downloads', 'Analytics Downloads']);
                $data = $analytics->get_top_files(100);
                foreach ($data as $row) {
                    fputcsv($output, [
                        $row->file_name,
                        self::format_bytes($row->file_size),
                        $row->download_count,
                        $row->analytics_downloads
                    ]);
                }
                break;
                
            case 'recent':
                fputcsv($output, ['Date/Time', 'File Name', 'User', 'IP Address', 'User Agent']);
                $data = $analytics->get_recent_downloads(500);
                foreach ($data as $row) {
                    fputcsv($output, [
                        $row->download_time,
                        $row->file_name,
                        $row->user_name ?: 'Guest',
                        $row->ip_address,
                        $row->user_agent
                    ]);
                }
                break;
                
            default: // all
                fputcsv($output, ['ID', 'File ID', 'File Name', 'User', 'Download Time', 'IP Address', 'User Agent']);
                $sql = "SELECT a.*, f.file_name, u.display_name 
                        FROM {$analytics->analytics_table} a
                        LEFT JOIN {$analytics->files_table} f ON a.file_id = f.id
                        LEFT JOIN {$analytics->wpdb->users} u ON a.user_id = u.ID
                        ORDER BY a.download_time DESC";
                $data = $analytics->wpdb->get_results($sql);
                foreach ($data as $row) {
                    fputcsv($output, [
                        $row->id,
                        $row->file_id,
                        $row->file_name,
                        $row->display_name ?: 'Guest',
                        $row->download_time,
                        $row->ip_address,
                        $row->user_agent
                    ]);
                }
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Format bytes to human readable
     * 
     * @param int $bytes File size in bytes
     * @return string Formatted size
     */
    private static function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Render analytics dashboard page
     */
    public static function render_analytics_page() {
        $analytics = new self();
        
        // Get data
        $total_downloads = $analytics->get_total_downloads();
        $unique_downloaders = $analytics->get_unique_downloaders();
        $top_files = $analytics->get_top_files(10);
        $recent_downloads = $analytics->get_recent_downloads(15);
        $downloads_by_date = $analytics->get_downloads_by_date(30);
        $top_users = $analytics->get_top_users(10);
        
        // Prepare chart data
        $chart_labels = [];
        $chart_data = [];
        foreach ($downloads_by_date as $row) {
            $chart_labels[] = date('M j', strtotime($row->date));
            $chart_data[] = $row->downloads;
        }
        
        // Localize script data
        wp_localize_script('telefiup-analytics-js', 'telefiupAnalytics', array(
            'chartLabels' => $chart_labels,
            'chartData' => $chart_data,
            'exportNonce' => wp_create_nonce('telegram_export_analytics')
        ));
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">📊 Download Analytics Dashboard</h1>
            
            <!-- Stats Cards -->
            <div class="tg-stats-grid">
                <div class="tg-stat-card">
                    <div class="tg-stat-label">Total Downloads</div>
                    <div class="tg-stat-value"><?php echo number_format($total_downloads); ?></div>
                </div>
                <div class="tg-stat-card">
                    <div class="tg-stat-label">Unique Downloaders</div>
                    <div class="tg-stat-value"><?php echo number_format($unique_downloaders); ?></div>
                </div>
                <div class="tg-stat-card">
                    <div class="tg-stat-label">Total Files</div>
                    <div class="tg-stat-value"><?php echo number_format(count($top_files)); ?>+</div>
                </div>
                <div class="tg-stat-card">
                    <div class="tg-stat-label">Avg Downloads/File</div>
                    <div class="tg-stat-value"><?php echo count($top_files) > 0 ? round($total_downloads / count($top_files)) : 0; ?></div>
                </div>
            </div>
            
            <!-- Export Buttons -->
            <div class="tg-export-btn">
                <button onclick="exportAnalytics('all')" class="button button-primary">
                    📥 Export All Data (CSV)
                </button>
                <button onclick="exportAnalytics('top_files')" class="button">
                    📊 Export Top Files
                </button>
                <button onclick="exportAnalytics('recent')" class="button">
                    🕒 Export Recent Downloads
                </button>
            </div>
            
            <!-- Downloads Chart -->
            <div class="tg-section">
                <h2 class="tg-section-title">Download Trends (Last 30 Days)</h2>
                <div class="tg-chart-container">
                    <canvas id="downloadsChart" width="800" height="250"></canvas>
                </div>
            </div>
            
            <!-- Top Files -->
            <div class="tg-section">
                <h2 class="tg-section-title">🏆 Top Downloaded Files</h2>
                <table class="tg-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Downloads</th>
                            <th>Tracked</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($top_files as $file): 
                        ?>
                        <tr>
                            <td><strong><?php echo $rank++; ?></strong></td>
                            <td><?php echo esc_html($file->file_name); ?></td>
                            <td><?php echo self::format_bytes($file->file_size); ?></td>
                            <td><span class="tg-badge tg-badge-success"><?php echo number_format($file->download_count); ?></span></td>
                            <td><span class="tg-badge tg-badge-info"><?php echo number_format($file->analytics_downloads); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Top Users -->
            <?php if (!empty($top_users)): ?>
            <div class="tg-section">
                <h2 class="tg-section-title">👥 Top Users (Logged In)</h2>
                <table class="tg-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Email</th>
                            <th>Downloads</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($top_users as $user): 
                        ?>
                        <tr>
                            <td><?php echo $rank++; ?></td>
                            <td><?php echo esc_html($user->display_name ?: 'Unknown'); ?></td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><span class="tg-badge tg-badge-success"><?php echo number_format($user->downloads); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Recent Downloads -->
            <div class="tg-section">
                <h2 class="tg-section-title">🕒 Recent Downloads</h2>
                <table class="tg-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>File</th>
                            <th>User</th>
                            <th>IP Address</th>
                            <th>Browser</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_downloads as $download): ?>
                        <tr>
                            <td><?php echo date('M j, Y H:i', strtotime($download->download_time)); ?></td>
                            <td><?php echo esc_html($download->file_name); ?></td>
                            <td><?php echo esc_html($download->user_name ?: 'Guest'); ?></td>
                            <td><code><?php echo esc_html($download->ip_address); ?></code></td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr($download->user_agent); ?>">
                                <?php echo esc_html(self::parse_user_agent($download->user_agent)); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Parse user agent to readable browser name
     * 
     * @param string $user_agent User agent string
     * @return string Browser name
     */
    private static function parse_user_agent($user_agent) {
        if (strpos($user_agent, 'Chrome') !== false) return 'Chrome';
        if (strpos($user_agent, 'Safari') !== false) return 'Safari';
        if (strpos($user_agent, 'Firefox') !== false) return 'Firefox';
        if (strpos($user_agent, 'Edge') !== false) return 'Edge';
        if (strpos($user_agent, 'Opera') !== false) return 'Opera';
        return 'Other';
    }
}
