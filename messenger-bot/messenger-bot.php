<?php
/*
Plugin Name: Messenger Bot
Plugin URI: https://zfpluginbot.xyz
Description: A plugin for handling Telegram messages
Version: 1.0.0
Author: Zeynab Farshidi
*/

if (!defined('ABSPATH')) {
    exit;
}

define('MESSENGER_BOT_VERSION', '1.0.0');
define('MESSENGER_BOT_PATH', plugin_dir_path(__FILE__));
define('MESSENGER_BOT_URL', plugin_dir_url(__FILE__));
define('BOT_TOKEN', '7681362529:AAHUjV8JgDlNJWjjsnATUjK9Svujcmjmq_8');

require_once MESSENGER_BOT_PATH . 'includes/Messengers/TelegramMessenger.php';
require_once MESSENGER_BOT_PATH . 'includes/class-messenger-manager.php';
require_once MESSENGER_BOT_PATH . 'includes/Messengers/ProxyManager.php';
require_once MESSENGER_BOT_PATH . 'includes/Models/Group.php';

use MessengerBot\Messengers\TelegramMessenger;
use MessengerBot\Messengers\ProxyManager;
use MessengerBot\Models\Group;

spl_autoload_register(function ($class) {
    // اضافه کردن یک log برای دیباگ
    error_log("Trying to load: " . $class);

    $prefix = 'MessengerBot\\';
    $base_dir = plugin_dir_path(__FILE__);

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // اضافه کردن log برای مسیر فایل
    error_log("Looking for file: " . $file);

    if (file_exists($file)) {
        require $file;
    }
});

class Messenger_Bot
{
    private static $instance = null;
    private $messenger_manager;
    private $telegram;
    private $proxy;
    private $group;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init_messenger_manager();
        $this->init_hooks();
        $this->telegram = new TelegramMessenger();
        $this->proxy = new ProxyManager();
        $this->group = new Group();
    }

    private function init_messenger_manager()
    {
        $this->messenger_manager = new MessengerManager();
        $telegram = new TelegramMessenger();
        $this->messenger_manager->register_messenger($telegram);
    }

    private function init_hooks()
    {
        add_action('category_add_form_fields', [$this, 'add_telegram_groups_field']);
        add_action('category_edit_form_fields', [$this, 'edit_telegram_groups_field']);
        add_action('created_category', [$this, 'save_telegram_groups']);
        add_action('edited_category', [$this, 'save_telegram_groups']);
        add_action('publish_post', [$this, 'send_post_to_telegram_groups'], 10, 2);
        add_action('admin_menu', [$this, 'add_telegram_groups_menu']);
//ارسال پیام به گروه‌های تلگرام
        add_action('admin_menu', [$this, 'add_menu_send_messages_to_telegram_groups']);
        add_action('admin_init', [$this, 'processing_of_sending_messages_to_telegram_groups']);
        add_action('init', [$this, 'register_portfolio_post_type']);
        add_action('add_meta_boxes', [$this, 'add_portfolio_file_metabox']);
        add_action('save_post_portfolio', [$this, 'save_portfolio_file']);
        add_filter('the_content', [$this, 'add_telegram_notification_button'], 20);
        add_action('wp_footer', [$this, 'add_telegram_notify_scripts']);
        add_action('init', [$this, 'register_portfolio_taxonomy']);
        add_action('portfolio_category_add_form_fields', [$this, 'add_telegram_groups_to_portfolio_category']);
        add_action('portfolio_category_edit_form_fields', [$this, 'add_telegram_groups_to_portfolio_category']);
        add_action('created_portfolio_category', [$this, 'save_portfolio_telegram_groups']);
        add_action('edited_portfolio_category', [$this, 'save_portfolio_telegram_groups']);
        add_action('template_redirect', [$this, 'process_portfolio_telegram_notification']);
        add_filter('the_content', [$this, 'add_portfolio_categories_to_content']);
        add_filter('the_content', [$this, 'add_voice_recorder_to_portfolio_form']);
        add_action('rest_api_init', [$this, 'process_telegram_webhook']);
//        ------Start proxy codes------
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'save_proxy']);
        add_action('admin_init', array($this, 'delete_proxy'));
//        ------End proxy codes------
    }

    //    ------Start proxy codes------
    public function add_admin_menu()
    {
        $this->proxy->add_admin_menu();
    }

    public function save_proxy()
    {
        $this->proxy->save_proxy();
    }

    public function delete_proxy()
    {
        $this->proxy->delete_proxy();
    }
    //    ------End proxy codes------

//    ------Start View and save Telegram groups in categories------
    public function add_telegram_groups_field(){
        $messengerType = $this->telegram->getName() . '_group';
        $this->group->addGroupsField($messengerType);
    }

    public function edit_telegram_groups_field($term)
    {
        $messengerType = $this->telegram->getName() . '_group';
        $this->group->editGroupsField($term, $messengerType);
    }

    public function save_telegram_groups($term_id)
    {
        $this->group->saveGroups($term_id);
    }
