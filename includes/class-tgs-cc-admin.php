<?php
/**
 * Phần quản trị: cắm vào tgs_shop_managerment bằng filter, không sửa plugin gốc.
 *
 *   tgs_shop_dashboard_routes → các màn ?page=tgs-shop-management&view=cham-cong-*
 *   tgs_shop_workflow_nav     → nhóm menu "Nhân sự" trên thanh mega-nav
 *   wp_ajax_tgs_cc_admin      → một cửa AJAX duy nhất, phân nhánh theo "op"
 */

defined('ABSPATH') || exit;

class TGS_CC_Admin
{
    const NONCE = 'tgs_cc_admin';

    public static function views()
    {
        return array(
            'cham-cong-bang'    => array('Bảng chấm công', 'bang-cham-cong.php'),
            'cham-cong-lich'    => array('Xếp lịch làm việc', 'lich-lam-viec.php'),
            'cham-cong-bao-cao' => array('Báo cáo chấm công', 'bao-cao.php'),
            'cham-cong-ca'      => array('Ca làm việc', 'ca-lam-viec.php'),
            'cham-cong-cai-dat' => array('Cài đặt chấm công', 'cai-dat.php'),
        );
    }

    public static function init()
    {
        add_filter('tgs_shop_dashboard_routes', array(__CLASS__, 'routes'));
        add_filter('tgs_shop_workflow_nav', array(__CLASS__, 'nav'), 20, 2);
        add_action('wp_ajax_tgs_cc_admin', array(__CLASS__, 'ajax'));
    }

    public static function routes($routes)
    {
        if (!is_array($routes)) {
            return $routes;
        }
        foreach (self::views() as $view => $cfg) {
            // header-mega-nav.php gọi filter này với giá trị true chỉ để biết view có tồn tại
            $routes[$view] = array($cfg[0], TGS_CC_DIR . 'admin-views/' . $cfg[1]);
        }
        return $routes;
    }

    public static function nav($nav, $current_view = '')
    {
        if (!is_array($nav) || !tgs_cc_user_can_manage()) {
            return $nav;
        }
        $item = function ($view, $label, $icon) {
            return array('view' => $view, 'label' => $label, 'icon' => $icon, 'capability' => 'read');
        };
        $group = array(
            'label' => 'Nhân sự',
            'icon' => 'bx bx-time-five',
            'mega' => true,
            'sections' => array(
                array('key' => 'cham-cong', 'heading' => 'Chấm công', 'icon' => 'bx bx-fingerprint', 'items' => array(
                    $item('cham-cong-bang', 'Bảng chấm công', 'bx bx-calendar-check'),
                    $item('cham-cong-lich', 'Xếp lịch làm việc', 'bx bx-calendar-edit'),
                )),
                array('key' => 'cham-cong-bao-cao', 'heading' => 'Báo cáo', 'icon' => 'bx bx-bar-chart-alt-2', 'items' => array(
                    $item('cham-cong-bao-cao', 'Báo cáo chấm công', 'bx bx-spreadsheet'),
                )),
                array('key' => 'cham-cong-thiet-lap', 'heading' => 'Thiết lập', 'icon' => 'bx bx-cog', 'items' => array(
                    $item('cham-cong-ca', 'Ca làm việc', 'bx bx-time'),
                    $item('cham-cong-cai-dat', 'Cài đặt chấm công', 'bx bx-slider-alt'),
                )),
            ),
        );

        // Đặt trước nhóm "Báo cáo" nếu có, không thì cuối thanh menu
        $out = array();
        $placed = false;
        foreach ($nav as $k => $v) {
            if (!$placed && $k === 'reports') {
                $out['hr'] = $group;
                $placed = true;
            }
            $out[$k] = $v;
        }
        if (!$placed) {
            $out['hr'] = $group;
        }
        return $out;
    }

