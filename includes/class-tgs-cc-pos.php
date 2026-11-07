<?php
/**
 * Chấm công trên POS — cắm vào tgs_pos mà không sửa file của nó.
 *
 *   1. filter page_template (ưu tiên 99, sau tgs_pos) → biết trang đang mở là
 *      màn bán hàng /pos (template pos-layout-tailwind.php).
 *   2. wp_footer → chèn nút "Chấm công" nổi + hộp thoại.
 *   3. Trang riêng ?tgs-cham-cong=1 → màn chấm công toàn màn hình cho điện
 *      thoại / máy tính bảng đặt ở cửa (không cần mở màn bán hàng).
 *
 * Nhân viên chấm bằng chính TÀI KHOẢN ĐANG ĐĂNG NHẬP POS (tài khoản được cấp).
 * Máy quầy dùng chung một tài khoản → nút "Chấm công cho tài khoản khác":
 * nhập tên đăng nhập + mật khẩu của mình, hệ thống xác thực rồi chấm cho
 * đúng người đó, KHÔNG đổi phiên đăng nhập đang bán hàng.
 */

defined('ABSPATH') || exit;

class TGS_CC_POS
{
    const NONCE = 'tgs_cc_pos';
    const MAX_FAIL = 5;

    private static $on_pos = false;

    public static function init()
    {
        add_filter('page_template', array(__CLASS__, 'detect_pos'), 99);
        add_action('wp_footer', array(__CLASS__, 'render_widget'), 50);
        add_action('template_redirect', array(__CLASS__, 'standalone_page'), 1);
        add_action('wp_ajax_tgs_cc_pos', array(__CLASS__, 'ajax'));
    }

    public static function detect_pos($template)
    {
        if (is_string($template) && substr(str_replace('\\', '/', $template), -strlen('templates/front/pos-layout-tailwind.php')) === 'templates/front/pos-layout-tailwind.php') {
            self::$on_pos = true;
        }
        return $template;
    }

    public static function standalone_url()
    {
        return add_query_arg('tgs-cham-cong', '1', home_url('/'));
    }

    private static function config()
    {
        $uid = get_current_user_id();
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'allowOther' => (int) TGS_CC_Settings::get('allow_other_account'),
            'userName' => TGS_CC_Service::user_name($uid),
            'standaloneUrl' => self::standalone_url(),
            'shopName' => get_bloginfo('name'),
        );
    }

    private static function assets_html()
    {
        $ver = TGS_CC_VERSION . '.' . (int) @filemtime(TGS_CC_DIR . 'assets/js/pos.js');
        return '<link rel="stylesheet" href="' . esc_url(TGS_CC_URL . 'assets/css/pos.css?v=' . $ver) . '">'
            . '<script>window.TGS_CC_POS = ' . wp_json_encode(self::config(), JSON_UNESCAPED_UNICODE) . ';</script>'
            . '<script src="' . esc_url(TGS_CC_URL . 'assets/js/pos.js?v=' . $ver) . '"></script>';
    }

    public static function render_widget()
    {
        if (!self::$on_pos || !is_user_logged_in() || !TGS_CC_Settings::get('pos_button')) {
            return;
        }
        // Trang con của POS mở trong hộp thoại (iframe) không vẽ nút lần nữa
        if (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && strtolower((string) $_SERVER['HTTP_SEC_FETCH_DEST']) === 'iframe') {
            return;
        }
        echo '<button type="button" id="tgs-cc-fab" class="tgs-cc-fab" title="Chấm công vào / ra ca">'
            . '<span class="tgs-cc-fab__dot" aria-hidden="true"></span>'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></svg>'
            . '<span class="tgs-cc-fab__text">Chấm công</span></button>';
        echo self::assets_html(); // phpcs:ignore
    }

    /** Màn chấm công độc lập: ?tgs-cham-cong=1 */
    public static function standalone_page()
    {
        if (empty($_GET['tgs-cham-cong'])) {
            return;
        }
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::standalone_url()));
            exit;
        }
        nocache_headers();
        ?><!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Chấm công — <?php echo esc_html(get_bloginfo('name')); ?></title>
