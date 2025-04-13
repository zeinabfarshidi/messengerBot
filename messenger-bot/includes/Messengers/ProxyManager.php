<?php
namespace MessengerBot\Messengers;
class ProxyManager{
    private $bot_token;

    public function __construct() {
        $this->bot_token = '7681362529:AAFXTA5HllMf9LtgyZUo4F5bmjb5qNhDIGA';
    }

    public function sendRequest($method, $params = []) {
        $url = "https://api.telegram.org/bot" . $this->bot_token . "/$method";

        // Check server location
        $server_location = $this->get_server_location();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);

        // Only use a proxy on Iranian servers
        if ($server_location === 'IR') {
            $proxy = $this->get_active_proxy();
            if (!$proxy) {
                return false;
            }

            curl_setopt($ch, CURLOPT_PROXY, $proxy['ip']);
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy['port']);
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxy['type']);
        }

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }

        $response = curl_exec($ch);

        if (!$response && $server_location === 'IR') {
            $this->switch_to_next_proxy($proxy['ip']);
            return $this->sendRequest($method, $params);
        }

        curl_close($ch);
        return json_decode($response, true);
    }

    public function sendFileRequest($endpoint, $params = []) {
        $url = "https://api.telegram.org/bot" . $this->bot_token . "/$endpoint";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // Proxy settings
        $server_location = $this->get_server_location();
        if ($server_location === 'IR') {
            $proxy = $this->get_active_proxy();
            if ($proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy['ip']);
                curl_setopt($ch, CURLOPT_PROXYPORT, $proxy['port']);
                curl_setopt($ch, CURLOPT_PROXYTYPE, $proxy['type']);
            }
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    public function add_admin_menu() {
        add_menu_page(
            'تنظیمات پروکسی',
            'پروکسی‌ها',
            'manage_options',
            'proxy-settings',
            [$this, 'display_proxy_page'],
            'dashicons-admin-generic'
        );
    }

    public function display_proxy_page() {
        // Show current proxy status
        $active_proxy = $this->get_active_proxy();
        if ($active_proxy) {
            echo '<div class="notice notice-info">';
            echo '<h3>پروکسی فعال</h3>';
            echo '<p>IP: ' . $active_proxy['ip'] . '</p>';
            echo '<p>Port: ' . $active_proxy['port'] . '</p>';
            echo '<p>Type: ' . $active_proxy['type'] . '</p>';

            // Connection test and status display
            $test_result = $this->test_connection($active_proxy);
            if ($test_result['success']) {
                echo '<p class="success">✅ ' . $test_result['details'] . '</p>';
            } else {
                echo '<p class="error">❌ خطا در اتصال: ' . $test_result['message'] . '</p>';
                echo '<p>در حال اتصال به پروکسی بعدی...</p>';
            }
            echo '</div>';
        }

        ?>
        <div class="wrap">
            <h2>مدیریت پروکسی‌ها</h2>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th>IP پروکسی</th>
                        <th>پورت</th>
                        <th>نوع</th>
                    </tr>
                    <tr>
                        <td><input type="text" name="proxy_ip" required></td>
                        <td><input type="number" name="proxy_port" required></td>
                        <td>
                            <select name="proxy_type">
                                <option value="CURLPROXY_HTTP">HTTP</option>
                                <option value="CURLPROXY_SOCKS4">SOCKS4</option>
                                <option value="CURLPROXY_SOCKS5">SOCKS5</option>
                                <option value="CURLPROXY_SOCKS4A">SOCKS4A</option>
                                <option value="CURLPROXY_SOCKS5_HOSTNAME">SOCKS5 HOSTNAME</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php wp_nonce_field('save_proxy_action', 'proxy_nonce'); ?>
                <input type="submit" name="save_proxy" class="button button-primary" value="ذخیره پروکسی" style="margin-bottom: 20px">
            </form>

            <?php $this->displaySavedProxies(); ?>

            <!--            proxy test-->
<!--            --><?php
//            echo '<form method="post">';
//            echo '<input type="submit" name="test_proxy" class="button button-secondary" value="تست پروکسی فعال">';
//            echo '</form>';
//
//            ?>
        </div>
        <?php
    }

    private function displaySavedProxies() {
        $args = array(
            'post_type' => 'proxy',
            'posts_per_page' => -1
        );

        $proxies = get_posts($args);

        if (empty($proxies)) {
            echo '<p>هیچ پروکسی ذخیره شده‌ای وجود ندارد.</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>IP</th>';
        echo '<th>Port</th>';
        echo '<th>Type</th>';
        echo '<th>وضعیت</th>';
        echo '<th>عملیات</th>';
        echo '</tr></thead>';

        foreach ($proxies as $proxy) {
            $data = json_decode($proxy->post_content, true);
            $status = get_post_meta($proxy->ID, 'proxy_status', true);

            echo '<tr>';
            echo '<td>' . $data['ip'] . '</td>';
            echo '<td>' . $data['port'] . '</td>';
            echo '<td>' . $data['type'] . '</td>';
            echo '<td>' . ($status == 'active' ? 'فعال' : 'غیرفعال') . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            echo '<input type="hidden" name="proxy_id" value="' . $proxy->ID . '">';
            echo '<input type="submit" name="delete_proxy" class="button button-small" value="حذف">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    private function test_connection($proxy) {
        $response = $this->sendRequest('getMe');

        if ($response && isset($response['ok']) && $response['ok']) {
            return [
                'success' => true,
                'details' => 'اتصال موفق - نام ربات: ' . $response['result']['username']
            ];
        } else {
            return [
                'success' => false,
                'message' => 'خطا در اتصال به پروکسی ' . $proxy['ip']
            ];
        }
    }

    private function display_saved_proxies() {
        $args = array(
            'post_type' => 'proxy',
            'posts_per_page' => -1
        );

        $proxies = get_posts($args);

        if (!empty($proxies)) {
            echo '<h3>پروکسی‌های ذخیره شده</h3>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>IP</th>';
            echo '<th>پورت</th>';
            echo '<th>نوع</th>';
            echo '<th>وضعیت</th>';
            echo '<th>عملیات</th>';
            echo '</tr></thead>';

            foreach ($proxies as $proxy) {
                $data = json_decode($proxy->post_content, true);
                $status = get_post_meta($proxy->ID, 'proxy_status', true);

                echo '<tr>';
                echo '<td>' . esc_html($data['ip']) . '</td>';
                echo '<td>' . esc_html($data['port']) . '</td>';
                echo '<td>' . esc_html($data['type']) . '</td>';
                echo '<td>' . ($status == 'active' ? 'فعال' : 'غیرفعال') . '</td>';
                echo '<td>';
                echo '<form method="post" style="display:inline;">';
                echo '<input type="hidden" name="proxy_id" value="' . $proxy->ID . '">';
                echo '<input type="submit" name="delete_proxy" class="button button-small" value="حذف">';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</table>';
        }
    }

    public function save_proxy() {
        if (isset($_POST['save_proxy'])) {
            $post_data = array(
                'post_title'    => $_POST['proxy_ip'],
                'post_content'  => json_encode([
                    'ip' => $_POST['proxy_ip'],
                    'port' => $_POST['proxy_port'],
                    'type' => $_POST['proxy_type']
                ]),
                'post_status'   => 'publish',
                'post_type'     => 'proxy'
            );
            $post_id = wp_insert_post($post_data);
            // اگر اولین پروکسی است، فعالش کن
            $args = array(
                'post_type' => 'proxy',
                'posts_per_page' => -1
            );
            $proxies = get_posts($args);
            if (count($proxies) == 1) {
                update_post_meta($post_id, 'proxy_status', 'active');
            }

        }
    }

    public function delete_proxy() {
        if (isset($_POST['delete_proxy']) && isset($_POST['proxy_id'])) {
            $proxy_id = intval($_POST['proxy_id']);
            wp_delete_post($proxy_id, true);

            // ریدایرکت به صفحه پروکسی‌ها
            wp_redirect(admin_url('admin.php?page=proxy-settings'));
            exit;
        }
    }

    //    Active proxy reception function:
    private function get_active_proxy() {
        $args = array(
            'post_type' => 'proxy',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => 'proxy_status',
                    'value' => 'active'
                )
            )
        );

        $proxy = get_posts($args);
        if (!empty($proxy)) {
            $data = json_decode($proxy[0]->post_content, true);
            $data['id'] = $proxy[0]->ID;
            return $data;
        }

        // If no proxy is active, activate the first proxy
        $args = array(
            'post_type' => 'proxy',
            'posts_per_page' => 1
        );

        $proxy = get_posts($args);
        if (!empty($proxy)) {
            update_post_meta($proxy[0]->ID, 'proxy_status', 'active');
            $data = json_decode($proxy[0]->post_content, true);
            $data['id'] = $proxy[0]->ID;
            return $data;
        }

        return null;
    }

    private function get_server_location() {
        $ip = $_SERVER['SERVER_ADDR'];
        $api_url = "http://ip-api.com/json/" . $ip;
        $response = file_get_contents($api_url);
        $data = json_decode($response, true);
        return $data['countryCode'] ?? '';
    }

    //    Proxy change function:
    private function switch_to_next_proxy($current_proxy_ip) {
        $args = array(
            'post_type' => 'proxy',
            'posts_per_page' => -1
        );

        $proxies = get_posts($args);

        // First, we disable all proxies
        foreach ($proxies as $proxy) {
            update_post_meta($proxy->ID, 'proxy_status', 'inactive');
        }

        // Find the current proxy and enable the next proxy
        foreach ($proxies as $key => $proxy) {
            $data = json_decode($proxy->post_content, true);
            if ($data['ip'] == $current_proxy_ip) {
                $next_key = ($key + 1) % count($proxies);
                update_post_meta($proxies[$next_key]->ID, 'proxy_status', 'active');
                break;
            }
        }
    }
}
