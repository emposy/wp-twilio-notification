<?php
/*
Plugin Name: Twilio Notification for CF7
Plugin URI: https://github.com/masayapy/twilio-notification
Description: Contact Form 7からTwilioで電話とSMSを送信
Version: 1.0.0
Author: Emposy, inc.
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
    }
    
    public function add_admin_menu() {
        add_options_page('Twilio通知設定', 'Twilio通知', 'manage_options', 'twilio-notification', [$this, 'settings_page']);
    }
    
    public function register_settings() {
        register_setting('twilio_notification_group', $this->option_name);
        add_settings_section('twilio_notification_section', 'Twilio設定', null, 'twilio-notification');
        
        add_settings_field('twilio_sid', 'Account SID', [$this, 'render_text_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'twilio_sid']);
        add_settings_field('twilio_token', 'Auth Token', [$this, 'render_text_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'twilio_token', 'type' => 'password']);
        add_settings_field('twilio_number', 'Twilio電話番号', [$this, 'render_text_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'twilio_number', 'placeholder' => '+815012345678']);
        add_settings_field('to_number', '通知先電話番号', [$this, 'render_text_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'to_number', 'placeholder' => '+819012345678']);
        add_settings_field('twiml_url', 'TwiML URL', [$this, 'render_text_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'twiml_url', 'type' => 'url']);
        add_settings_field('sms_body', 'SMS本文', [$this, 'render_textarea_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'sms_body']);
        add_settings_field('cf7_tel_field', 'CF7電話番号フィールド名', [$this, 'render_text_field'], 'twilio-notification', 'twilio_notification_section', ['field' => 'cf7_tel_field', 'placeholder' => 'your-tel']);
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
        printf('<textarea name="%s[%s]" rows="5" class="large-text">%s</textarea>', 
            esc_attr($this->option_name), esc_attr($args['field']), esc_textarea($value));
    }
    
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Twilio通知設定</h1>
            <form method="post" action="options.php">
                <?php settings_fields('twilio_notification_group'); ?>
                <?php do_settings_sections('twilio-notification'); ?>
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
        $to_number = $options['to_number'] ?? '';
        $twiml_url = $options['twiml_url'] ?? '';
        
        if (empty($sid) || empty($token) || empty($twilio_number) || empty($to_number)) return;
        
        $client = new Client($sid, $token);
        
        if (!empty($twiml_url)) {
            try {
                $client->calls->create($to_number, $twilio_number, ['url' => $twiml_url]);
            } catch (Exception $e) {
                error_log('Twilio Call Error: ' . $e->getMessage());
            }
        }
        
        $data = $submission->get_posted_data();
        $field_name = $options['cf7_tel_field'] ?? 'your-tel';
        $sms_body = $options['sms_body'] ?? 'お問い合わせありがとうございます。';
        $customer_tel = $data[$field_name] ?? null;
        
        if (!empty($customer_tel)) {
            $formatted_tel = $this->format_phone_number($customer_tel);
            if (!empty($formatted_tel)) {
                try {
                    $client->messages->create($formatted_tel, ['from' => $twilio_number, 'body' => $sms_body]);
                } catch (Exception $e) {
                    error_log('Twilio SMS Error: ' . $e->getMessage());
                }
            }
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