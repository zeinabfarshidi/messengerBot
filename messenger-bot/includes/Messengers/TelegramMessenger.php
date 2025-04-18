<?php

namespace MessengerBot\Messengers;

require_once MESSENGER_BOT_PATH . 'includes/Messengers/ProxyManager.php';
require_once MESSENGER_BOT_PATH . 'includes/Models/Group.php';
require_once MESSENGER_BOT_PATH . 'includes/Interfaces/MessengerInterface.php';

use MessengerBot\Interfaces\MessengerInterface;
use MessengerBot\Models\Group;

class TelegramMessenger implements MessengerInterface
{
    private $bot_token;
    private $proxy;
    private $group;

    public function __construct()
    {
        $this->bot_token = BOT_TOKEN;
        $this->proxy = new ProxyManager();
        $this->group = new Group();
    }

    public function initialize(): void
    {
        // Check and set up webhook only if not set up
        if (!get_option('telegram_webhook_set')) {
            $webhook_url = site_url('wp-json/messenger-bot/v1/webhook');
            $result = $this->setWebhook($webhook_url);

            if ($result) {
                update_option('telegram_webhook_set', true);
            }
        }

        // Definition of the checkConnection method
        if ($this->checkConnection()) {
            // Connection is established.
            update_option('telegram_connection_status', 'connected');
        } else {
            // Connection problem
            update_option('telegram_connection_status', 'failed');
        }
    }

    private function checkConnection(): bool
    {
        $response = $this->proxy->sendRequest('getMe');
        return isset($response['ok']) && $response['ok'] === true;
    }

    public function getName(): string
    {
        return 'telegram';
    }

