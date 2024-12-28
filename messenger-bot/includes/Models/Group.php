<?php

namespace MessengerBot\Models;
class Group {
    public function getGroups($messengerType) {
        $args = array(
            'post_type' => $messengerType,
            'posts_per_page' => -1,
            'post_status' => 'publish'
        );

        $groups = [];
        $posts = get_posts($args);

        foreach($posts as $post) {
            $groups[] = [
                'id' => get_post_meta($post->ID, 'group_id', true),
                'title' => $post->post_title,
                'membership_date' => get_post_meta($post->ID, 'group_membership_date', true)
            ];
        }

        return $groups;
    }

    public function saveGroup($json_decode, $messengerType) {
        if (isset($json_decode['message']['left_chat_member'])) {
            // حذف گروه اگر ربات از آن خارج شده
            $user = $json_decode['message']['left_chat_member'];
            $chat = $json_decode['message']['chat'];

            if ($user['is_bot']) {
                // پیدا کردن پست با group_id
                $args = array(
                    'post_type' => $messengerType . '_group',
                    'meta_key' => 'group_id',
                    'meta_value' => $chat['id'],
                    'posts_per_page' => 1
                );

                $posts = get_posts($args);
                if (!empty($posts)) {
                    wp_delete_post($posts[0]->ID, true); // true برای حذف کامل از دیتابیس
                    error_log('ربات از گروه ' . $chat['title'] . ' حذف شد');
                }
            }
        }
        else {
            if ($json_decode['message']['new_chat_member'] && $json_decode['message']['new_chat_member']['is_bot']) {
                $chat = $json_decode['message']['chat'];

                // بررسی وجود گروه در دیتابیس
                $exists = get_posts(array(
                    'post_type' => $messengerType . '_group',
                    'meta_key' => 'group_id',
                    'meta_value' => $chat['id'],
                    'posts_per_page' => 1
                ));

                if (empty($exists)) {
                    $post_data = array(
                        'post_title'    => $chat['title'],
                        'post_content'  => 'Group ID: ' . $chat['id'],
                        'post_status'   => 'publish',
                        'post_author'   => 1,
                        'post_type'     => 'telegram_group'
                    );

                    $post_id = wp_insert_post($post_data);
                    update_post_meta($post_id, 'group_id', $chat['id']);
                    update_post_meta($post_id, 'group_membership_date', current_time('mysql'));
                    error_log('ربات به گروه ' . $chat['title'] . ' اضافه شد');
                }
            }
        }
    }

    public function addGroupsField($messengerType) {
        $groups = $this->getGroups($messengerType);

        echo '<div class="form-field">
        <label>گروه‌های تلگرام</label>
        <div style="margin-top: 10px;">';

        foreach($groups as $group) {
            echo '<label style="display: block; margin: 5px 0;">
            <input type="checkbox" name="telegram_groups[]" value="' . esc_attr($group["id"]) . '">
            ' . esc_html($group["title"]) . '
        </label>';
        }

        echo '</div></div>';
    }

    public function editGroupsField($term, $messengerType) {
        $groups = $this->getGroups($messengerType);
        $saved_groups = get_term_meta($term->term_id, 'telegram_members', true);

        echo '<tr class="form-field">
        <th scope="row"><label>گروه‌های تلگرام</label></th>
        <td>';

        foreach($groups as $group) {
            $checked = in_array($group['id'], json_decode($saved_groups, true)) ? 'checked' : '';
            echo '<label style="display: block; margin: 5px 0;">
            <input type="checkbox" name="telegram_groups[]" value="' . esc_attr($group["id"]) . '" ' . $checked . '>
            ' . esc_html($group["title"]) . '
        </label>';
        }
        echo '</td></tr>';
    }

    public function saveGroups($term_id) {
        if (isset($_POST['telegram_groups'])) {
            $groups = array_map('sanitize_text_field', $_POST['telegram_groups']);
            $json_groups = json_encode($groups);
            update_term_meta($term_id, 'telegram_members', $json_groups);
        }
    }

    public function displayGroupsPage($messengerType) {
        echo '<div class="wrap">';
        echo '<h1>گروه‌های تلگرام</h1>';
        $groups = $this->getGroups($messengerType);

        if ($groups) {
            echo '<h3>گروه‌های موجود</h3>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>نام گروه</th><th>شناسه گروه</th></tr></thead>';
            echo '<tbody>';
            foreach($groups as $group) {
                echo '<tr>';
                echo '<td>' . esc_html($group['title']) . '</td>';
                echo '<td>' . esc_html($group['id']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }


}
