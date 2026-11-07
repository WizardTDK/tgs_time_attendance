<?php
/**
 * NƠI DUY NHẤT chứa nghiệp vụ chấm công.
 *
 * Màn quản trị và màn POS chỉ gọi vào đây — không tự tính công ở chỗ khác,
 * để báo cáo, bảng chấm công và nút chấm trên POS luôn khớp nhau.
 *
 * ── LUẬT TÍNH (bám KiotViet) ────────────────────────────────────────────────
 *
 *   Khung ca         [giờ vào, giờ ra]; giờ ra ≤ giờ vào nghĩa là ca qua đêm.
 *   Cho phép chấm    vào từ (giờ vào − checkin_before) tới trước giờ ra;
 *                    ra tới muộn nhất (giờ ra + checkout_after).
 *   Đi muộn          vào sau giờ vào > late_grace phút  → ghi số phút muộn.
 *   Về sớm           ra trước giờ ra > early_grace phút → ghi số phút sớm.
 *   Giờ làm          từ max(vào, giờ vào ca) tới min(ra, giờ ra ca) − nghỉ giữa ca
 *                    (có mặt cả ca thì trừ đủ; có mặt một phần thì trừ theo tỷ lệ).
 *   Làm thêm         (nếu bật) vào sớm / ra muộn hơn giờ ca ≥ overtime_min phút.
 *   Công             số công của ca; bật "nửa công" thì theo giờ làm thực tế.
 *   Chấm thiếu       có vào mà không có ra (đã quá giờ cho phép chấm ra) → 0 công.
 */

defined('ABSPATH') || exit;

class TGS_CC_Service
{
    /* ═══════════════════════════ TIỆN ÍCH GIỜ ═══════════════════════════ */

