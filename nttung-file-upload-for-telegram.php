<?php
/**
 * Plugin Name: Nttung File Upload for Telegram
 * Plugin URI: https://github.com/thanhtungtav4/nttung-file-upload-for-telegram
 * Description: Upload and send files to Telegram using the Bot API with Analytics, Categories, and Access Control. Features client-side upload to save 99.99% VPS bandwidth.
 * Version: 2.6.2
 * Author: Thanh Tung
 * Author URI: https://nttung.dev
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nttung-file-upload-for-telegram
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

// Load class files
require_once plugin_dir_path(__FILE__) . 'includes/GeneralSettings.php';
require_once plugin_dir_path(__FILE__) . 'includes/FileSplitter.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramDownloadProxy.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramShortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramUploaderCore.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramUploaderDisplay.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramUploader.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramUploadAPI.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramAnalytics.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramCategories.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramAccessControl.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramAsyncUploader.php';
require_once plugin_dir_path(__FILE__) . 'includes/TelegramUploadToken.php';

// Init settings
add_action('plugins_loaded', function () {
    if (class_exists('GeneralSettings')) {
        GeneralSettings::init();
    }
    if (class_exists('TelegramDownloadProxy')) {
        TelegramDownloadProxy::init();
    }
    if (class_exists('TelegramShortcodes')) {
        TelegramShortcodes::init();
    }
    if (class_exists('TelegramAnalytics')) {
        TelegramAnalytics::init();
    }
    if (class_exists('TelegramCategories')) {
        TelegramCategories::init();
    }
    if (class_exists('TelegramAsyncUploader')) {
        TelegramAsyncUploader::init();
    }
    if (class_exists('TelegramUploadToken')) {
        TelegramUploadToken::init();
    }
    
    // Schedule daily cron job for expired files cleanup
    if (!wp_next_scheduled('telegram_cleanup_expired_files')) {
        wp_schedule_event(time(), 'daily', 'telegram_cleanup_expired_files');
    }
    
    // Schedule daily cron job for pending uploads cleanup
    if (!wp_next_scheduled('telegram_cleanup_pending_uploads')) {
        wp_schedule_event(time(), 'daily', 'telegram_cleanup_pending_uploads');
    }
});

// Cron job to deactivate expired files
add_action('telegram_cleanup_expired_files', function () {
    if (class_exists('TelegramAccessControl')) {
        $deactivated = TelegramAccessControl::deactivate_expired_files();
        
        // Optional: Log the cleanup
        if ($deactivated > 0) {
            error_log("Telegram Upload: Deactivated {$deactivated} expired file(s)");
        }
    }
});

// Cron job to cleanup old pending uploads
add_action('telegram_cleanup_pending_uploads', function () {
    if (class_exists('TelegramAsyncUploader')) {
        TelegramAsyncUploader::cleanup_old_pending();
    }
});

// Register REST API routes
add_action('rest_api_init', function () {
    if (class_exists('TelegramUploadAPI')) {
        $core = new TelegramUploaderCore();
        $api = new TelegramUploadAPI($core);
        $api->register_routes();
    }
});

// Register activation hook: create DB table and flush rewrite rules
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'telegram_uploaded_files';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        file_name text NOT NULL,
        file_size bigint(20) NOT NULL,
        file_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        telegram_file_id text NOT NULL,
        download_count int(11) DEFAULT 0 NOT NULL,
        category varchar(100) DEFAULT NULL,
        tags text DEFAULT NULL,
        description text DEFAULT NULL,
        expiration_date datetime DEFAULT NULL,
        password_hash varchar(255) DEFAULT NULL,
        max_downloads int(11) DEFAULT NULL,
        access_count int(11) DEFAULT 0 NOT NULL,
        is_active tinyint(1) DEFAULT 1 NOT NULL,
        PRIMARY KEY  (id),
        KEY category (category),
        KEY expiration_date (expiration_date),
        KEY is_active (is_active)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    
    // Migration: Add download_count column if it doesn't exist (for upgrades)
    $column_exists = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'download_count'",
            DB_NAME,
            $table
        )
    );
    
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN download_count INT(11) DEFAULT 0 NOT NULL");
    }
    
    // Migration: Add category, tags, description columns if they don't exist
    $category_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'category'",
        DB_NAME, $table
    ));
    
    if (!$category_exists) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN category VARCHAR(100) DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN tags TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN description TEXT DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD KEY category (category)");
    }
    
    // Migration: Add access control columns if they don't exist
    $expiration_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'expiration_date'",
        DB_NAME, $table
    ));
    
    if (!$expiration_exists) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN expiration_date DATETIME DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN max_downloads INT(11) DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN access_count INT(11) DEFAULT 0 NOT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN is_active TINYINT(1) DEFAULT 1 NOT NULL");
        $wpdb->query("ALTER TABLE {$table} ADD KEY expiration_date (expiration_date)");
        $wpdb->query("ALTER TABLE {$table} ADD KEY is_active (is_active)");
    }
    
    // Create analytics table
    $analytics_table = $wpdb->prefix . 'telegram_download_analytics';
    $analytics_sql = "CREATE TABLE $analytics_table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        file_id mediumint(9) NOT NULL,
        user_id bigint(20) DEFAULT NULL,
        download_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        ip_address varchar(45) NOT NULL,
        user_agent text NOT NULL,
        PRIMARY KEY  (id),
        KEY file_id (file_id),
        KEY user_id (user_id),
        KEY download_time (download_time)
    ) $charset_collate;";
    
    dbDelta($analytics_sql);
    
    // Create pending uploads table (for async uploader)
    if (class_exists('TelegramAsyncUploader')) {
        TelegramAsyncUploader::create_pending_table();
    }
    
    // Create upload tokens table (for client-side upload) - v2.6.0
    $tokens_table = $wpdb->prefix . 'telegram_upload_tokens';
    $tokens_sql = "CREATE TABLE $tokens_table (
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
    
    dbDelta($tokens_sql);
    
    // Activate download proxy
    if (class_exists('TelegramDownloadProxy')) {
        TelegramDownloadProxy::activate();
    }
});

// Register deactivation hook: flush rewrite rules and clear cron
register_deactivation_hook(__FILE__, function () {
    // Clear scheduled cron job
    $timestamp = wp_next_scheduled('telegram_cleanup_expired_files');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'telegram_cleanup_expired_files');
    }
    
    // Flush rewrite rules
    if (class_exists('TelegramDownloadProxy')) {
        TelegramDownloadProxy::deactivate();
    }
});
