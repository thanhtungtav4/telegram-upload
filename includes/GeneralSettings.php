<?php
if (!defined('ABSPATH')) exit;

class GeneralSettings {
    const OPTION_NAME = 'te_file_upload_settings';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_settings_submenu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook !== 'toplevel_page_telegram-file-upload') return; // chỉ load đúng trang
            wp_enqueue_style(
                'telefiup-tailwind-settings',
                plugins_url('../assets/vendor/tailwind.min.css', __FILE__),
                array(),
                '2.2.19'
            );
            wp_enqueue_script(
                'telefiup-admin-settings',
                plugins_url('../assets/admin-settings.js', __FILE__),
                array(),
                '2.6.1',
                true
            );
        });
        add_action('init', function() {
            if (class_exists('TelegramUploader')) {
                new TelegramUploader();
            }
        });
        self::register_test_ajax();
    }

    public static function add_settings_submenu() {
        add_submenu_page(
            'telegram-file-upload',
            'Settings',
            'Settings',
            'manage_options',
            'te-file-upload-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting(self::OPTION_NAME, self::OPTION_NAME, [__CLASS__, 'sanitize_settings']);
    }

    public static function sanitize_settings($input) {
        return [
            'token'         => base64_encode(sanitize_text_field($input['token'])),
            'chat_id'       => base64_encode(sanitize_text_field($input['chat_id'])),
            'api_base'      => esc_url_raw($input['api_base']),
            'upload_method' => sanitize_text_field($input['upload_method'] ?? 'server-side'),
        ];
    }

    public static function render_settings_page() {
        $options = get_option(self::OPTION_NAME);
        $token = isset($options['token']) ? base64_decode($options['token']) : '';
        $chat_id = isset($options['chat_id']) ? base64_decode($options['chat_id']) : '';
        $api_base = isset($options['api_base']) ? $options['api_base'] : 'https://api.telegram.org';
        $upload_method = isset($options['upload_method']) ? $options['upload_method'] : 'server-side';
        ?>
        <div class="wrap">
            <h1>Telegram Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_NAME); ?>
                <table class="form-table">
                    <tr><th>Bot Token</th><td><input type="text" name="<?php echo self::OPTION_NAME ?>[token]" value="<?php echo esc_attr($token) ?>" size="60" /></td></tr>
                    <tr><th>Chat ID</th><td><input type="text" name="<?php echo self::OPTION_NAME ?>[chat_id]" value="<?php echo esc_attr($chat_id) ?>" size="60" /></td></tr>
                    <tr>
                        <th>API Base URL</th>
                        <td>
                            <input type="text" name="<?php echo self::OPTION_NAME ?>[api_base]" value="<?php echo esc_attr($api_base) ?>" size="60" />
                            <br />
                            <small class="text-gray-600">Default: https://api.telegram.org. Only change this if you use a custom Telegram API endpoint.</small>
                        </td>
                    </tr>
                    <tr>
                        <th>Upload Method</th>
                        <td>
                            <label>
                                <input type="radio" name="<?php echo self::OPTION_NAME ?>[upload_method]" value="server-side" <?php echo checked($upload_method, 'server-side', false) ?> />
                                <strong>Server-Side Upload (Mặc định)</strong>
                                <br />
                                <small class="text-gray-600">
                                    ✅ Bảo mật tốt (bot token không lộ ra)<br />
                                    ❌ Tốn bandwidth VPS<br />
                                    ❌ Có thể timeout với file lớn
                                </small>
                            </label>
                            <br /><br />
                            <label>
                                <input type="radio" name="<?php echo self::OPTION_NAME ?>[upload_method]" value="client-side" <?php echo checked($upload_method, 'client-side', false) ?> />
                                <strong>Client-Side Upload (Tiết kiệm bandwidth)</strong>
                                <br />
                                <small class="text-gray-600">
                                    ✅ Tiết kiệm 99% bandwidth VPS<br />
                                    ✅ Không timeout (upload trực tiếp lên Telegram)<br />
                                    ⚠️ Bot token sẽ lộ ra client (có thể bị abuse)<br />
                                    ✅ Có rate limiting để bảo vệ
                                </small>
                            </label>
                            <br /><br />
                            <div class="notice notice-info inline">
                                <p>
                                    <strong>Khuyến nghị:</strong> Dùng <strong>Client-Side</strong> nếu:<br />
                                    • Bạn upload file lớn (>10MB) thường xuyên<br />
                                    • VPS bandwidth có giới hạn<br />
                                    • Chỉ user WordPress được upload (đã có authentication)<br />
                                    <br />
                                    Dùng <strong>Server-Side</strong> nếu:<br />
                                    • Bạn lo ngại bot token bị lộ<br />
                                    • File size nhỏ (<5MB)<br />
                                    • VPS bandwidth không giới hạn
                                </p>
                            </div>
                        </td>
                    </tr>
                </table>
                <button type="button" id="te-send-test" class="bg-blue-500 text-white px-4 py-2 rounded">Send Test</button>
                <span id="te-send-test-result" class="ml-4"></span>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function get_token() {
        $options = get_option(self::OPTION_NAME);
        return isset($options['token']) ? base64_decode($options['token']) : '';
    }

    public static function get_chat_id() {
        $options = get_option(self::OPTION_NAME);
        return isset($options['chat_id']) ? base64_decode($options['chat_id']) : '';
    }

    public static function get_api_base() {
        $options = get_option(self::OPTION_NAME);
        return isset($options['api_base']) && !empty($options['api_base']) 
            ? esc_url_raw($options['api_base']) 
            : 'https://api.telegram.org';
    }

    // Register AJAX handler for Send Test button
    public static function register_test_ajax() {
        add_action('wp_ajax_te_send_test_telegram', [__CLASS__, 'ajax_send_test_telegram']);
        add_action('wp_ajax_nopriv_te_send_test_telegram', [__CLASS__, 'ajax_send_test_telegram']);
    }

    public static function ajax_send_test_telegram() {
        $token = self::get_token();
        $chat_id = self::get_chat_id();
        $api_base = self::get_api_base();
        if (!$token || !$chat_id) {
            wp_send_json_error(['message' => 'Bot Token or Chat ID not set.']);
        }
        $message = '✅ Telegram File Upload plugin test message.';
        $url = $api_base . "/bot{$token}/sendMessage";
        $response = wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $message,
            ],
        ]);
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['ok'])) {
            wp_send_json_success(['message' => 'Test message sent!']);
        } else {
            $desc = isset($body['description']) ? $body['description'] : 'Unknown error';
            wp_send_json_error(['message' => $desc]);
        }
    }
}