    public static function ts($mysql)
    {
        if (!$mysql || $mysql === '0000-00-00 00:00:00') {
            return 0;
        }
        try {
            $d = new DateTimeImmutable($mysql, wp_timezone());
            return $d->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function fmt($ts, $format = 'Y-m-d H:i:s')
    {
        return $ts ? wp_date($format, $ts) : '';
    }

    public static function today()
    {
        return wp_date('Y-m-d');
    }

    public static function valid_date($d)
    {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    /** Danh sách ngày Y-m-d từ $from tới $to (bao gồm cả hai đầu) */
    public static function date_range($from, $to)
    {
        $out = array();
        $d = new DateTimeImmutable($from, wp_timezone());
        $end = new DateTimeImmutable($to, wp_timezone());
        $guard = 0;
        while ($d <= $end && $guard++ < 400) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        return $out;
    }

    /* ═══════════════════════════ IP MÁY CHẤM ═══════════════════════════ */

    private static function clean_ip($ip)
    {
        $ip = trim((string) $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * IP thật của máy đang gọi.
     *
     * Mặc định chỉ tin REMOTE_ADDR. Site chạy sau Cloudflare / reverse proxy thì
     * REMOTE_ADDR là IP của proxy (mọi máy giống nhau) → bật "proxy_mode" trong
     * Cài đặt. Header IP chỉ được tin khi request ĐẾN TỪ proxy đáng tin, nếu
     * không ai cũng tự gửi header giả được.
     */
    public static function client_ip()
    {
        static $ip = null;
        if ($ip !== null) {
            return $ip;
        }
        $remote = self::clean_ip(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        $ip = $remote;
        $mode = (string) TGS_CC_Settings::get('proxy_mode');

        if ($mode === 'cloudflare' && $remote && self::ip_in_list($remote, self::cloudflare_ranges())) {
            $cf = self::clean_ip(isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']) : '');
            if ($cf) {
                $ip = $cf;
            }
        } elseif ($mode === 'proxy' && $remote && self::ip_in_list($remote, self::trusted_proxies())) {
            $found = '';
            $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : '';
            if ($xff !== '') {
                // Đi từ phải sang trái, bỏ qua các proxy tin cậy → IP đầu tiên không phải proxy là máy khách
                $chain = array_reverse(array_map('trim', explode(',', $xff)));
                foreach ($chain as $cand) {
                    $cand = self::clean_ip($cand);
                    if ($cand && !self::ip_in_list($cand, self::trusted_proxies())) {
                        $found = $cand;
                        break;
                    }
                }
            }
            if (!$found) {
                $found = self::clean_ip(isset($_SERVER['HTTP_X_REAL_IP']) ? wp_unslash($_SERVER['HTTP_X_REAL_IP']) : '');
            }
            if ($found) {
                $ip = $found;
            }
        }
        return substr($ip, 0, 64);
    }

    /** Dải IP của Cloudflare (https://www.cloudflare.com/ips/) — đổi qua filter tgs_cc_cloudflare_ranges */
    public static function cloudflare_ranges()
    {
        return (array) apply_filters('tgs_cc_cloudflare_ranges', array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
            '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
            '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        ));
    }

    /** Proxy tin cậy: danh sách trong Cài đặt; để trống = mạng nội bộ (proxy cùng máy / cùng mạng docker) */
    public static function trusted_proxies()
    {
        $list = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) TGS_CC_Settings::get('trusted_proxies'))));
        return $list ? array_values($list) : array('127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7');
    }

    public static function ip_in_list($ip, $rules)
    {
        foreach ((array) $rules as $rule) {
            if (self::ip_match($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IP có khớp một luật không. Luật nhận: IP đầy đủ, CIDR (113.161.12.0/24),
     * tiền tố kết thúc bằng dấu chấm (113.161.12.) hoặc dấu sao (113.161.12.*).
     */
    public static function ip_match($ip, $rule)
    {
        $ip = trim((string) $ip);
        $rule = trim((string) $rule);
        if ($ip === '' || $rule === '') {
            return false;
        }
        if ($ip === $rule) {
            return true;
        }
        if (strpos($rule, '/') !== false) {
            list($net, $bits) = explode('/', $rule, 2);
            $a = @inet_pton($ip);
            $b = @inet_pton(trim($net));
            if ($a === false || $b === false || strlen($a) !== strlen($b) || !is_numeric($bits)) {
                return false;
            }
            $bits = max(0, min(strlen($a) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            if ($bytes && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
                return false;
            }
            $rest = $bits % 8;
            if ($rest) {
                $mask = (0xFF << (8 - $rest)) & 0xFF;
                return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
            }
            return true;
        }
        $prefix = rtrim($rule, '*');
        if ($prefix !== $rule || substr($rule, -1) === '.' || substr($rule, -1) === ':') {
            return $prefix !== '' && strpos($ip, $prefix) === 0;
        }
        return false;
    }

    /* ═══════════════════════════ NHÂN VIÊN ═══════════════════════════ */

    /** Tài khoản thuộc site hiện tại, trừ khách hàng / người đăng ký */
    public static function site_users()
    {
        static $cache = array(); // theo từng site
        $b = get_current_blog_id();
        if (!isset($cache[$b])) {
            $cache[$b] = get_users(array(
                'blog_id' => get_current_blog_id(),
                'role__not_in' => apply_filters('tgs_cc_excluded_roles', array('customer', 'subscriber')),
                'orderby' => 'display_name',
                'order' => 'ASC',
                'number' => 1000,
            ));
        }
        return $cache[$b];
    }

    /**
     * Nhân viên chấm công của shop:
     *   - tài khoản có vai trò nằm trong "employee_roles" (mặc định tmd_pos_user — nhân viên POS),
     *     trừ người bị bỏ ra (excluded_users);
     *   - cộng thêm người được chọn tay (included_users), vai trò nào cũng được.
     * Tài khoản admin / kế toán / tài khoản mới tạo với vai trò khác KHÔNG tự vào danh sách.
     *
     * $include_excluded = true: trả cả tài khoản không tham gia (cờ excluded) — dùng cho màn Cài đặt.
     */
    public static function employees($include_excluded = false)
    {
        static $cache = array();
        $key = get_current_blog_id() . ($include_excluded ? '|all' : '|active'); // theo từng site
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $roles = array_map('strval', (array) TGS_CC_Settings::get('employee_roles'));
        $included = array_map('intval', (array) TGS_CC_Settings::get('included_users'));
        $excluded = array_map('intval', (array) TGS_CC_Settings::get('excluded_users'));

        $out = array();
        foreach (self::site_users() as $u) {
            $id = (int) $u->ID;
            $by_role = (bool) array_intersect((array) $u->roles, $roles);
            $in = in_array($id, $included, true) || ($by_role && !in_array($id, $excluded, true));
            if (!$in && !$include_excluded) {
                continue;
            }
            $out[] = array(
                'id' => $id,
                'name' => $u->display_name ?: $u->user_login,
                'login' => $u->user_login,
                'roles' => implode(', ', (array) $u->roles),
                'excluded' => !$in,
                'by_role' => $by_role,
            );
        }
        $cache[$key] = apply_filters('tgs_cc_employees', $out, $include_excluded);
        return $cache[$key];
    }

    /** Tài khoản có thuộc cửa hàng này không (kể cả người đang không tham gia chấm công) */
    public static function is_shop_user($user_id)
    {
        return in_array((int) $user_id, wp_list_pluck(self::employees(true), 'id'), true);
    }

    /* ═══════════════════════════ CHỐT THÁNG ═══════════════════════════ */

    const LOCK_OPTION = 'tgs_cc_locks';

    /** Các tháng đã chốt: array('Y-m' => array('by' => user_id, 'at' => 'Y-m-d H:i:s')) */
    public static function locks()
    {
        $l = get_option(self::LOCK_OPTION, array());
        return is_array($l) ? $l : array();
    }

    public static function is_locked($date)
    {
        $locks = self::locks();
        return isset($locks[substr((string) $date, 0, 7)]);
    }

    /** Các tháng đã chốt nằm trong khoảng ngày */
    public static function locked_between($from, $to)
    {
        $out = array();
        foreach (array_keys(self::locks()) as $m) {
            if ($m >= substr($from, 0, 7) && $m <= substr($to, 0, 7)) {
                $out[] = $m;
            }
        }
        sort($out);
        return $out;
    }

    public static function lock_error($date)
    {
        $m = substr((string) $date, 0, 7);
        return new WP_Error('locked', 'Bảng công tháng ' . substr($m, 5, 2) . '/' . substr($m, 0, 4) . ' đã chốt nên không sửa được. Cần quản trị cửa hàng mở chốt tháng trước.');
    }

    /** Người được mở chốt: chỉ quản trị site (quản lý được cấp quyền chấm công không tự mở được) */
    public static function can_unlock($user_id = 0)
    {
        $user_id = $user_id ?: get_current_user_id();
        return is_super_admin($user_id) || user_can($user_id, 'manage_options');
    }

    /** Chỉ chốt được tháng đã kết thúc */
    public static function can_lock_month($month)
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $month) && $month < wp_date('Y-m');
    }

    public static function month_lock($month)
    {
        if (!self::can_lock_month($month)) {
            return new WP_Error('invalid', 'Chỉ chốt được tháng đã kết thúc.');
        }
        $locks = self::locks();
        if (isset($locks[$month])) {
            return new WP_Error('invalid', 'Tháng này đã chốt rồi.');
        }
        $locks[$month] = array('by' => get_current_user_id(), 'at' => current_time('mysql'));
        ksort($locks);
        update_option(self::LOCK_OPTION, $locks, false);
        self::log(0, 0, 'month_lock', array('thang' => $month));
        return self::lock_info($month);
    }

    public static function month_unlock($month)
    {
        if (!self::can_unlock()) {
            return new WP_Error('forbidden', 'Chỉ quản trị cửa hàng được mở chốt bảng công.');
        }
        $locks = self::locks();
        if (!isset($locks[$month])) {
            return new WP_Error('invalid', 'Tháng này chưa chốt.');
        }
        unset($locks[$month]);
        update_option(self::LOCK_OPTION, $locks, false);
        self::log(0, 0, 'month_unlock', array('thang' => $month));
        return true;
    }

    /** Thông tin chốt của một tháng để hiện ra giao diện (null = chưa chốt) */
    public static function lock_info($month)
    {
        $locks = self::locks();
        if (!isset($locks[$month])) {
            return null;
        }
        $l = $locks[$month];
        return array(
            'by' => self::user_name(isset($l['by']) ? $l['by'] : 0),
            'at' => !empty($l['at']) ? mysql2date('d/m/Y H:i', $l['at']) : '',
        );
    }

    public static function employee_ids($filter = array())
    {
        $ids = wp_list_pluck(self::employees(), 'id');
        $filter = array_filter(array_map('intval', (array) $filter));
        return $filter ? array_values(array_intersect($ids, $filter)) : $ids;
    }

    public static function user_name($user_id)
    {
        $u = get_userdata($user_id);
        return $u ? ($u->display_name ?: $u->user_login) : ('#' . $user_id);
    }

    /* ═══════════════════════════ CA LÀM VIỆC ═══════════════════════════ */

    public static function shifts($active_only = false)
    {
        global $wpdb;
        $t = TGS_CC_DB::t('shifts');
        $where = $active_only ? 'WHERE status = 1' : '';
        $rows = $wpdb->get_results("SELECT * FROM {$t} {$where} ORDER BY sort_order ASC, start_time ASC, shift_id ASC", ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $out[(int) $r['shift_id']] = self::shift_public($r);
        }
        return $out;
    }

    /** Ca đã đọc, khoá "mã site:mã ca" — shop khác nhau có thể trùng shift_id */
    private static $shift_cache = array();

    private static function shift_key($id)
    {
        return get_current_blog_id() . ':' . (int) $id;
    }

    public static function shift($id)
    {
        $id = (int) $id;
        $k = self::shift_key($id);
        if (!array_key_exists($k, self::$shift_cache)) {
            global $wpdb;
            $t = TGS_CC_DB::t('shifts');
            $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE shift_id = %d", $id), ARRAY_A);
            self::$shift_cache[$k] = $r ? self::shift_public($r) : null;
        }
        return self::$shift_cache[$k];
    }

    private static function shift_public($r)
    {
        $start = substr($r['start_time'], 0, 5);
        $end = substr($r['end_time'], 0, 5);
        return array(
            'id' => (int) $r['shift_id'],
            'code' => $r['shift_code'],
            'name' => $r['shift_name'],
            'start' => $start,
            'end' => $end,
            'overnight' => $end <= $start,
            'break_minutes' => (int) $r['break_minutes'],
            'checkin_before' => (int) $r['checkin_before'],
            'checkout_after' => (int) $r['checkout_after'],
            'work_value' => (float) $r['work_value'],
            'color' => $r['color'],
            'status' => (int) $r['status'],
            'sort_order' => (int) $r['sort_order'],
            'hours' => self::shift_hours($start, $end, (int) $r['break_minutes']),
        );
    }

    /** Độ dài ca theo phút (ca qua đêm cộng 24h) */
    private static function shift_minutes($start, $end)
    {
        list($sh, $sm) = array_map('intval', explode(':', $start));
        list($eh, $em) = array_map('intval', explode(':', $end));
        $mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
        return $mins <= 0 ? $mins + 1440 : $mins;
    }

    private static function shift_hours($start, $end, $break)
    {
        return round(max(0, self::shift_minutes($start, $end) - $break) / 60, 2);
    }

    /** Mốc giờ thật của một ca trong một ngày: start, end, open (sớm nhất được chấm vào), close (muộn nhất được chấm ra) */
    public static function window($shift, $work_date)
    {
        $start = self::ts($work_date . ' ' . $shift['start'] . ':00');
        $end = self::ts($work_date . ' ' . $shift['end'] . ':00');
        if ($end <= $start) {
            $end += DAY_IN_SECONDS;
        }
        return array(
            'start' => $start,
            'end' => $end,
            'open' => $start - $shift['checkin_before'] * 60,
            'close' => $end + $shift['checkout_after'] * 60,
        );
    }

    /**
     * Hai lượt ca (ca, ngày) có chồng giờ nhau không — tính theo giờ ca thật
     * [giờ vào, giờ ra), có xét ca qua đêm. Ca nối tiếp (14:00 hết, 14:00 vào) không tính là chồng.
     *
     * Ca LỒNG NHAU giữa CÁC NHÂN VIÊN KHÁC NHAU là bình thường (cửa hàng 2–5 người bán cùng lúc,
     * ca sáng / ca giữa / ca chiều gối nhau). Chỉ MỘT nhân viên thì không làm 2 ca chồng giờ cùng lúc.
     */
    public static function shifts_overlap($sid_a, $date_a, $sid_b, $date_b)
    {
        $a = self::shift($sid_a);
        $b = self::shift($sid_b);
        if (!$a || !$b) {
            return false;
        }
        $wa = self::window($a, $date_a);
        $wb = self::window($b, $date_b);
        return $wa['start'] < $wb['end'] && $wb['start'] < $wa['end'];
    }

    private static function shift_label($sid, $date = '')
    {
        $s = self::shift($sid);
        if (!$s) {
            return 'ca #' . (int) $sid;
        }
        return $s['name'] . ' (' . $s['start'] . '–' . $s['end'] . ($date ? ', ' . mysql2date('d/m', $date) : '') . ')';
    }

    private static function shift_day($date, $offset)
    {
        return wp_date('Y-m-d', self::ts($date . ' 12:00:00') + $offset * DAY_IN_SECONDS);
    }

    /**
     * Lịch đã có của nhân viên chồng giờ với (ca, ngày) định xếp → trả về lượt bị chồng, không có → null.
     * Xét cả ngày trước / ngày sau vì ca qua đêm. $skip_dates: các ngày sắp bị ghi đè, bỏ qua.
     */
    private static function schedule_conflict($user_id, $shift_id, $work_date, $skip_dates = array())
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT shift_id, work_date FROM ' . TGS_CC_DB::t('schedules') . ' WHERE user_id = %d AND work_date BETWEEN %s AND %s',
            (int) $user_id,
            self::shift_day($work_date, -1),
            self::shift_day($work_date, 1)
        ), ARRAY_A);
        foreach ($rows as $r) {
            if (in_array($r['work_date'], $skip_dates, true)) {
                continue;
            }
            if ((int) $r['shift_id'] === (int) $shift_id && $r['work_date'] === $work_date) {
                continue;
            }
            if (self::shifts_overlap($shift_id, $work_date, (int) $r['shift_id'], $r['work_date'])) {
                return array((int) $r['shift_id'], $r['work_date']);
            }
        }
        return null;
    }

    /** Trong danh sách ca chọn cho cùng một ngày có cặp nào chồng giờ không → WP_Error / null */
    private static function shift_set_conflict($shift_ids, $work_date = '')
    {
        $date = $work_date ?: self::today();
        $shift_ids = array_values($shift_ids);
        for ($i = 0; $i < count($shift_ids); $i++) {
            for ($j = $i + 1; $j < count($shift_ids); $j++) {
                if (self::shifts_overlap($shift_ids[$i], $date, $shift_ids[$j], $date)) {
                    return new WP_Error('overlap', self::shift_label($shift_ids[$i]) . ' và ' . self::shift_label($shift_ids[$j])
                        . ' chồng giờ nhau — một nhân viên không làm 2 ca chồng giờ cùng lúc. (Nhiều nhân viên khác nhau làm các ca lồng nhau thì vẫn xếp bình thường.)');
                }
            }
        }
        return null;
    }

    public static function shift_save($p)
    {
        global $wpdb;
        $t = TGS_CC_DB::t('shifts');
        $id = isset($p['id']) ? (int) $p['id'] : 0;
        $name = sanitize_text_field(isset($p['name']) ? $p['name'] : '');
        $start = self::norm_time(isset($p['start']) ? $p['start'] : '');
        $end = self::norm_time(isset($p['end']) ? $p['end'] : '');
        if ($name === '' || !$start || !$end) {
            return new WP_Error('invalid', 'Nhập đủ tên ca, giờ vào và giờ ra.');
        }
        if ($start === $end) {
            return new WP_Error('invalid', 'Giờ vào và giờ ra không được trùng nhau.');
        }
        $code = strtoupper(sanitize_key(isset($p['code']) ? $p['code'] : ''));
        if ($code === '') {
            $code = strtoupper(sanitize_key(remove_accents($name)));
            $code = str_replace('-', '_', $code);
        }
        $color = sanitize_hex_color(isset($p['color']) ? $p['color'] : '') ?: '#1e4fa3';
        $break = max(0, min(600, (int) (isset($p['break_minutes']) ? $p['break_minutes'] : 0)));
        $len = self::shift_minutes($start, $end);
        if ($break >= $len) {
            return new WP_Error('invalid', 'Nghỉ giữa ca (' . $break . ' phút) phải ngắn hơn độ dài ca (' . $len . ' phút).');
        }

        $data = array(
            'shift_code' => substr($code, 0, 30),
            'shift_name' => substr($name, 0, 100),
            'start_time' => $start . ':00',
            'end_time' => $end . ':00',
            'break_minutes' => $break,
            'checkin_before' => max(0, min(720, (int) (isset($p['checkin_before']) ? $p['checkin_before'] : 60))),
            'checkout_after' => max(0, min(720, (int) (isset($p['checkout_after']) ? $p['checkout_after'] : 120))),
            'work_value' => max(0, min(10, (float) (isset($p['work_value']) ? $p['work_value'] : 1))),
            'color' => $color,
            'status' => !empty($p['status']) ? 1 : 0,
            'sort_order' => (int) (isset($p['sort_order']) ? $p['sort_order'] : 0),
            'updated_at' => current_time('mysql'),
        );

        $old = $id ? self::shift($id) : null;
        if ($id) {
            $wpdb->update($t, $data, array('shift_id' => $id));
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($t, $data);
            $id = (int) $wpdb->insert_id;
        }
        unset(self::$shift_cache[self::shift_key($id)]);
        $new = self::shift($id) ?: self::shift_public(array_merge($data, array('shift_id' => $id)));

        // Chỉ những thay đổi ảnh hưởng cách tính công mới cần tính lại
        $new['_recalc'] = false;
        if ($old) {
            foreach (array('start', 'end', 'break_minutes', 'work_value', 'checkout_after') as $k) {
                if ((string) $old[$k] !== (string) $new[$k]) {
                    $new['_recalc'] = true;
                    break;
                }
            }
        }
        return $new;
    }

    /**
     * Ca đã có dữ liệu chấm công — hoặc có lịch trong tháng đã chốt — thì không
     * xoá được, chỉ chuyển sang Ngừng hoạt động.
     */
    public static function shift_delete($id)
    {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0 || !self::shift($id)) {
            return array('deleted' => false, 'message' => 'Không tìm thấy ca cần xoá.');
        }
        $deactivate = function ($why) use ($wpdb, $id) {
            $wpdb->update(TGS_CC_DB::t('shifts'), array('status' => 0, 'updated_at' => current_time('mysql')), array('shift_id' => $id));
            unset(self::$shift_cache[self::shift_key($id)]);
            return array('deleted' => false, 'message' => $why . ' nên không xoá được — đã chuyển sang Ngừng hoạt động.');
        };
        $used = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TGS_CC_DB::t('records') . ' WHERE shift_id = %d', $id));
        if ($used > 0) {
            return $deactivate('Ca đã có ' . $used . ' lượt chấm công');
        }
        $months = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT DATE_FORMAT(work_date, '%%Y-%%m') FROM " . TGS_CC_DB::t('schedules') . ' WHERE shift_id = %d', $id));
        foreach ((array) $months as $m) {
            if (self::is_locked($m . '-01')) {
                return $deactivate('Ca có lịch làm việc trong tháng đã chốt bảng công');
            }
        }
        $wpdb->delete(TGS_CC_DB::t('schedules'), array('shift_id' => $id));
        $wpdb->delete(TGS_CC_DB::t('shifts'), array('shift_id' => $id));
        return array('deleted' => true, 'message' => 'Đã xoá ca.');
    }

    public static function norm_time($v)
    {
        $v = trim((string) $v);
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $v, $m)) {
            return '';
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $h, $i);
    }

