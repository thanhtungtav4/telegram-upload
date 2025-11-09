<?php
if (!defined('ABSPATH')) exit;

/**
 * Telegram Upload Categories Manager
 * Manage file categories and tags
 */
class TelegramCategories {
    
    private $wpdb;
    private $table;
    
    /**
     * Default categories
     */
    const DEFAULT_CATEGORIES = [
        'documents' => '📄 Documents',
        'images' => '🖼️ Images',
        'videos' => '🎬 Videos',
        'audio' => '🎵 Audio',
        'archives' => '📦 Archives',
        'other' => '📁 Other'
    ];
    
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'telegram_uploaded_files';
    }
    
    /**
     * Initialize categories manager
     */
    public static function init() {
        add_action('wp_ajax_telegram_get_categories', [__CLASS__, 'ajax_get_categories']);
        add_action('wp_ajax_telegram_bulk_assign_category', [__CLASS__, 'ajax_bulk_assign_category']);
    }
    
    /**
     * Get all available categories
     * 
     * @return array Categories with counts
     */
    public function get_all_categories() {
        // Get custom categories from database
        $custom_categories = $this->wpdb->get_results(
            "SELECT category, COUNT(*) as count 
            FROM {$this->table} 
            WHERE category IS NOT NULL AND category != ''
            GROUP BY category
            ORDER BY count DESC"
        );
        
        $categories = [];
        
        // Add default categories
        foreach (self::DEFAULT_CATEGORIES as $slug => $label) {
            $count = 0;
            foreach ($custom_categories as $cat) {
                if ($cat->category === $slug) {
                    $count = $cat->count;
                    break;
                }
            }
            $categories[$slug] = [
                'label' => $label,
                'count' => $count
            ];
        }
        
        // Add any custom categories not in defaults
        foreach ($custom_categories as $cat) {
            if (!isset($categories[$cat->category])) {
                $categories[$cat->category] = [
                    'label' => ucfirst($cat->category),
                    'count' => $cat->count
                ];
            }
        }
        
        return $categories;
    }
    
    /**
     * Get category for a file
     * 
     * @param int $file_id File ID
     * @return string|null Category
     */
    public function get_file_category($file_id) {
        return $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT category FROM {$this->table} WHERE id = %d",
            $file_id
        ));
    }
    
    /**
     * Get tags for a file
     * 
     * @param int $file_id File ID
     * @return array Tags
     */
    public function get_file_tags($file_id) {
        $tags = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT tags FROM {$this->table} WHERE id = %d",
            $file_id
        ));
        
        if (empty($tags)) {
            return [];
        }
        
        return array_map('trim', explode(',', $tags));
    }
    
    /**
     * Set category for a file
     * 
     * @param int $file_id File ID
     * @param string $category Category slug
     * @return bool Success
     */
    public function set_file_category($file_id, $category) {
        return $this->wpdb->update(
            $this->table,
            ['category' => sanitize_text_field($category)],
            ['id' => $file_id],
            ['%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Set tags for a file
     * 
     * @param int $file_id File ID
     * @param array|string $tags Tags (array or comma-separated string)
     * @return bool Success
     */
    public function set_file_tags($file_id, $tags) {
        if (is_array($tags)) {
            $tags = implode(',', array_map('trim', $tags));
        }
        
        return $this->wpdb->update(
            $this->table,
            ['tags' => sanitize_text_field($tags)],
            ['id' => $file_id],
            ['%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Set description for a file
     * 
     * @param int $file_id File ID
     * @param string $description Description
     * @return bool Success
     */
    public function set_file_description($file_id, $description) {
        return $this->wpdb->update(
            $this->table,
            ['description' => sanitize_textarea_field($description)],
            ['id' => $file_id],
            ['%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Bulk assign category to multiple files
     * 
     * @param array $file_ids File IDs
     * @param string $category Category slug
     * @return int Number of updated files
     */
    public function bulk_assign_category($file_ids, $category) {
        if (empty($file_ids) || !is_array($file_ids)) {
            return 0;
        }
        
        $file_ids = array_map('intval', $file_ids);
        $placeholders = implode(',', array_fill(0, count($file_ids), '%d'));
        
        $query = $this->wpdb->prepare(
            "UPDATE {$this->table} SET category = %s WHERE id IN ($placeholders)",
            array_merge([$category], $file_ids)
        );
        
        $this->wpdb->query($query);
        
        return $this->wpdb->rows_affected;
    }
    
    /**
     * Get files by category
     * 
     * @param string $category Category slug
     * @param int $limit Limit
     * @param int $offset Offset
     * @return array Files
     */
    public function get_files_by_category($category, $limit = 50, $offset = 0) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE category = %s 
                ORDER BY file_time DESC 
                LIMIT %d OFFSET %d";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $category, $limit, $offset));
    }
    
    /**
     * Get files by tags
     * 
     * @param array|string $tags Tags to search
     * @param int $limit Limit
     * @return array Files
     */
    public function get_files_by_tags($tags, $limit = 50) {
        if (is_string($tags)) {
            $tags = array_map('trim', explode(',', $tags));
        }
        
        if (empty($tags)) {
            return [];
        }
        
        $conditions = [];
        $values = [];
        
        foreach ($tags as $tag) {
            $conditions[] = "tags LIKE %s";
            $values[] = '%' . $this->wpdb->esc_like($tag) . '%';
        }
        
        $where = implode(' OR ', $conditions);
        $values[] = $limit;
        
        $sql = "SELECT * FROM {$this->table} 
                WHERE " . $where . " 
                ORDER BY file_time DESC 
                LIMIT %d";
        
        return $this->wpdb->get_results($this->wpdb->prepare($sql, ...$values));
    }
    
    /**
     * Get all unique tags
     * 
     * @return array Tags with counts
     */
    public function get_all_tags() {
        $results = $this->wpdb->get_results(
            "SELECT tags FROM {$this->table} WHERE tags IS NOT NULL AND tags != ''"
        );
        
        $tags = [];
        foreach ($results as $row) {
            $file_tags = array_map('trim', explode(',', $row->tags));
            foreach ($file_tags as $tag) {
                if (empty($tag)) continue;
                if (!isset($tags[$tag])) {
                    $tags[$tag] = 0;
                }
                $tags[$tag]++;
            }
        }
        
        arsort($tags);
        return $tags;
    }
    
    /**
     * Auto-detect category from file extension
     * 
     * @param string $filename Filename
     * @return string Category slug
     */
    public static function auto_detect_category($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $category_map = [
            'documents' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'ppt', 'pptx'],
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'ico'],
            'videos' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v'],
            'audio' => ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'],
            'archives' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'iso'],
        ];
        
        foreach ($category_map as $category => $extensions) {
            if (in_array($ext, $extensions)) {
                return $category;
            }
        }
        
        return 'other';
    }
    
    /**
     * AJAX: Get categories with counts
     */
    public static function ajax_get_categories() {
        $manager = new self();
        wp_send_json_success($manager->get_all_categories());
    }
    
    /**
     * AJAX: Bulk assign category
     */
    public static function ajax_bulk_assign_category() {
        check_ajax_referer('telegram_bulk_category', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $file_ids = isset($_POST['file_ids']) ? array_map('intval', $_POST['file_ids']) : [];
        $category = isset($_POST['category']) ? sanitize_text_field($_POST['category']) : '';
        
        if (empty($file_ids) || empty($category)) {
            wp_send_json_error('Invalid parameters');
        }
        
        $manager = new self();
        $updated = $manager->bulk_assign_category($file_ids, $category);
        
        wp_send_json_success([
            'updated' => $updated,
            'message' => sprintf('Updated %d files to category: %s', $updated, $category)
        ]);
    }
    
    /**
     * Render category dropdown
     * 
     * @param string $selected Selected category
     * @param string $name Input name
     * @param string $id Input ID
     * @return string HTML
     */
    public static function render_category_dropdown($selected = '', $name = 'category', $id = 'telegram_category') {
        $categories = self::DEFAULT_CATEGORIES;
        
        $html = '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" class="regular-text">';
        $html .= '<option value="">-- Select Category --</option>';
        
        foreach ($categories as $slug => $label) {
            $selected_attr = ($selected === $slug) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($slug) . '"' . $selected_attr . '>' . esc_html($label) . '</option>';
        }
        
        $html .= '</select>';
        
        return $html;
    }
    
    /**
     * Render category badge
     * 
     * @param string $category Category slug
     * @return string HTML
     */
    public static function render_category_badge($category) {
        if (empty($category)) {
            return '<span class="tg-badge tg-badge-gray">Uncategorized</span>';
        }
        
        $label = isset(self::DEFAULT_CATEGORIES[$category]) 
            ? self::DEFAULT_CATEGORIES[$category] 
            : ucfirst($category);
        
        $colors = [
            'documents' => 'blue',
            'images' => 'green',
            'videos' => 'purple',
            'audio' => 'orange',
            'archives' => 'red',
            'other' => 'gray'
        ];
        
        $color = isset($colors[$category]) ? $colors[$category] : 'gray';
        
        return '<span class="tg-badge tg-badge-' . $color . '">' . esc_html($label) . '</span>';
    }
}