//    ------End View and save Telegram groups in categories------

//    ------Start codes List of Telegram groups that the robot is a member of------
    public function add_telegram_groups_menu()
    {
        add_menu_page(
            'گروه‌های تلگرام',    // عنوان صفحه
            'گروه‌های تلگرام',    // عنوان منو
            'manage_options',      // سطح دسترسی
            'telegram-groups',     // شناسه منو
            [$this, 'display_telegram_groups_page'], // تابع نمایش صفحه
            'dashicons-groups',    // آیکون
            25                     // موقعیت در منو
        );
    }

    public function display_telegram_groups_page()
    {
        $messengerType = $this->telegram->getName() . '_group';
        $this->group->displayGroupsPage($messengerType);
    }
//    ------End codes List of Telegram groups that the robot is a member of------

//    ------Start codes for automatically sending posts to Telegram groups------
    public function send_post_to_telegram_groups($post_id, $post)
    {
        $this->telegram->sendPostToTelegramGroups($post_id, $post);
    }
//    ------End codes for automatically sending posts to Telegram groups------

//    ------Start codes for sending messages to Telegram groups------
    public function display_telegram_send_message_page() {
        $this->telegram->displayTelegramSendMessagePage();
    }
    public function processing_of_sending_messages_to_telegram_groups()
    {
        $this->telegram->processingOfSendingMessagesToTelegramGroups();
    }
    public function add_menu_send_messages_to_telegram_groups()
    {
        add_menu_page(
            'ارسال پیام به گروه‌ها',
            'ارسال پیام تلگرام',
            'manage_options',
            'telegram-send-message',
            [$this, 'display_telegram_send_message_page'],
            'dashicons-megaphone',
            26
        );
    }
//    Sending bot contacts to users
    public function send_bot_contact($chat_id) {
        $this->telegram->sendBotContact($chat_id, 'zf_bot');
    }
//    Getting a list of group members and sending messages to them
    public function send_direct_message_to_members() {
        $this->telegram->sendDirectMessageToMembers();
    }
//    Add this code to AJAX handler section
//    public function ajax_send_to_member()
//    {
//        $result = $this->send_direct_message_to_members();
//        echo $result;
//        wp_die();
//    }
//    ------End codes for sending messages to Telegram groups------

//    ------Start Portfolio Post Codes------
    public function register_portfolio_post_type() {
        $this->telegram->registerPortfolioPostType();
    }
//    Add File Upload Metabox
    public function add_portfolio_file_metabox() {
        $this->telegram->addPortfolioFileMetabox();
    }
    public function render_portfolio_file_metabox($post) {
        $this->telegram->renderPortfolioFileMetabox($post);
    }
    public function save_portfolio_file($post_id) {
        $this->telegram->savePortfolioFile($post_id);
    }
//    Add Notification Button to Portfolio Screen
    public function add_telegram_notification_button($content) {
        return $this->telegram->addTelegramNotificationButton($content);
    }
//    JavaScript Codes
    public function add_telegram_notify_scripts() {
        $this->telegram->addTelegramNotifyScripts();
    }
//    Create Categories for Portfolio
    public function register_portfolio_taxonomy() {
        $this->telegram->registerPortfolioTaxonomy();
    }
//    Add Telegram Groups Field to Portfolio Category

    public function add_telegram_groups_to_portfolio_category($term = null) {
        $this->telegram->addTelegramGroupsToPortfolioCategory($term);
    }
    public function save_portfolio_telegram_groups($term_id) {
        $this->telegram->savePortfolioTelegramGroups($term_id);
    }
//    Processing Sending Messages to Telegram Groups in Portfolio
    public function process_portfolio_telegram_notification() {
        $this->telegram->processPortfolioTelegramNotification();
    }
    public function send_message_to_telegram($group_id, $message) {
        $this->telegram->sendMessageToTelegram($group_id, $message);
    }
    public function send_telegram_message($group_id, $message) {
        $this->telegram->sendTelegramMessage($group_id, $message);
    }
    public function send_telegram_file($group_id, $file_url) {
        $this->telegram->sendTelegramFile($group_id, $file_url);
    }
    public function add_portfolio_categories_to_content($content) {
        $this->telegram->addPortfolioCategoriesToContent($content);
    }
//    Adding Audio Record Button to Form
    public function add_voice_recorder_to_portfolio_form($content) {
        $this->telegram->addVoiceRecorderToPortfolioForm($content);
    }
//    Sending Audio Message to Telegram
    public function send_voice_to_telegram($group_id, $voice_base64) {
        $this->telegram->sendVoiceToTelegram($group_id, $voice_base64);
    }
    //    ------End Portfolio Post Codes------

//    ------Start sending messages in the bot------
    public function process_telegram_webhook() {
        $this->telegram->processTelegramWebhook();
    }
    //    ------End sending messages in the bot------

}

function messenger_bot()
{
    return Messenger_Bot::get_instance();
}

messenger_bot();


