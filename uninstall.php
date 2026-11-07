<?php
/**
 * Gỡ plugin (Plugins → Xoá).
 *
 * Dữ liệu chấm công là dữ liệu tính lương nên MẶC ĐỊNH GIỮ LẠI. Chỉ shop nào bật
 * "Xoá toàn bộ dữ liệu chấm công khi gỡ plugin" trong Cài đặt chấm công mới bị
 * xoá bảng, cài đặt, các tháng đã chốt và quyền quản lý chấm công đã cấp.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

function tgs_cc_uninstall_site()
{
    global $wpdb;
    $s = get_option('tgs_cc_settings');
    if (!is_array($s) || empty($s['delete_on_uninstall'])) {
        return;
    }
    foreach (array('shifts', 'schedules', 'records', 'logs') as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tgs_cc_{$t}"); // phpcs:ignore
    }
    delete_option('tgs_cc_settings');
    delete_option('tgs_cc_db_version');
    delete_option('tgs_cc_locks');

    foreach (get_users(array('blog_id' => get_current_blog_id(), 'number' => 2000)) as $u) {
        if (!empty($u->caps['tgs_manage_attendance'])) {
            $u->remove_cap('tgs_manage_attendance');
        }
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $tgs_cc_blog_id) {
        switch_to_blog($tgs_cc_blog_id);
        tgs_cc_uninstall_site();
        restore_current_blog();
    }
} else {
    tgs_cc_uninstall_site();
}