</head>
<body class="tgs-cc-standalone">
    <div class="tgs-cc-standalone__bar">
        <strong><?php echo esc_html(get_bloginfo('name')); ?></strong>
        <a href="<?php echo esc_url(home_url('pos')); ?>">Màn bán hàng →</a>
    </div>
    <?php echo self::assets_html(); // phpcs:ignore ?>
    <script>document.addEventListener('DOMContentLoaded', function () { window.TGSChamCong && window.TGSChamCong.open({ standalone: true }); });</script>
</body>
</html><?php
        exit;
    }

    /* ═══════════════════════════ AJAX ═══════════════════════════ */

    private static function fail_key()
    {
        return 'tgs_cc_fail_' . md5(TGS_CC_Service::client_ip());
    }

    /** Xác thực tài khoản khác (máy quầy dùng chung) — có chặn dò mật khẩu */
    private static function auth_other($login, $password)
    {
        if (!TGS_CC_Settings::get('allow_other_account')) {
            return new WP_Error('off', 'Cửa hàng đã tắt chấm công bằng tài khoản khác.');
        }
        $fails = (int) get_transient(self::fail_key());
        if ($fails >= self::MAX_FAIL) {
            return new WP_Error('locked', 'Nhập sai quá nhiều lần. Thử lại sau 10 phút.');
        }
        $login = sanitize_user($login);
        if ($login === '' || $password === '') {
            return new WP_Error('empty', 'Nhập tên đăng nhập và mật khẩu.');
        }
        $user = wp_authenticate($login, $password);
        if (is_wp_error($user)) {
            set_transient(self::fail_key(), $fails + 1, 10 * MINUTE_IN_SECONDS);
            return new WP_Error('bad', 'Sai tên đăng nhập hoặc mật khẩu.');
        }
        delete_transient(self::fail_key());
        return $user;
    }

    /** Tài khoản có thuộc danh sách nhân viên chấm công của shop này không */
    private static function check_employee($user_id)
    {
        if (!in_array((int) $user_id, TGS_CC_Service::employee_ids(), true)) {
            return new WP_Error('not_employee', 'Tài khoản này chưa thuộc danh sách nhân viên chấm công của cửa hàng. Liên hệ quản lý.');
        }
        if (!TGS_CC_Settings::ip_allowed(TGS_CC_Service::client_ip())) {
            return new WP_Error('ip', 'Máy này không nằm trong mạng của cửa hàng nên không chấm công được.');
        }
        return true;
    }

    public static function ajax()
    {
        $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';

        // POS mở cả ngày, phiên đăng nhập 60 ngày nhưng nonce chỉ sống 24h → cho xin nonce mới.
        if ($op === 'refresh') {
            wp_send_json_success(array('nonce' => wp_create_nonce(self::NONCE)));
        }
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(array('message' => 'nonce', 'code' => 'nonce'), 403);
        }

        $uid = get_current_user_id();
        $other = !empty($_POST['login']);
        if ($other) {
            $user = self::auth_other(wp_unslash($_POST['login']), isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '');
            if (is_wp_error($user)) {
                wp_send_json_error(array('message' => $user->get_error_message()), 400);
            }
            $uid = (int) $user->ID;
        }

        $chk = self::check_employee($uid);
        if (is_wp_error($chk)) {
            wp_send_json_error(array('message' => $chk->get_error_message(), 'user' => TGS_CC_Service::user_name($uid)), 400);
        }

        if ($op === 'state') {
            wp_send_json_success(TGS_CC_Service::pos_state($uid));
        }

        if ($op === 'punch') {
            $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : '';
            $r = TGS_CC_Service::punch($uid, $type, array(
                'shift_id' => isset($_POST['shift_id']) ? (int) $_POST['shift_id'] : 0,
                'work_date' => isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '',
            ), $other ? 'pos_khac' : 'pos');
            if (is_wp_error($r)) {
                wp_send_json_error(array('message' => $r->get_error_message(), 'state' => TGS_CC_Service::pos_state($uid)), 400);
            }
            $r['state'] = TGS_CC_Service::pos_state($uid);
            wp_send_json_success($r);
        }

        wp_send_json_error(array('message' => 'Thao tác không hợp lệ.'), 400);
    }
}