    /* ═══════════════════════════ TÍNH CÔNG ═══════════════════════════ */

    public static function status_labels()
    {
        return array(
            'dung_gio'        => array('Đúng giờ', 'success'),
            'di_muon'         => array('Đi muộn', 'warning'),
            've_som'          => array('Về sớm', 'warning'),
            'muon_som'        => array('Đi muộn, về sớm', 'warning'),
            'dang_lam'        => array('Đang làm', 'info'),
            'chua_vao'        => array('Chưa vào ca', 'warning'),
            'thieu'           => array('Chấm công thiếu', 'danger'),
            'nghi_co_phep'    => array('Nghỉ có phép', 'primary'),
            'nghi_khong_phep' => array('Nghỉ không phép', 'danger'),
            'chua_cham'       => array('Chưa chấm công', 'secondary'),
            'lich'            => array('Chưa tới ca', 'secondary'),
        );
    }

    /**
     * Tính các chỉ số của một dòng chấm công. $rec cần: work_date, check_in_at,
     * check_out_at, leave_type. Trả về mảng các cột cần lưu.
     */
    public static function compute($rec, $shift, $now = null)
    {
        $now = $now ?: time();
        $s = TGS_CC_Settings::all();
        $out = array(
            'late_minutes' => 0,
            'early_minutes' => 0,
            'overtime_minutes' => 0,
            'worked_minutes' => 0,
            'work_value' => 0,
            'status' => 'chua_cham',
        );

        if (!empty($rec['leave_type'])) {
            $out['status'] = $rec['leave_type'] === 'co_phep' ? 'nghi_co_phep' : 'nghi_khong_phep';
            return $out;
        }
        if (!$shift) {
            return $out;
        }

        $w = self::window($shift, $rec['work_date']);
        $in = self::ts(isset($rec['check_in_at']) ? $rec['check_in_at'] : '');
        $outt = self::ts(isset($rec['check_out_at']) ? $rec['check_out_at'] : '');

        if ($in) {
            $late = (int) floor(($in - $w['start']) / 60);
            if ($late > (int) $s['late_grace']) {
                $out['late_minutes'] = $late;
            }
        }

        if (!$in && !$outt) {
            return $out;
        }
        if (!$in || !$outt) {
            $out['status'] = (!$outt && $now <= $w['close']) ? 'dang_lam' : 'thieu';
            return $out;
        }

        $early = (int) floor(($w['end'] - $outt) / 60);
        if ($early > (int) $s['early_grace']) {
            $out['early_minutes'] = $early;
        }

        $eff_in = max($in, $w['start']);
        $eff_out = min($outt, $w['end']);
        $span = max(0, (int) floor(($eff_out - $eff_in) / 60));
        // Nghỉ giữa ca: có mặt cả ca → trừ đủ; có mặt một phần → trừ theo tỷ lệ thời gian có mặt.
        // (Trước đây làm ít hơn thời gian nghỉ thì không trừ gì → giờ làm bị thổi phồng.)
        $len = max(1, (int) floor(($w['end'] - $w['start']) / 60));
        $break = (int) $shift['break_minutes'];
        $deduct = $span >= $len ? $break : (int) round($break * $span / $len);
        $worked = max(0, $span - $deduct);
        $out['worked_minutes'] = $worked;

        if (!empty($s['overtime_enabled'])) {
            $min = (int) $s['overtime_min'];
            $before = (int) floor(($w['start'] - $in) / 60);
            $after = (int) floor(($outt - $w['end']) / 60);
            $ot = 0;
            if ($before >= $min && $before > 0) {
                $ot += $before;
            }
            if ($after >= $min && $after > 0) {
                $ot += $after;
            }
            $out['overtime_minutes'] = $ot;
        }

        $value = (float) $shift['work_value'];
        if (!empty($s['half_day_enabled'])) {
            $hours = $worked / 60;
            if ($hours < (float) $s['half_day_from']) {
                $value = 0;
            } elseif ($hours < (float) $s['half_day_to']) {
                $value = $value / 2;
            }
        }
        $out['work_value'] = round($value, 2);

        if ($out['late_minutes'] && $out['early_minutes']) {
            $out['status'] = 'muon_som';
        } elseif ($out['late_minutes']) {
            $out['status'] = 'di_muon';
        } elseif ($out['early_minutes']) {
            $out['status'] = 've_som';
        } else {
            $out['status'] = 'dung_gio';
        }
        return $out;
    }

    /* ═══════════════════════════ DÒNG CHẤM CÔNG ═══════════════════════════ */

