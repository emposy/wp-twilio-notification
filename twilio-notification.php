<?php
/*
Plugin Name: Twilio Notification for CF7
Plugin URI: https://github.com/emposy/wp-twilio-notification
Description: Contact Form 7からTwilioを使って電話とSMSで通知を送信
Version: 1.2.0
Author: masasgr
Text Domain: twilio-notification
Requires at least: 5.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';
use Twilio\Rest\Client;

class TwilioNotificationCF7 {
    
    private $option_name = 'twilio_notification_settings';
    
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wpcf7_mail_sent', [$this, 'send_notification']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }
    
    public function add_admin_menu() {
        add_options_page('Twilio通知設定', 'Twilio通知', 'manage_options', 'twilio-notification', [$this, 'settings_page']);
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_twilio-notification') return;
        ?>
        <style>
            .nav-tab-wrapper { margin-bottom: 20px; }
            .twilio-tab-content { display: none; }
            .twilio-tab-content.active { display: block; }
            .twiml-option { display: none; }
            .twiml-option.active { display: block; margin-top: 10px; }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $('.twilio-tab-content').removeClass('active');
                $(this).addClass('nav-tab-active');
                $($(this).attr('href')).addClass('active');
            });
            
            $('input[name="<?php echo $this->option_name; ?>[twiml_type]"]').change(function() {
                $('.twiml-option').removeClass('active');
                if ($(this).val() === 'url') {
                    $('#twiml-url-option').addClass('active');
                } else {
                    $('#twiml-text-option').addClass('active');
                }
            }).trigger('change');
        });
        </script>
        <?php
    }
    
    public function register_settings() {
        register_setting('twilio_notification_group', $this->option_name);
        
        // 基本設定
        add_settings_section('twilio_basic_section', '基本設定', null, 'twilio-notification-basic');
        add_settings_field('twilio_sid', 'Account SID', [$this, 'render_text_field'], 'twilio-notification-basic', 'twilio_basic_section', ['field' => 'twilio_sid']);
        add_settings_field('twilio_token', 'Auth Token', [$this, 'render_text_field'], 'twilio-notification-basic', 'twilio_basic_section', ['field' => 'twilio_token', 'type' => 'password']);
        add_settings_field('twilio_number', 'Twilio電話番号', [$this, 'render_text_field'], 'twilio-notification-basic', 'twilio_basic_section', ['field' => 'twilio_number', 'placeholder' => '+815012345678']);
        add_settings_field('cf7_tel_field', 'CF7電話番号フィールド名', [$this, 'render_text_field'], 'twilio-notification-basic', 'twilio_basic_section', ['field' => 'cf7_tel_field', 'placeholder' => 'your-tel']);
        
        // 電話通知設定
        add_settings_section('twilio_call_section', '電話通知設定', null, 'twilio-notification-call');
        add_settings_field('to_number', '通知先電話番号', [$this, 'render_text_field'], 'twilio-notification-call', 'twilio_call_section', ['field' => 'to_number', 'placeholder' => '+819012345678']);
        add_settings_field('twiml_type', 'メッセージ形式', [$this, 'render_twiml_type_field'], 'twilio-notification-call', 'twilio_call_section', []);
        
        // SMS設定
        add_settings_section('twilio_sms_section', 'SMS設定', null, 'twilio-notification-sms');
        add_settings_field('sms_enabled', 'SMS送信', [$this, 'render_checkbox_field'], 'twilio-notification-sms', 'twilio_sms_section', ['field' => 'sms_enabled', 'label' => 'お客様へSMSを送信する']);
        add_settings_field('sms_body', 'SMS本文', [$this, 'render_textarea_field'], 'twilio-notification-sms', 'twilio_sms_section', ['field' => 'sms_body', 'rows' => 5]);
        
        // テストモード設定
        add_settings_section('twilio_test_section', 'テストモード設定', null, 'twilio-notification-test');
        add_settings_field('test_mode', 'テストモード', [$this, 'render_checkbox_field'], 'twilio-notification-test', 'twilio_test_section', ['field' => 'test_mode', 'label' => 'テストモードを有効にする（本番の電話番号の代わりにテスト用番号を使用）']);
        add_settings_field('test_to_number', 'テスト用通知先電話番号', [$this, 'render_text_field'], 'twilio-notification-test', 'twilio_test_section', ['field' => 'test_to_number', 'placeholder' => '+819012345678']);
        add_settings_field('test_customer_number', 'テスト用お客様電話番号', [$this, 'render_text_field'], 'twilio-notification-test', 'twilio_test_section', ['field' => 'test_customer_number', 'placeholder' => '+819012345678']);
    }
    
    public function render_text_field($args) {
        $options = get_option($this->option_name);
        $value = isset($options[$args['field']]) ? $options[$args['field']] : '';
        $type = isset($args['type']) ? $args['type'] : 'text';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        printf('<input type="%s" name="%s[%s]" value="%s" placeholder="%s" class="regular-text" />', 
            esc_attr($type), esc_attr($this->option_name), esc_attr($args['field']), esc_attr($value), esc_attr($placeholder));
    }
    
    public function render_textarea_field($args) {
        $options = get_option($this->option_name);
        $value = isset($options[$args['field']]) ? $options[$args['field']] : '';
        $rows = isset($args['rows']) ? $args['rows'] : 5;
        printf('<textarea name="%s[%s]" rows="%d" class="large-text">%s</textarea>', 
            esc_attr($this->option_name), esc_attr($args['field']), $rows, esc_textarea($value));
    }
    
    public function render_checkbox_field($args) {
        $options = get_option($this->option_name);
        $checked = isset($options[$args['field']]) && $options[$args['field']] ? 'checked' : '';
        $label = isset($args['label']) ? $args['label'] : '';
        printf('<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>', 
            esc_attr($this->option_name), esc_attr($args['field']), $checked, esc_html($label));
    }
    
    public function render_twiml_type_field() {
        $options = get_option($this->option_name);
        $type = isset($options['twiml_type']) ? $options['twiml_type'] : 'url';
        $url = isset($options['twiml_url']) ? $options['twiml_url'] : '';
        $text = isset($options['twiml_text']) ? $options['twiml_text'] : '';
        ?>
        <label><input type="radio" name="<?php echo $this->option_name; ?>[twiml_type]" value="url" <?php checked($type, 'url'); ?> /> TwiML URLを使用</label><br>
        <label><input type="radio" name="<?php echo $this->option_name; ?>[twiml_type]" value="text" <?php checked($type, 'text'); ?> /> テキストメッセージを使用</label>
        
        <div id="twiml-url-option" class="twiml-option">
            <input type="url" name="<?php echo $this->option_name; ?>[twiml_url]" value="<?php echo esc_attr($url); ?>" placeholder="https://..." class="regular-text" />
            <p class="description">TwiML BinのURLを入力してください</p>
        </div>
        
        <div id="twiml-text-option" class="twiml-option">
            <textarea name="<?php echo $this->option_name; ?>[twiml_text]" rows="3" class="large-text"><?php echo esc_textarea($text); ?></textarea>
            <p class="description">電話で読み上げるメッセージを入力してください（日本語OK）</p>
        </div>
        <?php
    }
    
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Twilio通知設定</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="#basic" class="nav-tab nav-tab-active">基本設定</a>
                <a href="#call" class="nav-tab">電話通知</a>
                <a href="#sms" class="nav-tab">SMS</a>
                <a href="#test" class="nav-tab">テストモード</a>
            </h2>
            
            <form method="post" action="options.php">
                <?php settings_fields('twilio_notification_group'); ?>
                
                <div id="basic" class="twilio-tab-content active">
                    <?php do_settings_sections('twilio-notification-basic'); ?>
                </div>
                
                <div id="call" class="twilio-tab-content">
                    <?php do_settings_sections('twilio-notification-call'); ?>
                </div>
                
                <div id="sms" class="twilio-tab-content">
                    <?php do_settings_sections('twilio-notification-sms'); ?>
                </div>
                
                <div id="test" class="twilio-tab-content">
                    <?php do_settings_sections('twilio-notification-test'); ?>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public function send_notification($cf7) {
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;
        
        $options = get_option($this->option_name);
        $sid = $options['twilio_sid'] ?? '';
        $token = $options['twilio_token'] ?? '';
        $twilio_number = $options['twilio_number'] ?? '';
        
        if (empty($sid) || empty($token) || empty($twilio_number)) return;
        
        $client = new Client($sid, $token);
        $test_mode = isset($options['test_mode']) && $options['test_mode'];
        
        // 管理者へ電話
        $to_number = $test_mode && !empty($options['test_to_number']) ? $options['test_to_number'] : ($options['to_number'] ?? '');
        if (!empty($to_number)) {
            $this->make_call($client, $twilio_number, $to_number, $options);
        }
        
        // お客様へSMS
        if (isset($options['sms_enabled']) && $options['sms_enabled']) {
            $this->send_customer_sms($submission, $client, $twilio_number, $options, $test_mode);
        }
    }
    
    private function make_call($client, $from, $to, $options) {
        $twiml_type = $options['twiml_type'] ?? 'url';
        
        try {
            if ($twiml_type === 'url' && !empty($options['twiml_url'])) {
                $client->calls->create($to, $from, ['url' => $options['twiml_url']]);
            } elseif ($twiml_type === 'text' && !empty($options['twiml_text'])) {
                $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response><Say language="ja-JP">' . htmlspecialchars($options['twiml_text']) . '</Say></Response>';
                $client->calls->create($to, $from, ['twiml' => $twiml]);
            }
        } catch (Exception $e) {
            error_log('Twilio Call Error: ' . $e->getMessage());
        }
    }
    
    private function send_customer_sms($submission, $client, $from, $options, $test_mode) {
        $data = $submission->get_posted_data();
        $field_name = $options['cf7_tel_field'] ?? 'your-tel';
        $sms_body = $options['sms_body'] ?? 'お問い合わせありがとうございます。';
        
        if ($test_mode && !empty($options['test_customer_number'])) {
            $customer_tel = $options['test_customer_number'];
        } else {
            $customer_tel = $data[$field_name] ?? null;
        }
        
        if (empty($customer_tel)) return;
        
        $formatted_tel = $this->format_phone_number($customer_tel);
        if (empty($formatted_tel)) return;
        
        try {
            $client->messages->create($formatted_tel, ['from' => $from, 'body' => $sms_body]);
        } catch (Exception $e) {
            error_log('Twilio SMS Error: ' . $e->getMessage());
        }
    }
    
    private function format_phone_number($number) {
        $number = preg_replace('/[\s\-]/', '', $number);
        if (strpos($number, '0') === 0) {
            $number = '+81' . substr($number, 1);
        }
        if (strpos($number, '+') !== 0) {
            $number = '+81' . $number;
        }
        return $number;
    }
}

add_action('plugins_loaded', function() {
    $plugin = new TwilioNotificationCF7();
    $plugin->init();
});