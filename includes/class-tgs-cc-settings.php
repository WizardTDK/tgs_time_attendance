<?php
/**
 * Cài đặt chấm công của từng site (một option dạng mảng).
 */

defined('ABSPATH') || exit;

class TGS_CC_Settings
{
    const OPTION = 'tgs_cc_settings';

    /** Cài đặt đã đọc, theo từng site — đổi site bằng switch_to_blog() không đọc nhầm cài đặt shop trước */
    private static $cache = array();

    public static function defaults()
    {
        return array(
            // Đi muộn / về sớm: lệch quá số phút này mới bị ghi nhận
            'late_grace'          => 5,
            'early_grace'         => 5,
            // Làm thêm giờ: chấm vào sớm / ra muộn hơn giờ ca từ N phút trở lên mới tính
            'overtime_enabled'    => 0,
            'overtime_min'        => 30,
            // Nửa công: giờ làm thực tế < from → 0 công; from ≤ giờ < to → nửa công; ≥ to → đủ công
            'half_day_enabled'    => 0,
            'half_day_from'       => 2,
            'half_day_to'         => 5,
            // Ngày công chuẩn trong tháng (in lên bảng công / báo cáo)
            'standard_days'       => 26,
            // POS
            'require_schedule'    => 0, // 1 = chỉ được chấm ca đã xếp lịch
            'allow_other_account' => 1, // cho người đang trong ca điểm danh hộ đồng nghiệp cùng ca trên POS
            'pos_button'          => 1, // hiện nút Chấm công trên màn POS
            'ip_whitelist'        => '', // mỗi dòng 1 IP / tiền tố / CIDR; để trống = không giới hạn
            // Lấy IP thật khi site chạy sau proxy: '' = không (REMOTE_ADDR) · 'cloudflare' · 'proxy' (nginx/LB riêng)
            'proxy_mode'          => '',
            'trusted_proxies'     => '', // chỉ dùng với proxy_mode = 'proxy'; trống = mạng nội bộ
            // Nhân viên chấm công = vai trò trong employee_roles (trừ excluded_users) + included_users
            'employee_roles'      => array('tmd_pos_user'),
            'included_users'      => array(),
            'excluded_users'      => array(),
            // Gỡ plugin (Xoá trong trang Plugins) thì xoá luôn bảng chấm công của shop này
            'delete_on_uninstall' => 0,
            // In báo cáo
            'report_title'        => '',
            'signers'             => "Người lập biểu\nQuản lý cửa hàng\nGiám đốc",
        );
    }

    public static function all()
    {
        $b = get_current_blog_id();
        if (!isset(self::$cache[$b])) {
            $saved = get_option(self::OPTION, array());
            self::$cache[$b] = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
        }
        return self::$cache[$b];
    }

    public static function get($key)
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function save($input)
    {
        $d = self::defaults();
        $out = array();
        $int_keys = array('late_grace', 'early_grace', 'overtime_min', 'standard_days');
        $bool_keys = array('overtime_enabled', 'half_day_enabled', 'require_schedule', 'allow_other_account', 'pos_button', 'delete_on_uninstall');
        $current = self::all();

        foreach ($int_keys as $k) {
            $out[$k] = isset($input[$k]) ? max(0, min(1440, (int) $input[$k])) : $d[$k];
        }
        foreach ($bool_keys as $k) {
            $out[$k] = !empty($input[$k]) ? 1 : 0;
        }
        $out['half_day_from'] = isset($input['half_day_from']) ? max(0, min(24, (float) $input['half_day_from'])) : $d['half_day_from'];
        $out['half_day_to'] = isset($input['half_day_to']) ? max(0, min(24, (float) $input['half_day_to'])) : $d['half_day_to'];
        if ($out['half_day_to'] < $out['half_day_from']) {
            $out['half_day_to'] = $out['half_day_from'];
        }
        $out['ip_whitelist'] = isset($input['ip_whitelist']) ? sanitize_textarea_field($input['ip_whitelist']) : '';
        $out['report_title'] = isset($input['report_title']) ? sanitize_text_field($input['report_title']) : '';
        $out['signers'] = isset($input['signers']) ? sanitize_textarea_field($input['signers']) : $d['signers'];

        $mode = isset($input['proxy_mode']) ? (string) $input['proxy_mode'] : '';
        $out['proxy_mode'] = in_array($mode, array('', 'cloudflare', 'proxy'), true) ? $mode : '';
        $out['trusted_proxies'] = isset($input['trusted_proxies']) ? sanitize_textarea_field($input['trusted_proxies']) : '';

        // Vai trò mặc định được chấm công
        $all_roles = array_keys(wp_roles()->roles);
        $roles = isset($input['employee_roles'])
            ? array_values(array_intersect(array_map('sanitize_key', (array) $input['employee_roles']), $all_roles))
            : (array) $current['employee_roles'];
        $out['employee_roles'] = $roles;

        // "members" = danh sách người được tích chấm công trên màn Cài đặt → quy ra
        // ngoại lệ so với vai trò: included (không thuộc vai trò mà vẫn chấm), excluded (thuộc vai trò mà bỏ ra).
        if (isset($input['members'])) {
            $members = array_map('intval', (array) $input['members']);
            $inc = array();
            $exc = array();
            foreach (TGS_CC_Service::site_users() as $u) {
                $id = (int) $u->ID;
                $by_role = (bool) array_intersect((array) $u->roles, $roles);
                $want = in_array($id, $members, true);
                if ($want && !$by_role) {
                    $inc[] = $id;
                } elseif (!$want && $by_role) {
                    $exc[] = $id;
                }
            }
            $out['included_users'] = $inc;
            $out['excluded_users'] = $exc;
        } else {
            $out['included_users'] = array_values(array_map('intval', (array) $current['included_users']));
            $out['excluded_users'] = array_values(array_map('intval', (array) $current['excluded_users']));
        }

        update_option(self::OPTION, $out, false);
        unset(self::$cache[get_current_blog_id()]);
        return self::all();
    }

    /** Các khoá cài đặt ảnh hưởng tới cách tính công — đổi khoá nào thì mới phải tính lại */
    public static function calc_keys()
    {
        return array('late_grace', 'early_grace', 'overtime_enabled', 'overtime_min', 'half_day_enabled', 'half_day_from', 'half_day_to');
    }

    /** IP của máy đang gọi có nằm trong danh sách cho phép không (trống = cho tất cả) */
    public static function ip_allowed($ip)
    {
        $list = trim((string) self::get('ip_whitelist'));
        if ($list === '') {
            return true;
        }
        // Mỗi dòng: IP, CIDR "113.161.12.0/24", tiền tố "113.161.12." hoặc "113.161.12.*"
        return TGS_CC_Service::ip_in_list($ip, preg_split('/[\r\n,;]+/', $list));
    }
}