    public function sendMessage(string $chatId, string $message, array $options = []): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        return $this->proxy->sendRequest('sendMessage', array_merge($params, $options));
    }

    public function getGroupInfo(string $groupId): array
    {
        return $this->proxy->sendRequest('getChat', [
            'chat_id' => $groupId
        ]);
    }

    public function sendPostToTelegramGroups($post_id, $post)
    {
        $content = file_get_contents('php://input');
        $json_decode = json_decode($content, true);
        // If the post is not publishing, go back.
        if ($post->post_status !== 'publish') {
            return;
        }

        global $wpdb;
        $category_ids = $json_decode['categories'];
        $categories_name = [];
        $groups_ids = [];
        foreach ($category_ids as $category_id) {
            $category = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}terms WHERE term_id = '$category_id'");
            $categories_name[] = $category->name;
            global $wpdb;
            $termmeta = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}termmeta WHERE term_id = %d",
                $category_id
            ));

            foreach (json_decode(get_term_meta($category_id, 'telegram_members', true), true) as $value) {
                $groups_ids[] = $value;
            }
        }

        foreach (array_unique($groups_ids) as $groups_id) {
            $message = "📢 مطلب جدید در " . implode(',', $categories_name) . ":\n\n";
            $message .= "🔸 " . $post->post_title . "\n\n";
            $message .= "📝 " . wp_trim_words(strip_tags($post->post_content), 30) . "\n\n";
            $message .= "🔗 " . get_permalink($post_id);

            $response = $this->sendMessage($groups_id, $message, []);
        }
    }

    public function displayTelegramSendMessagePage()
    {
        $message = $this->update_telegram_webhook();
        echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';

        $messengerType = $this->getName() . '_group';
        $groups = $this->group->getGroups($messengerType);
        ?>
        <div class="wrap">
            <h1>ارسال پیام به گروه‌های تلگرام</h1>
            <form method="post" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th>انتخاب گروه‌ها</th>
                        <td>
                            <label>
                                <input type="checkbox" id="select-all"> انتخاب همه
                            </label>
                            <br><br>
                            <?php foreach ($groups as $group): ?>
                                <label>
                                    <input type="checkbox" name="groups[]" value="<?php echo $group['id']; ?>">
                                    <?php echo $group['title']; ?>
                                </label>
                                <br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>متن پیام</th>
                        <td>
                            <textarea name="message" rows="5" cols="50" required></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th>فایل (اختیاری)</th>
                        <td>
                            <input type="file" name="attachment">
                        </td>
                    </tr>
                    <tr>
                        <th>ضبط صدا</th>
                        <td>
                            <button type="button" id="startRecord" class="button">شروع ضبط</button>
                            <button type="button" id="stopRecord" class="button" disabled>پایان ضبط</button>
                            <audio id="audioPreview" controls style="display:none"></audio>
                            <input type="hidden" name="audio_data" id="audioData">
                        </td>
                    </tr>
                </table>
                <div id="debug-results"></div>
                <?php wp_nonce_field('send_telegram_message', 'telegram_message_nonce'); ?>
                <input type="submit" name="send_message" class="button button-primary" value="ارسال پیام">
            </form>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                let a = $('#select-all').change(function () {
                    $('input[name="groups[]"]').prop('checked', $(this).prop('checked'));
                });
                let mediaRecorder;
                let audioChunks = [];

                $('#startRecord').click(async function () {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({audio: true});
                        mediaRecorder = new MediaRecorder(stream);

                        mediaRecorder.ondataavailable = (event) => {
                            audioChunks.push(event.data);
                        };

                        mediaRecorder.onstop = () => {
                            const audioBlob = new Blob(audioChunks, {type: 'audio/wav'});
                            const audioUrl = URL.createObjectURL(audioBlob);
                            $('#audioPreview').attr('src', audioUrl).show();

                            // Convert to Base64 for sending
                            const reader = new FileReader();
                            reader.readAsDataURL(audioBlob);
                            reader.onloadend = () => {
                                $('#audioData').val(reader.result);
                            };
                        };

                        audioChunks = [];
                        mediaRecorder.start();
                        $(this).prop('disabled', true);
                        $('#stopRecord').prop('disabled', false);
                    } catch (err) {
                        alert('خطا در دسترسی به میکروفون: ' + err.message);
                    }
                });

                $('#stopRecord').click(function () {
                    mediaRecorder.stop();
                    $(this).prop('disabled', true);
                    $('#startRecord').prop('disabled', false);
                });

                // برای لاگ
                $('#sendToMembers').click(function () {
                    $.post(ajaxurl, {
                        action: 'send_to_members'
                    }, function (response) {
                        // Show results on the page
                        $('#debug-results').html(response);
                    });
                });
            });
        </script>
        <?php
    }

    public function processingOfSendingMessagesToTelegramGroups()
    {
        if (isset($_POST['send_message']) && check_admin_referer('send_telegram_message', 'telegram_message_nonce')) {
            $groups = isset($_POST['groups']) ? $_POST['groups'] : [];
            $message = sanitize_textarea_field($_POST['message']);
            if (!empty($groups) && !empty($message)) {
                foreach ($groups as $group_id) {
                    // Send text with proxy
//                    $response = $this->proxy->sendRequest('sendMessage', [
//                        'chat_id' => $group_id,
//                        'text' => $message
//                    ]);

                    $response = $this->sendMessage($group_id, $message, []);

                    // If the file has been uploaded
                    if (!empty($_FILES['attachment']['tmp_name'])) {
                        $file_path = $_FILES['attachment']['tmp_name'];
                        $file_type = wp_check_filetype($_FILES['attachment']['name'])['type'];
                        // Detect file type and send
                        if (strpos($file_type, 'image') !== false) {
                            $endpoint = 'sendPhoto';
                            $param = 'photo';
                        } elseif (strpos($file_type, 'video') !== false) {
                            $endpoint = 'sendVideo';
                            $param = 'video';
                        } elseif (strpos($file_type, 'audio') !== false ||
                            strpos($file_type, 'mpeg') !== false ||
                            strpos($file_type, 'mp3') !== false) {
                            $endpoint = 'sendAudio';
                            $param = 'audio';
                        }

                        if (isset($endpoint)) {
                            // Sending files with a proxy
                            $response = $this->proxy->sendFileRequest($endpoint, [
                                'chat_id' => $group_id,
                                $param => new \CURLFile($file_path)
                            ]);
                            /* Previous code
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$token}/{$endpoint}");
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                                'chat_id' => $group_id,
                                $param => new CURLFile($file_path)
                            ]);
                            $response = curl_exec($ch);
                            curl_close($ch);
                            */
                        }
                    }
                    // Recorded voice processing code
                    if (isset($_POST['audio_data']) && !empty($_POST['audio_data'])) {
                        error_log('فایل صوتی وجود دارد');
                        $audio_data = $_POST['audio_data'];
                        $audio_data = str_replace('data:audio/wav;base64,', '', $audio_data);
                        $audio_data = base64_decode($audio_data);

                        // Temporary storage of audio files
                        $temp_file = wp_tempnam('audio_message');
                        file_put_contents($temp_file, $audio_data);

                        // Sending audio files with a proxy
                        $response = $this->proxy->sendFileRequest('sendAudio', [
                            'chat_id' => $group_id,
                            'audio' => new \CURLFile($temp_file, 'audio/wav', 'audio_message.wav')
                        ]);

                        /* Previous code
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$token}/sendAudio");
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, [
                            'chat_id' => $group_id,
                            'audio' => new CURLFile($temp_file, 'audio/wav', 'audio_message.wav')
                        ]);
                        $response = curl_exec($ch);
                        curl_close($ch);
                        */

                        // Delete temporary files
                        unlink($temp_file);
                    }
                }
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-success"><p>پیام با موفقیت ارسال شد.</p></div>';
                });

                echo 'ارسال با استفاده از انترفیس';
            }
        }
    }

    public function sendBotContact($chat_id, $bot_name)
    {
        $response = wp_remote_post("https://api.telegram.org/bot{$this->bot_token}/sendContact", [
            'body' => [
                'chat_id' => $chat_id,
                'phone_number' => '+98xxxxxxxxxx', // شماره تلفن ربات
                'first_name' => $bot_name,
                'last_name' => 'Bot',
                'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:{$bot_name}\nEND:VCARD"
            ]
        ]);
    }

    public function registerPortfolioPostType()
    {
        register_post_type('portfolio', array(
            'labels' => array(
                'name' => 'نمونه کارها',
                'singular_name' => 'نمونه کار',
                'add_new' => 'افزودن نمونه کار',
                'add_new_item' => 'افزودن نمونه کار جدید',
                'edit_item' => 'ویرایش نمونه کار',
                'all_items' => 'همه نمونه کارها'
            ),
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'thumbnail'),
            'menu_icon' => 'dashicons-portfolio',
            'publicly_queryable' => true,
            'show_in_nav_menus' => true,
            'rewrite' => array('slug' => 'portfolio')
        ));
    }

    public function addPortfolioFileMetabox()
    {
        add_meta_box(
            'portfolio_file',
            'فایل نمونه کار',
            'render_portfolio_file_metabox',
            'portfolio',
            'normal',
            'high'
        );
    }

    public function renderPortfolioFileMetabox($post)
    {
        wp_nonce_field('save_portfolio_file', 'portfolio_file_nonce');
        $file_url = get_post_meta($post->ID, '_portfolio_file', true);
        ?>
        <div>
            <input type="text" id="portfolio_file" name="portfolio_file" value="<?php echo esc_attr($file_url); ?>"
                   style="width:80%">
            <button type="button" class="button" id="upload_file_button">انتخاب فایل</button>
            <?php if ($file_url): ?>
                <a href="<?php echo esc_url($file_url); ?>" target="_blank">مشاهده فایل</a>
            <?php endif; ?>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                $('#upload_file_button').click(function (e) {
                    e.preventDefault();
                    var custom_uploader = wp.media({
                        title: 'انتخاب فایل نمونه کار',
                        button: {
                            text: 'انتخاب'
                        },
                        multiple: false
                    });
                    custom_uploader.on('select', function () {
                        var attachment = custom_uploader.state().get('selection').first().toJSON();
                        $('#portfolio_file').val(attachment.url);
                    });
                    custom_uploader.open();
                });
            });
        </script>
        <?php
    }

    public function savePortfolioFile($post_id)
    {
        if (!isset($_POST['portfolio_file_nonce']) ||
            !wp_verify_nonce($_POST['portfolio_file_nonce'], 'save_portfolio_file')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (isset($_POST['portfolio_file'])) {
            update_post_meta($post_id, '_portfolio_file', sanitize_text_field($_POST['portfolio_file']));
        }
    }

    public function addTelegramNotificationButton($content)
    {
        if (is_singular('portfolio')) {
            $button = '<div class="telegram-notify-button">';
            $button .= '<button type="button" class="button" onclick="openTelegramNotifyForm()">اطلاع‌رسانی در تلگرام</button>';
            $button .= '</div>';

            // فرم ارسال پیام
            $button .= '<div id="telegram-notify-form" style="display:none;">';
            $button .= '<h3>ارسال پیام به گروه‌های تلگرام</h3>';
            $button .= '<form method="post" enctype="multipart/form-data">';
            $button .= wp_nonce_field('telegram_notify', 'telegram_notify_nonce', true, false);
            $button .= '<textarea name="message" placeholder="متن پیام" style="width: 100%; height: 150px; padding: 15px"></textarea><br>';
            $button .= '<div style="margin: 20px 0"><input type="file" name="attachment"></div>';
            $button .= '<button type="submit" name="send_telegram" class="button">ارسال پیام</button>';
            $button .= '</form>';
            $button .= '</div>';

            $content .= $button;
        }
        return $content;
    }

    public function addTelegramNotifyScripts()
    {
        if (is_singular('portfolio')) {
            ?>
            <script>
                function openTelegramNotifyForm() {
                    var form = document.getElementById('telegram-notify-form');
                    form.style.display = form.style.display === 'none' ? 'block' : 'none';
                }
            </script>
            <?php
        }
    }

    public function registerPortfolioTaxonomy()
    {
        register_taxonomy(
            'portfolio_category',
            'portfolio',
            array(
                'labels' => array(
                    'name' => 'دسته‌بندی نمونه کارها',
                    'singular_name' => 'دسته‌بندی نمونه کار',
                    'add_new_item' => 'افزودن دسته‌بندی جدید',
                    'edit_item' => 'ویرایش دسته‌بندی',
                    'all_items' => 'همه دسته‌بندی‌ها'
                ),
                'hierarchical' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => array('slug' => 'portfolio-category')
            )
        );
    }

    public function addTelegramGroupsToPortfolioCategory($term = null)
    {
        $message = $this->update_telegram_webhook();
        echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';

        global $wpdb;
        $messengerType = $this->getName() . '_group';
        $groups = $this->group->getGroups($messengerType);
        // Get selected groups
        $selected_groups = [];
        if ($term && isset($term->term_id)) {
            $selected_groups = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}termmeta WHERE term_id =  '$term->term_id' LIMIT 1");
            if (!is_array($selected_groups)) {
                $selected_groups = array();
            }
        }

        // For the edit page
        if ($term) {
            ?>
            <tr class="form-field">
                <th scope="row"><label>گروه‌های تلگرام مرتبط</label></th>
                <td>
                    <div style="max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #ddd;">
                        <?php
                        $group_ids = json_decode($selected_groups[0]->meta_value, true);
                        foreach ($groups as $group):
                            ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox"
                                       name="telegram_groups[]"
                                       value="<?php echo $group['id']; ?>"
                                    <?php echo in_array($group['id'], $group_ids) ? 'checked' : ''; ?>>
                                <?php echo $group['title']; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">گروه‌های تلگرامی که با این دسته‌بندی مرتبط هستند را انتخاب کنید.</p>
                </td>
            </tr>
            <?php
        } // For the Add New Category page
        else {
            ?>
            <div class="form-field">
                <label>گروه‌های تلگرام مرتبط</label>
                <div style="max-height: 200px; overflow-y: auto; padding: 10px; border: 1px solid #ddd;">
                    <?php foreach ($groups as $group): ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="telegram_groups[]" value="<?php echo $group['id']; ?>">
                            <?php echo $group['title']; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p>گروه‌های تلگرامی که با این دسته‌بندی مرتبط هستند را انتخاب کنید.</p>
            </div>
            <?php
        }
    }

    public function savePortfolioTelegramGroups($term_id)
    {
        if (isset($_POST['telegram_groups'])) {
            $groups = array_map('intval', $_POST['telegram_groups']);
            update_term_meta($term_id, 'telegram_groups', json_encode($groups));
        }
    }

    public function processPortfolioTelegramNotification()
    {
        if (isset($_POST['send_telegram']) && isset($_POST['telegram_notify_nonce'])) {
            if (!wp_verify_nonce($_POST['telegram_notify_nonce'], 'telegram_notify')) {
                return;
            }

            $post_id = get_the_ID();
            $post_title = get_the_title($post_id);
            $post_link = get_permalink($post_id);
            $message = isset($_POST['message']) ? $_POST['message'] : '';
            $voice_message = isset($_POST['voice_message']) ? $_POST['voice_message'] : '';

            $final_message = $message . "\n\n";
            $final_message .= "عنوان: " . $post_title . "\n";
            $final_message .= "لینک: " . $post_link;

            $categories = get_the_terms($post_id, 'portfolio_category');

            $group_ids = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $telegram_groups = get_term_meta($category->term_id, 'telegram_groups', true);
                    if (is_array(json_decode($telegram_groups, true))) {
                        foreach (json_decode($telegram_groups, true) as $group_id) {
                            $group_ids[] = $group_id;
                        }
                    }
                }
            }

            foreach (array_unique($group_ids) as $id) {
                // ارسال پیام متنی
                $this->sendMessage($id, $message, []);

                // ارسال پیام صوتی
                if (!empty($voice_message)) {
                    $this->sendVoiceToTelegram($id, $voice_message);
                }
            }
        }
    }

    public function sendTelegramMessage($group_id, $message)
    {
        $response = $this->proxy->sendRequest('sendMessage', [
            'chat_id' => $group_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ]);

        if (is_wp_error($response)) {
            error_log('Telegram API Error: ' . $response->get_error_message());
        }
    }

    public function sendTelegramFile($group_id, $file_url)
    {
        $bot_token = get_option('telegram_bot_token');
        // File type detection
        $file_type = wp_check_filetype($file_url);
        $method = 'sendDocument';

        if (strpos($file_type['type'], 'image') !== false) {
            $method = 'sendPhoto';
        } elseif (strpos($file_type['type'], 'video') !== false) {
            $method = 'sendVideo';
        } elseif (strpos($file_type['type'], 'audio') !== false) {
            $method = 'sendAudio';
        }

        $url = "https://api.telegram.org/bot{$bot_token}/{$method}";
        $args = array(
            'body' => array(
                'chat_id' => $group_id,
                'caption' => '',
                $method === 'sendPhoto' ? 'photo' : 'document' => $file_url
            )
        );

        wp_remote_post($url, $args);
    }

    public function addPortfolioCategoriesToContent($content)
    {
        if (is_singular('portfolio')) {
            $categories = get_the_terms(get_the_ID(), 'portfolio_category');
            $category_names = [];
            foreach ($categories as $category) {
                $category_names[] = $category->name;
            }
            $categories_str = implode(', ', $category_names);
            if ($categories) {
                $categories_html = '<div class="portfolio-categories">';
                $categories_html .= '<strong>دسته‌بندی‌ها: </strong>' . $categories_str;
                $categories_html .= '</div>';
                $content = $categories_html . $content;
            }
        }
        return $content;
    }

    public function addVoiceRecorderToPortfolioForm($content)
    {
        if (is_singular('portfolio')) {
            $recorder_html = '
        <div class="voice-recorder-container">
            <button type="button" id="startRecord">شروع ضبط</button>
            <button type="button" id="stopRecord" style="display:none;">پایان ضبط</button>
            <div id="recordingStatus"></div>
            <audio id="recordedAudio" controls style="display:none;"></audio>
            <input type="hidden" name="voice_message" id="voice_message">
        </div>
        <script>
            let mediaRecorder;
            let audioChunks = [];
            
            document.getElementById("startRecord").addEventListener("click", function() {
                navigator.mediaDevices.getUserMedia({ audio: true })
                    .then(stream => {
                        mediaRecorder = new MediaRecorder(stream);
                        mediaRecorder.start();
                        
                        document.getElementById("startRecord").style.display = "none";
                        document.getElementById("stopRecord").style.display = "inline-block";
                        document.getElementById("recordingStatus").textContent = "در حال ضبط...";
                        
                        audioChunks = [];
                        mediaRecorder.addEventListener("dataavailable", event => {
                            audioChunks.push(event.data);
                        });
                        
                        mediaRecorder.addEventListener("stop", () => {
                            const audioBlob = new Blob(audioChunks, { type: "audio/wav" });
                            const audioUrl = URL.createObjectURL(audioBlob);
                            const audio = document.getElementById("recordedAudio");
                            audio.src = audioUrl;
                            audio.style.display = "block";
                            
                            // Convert Blob to Base64
                            const reader = new FileReader();
                            reader.readAsDataURL(audioBlob);
                            reader.onloadend = function() {
                                const base64data = reader.result;
                                document.getElementById("voice_message").value = base64data;
                            }
                        });
                    });
            });
            
            document.getElementById("stopRecord").addEventListener("click", function() {
                mediaRecorder.stop();
                document.getElementById("startRecord").style.display = "inline-block";
                document.getElementById("stopRecord").style.display = "none";
                document.getElementById("recordingStatus").textContent = "ضبط به پایان رسید";
            });
        </script>';

            // Add a recorder before the submit button
            $content = str_replace('</form>', $recorder_html . '</form>', $content);
        }
        return $content;
    }

    public function sendVoiceToTelegram($group_id, $voice_base64)
    {
        if (!empty($voice_base64)) {
            $voice_data = str_replace('data:audio/wav;base64,', '', $voice_base64);
            $voice_data = str_replace(' ', '+', $voice_data);
            $voice_binary = base64_decode($voice_data);
            $temp_file = tempnam(sys_get_temp_dir(), 'voice');
            file_put_contents($temp_file, $voice_binary);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$this->bot_token}/sendVoice");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'chat_id' => $group_id,
                'voice' => new \CURLFile($temp_file, 'audio/wav', 'voice.wav')
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Set timeout to 60 seconds

            $result = curl_exec($ch);
            curl_close($ch);

            unlink($temp_file);
        }
    }

    public function processTelegramWebhook()
    {
        $content = file_get_contents('php://input');
        $json_decode = json_decode($content, true);
        $text = $json_decode["message"]["text"];
        $sender_chat_id = $json_decode["message"]["chat"]["id"];
        $explode = explode(' ', $text);
        if ($explode[0] == '/send') {
            $chat_id = $explode[1];
            unset($explode[0]);
            unset($explode[1]);
            $text_send = implode(' ', $explode);
            $this->sendMessageInBot($chat_id, $text_send, $sender_chat_id);
        }
        $this->group->saveGroup($json_decode, $this->getName());

        // Send 200 OK response to Telegram
        http_response_code(200);
        exit();
    }







    public function sendMessageInBot($chat_id, $text_send, $sender_chat_id)
    {
//        $param = "chat_id=" . $chat_id . "&text=" . $text_send . "&parse_mode=HTML";
//        $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage?" . $param;
//        $result = file_get_contents($url);

//        Send by proxy
        $result = $this->proxy->sendRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text_send,
            'parse_mode' => 'HTML'
        ]);

        if ($result) {
            $success_param = "chat_id=" . $sender_chat_id . "&text=پیام با موفقیت ارسال شد ✅";
            file_get_contents("https://api.telegram.org/bot{$this->bot_token}/sendMessage?" . $success_param);
        } else {
            $error_param = "chat_id=" . $sender_chat_id . "&text=خطا: کاربر مورد نظر باید ابتدا ربات را استارت کند ❌";
            file_get_contents("https://api.telegram.org/bot{$this->bot_token}/sendMessage?" . $error_param);
        }
    }

    function update_telegram_webhook() {
        $bot_token = BOT_TOKEN;
        $webhook_url = home_url('/wp-json/telegram/webhook');

        // دریافت وضعیت فعلی وب‌هوک
        $get_webhook_info_url = "https://api.telegram.org/bot{$bot_token}/getWebhookInfo";
        $response_info = wp_remote_get($get_webhook_info_url);

        if (is_wp_error($response_info)) {
            return '❌ خطا در بررسی وضعیت وب‌هوک.';
        }

        $body_info = wp_remote_retrieve_body($response_info);
        $result_info = json_decode($body_info, true);

        if (isset($result_info['result']['url']) && $result_info['result']['url'] === $webhook_url) {
            return '✅ وب‌هوک از قبل به درستی تنظیم شده است.';
        }

        // تنظیم مجدد وب‌هوک در صورت تفاوت
        $api_url = "https://api.telegram.org/bot{$bot_token}/setWebhook?url={$webhook_url}";
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            return '❌ خطا در تنظیم وب‌هوک.';
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        if ($result['ok']) {
            return '✅ وب‌هوک با موفقیت تنظیم شد.';
        } else {
            return '❌ خطا در تنظیم وب‌هوک: ' . $result['description'];
        }
    }

}

