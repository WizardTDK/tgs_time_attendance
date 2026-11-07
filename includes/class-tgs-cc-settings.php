<?php
/**
 * Cài đặt chấm công của từng site (một option dạng mảng).
 */

defined('ABSPATH') || exit;

class TGS_CC_Settings
{
    const OPTION = 'tgs_cc_settings';

    private static $cache = null;

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
            'allow_other_account' => 1, // cho chấm công bằng tài khoản + mật khẩu khác trên máy quầy
            'pos_button'          => 1, // hiện nút Chấm công trên màn POS
            'ip_whitelist'        => '', // mỗi dòng 1 IP / tiền tố; để trống = không giới hạn
            // Danh sách nhân viên KHÔNG tham gia chấm công (user id)
            'excluded_users'      => array(),
            // In báo cáo
            'report_title'        => '',
            'signers'             => "Người lập biểu\nQuản lý cửa hàng\nGiám đốc",
        );
    }

    public static function all()
    {
        if (self::$cache === null) {
            $saved = get_option(self::OPTION, array());
            self::$cache = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
        }
        return self::$cache;
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
        $bool_keys = array('overtime_enabled', 'half_day_enabled', 'require_schedule', 'allow_other_account', 'pos_button');

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

        $ex = isset($input['excluded_users']) ? (array) $input['excluded_users'] : array();
        $out['excluded_users'] = array_values(array_unique(array_filter(array_map('intval', $ex))));

        update_option(self::OPTION, $out, false);
        self::$cache = null;
        return self::all();
    }

    /** IP của máy đang gọi có nằm trong danh sách cho phép không (trống = cho tất cả) */
    public static function ip_allowed($ip)
    {
        $list = trim((string) self::get('ip_whitelist'));
        if ($list === '') {
            return true;
        }
        foreach (preg_split('/[\r\n,;]+/', $list) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Cho phép tiền tố "113.161.12." hoặc ký tự đại diện "113.161.12.*"
            $prefix = rtrim($line, '*');
            if ($ip === $line || ($prefix !== $line && strpos($ip, $prefix) === 0) || (substr($line, -1) === '.' && strpos($ip, $line) === 0)) {
                return true;
            }
        }
        return false;
    }
}