    public static function record($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . TGS_CC_DB::t('records') . ' WHERE record_id = %d', (int) $id), ARRAY_A);
    }

    public static function find_record($user_id, $shift_id, $work_date)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . TGS_CC_DB::t('records') . ' WHERE user_id = %d AND shift_id = %d AND work_date = %s',
            (int) $user_id,
            (int) $shift_id,
            $work_date
        ), ARRAY_A);
    }

    /** Tính lại và lưu các cột kết quả của một dòng */
    public static function recompute($record_id)
    {
        global $wpdb;
        $rec = self::record($record_id);
        if (!$rec) {
            return null;
        }
        $calc = self::compute($rec, self::shift($rec['shift_id']));
        $wpdb->update(TGS_CC_DB::t('records'), $calc, array('record_id' => (int) $record_id));
        return array_merge($rec, $calc);
    }

    /**
     * Tính lại các dòng chấm công từ ngày $from (sau khi đổi cài đặt / sửa ca).
     * $shift_id > 0: chỉ các dòng của ca đó. Bỏ qua tháng đã chốt.
     * Đọc một lần, chỉ ghi lại dòng nào thực sự đổi → shop lớn không bị timeout.
     * Trả về số dòng đã thay đổi.
     */
    public static function recompute_from($from, $shift_id = 0)
    {
        global $wpdb;
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $sql = $wpdb->prepare('SELECT * FROM ' . TGS_CC_DB::t('records') . ' WHERE work_date >= %s', $from);
        if ($shift_id) {
            $sql .= $wpdb->prepare(' AND shift_id = %d', $shift_id);
        }
        $now = time();
        $changed = 0;
        $fields = array('late_minutes', 'early_minutes', 'overtime_minutes', 'worked_minutes', 'work_value', 'status');
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $r) {
            if (self::is_locked($r['work_date'])) {
                continue;
            }
            $calc = self::compute($r, self::shift($r['shift_id']), $now);
            $diff = false;
            foreach ($fields as $f) {
                if ($f === 'work_value' ? abs((float) $r[$f] - (float) $calc[$f]) > 0.001 : (string) $r[$f] !== (string) $calc[$f]) {
                    $diff = true;
                    break;
                }
            }
            if ($diff) {
                $wpdb->update(TGS_CC_DB::t('records'), $calc, array('record_id' => (int) $r['record_id']));
                $changed++;
            }
        }
        return $changed;
    }

    public static function log($record_id, $user_id, $action, $detail = array())
    {
        global $wpdb;
        $wpdb->insert(TGS_CC_DB::t('logs'), array(
            'record_id' => (int) $record_id,
            'user_id' => (int) $user_id,
            'actor_id' => get_current_user_id(),
            'action' => $action,
            'detail' => $detail ? wp_json_encode($detail, JSON_UNESCAPED_UNICODE) : '',
            'ip' => self::client_ip(),
            'created_at' => current_time('mysql'),
        ));
    }

    public static function record_logs($record_id)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . TGS_CC_DB::t('logs') . ' WHERE record_id = %d ORDER BY log_id DESC LIMIT 50',
            (int) $record_id
        ), ARRAY_A);
        $labels = array(
            'pos_in' => 'Chấm vào (POS)',
            'pos_out' => 'Chấm ra (POS)',
            'pos_in_ho' => 'Chấm vào hộ (POS — đồng nghiệp trong ca)',
            'pos_out_ho' => 'Chấm ra hộ (POS — đồng nghiệp trong ca)',
            'admin_edit' => 'Quản lý sửa',
            'admin_create' => 'Quản lý tạo',
            'admin_delete' => 'Quản lý xoá',
            'auto_fill' => 'Duyệt đủ công theo lịch',
        );
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'time' => mysql2date('d/m/Y H:i', $r['created_at']),
                'action' => isset($labels[$r['action']]) ? $labels[$r['action']] : $r['action'],
                'actor' => self::user_name($r['actor_id']),
                'ip' => $r['ip'],
                'detail' => $r['detail'],
            );
        }
        return $out;
    }

    /** Dữ liệu một dòng gửi ra giao diện */
    public static function record_public($rec, $now = null)
    {
        $now = $now ?: time();
        $status = $rec['status'];
        // Dòng đang mở (có vào, chưa ra): trạng thái phụ thuộc giờ hiện tại
        if (!empty($rec['check_in_at']) && empty($rec['check_out_at']) && empty($rec['leave_type'])) {
            $shift = self::shift($rec['shift_id']);
            if ($shift) {
                $w = self::window($shift, $rec['work_date']);
                $status = $now <= $w['close'] ? 'dang_lam' : 'thieu';
            }
        }
        $labels = self::status_labels();
        $in = self::ts($rec['check_in_at']);
        $out = self::ts($rec['check_out_at']);
        return array(
            'record_id' => (int) $rec['record_id'],
            'user_id' => (int) $rec['user_id'],
            'shift_id' => (int) $rec['shift_id'],
            'work_date' => $rec['work_date'],
            'in' => $in ? wp_date('H:i', $in) : '',
            'out' => $out ? wp_date('H:i', $out) : '',
            'in_full' => $in ? wp_date('d/m/Y H:i', $in) : '',
            'out_full' => $out ? wp_date('d/m/Y H:i', $out) : '',
            'in_source' => $rec['in_source'],
            'out_source' => $rec['out_source'],
            'leave_type' => $rec['leave_type'],
            'late' => (int) $rec['late_minutes'],
            'early' => (int) $rec['early_minutes'],
            'overtime' => (int) $rec['overtime_minutes'],
            'worked' => (int) $rec['worked_minutes'],
            'work_value' => (float) $rec['work_value'],
            'status' => $status,
            'status_label' => isset($labels[$status]) ? $labels[$status][0] : $status,
            'status_tone' => isset($labels[$status]) ? $labels[$status][1] : 'secondary',
            'note' => $rec['note'],
        );
    }

    /**
     * Ghép ngày làm + "HH:MM" thành datetime, xử lý ca qua đêm:
     * giờ nhỏ hơn giờ vào ca của ca qua đêm → thuộc ngày hôm sau.
     */
    private static function build_dt($work_date, $hm, $shift, $after_ts = 0)
    {
        $hm = self::norm_time($hm);
        if (!$hm) {
            return '';
        }
        $ts = self::ts($work_date . ' ' . $hm . ':00');
        if ($shift['overnight'] && $hm < $shift['start'] && $hm <= $shift['end']) {
            $ts += DAY_IN_SECONDS;
        }
        if ($after_ts && $ts <= $after_ts) {
            $ts += DAY_IN_SECONDS;
        }
        return wp_date('Y-m-d H:i:s', $ts);
    }

    /**
     * Quản lý tạo / sửa công cho một nhân viên, một ca, một ngày.
     * $p: user_id, shift_id, work_date, mode (work|co_phep|khong_phep), check_in, check_out (HH:MM), note
     */
    public static function admin_save_record($p)
    {
        global $wpdb;
        $uid = (int) (isset($p['user_id']) ? $p['user_id'] : 0);
        $sid = (int) (isset($p['shift_id']) ? $p['shift_id'] : 0);
        $date = isset($p['work_date']) ? $p['work_date'] : '';
        $shift = self::shift($sid);
        if (!$uid || !$shift || !self::valid_date($date)) {
            return new WP_Error('invalid', 'Thiếu nhân viên, ca hoặc ngày.');
        }
        if (!self::is_shop_user($uid)) {
            return new WP_Error('not_member', 'Tài khoản này không thuộc cửa hàng nên không tạo được công.');
        }
        if (self::is_locked($date)) {
            return self::lock_error($date);
        }
        $mode = isset($p['mode']) ? $p['mode'] : 'work';
        $note = substr(sanitize_text_field(isset($p['note']) ? $p['note'] : ''), 0, 255);

        $data = array('note' => $note, 'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql'));
        if ($mode === 'co_phep' || $mode === 'khong_phep') {
            $data['leave_type'] = $mode;
            $data['check_in_at'] = null;
            $data['check_out_at'] = null;
        } else {
            $in = self::build_dt($date, isset($p['check_in']) ? $p['check_in'] : '', $shift);
            $out = self::build_dt($date, isset($p['check_out']) ? $p['check_out'] : '', $shift, $in ? self::ts($in) : 0);
            if (!$in && !$out) {
                return new WP_Error('invalid', 'Nhập ít nhất giờ vào hoặc giờ ra (hoặc chọn nghỉ phép).');
            }
            $data['leave_type'] = '';
            $data['check_in_at'] = $in ?: null;
            $data['check_out_at'] = $out ?: null;

            // Cùng một nhân viên không thể có 2 lượt chấm công chồng giờ (ví dụ ca sáng 7–14 và ca giữa 10–18
            // cùng đủ giờ) — nếu không giờ làm bị tính hai lần.
            if ($in) {
                $clash = self::record_time_conflict($uid, $sid, $date, self::ts($in), $out ? self::ts($out) : self::window($shift, $date)['end']);
                if ($clash) {
                    return new WP_Error('overlap', 'Giờ này chồng với lượt ' . self::shift_label((int) $clash['shift_id'], $clash['work_date'])
                        . ' của cùng nhân viên (' . mysql2date('H:i', $clash['check_in_at']) . ' – ' . ($clash['check_out_at'] ? mysql2date('H:i', $clash['check_out_at']) : '…')
                        . '). Sửa giờ ra của lượt đó trước, hoặc chỉnh giờ vào / ra cho không trùng.');
                }
            }
        }

        $old = self::find_record($uid, $sid, $date);
        $t = TGS_CC_DB::t('records');
        if ($old) {
            if (array_key_exists('check_in_at', $data) && $data['check_in_at'] !== $old['check_in_at']) {
                $data['in_source'] = $data['check_in_at'] ? 'admin' : '';
            }
            if (array_key_exists('check_out_at', $data) && $data['check_out_at'] !== $old['check_out_at']) {
                $data['out_source'] = $data['check_out_at'] ? 'admin' : '';
            }
            $wpdb->update($t, $data, array('record_id' => (int) $old['record_id']));
            $rid = (int) $old['record_id'];
            $action = 'admin_edit';
        } else {
            $data = array_merge($data, array(
                'user_id' => $uid,
                'shift_id' => $sid,
                'work_date' => $date,
                'in_source' => !empty($data['check_in_at']) ? 'admin' : '',
                'out_source' => !empty($data['check_out_at']) ? 'admin' : '',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ));
            $wpdb->insert($t, $data);
            $rid = (int) $wpdb->insert_id;
            $action = 'admin_create';
        }
        $rec = self::recompute($rid);
        self::log($rid, $uid, $action, array(
            'truoc' => $old ? array('vao' => $old['check_in_at'], 'ra' => $old['check_out_at'], 'nghi' => $old['leave_type']) : null,
            'sau' => array('vao' => $rec['check_in_at'], 'ra' => $rec['check_out_at'], 'nghi' => $rec['leave_type']),
            'ghi_chu' => $note,
        ));
        return self::record_public($rec);
    }

    /** Lượt chấm công khác của nhân viên có khoảng [vào, ra) chồng lên [$from, $to) → dòng đó, không có → null */
    private static function record_time_conflict($user_id, $shift_id, $work_date, $from, $to)
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . TGS_CC_DB::t('records') . " WHERE user_id = %d AND work_date BETWEEN %s AND %s AND check_in_at IS NOT NULL AND leave_type = ''",
            (int) $user_id,
            self::shift_day($work_date, -1),
            self::shift_day($work_date, 1)
        ), ARRAY_A);
        foreach ($rows as $r) {
            if ((int) $r['shift_id'] === (int) $shift_id && $r['work_date'] === $work_date) {
                continue;
            }
            $o_from = self::ts($r['check_in_at']);
            if ($r['check_out_at']) {
                $o_to = self::ts($r['check_out_at']);
            } else {
                $sh = self::shift((int) $r['shift_id']);
                $o_to = $sh ? self::window($sh, $r['work_date'])['end'] : $o_from;
            }
            if ($from < $o_to && $o_from < $to) {
                return $r;
            }
        }
        return null;
    }

    public static function admin_delete_record($record_id)
    {
        global $wpdb;
        $rec = self::record($record_id);
        if (!$rec) {
            return new WP_Error('not_found', 'Không tìm thấy dòng chấm công.');
        }
        if (self::is_locked($rec['work_date'])) {
            return self::lock_error($rec['work_date']);
        }
        $wpdb->delete(TGS_CC_DB::t('records'), array('record_id' => (int) $record_id));
        self::log($record_id, $rec['user_id'], 'admin_delete', array('vao' => $rec['check_in_at'], 'ra' => $rec['check_out_at'], 'nghi' => $rec['leave_type']));
        return true;
    }

    /**
     * "Duyệt đủ công theo lịch": mọi ca đã xếp lịch, ĐÃ QUA giờ ra mà chưa có
     * dữ liệu chấm → điền vào = giờ vào ca, ra = giờ ra ca. Dùng cho shop chưa
     * quen chấm trên POS hoặc ngày máy hỏng.
     */
    public static function admin_fill_from_schedule($from, $to, $user_ids = array())
    {
        global $wpdb;
        $ids = self::employee_ids($user_ids);
        if (!$ids) {
            return 0;
        }
        $now = time();
        $sched = self::schedules_between($from, $to, $ids);
        $count = 0;
        foreach ($sched as $s) {
            $shift = self::shift($s['shift_id']);
            if (!$shift || self::is_locked($s['work_date'])) {
                continue;
            }
            $w = self::window($shift, $s['work_date']);
            if ($w['end'] > $now || self::find_record($s['user_id'], $s['shift_id'], $s['work_date'])) {
                continue;
            }
            // Lịch cũ có 2 ca chồng giờ cho cùng người → không điền ca thứ hai (tránh tính giờ 2 lần)
            if (self::record_time_conflict($s['user_id'], $s['shift_id'], $s['work_date'], $w['start'], $w['end'])) {
                continue;
            }
            $wpdb->insert(TGS_CC_DB::t('records'), array(
                'user_id' => (int) $s['user_id'],
                'shift_id' => (int) $s['shift_id'],
                'work_date' => $s['work_date'],
                'check_in_at' => wp_date('Y-m-d H:i:s', $w['start']),
                'check_out_at' => wp_date('Y-m-d H:i:s', $w['end']),
                'in_source' => 'auto',
                'out_source' => 'auto',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ));
            $rid = (int) $wpdb->insert_id;
            if ($rid) {
                self::recompute($rid);
                self::log($rid, $s['user_id'], 'auto_fill');
                $count++;
            }
        }
        return $count;
    }

    /* ═══════════════════════════ LỊCH LÀM VIỆC ═══════════════════════════ */

    public static function schedules_between($from, $to, $user_ids = array())
    {
        global $wpdb;
        $t = TGS_CC_DB::t('schedules');
        $sql = $wpdb->prepare("SELECT * FROM {$t} WHERE work_date BETWEEN %s AND %s", $from, $to);
        $user_ids = array_filter(array_map('intval', (array) $user_ids));
        if ($user_ids) {
            $sql .= ' AND user_id IN (' . implode(',', $user_ids) . ')';
        }
        return (array) $wpdb->get_results($sql . ' ORDER BY work_date, user_id', ARRAY_A);
    }

    /** Đặt lại toàn bộ ca của một ô (1 nhân viên × 1 ngày) */
    public static function schedule_set_cell($user_id, $work_date, $shift_ids)
    {
        global $wpdb;
        $t = TGS_CC_DB::t('schedules');
        $user_id = (int) $user_id;
        if (!in_array($user_id, self::employee_ids(), true)) {
            return new WP_Error('not_employee', 'Tài khoản này không thuộc danh sách nhân viên chấm công của cửa hàng.');
        }
        if (self::is_locked($work_date)) {
            return self::lock_error($work_date);
        }
        $shift_ids = array_values(array_unique(array_filter(array_map('intval', (array) $shift_ids))));
        $err = self::shift_set_conflict($shift_ids, $work_date);
        if ($err) {
            return $err;
        }
        foreach ($shift_ids as $sid) {
            $c = self::schedule_conflict($user_id, $sid, $work_date, array($work_date));
            if ($c) {
                return new WP_Error('overlap', self::shift_label($sid, $work_date) . ' chồng giờ với ' . self::shift_label($c[0], $c[1])
                    . ' đã xếp cho nhân viên này. Bỏ một trong hai ca.');
            }
        }
        $current = $wpdb->get_col($wpdb->prepare("SELECT shift_id FROM {$t} WHERE user_id = %d AND work_date = %s", $user_id, $work_date));
        $current = array_map('intval', $current);

        foreach (array_diff($current, $shift_ids) as $sid) {
            $wpdb->delete($t, array('user_id' => $user_id, 'work_date' => $work_date, 'shift_id' => $sid));
        }
        foreach (array_diff($shift_ids, $current) as $sid) {
            self::schedule_insert($user_id, $sid, $work_date);
        }
        return $shift_ids;
    }

    /** Số lượt bỏ qua ở lần xếp lịch gần nhất vì chồng giờ với ca đã xếp của cùng nhân viên */
    public static $schedule_skipped = 0;

    private static function schedule_insert($user_id, $shift_id, $work_date)
    {
        global $wpdb;
        $t = TGS_CC_DB::t('schedules');
        if (self::schedule_conflict($user_id, $shift_id, $work_date)) {
            self::$schedule_skipped++;
            return 0;
        }
        return $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$t} (user_id, shift_id, work_date, note, created_by, created_at) VALUES (%d, %d, %s, '', %d, %s)",
            (int) $user_id,
            (int) $shift_id,
            $work_date,
            get_current_user_id(),
            current_time('mysql')
        ));
    }

    /**
     * Xếp lịch hàng loạt: nhiều nhân viên × nhiều ca, lặp theo thứ trong tuần.
     * $weekdays: mảng số theo date('w') — 0 = CN, 1 = T2 … 6 = T7.
     * $replace = true: xoá lịch cũ của các nhân viên đó trong khoảng ngày trước khi xếp.
     */
    public static function schedule_bulk($user_ids, $shift_ids, $from, $to, $weekdays, $replace = false)
    {
        global $wpdb;
        $user_ids = self::employee_ids($user_ids);
        $shift_ids = array_values(array_filter(array_map('intval', (array) $shift_ids)));
        $weekdays = array_map('intval', (array) $weekdays);
        if (!$user_ids || !$shift_ids || !$weekdays) {
            return new WP_Error('invalid', 'Chọn ít nhất một nhân viên, một ca và một thứ trong tuần.');
        }
        // Mỗi nhân viên được gán TẤT CẢ ca đã chọn → các ca chọn không được chồng giờ nhau
        $err = self::shift_set_conflict($shift_ids);
        if ($err) {
            return $err;
        }
        self::$schedule_skipped = 0;
        $days = self::date_range($from, $to);
        if (count($days) > 93) {
            return new WP_Error('invalid', 'Mỗi lần xếp tối đa 93 ngày.');
        }
        $locked = self::locked_between($from, $to);
        if ($locked) {
            return self::lock_error($locked[0] . '-01');
        }
        if ($replace) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . TGS_CC_DB::t('schedules') . ' WHERE work_date BETWEEN %s AND %s AND user_id IN (' . implode(',', $user_ids) . ')',
                $from,
                $to
            ));
        }
        $n = 0;
        foreach ($days as $d) {
            if (!in_array((int) wp_date('w', self::ts($d . ' 12:00:00')), $weekdays, true)) {
                continue;
            }
            foreach ($user_ids as $uid) {
                foreach ($shift_ids as $sid) {
                    $n += (int) self::schedule_insert($uid, $sid, $d);
                }
            }
        }
        return $n;
    }

    /**
     * Chép lịch của tuần nguồn sang tuần đích (7 ngày bắt đầu từ thứ Hai).
     * Tuần nguồn trống → báo lỗi, KHÔNG xoá tuần đích (trước đây bấm nhầm là mất trắng lịch).
     */
    public static function schedule_copy_week($src_monday, $dst_monday, $user_ids = array(), $replace = true)
    {
        global $wpdb;
        $ids = self::employee_ids($user_ids);
        if (!$ids) {
            return new WP_Error('invalid', 'Không có nhân viên nào để chép lịch.');
        }
        $src_end = wp_date('Y-m-d', self::ts($src_monday . ' 12:00:00') + 6 * DAY_IN_SECONDS);
        $dst_end = wp_date('Y-m-d', self::ts($dst_monday . ' 12:00:00') + 6 * DAY_IN_SECONDS);
        $locked = self::locked_between($dst_monday, $dst_end);
        if ($locked) {
            return self::lock_error($locked[0] . '-01');
        }
        $src = self::schedules_between($src_monday, $src_end, $ids);
        if (!$src) {
            return new WP_Error('empty', 'Tuần ' . mysql2date('d/m', $src_monday) . ' – ' . mysql2date('d/m/Y', $src_end) . ' chưa có lịch nào để chép. Lịch tuần đang xem được giữ nguyên.');
        }
        if ($replace) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . TGS_CC_DB::t('schedules') . ' WHERE work_date BETWEEN %s AND %s AND user_id IN (' . implode(',', $ids) . ')',
                $dst_monday,
                $dst_end
            ));
        }
        $offset = (int) round((self::ts($dst_monday . ' 12:00:00') - self::ts($src_monday . ' 12:00:00')) / DAY_IN_SECONDS);
        self::$schedule_skipped = 0;
        $n = 0;
        foreach ($src as $s) {
            $d = wp_date('Y-m-d', self::ts($s['work_date'] . ' 12:00:00') + $offset * DAY_IN_SECONDS);
            $n += (int) self::schedule_insert($s['user_id'], $s['shift_id'], $d);
        }
        return $n;
    }

    public static function schedule_clear($from, $to, $user_ids = array())
    {
        global $wpdb;
        $ids = self::employee_ids($user_ids);
        if (!$ids) {
            return 0;
        }
        $locked = self::locked_between($from, $to);
        if ($locked) {
            return self::lock_error($locked[0] . '-01');
        }
        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . TGS_CC_DB::t('schedules') . ' WHERE work_date BETWEEN %s AND %s AND user_id IN (' . implode(',', $ids) . ')',
            $from,
            $to
        ));
    }

    /* ═══════════════════════════ BẢNG CHẤM CÔNG ═══════════════════════════ */

    /**
     * Dữ liệu bảng: mỗi ô (nhân viên × ngày) là danh sách ca — ca có lịch,
     * ca có chấm công, hoặc cả hai. Báo cáo cũng dựng từ đây.
     */
    public static function board($from, $to, $user_ids = array(), $shift_id = 0)
    {
        global $wpdb;
        $now = time();
        $employees = self::employees();
        $filter = array_filter(array_map('intval', (array) $user_ids));
        if ($filter) {
            $employees = array_values(array_filter($employees, function ($e) use ($filter) {
                return in_array($e['id'], $filter, true);
            }));
        }
        $ids = wp_list_pluck($employees, 'id');
        $shifts = self::shifts(false);
        $days = self::date_range($from, $to);
        $cells = array();

        if ($ids) {
            foreach (self::schedules_between($from, $to, $ids) as $s) {
                if ($shift_id && (int) $s['shift_id'] !== (int) $shift_id) {
                    continue;
                }
                $k = $s['user_id'] . '|' . $s['work_date'];
                $cells[$k][(int) $s['shift_id']] = array('shift_id' => (int) $s['shift_id'], 'scheduled' => true, 'record' => null);
            }

            $sql = $wpdb->prepare('SELECT * FROM ' . TGS_CC_DB::t('records') . ' WHERE work_date BETWEEN %s AND %s', $from, $to)
                . ' AND user_id IN (' . implode(',', $ids) . ')';
            if ($shift_id) {
                $sql .= $wpdb->prepare(' AND shift_id = %d', $shift_id);
            }
            foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $r) {
                $k = $r['user_id'] . '|' . $r['work_date'];
                $sid = (int) $r['shift_id'];
                if (!isset($cells[$k][$sid])) {
                    $cells[$k][$sid] = array('shift_id' => $sid, 'scheduled' => false, 'record' => null);
                }
                $cells[$k][$sid]['record'] = self::record_public($r, $now);
            }
        }

        $labels = self::status_labels();
        foreach ($cells as $k => $list) {
            list(, $date) = explode('|', $k);
            foreach ($list as $sid => $e) {
                if ($e['record']) {
                    $st = $e['record']['status'];
                } else {
                    // Có lịch, chưa chấm: chưa tới giờ → "Chưa tới ca"; ca đang diễn ra → "Chưa vào ca"
                    // (vẫn chấm vào được, chưa phải vắng); hết ca rồi mới là "Chưa chấm công" (vắng).
                    $st = 'chua_cham';
                    if (isset($shifts[$sid])) {
                        $w = self::window($shifts[$sid], $date);
                        $st = $now < $w['start'] ? 'lich' : ($now < $w['end'] ? 'chua_vao' : 'chua_cham');
                    }
                }
                $cells[$k][$sid]['status'] = $st;
                $cells[$k][$sid]['status_label'] = $labels[$st][0];
                $cells[$k][$sid]['status_tone'] = $labels[$st][1];
            }
            // Xếp ca trong ô theo giờ vào
            uasort($cells[$k], function ($a, $b) use ($shifts) {
                $sa = isset($shifts[$a['shift_id']]) ? $shifts[$a['shift_id']]['start'] : '';
                $sb = isset($shifts[$b['shift_id']]) ? $shifts[$b['shift_id']]['start'] : '';
                return strcmp($sa, $sb);
            });
            $cells[$k] = array_values($cells[$k]);
        }

        return array(
            'from' => $from,
            'to' => $to,
            'today' => self::today(),
            'days' => $days,
            'employees' => $employees,
            'shifts' => array_values($shifts),
            'cells' => (object) $cells,
            'locked' => self::locked_between($from, $to),
        );
    }

    /* ═══════════════════════════ BÁO CÁO ═══════════════════════════ */

    private static function empty_totals()
    {
        return array(
            'so_ca_lich' => 0,
            'so_ca_di_lam' => 0,
            'ngay_cong' => 0,
            'tong_cong' => 0.0,
            'gio_lam' => 0.0,
            'gio_lam_them' => 0.0,
            'muon_lan' => 0,
            'muon_phut' => 0,
            'som_lan' => 0,
            'som_phut' => 0,
            'cham_thieu' => 0,
            'nghi_co_phep' => 0,
            'nghi_khong_phep' => 0,
            'vang' => 0,
            'dang_lam' => 0,
        );
    }

    /** Cộng dồn một ca vào tổng của nhân viên */
    private static function add_entry(&$t, $e)
    {
        if (!empty($e['scheduled'])) {
            $t['so_ca_lich']++;
        }
        $r = $e['record'];
        switch ($e['status']) {
            case 'dung_gio':
            case 'di_muon':
            case 've_som':
            case 'muon_som':
                $t['so_ca_di_lam']++;
                $t['tong_cong'] += $r['work_value'];
                $t['gio_lam'] += $r['worked'] / 60;
                $t['gio_lam_them'] += $r['overtime'] / 60;
                break;
            case 'thieu':
                $t['cham_thieu']++;
                break;
            case 'dang_lam':
                $t['dang_lam']++;
                break;
            case 'nghi_co_phep':
                $t['nghi_co_phep']++;
                break;
            case 'nghi_khong_phep':
                $t['nghi_khong_phep']++;
                break;
            case 'chua_cham':
                $t['vang']++;
                break;
        }
        if ($r && $r['late'] > 0) {
            $t['muon_lan']++;
            $t['muon_phut'] += $r['late'];
        }
        if ($r && $r['early'] > 0) {
            $t['som_lan']++;
            $t['som_phut'] += $r['early'];
        }
    }

    private static function round_totals($t)
    {
        $t['tong_cong'] = round($t['tong_cong'], 2);
        $t['gio_lam'] = round($t['gio_lam'], 2);
        $t['gio_lam_them'] = round($t['gio_lam_them'], 2);
        return $t;
    }

    /** Tổng hợp theo nhân viên */
    public static function report_summary($from, $to, $user_ids = array(), $shift_id = 0)
    {
        $b = self::board($from, $to, $user_ids, $shift_id);
        $cells = (array) $b['cells'];
        $rows = array();
        $grand = self::empty_totals();
        foreach ($b['employees'] as $emp) {
            $t = self::empty_totals();
            foreach ($b['days'] as $d) {
                $k = $emp['id'] . '|' . $d;
                if (empty($cells[$k])) {
                    continue;
                }
                $day_value = 0;
                foreach ($cells[$k] as $e) {
                    self::add_entry($t, $e);
                    if ($e['record']) {
                        $day_value += $e['record']['work_value'];
                    }
                }
                if ($day_value > 0) {
                    $t['ngay_cong']++;
                }
            }
            foreach ($t as $kk => $v) {
                $grand[$kk] += $v;
            }
            $rows[] = array_merge(array('user_id' => $emp['id'], 'name' => $emp['name'], 'login' => $emp['login']), self::round_totals($t));
        }
        return array(
            'from' => $from,
            'to' => $to,
            'standard_days' => (int) TGS_CC_Settings::get('standard_days'),
            'rows' => $rows,
            'total' => self::round_totals($grand),
        );
    }

    /** Chi tiết từng ca: giờ vào/ra, muộn, sớm, giờ làm, công */
    public static function report_detail($from, $to, $user_ids = array(), $shift_id = 0)
    {
        $b = self::board($from, $to, $user_ids, $shift_id);
        $cells = (array) $b['cells'];
        $shifts = array();
        foreach ($b['shifts'] as $s) {
            $shifts[$s['id']] = $s;
        }
        $rows = array();
        foreach ($b['days'] as $d) {
            foreach ($b['employees'] as $emp) {
                $k = $emp['id'] . '|' . $d;
                if (empty($cells[$k])) {
                    continue;
                }
                foreach ($cells[$k] as $e) {
                    if ($e['status'] === 'lich' || $e['status'] === 'chua_vao') {
                        continue; // ca chưa tới / đang diễn ra mà chưa chấm: chưa có gì để báo cáo
                    }
                    $s = isset($shifts[$e['shift_id']]) ? $shifts[$e['shift_id']] : null;
                    $r = $e['record'];
                    $rows[] = array(
                        'date' => $d,
                        'user_id' => $emp['id'],
                        'name' => $emp['name'],
                        'login' => $emp['login'],
                        'shift' => $s ? $s['name'] : ('#' . $e['shift_id']),
                        'shift_time' => $s ? ($s['start'] . ' - ' . $s['end']) : '',
                        'scheduled' => !empty($e['scheduled']),
                        'in' => $r ? $r['in_full'] : '',
                        'out' => $r ? $r['out_full'] : '',
                        'late' => $r ? $r['late'] : 0,
                        'early' => $r ? $r['early'] : 0,
                        'worked' => $r ? round($r['worked'] / 60, 2) : 0,
                        'overtime' => $r ? round($r['overtime'] / 60, 2) : 0,
                        'work_value' => $r ? $r['work_value'] : 0,
                        'status' => $e['status'],
                        'status_label' => $e['status_label'],
                        'status_tone' => $e['status_tone'],
                        'source' => $r ? trim($r['in_source'] . ($r['out_source'] && $r['out_source'] !== $r['in_source'] ? '/' . $r['out_source'] : '')) : '',
                        'note' => $r ? $r['note'] : '',
                    );
                }
            }
        }
        return array('from' => $from, 'to' => $to, 'rows' => $rows);
    }

    /**
     * Bảng công tháng (mẫu in cuối tháng): mỗi nhân viên một dòng, mỗi ngày một cột ký hiệu.
     *   X = đủ 1 công · X/2 = nửa công · số = tổng công khi làm nhiều ca (vd 1,5 / 2)
     *   P = nghỉ có phép · KP = nghỉ không phép · V = vắng (có lịch, không chấm)
     *   T = chấm công thiếu (có vào không có ra) · Đ = đang trong ca
     *   flag "late" = trong ngày có đi muộn / về sớm (tô nền vàng)
     */
    public static function report_month($month, $user_ids = array())
    {
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            $month = wp_date('Y-m');
        }
        $from = $month . '-01';
        $to = wp_date('Y-m-t', self::ts($from . ' 12:00:00'));
        $b = self::board($from, $to, $user_ids);
        $cells = (array) $b['cells'];
        $rows = array();
        foreach ($b['employees'] as $emp) {
            $t = self::empty_totals();
            $days = array();
            foreach ($b['days'] as $d) {
                $k = $emp['id'] . '|' . $d;
                $list = !empty($cells[$k]) ? $cells[$k] : array();
                $value = 0;
                $flags = array();
                $late = false;
                foreach ($list as $e) {
                    self::add_entry($t, $e);
                    if ($e['record']) {
                        $value += $e['record']['work_value'];
                        if ($e['record']['late'] > 0 || $e['record']['early'] > 0) {
                            $late = true;
                        }
                    }
                    $flags[$e['status']] = true;
                }
                if ($value > 0) {
                    $t['ngay_cong']++;
                }
                $sym = '';
                $tone = '';
                if ($value > 0) {
                    $sym = abs($value - 1) < 0.001 ? 'X' : (abs($value - 0.5) < 0.001 ? 'X/2' : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ','));
                    $tone = $late ? 'late' : 'ok';
                    if (!empty($flags['thieu'])) {
                        $tone = 'warn';
                    }
                } elseif (!empty($flags['thieu'])) {
                    $sym = 'T';
                    $tone = 'warn';
                } elseif (!empty($flags['nghi_co_phep'])) {
                    $sym = 'P';
                    $tone = 'leave';
                } elseif (!empty($flags['nghi_khong_phep'])) {
                    $sym = 'KP';
                    $tone = 'bad';
                } elseif (!empty($flags['chua_cham'])) {
                    $sym = 'V';
                    $tone = 'bad';
                } elseif (!empty($flags['dang_lam'])) {
                    $sym = 'Đ';
                    $tone = 'info';
                } elseif (!empty($flags['lich']) || !empty($flags['chua_vao'])) {
                    $sym = '';
                    $tone = 'plan';
                }
                $days[$d] = array('sym' => $sym, 'tone' => $tone, 'value' => round($value, 2));
            }
            $rows[] = array_merge(
                array('user_id' => $emp['id'], 'name' => $emp['name'], 'login' => $emp['login'], 'days' => $days),
                self::round_totals($t)
            );
        }

        $s = TGS_CC_Settings::all();
        return array(
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'days' => $b['days'],
            'rows' => $rows,
            'standard_days' => (int) $s['standard_days'],
            'shop_name' => get_bloginfo('name'),
            'report_title' => $s['report_title'],
            'signers' => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $s['signers'])))),
            'lock' => self::lock_info($month),
            'can_lock' => self::can_lock_month($month),
            'can_unlock' => self::can_unlock(),
        );
    }

    /* ═══════════════════════════ CHẤM CÔNG TRÊN POS ═══════════════════════════ */

    /**
     * Trạng thái chấm công hiện tại của một nhân viên:
     *   mode = 'out'  đang có ca mở (đã vào, chưa ra) → nút "Chấm ra"
     *   mode = 'in'   danh sách ca có thể chấm vào lúc này
     *   mode = 'none' không có ca nào trong giờ cho phép
     */
    public static function pos_state($user_id)
    {
        global $wpdb;
        $user_id = (int) $user_id;
        $now = time();
        $today = wp_date('Y-m-d', $now);
        $yday = wp_date('Y-m-d', $now - DAY_IN_SECONDS);
        $settings = TGS_CC_Settings::all();
        $all_shifts = self::shifts(false);

        $recs = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . TGS_CC_DB::t('records') . ' WHERE user_id = %d AND work_date IN (%s, %s) ORDER BY check_in_at DESC',
            $user_id,
            $yday,
            $today
        ), ARRAY_A);

        $open = null;
        $taken = array();
        foreach ($recs as $r) {
            $taken[$r['shift_id'] . '|' . $r['work_date']] = true;
            if ($open || empty($r['check_in_at']) || !empty($r['check_out_at']) || !empty($r['leave_type'])) {
                continue;
            }
            $sh = isset($all_shifts[(int) $r['shift_id']]) ? $all_shifts[(int) $r['shift_id']] : null;
            if ($sh && $now <= self::window($sh, $r['work_date'])['close']) {
                $open = $r;
            }
        }

        $state = array(
            'user' => array('id' => $user_id, 'name' => self::user_name($user_id)),
            'now' => wp_date('H:i', $now),
            'now_full' => wp_date('l, d/m/Y', $now),
            'mode' => 'none',
            'open' => null,
            'candidates' => array(),
            'history' => array(),
            'message' => '',
        );

        if ($open) {
            $sh = $all_shifts[(int) $open['shift_id']];
            $w = self::window($sh, $open['work_date']);
            $early = (int) floor(($w['end'] - $now) / 60);
            $state['mode'] = 'out';
            $state['open'] = array(
                'record_id' => (int) $open['record_id'],
                'shift' => $sh['name'],
                'shift_time' => $sh['start'] . ' - ' . $sh['end'],
                'work_date' => $open['work_date'],
                'check_in' => wp_date('H:i', self::ts($open['check_in_at'])),
                'early_minutes' => $early > (int) $settings['early_grace'] ? $early : 0,
            );
        } else {
            $pairs = array();
            $scheduled = array();
            foreach ((array) $wpdb->get_results($wpdb->prepare(
                'SELECT shift_id, work_date FROM ' . TGS_CC_DB::t('schedules') . ' WHERE user_id = %d AND work_date IN (%s, %s)',
                $user_id,
                $yday,
                $today
            ), ARRAY_A) as $s) {
                $k = $s['shift_id'] . '|' . $s['work_date'];
                $pairs[$k] = array((int) $s['shift_id'], $s['work_date']);
                $scheduled[$k] = true;
            }
            if (empty($settings['require_schedule'])) {
                foreach ($all_shifts as $sh) {
                    if (!$sh['status']) {
                        continue;
                    }
                    $pairs[$sh['id'] . '|' . $today] = array($sh['id'], $today);
                    if ($sh['overnight']) {
                        $pairs[$sh['id'] . '|' . $yday] = array($sh['id'], $yday);
                    }
                }
            }

            foreach ($pairs as $k => $p) {
                if (isset($taken[$k]) || !isset($all_shifts[$p[0]])) {
                    continue;
                }
                $sh = $all_shifts[$p[0]];
                if (!$sh['status'] && empty($scheduled[$k])) {
                    continue;
                }
                $w = self::window($sh, $p[1]);
                if ($now < $w['open'] || $now >= $w['end']) {
                    continue;
                }
                $late = (int) floor(($now - $w['start']) / 60);
                $state['candidates'][] = array(
                    'shift_id' => $sh['id'],
                    'work_date' => $p[1],
                    'name' => $sh['name'],
                    'time' => $sh['start'] . ' - ' . $sh['end'],
                    'color' => $sh['color'],
                    'scheduled' => !empty($scheduled[$k]),
                    'late_minutes' => $late > (int) $settings['late_grace'] ? $late : 0,
                    '_dist' => abs($now - $w['start']),
                );
            }
            usort($state['candidates'], function ($a, $b) {
                if ($a['scheduled'] !== $b['scheduled']) {
                    return $a['scheduled'] ? -1 : 1;
                }
                return $a['_dist'] - $b['_dist'];
            });
            foreach ($state['candidates'] as &$c) {
                unset($c['_dist']);
            }
            unset($c);

            if ($state['candidates']) {
                $state['mode'] = 'in';
            } else {
                $state['message'] = !empty($settings['require_schedule'])
                    ? 'Bạn không có ca nào được xếp lịch trong khung giờ được phép chấm công lúc này.'
                    : 'Hiện không có ca nào đang trong khung giờ được phép chấm công.';
            }
        }

        foreach ($recs as $r) {
            if ($r['work_date'] !== $today && !($open && (int) $open['record_id'] === (int) $r['record_id'])) {
                continue;
            }
            $pub = self::record_public($r, $now);
            $sh = isset($all_shifts[(int) $r['shift_id']]) ? $all_shifts[(int) $r['shift_id']] : null;
            $state['history'][] = array(
                'shift' => $sh ? $sh['name'] : '',
                'in' => $pub['in'],
                'out' => $pub['out'],
                'status_label' => $pub['status_label'],
                'status_tone' => $pub['status_tone'],
            );
        }
        return $state;
    }

    /**
     * Người đang đăng nhập POS có được điểm danh hộ đồng nghiệp không:
     * phải ĐANG TRONG CA (đã chấm vào, chưa ra) — hoặc là quản lý chấm công.
     */
    public static function team_can_act($actor_id)
    {
        if (!TGS_CC_Settings::get('allow_other_account')) {
            return new WP_Error('off', 'Cửa hàng đã tắt điểm danh nhân viên khác trong ca.');
        }
        if (function_exists('tgs_cc_user_can_manage') && tgs_cc_user_can_manage($actor_id)) {
            return true;
        }
        if (!in_array((int) $actor_id, self::employee_ids(), true)) {
            return new WP_Error('not_employee', 'Tài khoản đang đăng nhập không thuộc danh sách nhân viên chấm công.');
        }
        $st = self::pos_state($actor_id);
        if ($st['mode'] !== 'out') {
            return new WP_Error('not_in_shift', 'Bạn cần chấm VÀO ca của mình trước, rồi mới điểm danh được cho nhân viên khác trong ca.');
        }
        return true;
    }

    /**
     * Đồng nghiệp TRONG CA lúc này (trừ chính người đang đăng nhập):
     *   - có lịch ở một ca đang trong khung chấm công (từ giờ cho chấm vào tới hết giờ cho chấm ra), hoặc
     *   - đang có lượt chấm vào chưa ra.
     * Ca lồng nhau (ca sáng / giữa / chiều gối nhau) đều tính — ai đang trong khung ca nào cũng hiện.
     * Shop không bắt buộc xếp lịch thì liệt kê thêm các nhân viên còn lại ở nhóm "Nhân viên khác".
     *
     * Trả về: members = [{id, name, mode, label, tone, shifts}], others = [{id, name}]
     */
    public static function team_state($actor_id)
    {
        global $wpdb;
        $now = time();
        $today = wp_date('Y-m-d', $now);
        $yday = wp_date('Y-m-d', $now - DAY_IN_SECONDS);
        $all_shifts = self::shifts(false);
        $emp = self::employee_ids();
        $in_shift = array(); // uid => [tên ca]

        foreach ((array) $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, shift_id, work_date FROM ' . TGS_CC_DB::t('schedules') . ' WHERE work_date IN (%s, %s)',
            $yday,
            $today
        ), ARRAY_A) as $s) {
            $sh = isset($all_shifts[(int) $s['shift_id']]) ? $all_shifts[(int) $s['shift_id']] : null;
            if (!$sh) {
                continue;
            }
            $w = self::window($sh, $s['work_date']);
            if ($now >= $w['open'] && $now <= $w['close']) {
                $in_shift[(int) $s['user_id']][$sh['id']] = $sh['name'] . ' ' . $sh['start'] . '–' . $sh['end'];
            }
        }
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, shift_id, work_date FROM ' . TGS_CC_DB::t('records') . " WHERE work_date IN (%s, %s) AND check_in_at IS NOT NULL AND check_out_at IS NULL AND leave_type = ''",
            $yday,
            $today
        ), ARRAY_A) as $r) {
            $sh = isset($all_shifts[(int) $r['shift_id']]) ? $all_shifts[(int) $r['shift_id']] : null;
            if ($sh && $now <= self::window($sh, $r['work_date'])['close']) {
                $in_shift[(int) $r['user_id']][$sh['id']] = $sh['name'] . ' ' . $sh['start'] . '–' . $sh['end'];
            }
        }

        $members = array();
        foreach ($in_shift as $uid => $names) {
            if ($uid === (int) $actor_id || !in_array($uid, $emp, true)) {
                continue;
            }
            $st = self::pos_state($uid);
            if ($st['mode'] === 'out') {
                $label = 'Đang trong ' . $st['open']['shift'] . ' từ ' . $st['open']['check_in'];
                $tone = 'success';
            } elseif ($st['mode'] === 'in') {
                $c = $st['candidates'][0];
                $label = 'Chưa vào ' . $c['name'] . ($c['late_minutes'] > 0 ? ' — đã muộn ' . $c['late_minutes'] . ' phút' : '');
                $tone = $c['late_minutes'] > 0 ? 'warning' : 'primary';
            } else {
                $label = 'Đã xong ca / không còn ca để chấm';
                $tone = 'info';
            }
            $members[] = array(
                'id' => $uid,
                'name' => $st['user']['name'],
                'mode' => $st['mode'],
                'label' => $label,
                'tone' => $tone,
                'shifts' => array_values($names),
            );
        }
        // Chưa vào ca lên đầu (cần điểm danh nhất), rồi đang trong ca, cuối cùng đã xong
        $rank = array('in' => 0, 'out' => 1, 'none' => 2);
        usort($members, function ($a, $b) use ($rank) {
            return $rank[$a['mode']] - $rank[$b['mode']] ?: strcmp($a['name'], $b['name']);
        });

        $others = array();
        if (empty(TGS_CC_Settings::get('require_schedule'))) {
            foreach ($emp as $uid) {
                if ($uid !== (int) $actor_id && !isset($in_shift[$uid])) {
                    $others[] = array('id' => $uid, 'name' => self::user_name($uid));
                }
            }
            usort($others, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
        }

        return array('members' => $members, 'others' => $others);
    }

    /** $target có phải đồng nghiệp được phép điểm danh hộ (nằm trong team_state của $actor) không */
    public static function team_has($actor_id, $target_id)
    {
        $t = self::team_state($actor_id);
        foreach (array_merge($t['members'], $t['others']) as $m) {
            if ((int) $m['id'] === (int) $target_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Chấm vào / chấm ra. Luôn kiểm tra lại bằng pos_state() ngay lúc bấm —
     * không tin dữ liệu từ trình duyệt (màn có thể mở từ trước, giờ đã khác).
     */
    public static function punch($user_id, $type, $args, $source = 'pos')
    {
        global $wpdb;
        // Khoá theo nhân viên: bấm cùng lúc từ 2 máy thì lượt sau phải chờ lượt trước xong
        // rồi mới đọc lại trạng thái → không thể mở 2 ca một lúc.
        $lock = 'tgs_cc_punch_' . get_current_blog_id() . '_' . (int) $user_id;
        if (!(int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock))) {
            return new WP_Error('busy', 'Đang xử lý một lượt chấm công khác của bạn, thử lại sau vài giây.');
        }
        try {
            return self::punch_locked($user_id, $type, $args, $source);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function punch_locked($user_id, $type, $args, $source)
    {
        global $wpdb;
        $state = self::pos_state($user_id);
        $now_mysql = current_time('mysql');
        $ip = self::client_ip();
        $t = TGS_CC_DB::t('records');

        if ($type === 'in') {
            if ($state['mode'] === 'out') {
                return new WP_Error('open', 'Bạn đang trong ' . $state['open']['shift'] . ' (vào lúc ' . $state['open']['check_in'] . '). Hãy chấm ra trước.');
            }
            $sid = (int) (isset($args['shift_id']) ? $args['shift_id'] : 0);
            $date = isset($args['work_date']) ? (string) $args['work_date'] : '';
            $ok = false;
            foreach ($state['candidates'] as $c) {
                if ($c['shift_id'] === $sid && $c['work_date'] === $date) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return new WP_Error('invalid', 'Ca này không còn trong khung giờ được chấm vào. Tải lại và thử lại.');
            }
            if (self::is_locked($date)) {
                return self::lock_error($date);
            }
            $inserted = $wpdb->insert($t, array(
                'user_id' => (int) $user_id,
                'shift_id' => $sid,
                'work_date' => $date,
                'check_in_at' => $now_mysql,
                'in_source' => $source,
                'in_ip' => $ip,
                'created_by' => get_current_user_id(),
                'created_at' => $now_mysql,
                'updated_at' => $now_mysql,
            ));
            if (!$inserted) {
                return new WP_Error('dup', 'Ca này đã được chấm vào rồi.');
            }
            $rid = (int) $wpdb->insert_id;
            $rec = self::recompute($rid);
            self::log($rid, $user_id, $source === 'pos_ho' ? 'pos_in_ho' : 'pos_in', array('nguon' => $source));
            $pub = self::record_public($rec);
            $msg = 'Đã chấm VÀO ' . self::shift($sid)['name'] . ' lúc ' . $pub['in'];
            if ($pub['late'] > 0) {
                $msg .= ' — đi muộn ' . $pub['late'] . ' phút';
            }
            return array('message' => $msg, 'record' => $pub);
        }

        if ($type === 'out') {
            if ($state['mode'] !== 'out') {
                return new WP_Error('no_open', 'Bạn chưa chấm vào ca nào (hoặc đã quá giờ cho phép chấm ra).');
            }
            $rid = (int) $state['open']['record_id'];
            $rec = self::record($rid);
            if (self::is_locked($rec['work_date'])) {
                return self::lock_error($rec['work_date']);
            }
            if (time() - self::ts($rec['check_in_at']) < 60) {
                return new WP_Error('too_fast', 'Bạn vừa chấm vào chưa đầy 1 phút.');
            }
            $wpdb->update($t, array(
                'check_out_at' => $now_mysql,
                'out_source' => $source,
                'out_ip' => $ip,
                'updated_by' => get_current_user_id(),
                'updated_at' => $now_mysql,
            ), array('record_id' => $rid));
            $rec = self::recompute($rid);
            self::log($rid, $user_id, $source === 'pos_ho' ? 'pos_out_ho' : 'pos_out', array('nguon' => $source));
            $pub = self::record_public($rec);
            $msg = 'Đã chấm RA ' . $state['open']['shift'] . ' lúc ' . $pub['out'] . ' · ' . round($pub['worked'] / 60, 2) . ' giờ, ' . $pub['work_value'] . ' công';
            if ($pub['early'] > 0) {
                $msg .= ' — về sớm ' . $pub['early'] . ' phút';
            }
            return array('message' => $msg, 'record' => $pub);
        }

        return new WP_Error('invalid', 'Thao tác không hợp lệ.');
    }
}
