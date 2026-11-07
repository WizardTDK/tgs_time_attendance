<?php
/**
 * Plugin Name: TGS - Chấm công nhân viên
 * Description: Chấm công theo ca cho cửa hàng: thiết lập ca, xếp lịch, bảng chấm công, báo cáo & xuất Excel cuối tháng (cắm vào TGS Shop Management) và nút chấm công vào/ra ca ngay trên màn POS (cắm vào TGS POS).
 * Version: 1.0.0
 * Author: TGS Team
 * Text Domain: tgs-time-attendance
 * Requires PHP: 7.4
 *
 * ─── CÁCH CẮM VÀO HAI PLUGIN GỐC (KHÔNG SỬA FILE NÀO CỦA CHÚNG) ──────────────
 *
 *   tgs_shop_managerment  → filter tgs_shop_dashboard_routes (thêm màn) +
 *                           filter tgs_shop_workflow_nav (thêm menu "Nhân sự").
 *                           Màn được vẽ trong main-layout.php nên dùng chung
 *                           Design System, thanh tab, nút Xuất Excel…
 *   tgs_pos               → filter page_template (nhận ra trang /pos) +
 *                           wp_footer (chèn nút "Chấm công" và hộp thoại).
 *
 * Dữ liệu là bảng RIÊNG của từng site ($wpdb->prefix) — mỗi shop một bộ, giống
 * cách local_ledger_* đang làm. Bảng tự tạo khi site mở trang lần đầu.
 */

defined('ABSPATH') || exit;

define('TGS_CC_VERSION', '1.0.0');
define('TGS_CC_FILE', __FILE__);
define('TGS_CC_DIR', plugin_dir_path(__FILE__));
define('TGS_CC_URL', plugin_dir_url(__FILE__));

require_once TGS_CC_DIR . 'includes/class-tgs-cc-db.php';
require_once TGS_CC_DIR . 'includes/class-tgs-cc-settings.php';
require_once TGS_CC_DIR . 'includes/class-tgs-cc-service.php';
require_once TGS_CC_DIR . 'includes/class-tgs-cc-admin.php';
require_once TGS_CC_DIR . 'includes/class-tgs-cc-pos.php';

register_activation_hook(__FILE__, array('TGS_CC_DB', 'activate'));

// Multisite: mỗi site tự tạo bảng khi có người mở trang (không bắt kích hoạt lại 650 shop).
add_action('init', array('TGS_CC_DB', 'maybe_install'), 5);

TGS_CC_Admin::init();
TGS_CC_POS::init();

/**
 * Người dùng có được quản lý chấm công (xem bảng, sửa công, xếp lịch, báo cáo) không.
 *
 * Thứ tự: quản trị site → quyền riêng tgs_manage_attendance → hệ phân quyền
 * menu TGS_Permission (nếu có) theo view "cham-cong-bang". Plugin khác muốn
 * đổi luật thì dùng filter tgs_cc_user_can_manage.
 */
function tgs_cc_user_can_manage($user_id = 0)
{
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if (!$user_id) {
        return false;
    }

    $ok = is_super_admin($user_id)
        || user_can($user_id, 'manage_options')
        || user_can($user_id, 'tgs_manage_attendance');

    if (!$ok && class_exists('TGS_Permission')) {
        $perm = TGS_Permission::get_instance();
        if (method_exists($perm, 'user_can_access_view')) {
            $ok = (bool) $perm->user_can_access_view($user_id, 'cham-cong-bang');
        }
    }

    return (bool) apply_filters('tgs_cc_user_can_manage', $ok, $user_id);
}
