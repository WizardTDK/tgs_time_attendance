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
 *   Giờ làm          từ max(vào, giờ vào ca) tới min(ra, giờ ra ca) − nghỉ giữa ca.
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

    public static function client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        return substr(preg_replace('/[^0-9a-fA-F:.]/', '', $ip), 0, 64);
    }

    /* ═══════════════════════════ NHÂN VIÊN ═══════════════════════════ */

    /**
     * Nhân viên của shop = tài khoản WordPress thuộc site hiện tại (tài khoản
     * được cấp để đăng nhập POS / quản trị), trừ khách hàng và người đăng ký.
     */
    public static function employees($include_excluded = false)
    {
        static $cache = array();
        $key = $include_excluded ? 'all' : 'active';
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $users = get_users(array(
            'blog_id' => get_current_blog_id(),
            'role__not_in' => apply_filters('tgs_cc_excluded_roles', array('customer', 'subscriber')),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 500,
        ));
        $excluded = array_map('intval', (array) TGS_CC_Settings::get('excluded_users'));

        $out = array();
        foreach ($users as $u) {
            $is_excluded = in_array((int) $u->ID, $excluded, true);
            if ($is_excluded && !$include_excluded) {
                continue;
            }
            $out[] = array(
                'id' => (int) $u->ID,
                'name' => $u->display_name ?: $u->user_login,
                'login' => $u->user_login,
                'roles' => implode(', ', (array) $u->roles),
                'excluded' => $is_excluded,
            );
        }
        $cache[$key] = apply_filters('tgs_cc_employees', $out, $include_excluded);
        return $cache[$key];
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

    public static function shift($id)
    {
        static $cache = array();
        $id = (int) $id;
        if (!isset($cache[$id])) {
            global $wpdb;
            $t = TGS_CC_DB::t('shifts');
            $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE shift_id = %d", $id), ARRAY_A);
            $cache[$id] = $r ? self::shift_public($r) : null;
        }
        return $cache[$id];
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

    private static function shift_hours($start, $end, $break)
    {
        list($sh, $sm) = array_map('intval', explode(':', $start));
        list($eh, $em) = array_map('intval', explode(':', $end));
        $mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($mins <= 0) {
            $mins += 1440;
        }
        return round(max(0, $mins - $break) / 60, 2);
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

        $data = array(
            'shift_code' => substr($code, 0, 30),
            'shift_name' => substr($name, 0, 100),
            'start_time' => $start . ':00',
            'end_time' => $end . ':00',
            'break_minutes' => max(0, min(600, (int) (isset($p['break_minutes']) ? $p['break_minutes'] : 0))),
            'checkin_before' => max(0, min(720, (int) (isset($p['checkin_before']) ? $p['checkin_before'] : 60))),
            'checkout_after' => max(0, min(720, (int) (isset($p['checkout_after']) ? $p['checkout_after'] : 120))),
            'work_value' => max(0, min(10, (float) (isset($p['work_value']) ? $p['work_value'] : 1))),
            'color' => $color,
            'status' => !empty($p['status']) ? 1 : 0,
            'sort_order' => (int) (isset($p['sort_order']) ? $p['sort_order'] : 0),
            'updated_at' => current_time('mysql'),
        );

        if ($id) {
            $wpdb->update($t, $data, array('shift_id' => $id));
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($t, $data);
            $id = (int) $wpdb->insert_id;
        }
        return self::shift($id) ?: self::shift_public(array_merge($data, array('shift_id' => $id)));
    }

    /** Ca đã có dữ liệu chấm công thì không xoá được, chỉ chuyển sang Ngừng hoạt động */
    public static function shift_delete($id)
    {
        global $wpdb;
        $id = (int) $id;
        if ($id <= 0 || !self::shift($id)) {
            return array('deleted' => false, 'message' => 'Không tìm thấy ca cần xoá.');
        }
        $used =(int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TGS_CC_DB::t('records') . ' WHERE shift_id = %d', $id));
        if ($used > 0) {
            $wpdb->update(TGS_CC_DB::t('shifts'), array('status' => 0, 'updated_at' => current_time('mysql')), array('shift_id' => $id));
            return array('deleted' => false, 'message' => 'Ca đã có ' . $used . ' lượt chấm công nên không xoá được — đã chuyển sang Ngừng hoạt động.');
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
        // Chỉ trừ nghỉ giữa ca khi khoảng làm đủ dài để có nghỉ
        $worked = $span > $shift['break_minutes'] ? $span - $shift['break_minutes'] : $span;
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

    /** Tính lại toàn bộ dòng chấm công từ ngày $from (dùng sau khi đổi cài đặt / sửa ca) */
    public static function recompute_from($from)
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT record_id FROM ' . TGS_CC_DB::t('records') . ' WHERE work_date >= %s', $from));
        foreach ($ids as $id) {
            self::recompute($id);
        }
        return count($ids);
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

    public static function admin_delete_record($record_id)
    {
        global $wpdb;
        $rec = self::record($record_id);
        if (!$rec) {
            return new WP_Error('not_found', 'Không tìm thấy dòng chấm công.');
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
            if (!$shift) {
                continue;
            }
            $w = self::window($shift, $s['work_date']);
            if ($w['end'] > $now || self::find_record($s['user_id'], $s['shift_id'], $s['work_date'])) {
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
        $shift_ids = array_values(array_unique(array_filter(array_map('intval', (array) $shift_ids))));
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

    private static function schedule_insert($user_id, $shift_id, $work_date)
    {
        global $wpdb;
        $t = TGS_CC_DB::t('schedules');
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
        $days = self::date_range($from, $to);
        if (count($days) > 93) {
            return new WP_Error('invalid', 'Mỗi lần xếp tối đa 93 ngày.');
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

    /** Chép lịch của tuần nguồn sang tuần đích (7 ngày bắt đầu từ thứ Hai) */
    public static function schedule_copy_week($src_monday, $dst_monday, $user_ids = array(), $replace = true)
    {
        global $wpdb;
        $ids = self::employee_ids($user_ids);
        if (!$ids) {
            return 0;
        }
        $src_end = wp_date('Y-m-d', self::ts($src_monday . ' 12:00:00') + 6 * DAY_IN_SECONDS);
        $dst_end = wp_date('Y-m-d', self::ts($dst_monday . ' 12:00:00') + 6 * DAY_IN_SECONDS);
        if ($replace) {
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . TGS_CC_DB::t('schedules') . ' WHERE work_date BETWEEN %s AND %s AND user_id IN (' . implode(',', $ids) . ')',
                $dst_monday,
                $dst_end
            ));
        }
        $offset = (int) round((self::ts($dst_monday . ' 12:00:00') - self::ts($src_monday . ' 12:00:00')) / DAY_IN_SECONDS);
        $n = 0;
        foreach (self::schedules_between($src_monday, $src_end, $ids) as $s) {
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
                    $st = 'chua_cham';
                    if (isset($shifts[$sid])) {
                        $w = self::window($shifts[$sid], $date);
                        $st = $now >= $w['start'] ? 'chua_cham' : 'lich';
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
                    if ($e['status'] === 'lich') {
                        continue; // ca chưa tới: không đưa vào báo cáo
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
                } elseif (!empty($flags['lich'])) {
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
     * Chấm vào / chấm ra. Luôn kiểm tra lại bằng pos_state() ngay lúc bấm —
     * không tin dữ liệu từ trình duyệt (màn có thể mở từ trước, giờ đã khác).
     */
    public static function punch($user_id, $type, $args, $source = 'pos')
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
            self::log($rid, $user_id, 'pos_in', array('nguon' => $source));
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
            self::log($rid, $user_id, 'pos_out', array('nguon' => $source));
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
