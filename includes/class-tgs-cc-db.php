<?php
/**
 * Bảng dữ liệu chấm công (mỗi site một bộ).
 *
 *   tgs_cc_shifts     Ca làm việc: giờ vào/ra, nghỉ giữa ca, khoảng cho phép chấm, số công.
 *   tgs_cc_schedules  Lịch làm việc: nhân viên X làm ca Y ngày Z.
 *   tgs_cc_records    Kết quả chấm công: một dòng / nhân viên / ca / ngày.
 *   tgs_cc_logs       Lịch sử thao tác trên từng dòng chấm công (ai chấm, ai sửa).
 *
 * Giờ lưu theo GIỜ SITE (current_time('mysql')), cùng quy ước với phần còn lại của WordPress.
 */

defined('ABSPATH') || exit;

class TGS_CC_DB
{
    const VERSION = '1.0.0';
    const OPTION  = 'tgs_cc_db_version';

    public static function t($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'tgs_cc_' . $name;
    }

    public static function activate($network_wide = false)
    {
        if (is_multisite() && $network_wide) {
            // Kích hoạt toàn mạng: không lặp hết các site ở đây (chậm) —
            // maybe_install() trên hook init sẽ tạo bảng khi từng site được mở.
            return;
        }
        self::install();
    }

    public static function maybe_install()
    {
        if (get_option(self::OPTION) === self::VERSION) {
            return;
        }
        self::install();
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $c = $wpdb->get_charset_collate();
        $shifts = self::t('shifts');
        $schedules = self::t('schedules');
        $records = self::t('records');
        $logs = self::t('logs');

        dbDelta("CREATE TABLE {$shifts} (
            shift_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shift_code varchar(30) NOT NULL DEFAULT '',
            shift_name varchar(100) NOT NULL DEFAULT '',
            start_time time NOT NULL DEFAULT '08:00:00',
            end_time time NOT NULL DEFAULT '17:00:00',
            break_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
            checkin_before smallint(5) unsigned NOT NULL DEFAULT 60,
            checkout_after smallint(5) unsigned NOT NULL DEFAULT 120,
            work_value decimal(5,2) NOT NULL DEFAULT 1.00,
            color varchar(9) NOT NULL DEFAULT '#1e4fa3',
            status tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (shift_id),
            KEY idx_status (status)
        ) {$c};");

        dbDelta("CREATE TABLE {$schedules} (
            schedule_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            shift_id bigint(20) unsigned NOT NULL,
            work_date date NOT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NULL,
            PRIMARY KEY  (schedule_id),
            UNIQUE KEY uk_user_shift_date (user_id,shift_id,work_date),
            KEY idx_date (work_date)
        ) {$c};");

        dbDelta("CREATE TABLE {$records} (
            record_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            shift_id bigint(20) unsigned NOT NULL,
            work_date date NOT NULL,
            check_in_at datetime NULL,
            check_out_at datetime NULL,
            in_source varchar(20) NOT NULL DEFAULT '',
            out_source varchar(20) NOT NULL DEFAULT '',
            in_ip varchar(64) NOT NULL DEFAULT '',
            out_ip varchar(64) NOT NULL DEFAULT '',
            leave_type varchar(20) NOT NULL DEFAULT '',
            late_minutes int(11) NOT NULL DEFAULT 0,
            early_minutes int(11) NOT NULL DEFAULT 0,
            overtime_minutes int(11) NOT NULL DEFAULT 0,
            worked_minutes int(11) NOT NULL DEFAULT 0,
            work_value decimal(5,2) NOT NULL DEFAULT 0.00,
            status varchar(20) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (record_id),
            UNIQUE KEY uk_user_shift_date (user_id,shift_id,work_date),
            KEY idx_date (work_date),
            KEY idx_user_date (user_id,work_date)
        ) {$c};");

        dbDelta("CREATE TABLE {$logs} (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            record_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(30) NOT NULL DEFAULT '',
            detail text NULL,
            ip varchar(64) NOT NULL DEFAULT '',
            created_at datetime NULL,
            PRIMARY KEY  (log_id),
            KEY idx_record (record_id),
            KEY idx_user (user_id)
        ) {$c};");

        // Ca mặc định cho shop mới để dùng được ngay — sửa/ngừng ở màn Ca làm việc.
        $has = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$shifts}");
        if ($has === 0) {
            $now = current_time('mysql');
            $defaults = array(
                array('CA_SANG', 'Ca sáng', '07:00:00', '14:00:00', '#1e4fa3', 1),
                array('CA_CHIEU', 'Ca chiều', '14:00:00', '21:30:00', '#0e6d92', 2),
            );
            foreach ($defaults as $d) {
                $wpdb->insert($shifts, array(
                    'shift_code' => $d[0],
                    'shift_name' => $d[1],
                    'start_time' => $d[2],
                    'end_time' => $d[3],
                    'break_minutes' => 0,
                    'checkin_before' => 60,
                    'checkout_after' => 120,
                    'work_value' => 1,
                    'color' => $d[4],
                    'status' => 1,
                    'sort_order' => $d[5],
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
            }
        }

        update_option(self::OPTION, self::VERSION, true);
    }
}