    /** Đầu mỗi màn: kiểm quyền + nạp CSS/JS + cấu hình cho JS */
    public static function page_boot($page)
    {
        if (!tgs_cc_user_can_manage()) {
            echo '<div class="alert alert-danger"><i class="bx bx-lock-alt"></i> Bạn không có quyền quản lý chấm công. Liên hệ quản lý cửa hàng để được cấp quyền.</div>';
            return false;
        }
        $ver = TGS_CC_VERSION . '.' . (int) @filemtime(TGS_CC_DIR . 'assets/js/admin.js');
        $cfg = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'page' => $page,
            'today' => TGS_CC_Service::today(),
            'shopName' => get_bloginfo('name'),
            'employees' => TGS_CC_Service::employees(),
            'shifts' => array_values(TGS_CC_Service::shifts(false)),
            'settings' => TGS_CC_Settings::all(),
            'statuses' => TGS_CC_Service::status_labels(),
            'urls' => array(),
        );
        foreach (array_keys(self::views()) as $v) {
            $cfg['urls'][$v] = admin_url('admin.php?page=tgs-shop-management&view=' . $v);
        }
        echo '<link rel="stylesheet" href="' . esc_url(TGS_CC_URL . 'assets/css/admin.css?v=' . $ver) . '">';
        echo '<script>window.TGS_CC = ' . wp_json_encode($cfg, JSON_UNESCAPED_UNICODE) . ';</script>';
        // defer: chạy sau jQuery/Bootstrap mà layout nạp ở cuối trang
        echo '<script defer src="' . esc_url(TGS_CC_URL . 'assets/js/admin.js?v=' . $ver) . '"></script>';
        return true;
    }

    /** Thanh tab chuyển nhanh giữa các màn chấm công */
    public static function tabs($active)
    {
        echo '<ul class="nav nav-tabs tgs-cc-tabs mb-2">';
        foreach (self::views() as $v => $cfg) {
            printf(
                '<li class="nav-item"><a class="nav-link%s" href="%s">%s</a></li>',
                $v === $active ? ' active' : '',
                esc_url(admin_url('admin.php?page=tgs-shop-management&view=' . $v)),
                esc_html($cfg[0])
            );
        }
        echo '</ul>';
    }

    /* ═══════════════════════════ AJAX ═══════════════════════════ */

    private static function range($p, $max_days = 93)
    {
        $from = isset($p['from']) ? (string) $p['from'] : '';
        $to = isset($p['to']) ? (string) $p['to'] : '';
        if (!TGS_CC_Service::valid_date($from) || !TGS_CC_Service::valid_date($to) || $to < $from) {
            wp_send_json_error(array('message' => 'Khoảng ngày không hợp lệ.'), 400);
        }
        if ((strtotime($to) - strtotime($from)) / DAY_IN_SECONDS > $max_days) {
            wp_send_json_error(array('message' => 'Khoảng ngày tối đa ' . $max_days . ' ngày.'), 400);
        }
        return array($from, $to);
    }

    public static function ajax()
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Phiên làm việc đã hết hạn, tải lại trang.'), 403);
        }
        if (!tgs_cc_user_can_manage()) {
            wp_send_json_error(array('message' => 'Bạn không có quyền quản lý chấm công.'), 403);
        }

        $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';
        $p = isset($_POST['payload']) ? json_decode(wp_unslash($_POST['payload']), true) : array();
        $p = is_array($p) ? $p : array();
        $users = isset($p['users']) ? array_map('intval', (array) $p['users']) : array();
        $shift_id = isset($p['shift_id']) ? (int) $p['shift_id'] : 0;

        switch ($op) {
            case 'board':
                list($from, $to) = self::range($p, 62);
                wp_send_json_success(TGS_CC_Service::board($from, $to, $users, $shift_id));

            case 'record_save':
                $r = TGS_CC_Service::admin_save_record($p);
                self::respond($r, 'Đã lưu chấm công.');

            case 'record_delete':
                $r = TGS_CC_Service::admin_delete_record(isset($p['record_id']) ? (int) $p['record_id'] : 0);
                self::respond($r, 'Đã xoá dữ liệu chấm công.');

            case 'record_logs':
                wp_send_json_success(TGS_CC_Service::record_logs(isset($p['record_id']) ? (int) $p['record_id'] : 0));

            case 'fill_schedule':
                list($from, $to) = self::range($p, 62);
                $n = TGS_CC_Service::admin_fill_from_schedule($from, $to, $users);
                wp_send_json_success(array('count' => $n, 'message' => 'Đã điền đủ công cho ' . $n . ' ca có lịch chưa chấm.'));

            case 'schedule_cell':
                $date = isset($p['date']) ? (string) $p['date'] : '';
                if (!TGS_CC_Service::valid_date($date) || empty($p['user_id'])) {
                    wp_send_json_error(array('message' => 'Thiếu nhân viên hoặc ngày.'), 400);
                }
                TGS_CC_Service::schedule_set_cell((int) $p['user_id'], $date, isset($p['shift_ids']) ? $p['shift_ids'] : array());
                wp_send_json_success(array('message' => 'Đã cập nhật lịch.'));

            case 'schedule_bulk':
                list($from, $to) = self::range($p, 93);
                $r = TGS_CC_Service::schedule_bulk($users, isset($p['shift_ids']) ? $p['shift_ids'] : array(), $from, $to, isset($p['weekdays']) ? $p['weekdays'] : array(), !empty($p['replace']));
                self::respond($r, is_wp_error($r) ? '' : 'Đã xếp ' . $r . ' lượt ca.');

            case 'schedule_copy_week':
                $src = isset($p['src']) ? (string) $p['src'] : '';
                $dst = isset($p['dst']) ? (string) $p['dst'] : '';
                if (!TGS_CC_Service::valid_date($src) || !TGS_CC_Service::valid_date($dst)) {
                    wp_send_json_error(array('message' => 'Tuần không hợp lệ.'), 400);
                }
                $n = TGS_CC_Service::schedule_copy_week($src, $dst, $users, true);
                wp_send_json_success(array('message' => 'Đã chép ' . $n . ' lượt ca sang tuần mới.'));

            case 'schedule_clear':
                list($from, $to) = self::range($p, 93);
                $n = TGS_CC_Service::schedule_clear($from, $to, $users);
                wp_send_json_success(array('message' => 'Đã xoá ' . $n . ' lượt ca.'));

            case 'shift_list':
                wp_send_json_success(array_values(TGS_CC_Service::shifts(false)));

            case 'shift_save':
                $r = TGS_CC_Service::shift_save($p);
                if (!is_wp_error($r) && !empty($p['id'])) {
                    // Đổi giờ ca → tính lại công tháng đang chấm theo giờ mới
                    TGS_CC_Service::recompute_from(wp_date('Y-m-01'));
                }
                self::respond($r, 'Đã lưu ca làm việc.');

            case 'shift_delete':
                wp_send_json_success(TGS_CC_Service::shift_delete(isset($p['id']) ? (int) $p['id'] : 0));

            case 'report_summary':
                list($from, $to) = self::range($p, 370);
                wp_send_json_success(TGS_CC_Service::report_summary($from, $to, $users, $shift_id));

            case 'report_detail':
                list($from, $to) = self::range($p, 93);
                wp_send_json_success(TGS_CC_Service::report_detail($from, $to, $users, $shift_id));

            case 'report_month':
                wp_send_json_success(TGS_CC_Service::report_month(isset($p['month']) ? (string) $p['month'] : '', $users));

            case 'settings_save':
                $saved = TGS_CC_Settings::save($p);
                // Luật mới áp cho cả tháng đang chấm; các tháng trước giữ nguyên (đã chốt bảng công)
                $n = TGS_CC_Service::recompute_from(wp_date('Y-m-01'));
                wp_send_json_success(array('settings' => $saved, 'message' => 'Đã lưu cài đặt, tính lại ' . $n . ' lượt chấm công của tháng này.'));
        }

        wp_send_json_error(array('message' => 'Thao tác không hợp lệ.'), 400);
    }

    private static function respond($r, $message)
    {
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()), 400);
        }
        wp_send_json_success(array('data' => $r, 'message' => $message));
    }
}
