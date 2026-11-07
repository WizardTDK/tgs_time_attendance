<?php
/**
 * Cài đặt chấm công của cửa hàng + danh sách nhân viên tham gia chấm công.
 */
defined('ABSPATH') || exit;
if (!TGS_CC_Admin::page_boot('settings')) {
    return;
}
$cc_s = TGS_CC_Settings::all();
$cc_employees = TGS_CC_Service::employees(true);
$cc_check = function ($k) use ($cc_s) {
    echo !empty($cc_s[$k]) ? ' checked' : '';
};
$cc_can_grant = TGS_CC_Service::can_unlock();
$cc_roles = array_diff_key(wp_roles()->get_names(), array_flip(array('customer', 'subscriber')));
$cc_emp_roles = (array) $cc_s['employee_roles'];
$cc_users = array();
foreach (TGS_CC_Service::site_users() as $u) {
    $cc_users[(int) $u->ID] = $u;
}
?>
<div class="ds-page-head">
    <div>
        <h1 class="ds-page-head__title">Cài đặt chấm công</h1>
        <p class="ds-page-head__meta">Áp dụng cho cửa hàng <?php echo esc_html(get_bloginfo('name')); ?></p>
    </div>
</div>

<?php TGS_CC_Admin::tabs('cham-cong-cai-dat'); ?>

<form id="cc-settings-form" onsubmit="return false">
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="card-title">Đi muộn · Về sớm · Làm thêm giờ</h6></div>
            <div class="card-body">
                <div class="ds-form-grid tgs-cc-form-2">
                    <div>
                        <label class="form-label">Cho phép đi muộn tối đa (phút)</label>
                        <input type="number" min="0" class="form-control" name="late_grace" value="<?php echo esc_attr($cc_s['late_grace']); ?>">
                    </div>
                    <div>
                        <label class="form-label">Cho phép về sớm tối đa (phút)</label>
                        <input type="number" min="0" class="form-control" name="early_grace" value="<?php echo esc_attr($cc_s['early_grace']); ?>">
                    </div>
                </div>
                <div class="ds-hint mt-1">Vào muộn / ra sớm quá số phút này mới bị ghi nhận đi muộn / về sớm.</div>
                <div class="ds-divider"></div>
                <label class="d-block mb-2"><input type="checkbox" class="form-check-input" name="overtime_enabled" value="1"<?php $cc_check('overtime_enabled'); ?>> Tính làm thêm giờ</label>
                <div class="ds-form-grid tgs-cc-form-2">
                    <div>
                        <label class="form-label">Chấm vào sớm / ra muộn hơn giờ ca từ (phút)</label>
                        <input type="number" min="0" class="form-control" name="overtime_min" value="<?php echo esc_attr($cc_s['overtime_min']); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="card-title">Cách tính công</h6></div>
            <div class="card-body">
                <label class="d-block mb-2"><input type="checkbox" class="form-check-input" name="half_day_enabled" value="1"<?php $cc_check('half_day_enabled'); ?>> Tính nửa công theo số giờ làm thực tế</label>
                <div class="ds-form-grid tgs-cc-form-2">
                    <div>
                        <label class="form-label">Nửa công khi làm từ (giờ)</label>
                        <input type="number" min="0" step="0.5" class="form-control" name="half_day_from" value="<?php echo esc_attr($cc_s['half_day_from']); ?>">
                    </div>
                    <div>
                        <label class="form-label">đến dưới (giờ)</label>
                        <input type="number" min="0" step="0.5" class="form-control" name="half_day_to" value="<?php echo esc_attr($cc_s['half_day_to']); ?>">
                    </div>
                </div>
                <div class="ds-hint mt-1">Làm ít hơn mốc đầu → 0 công; từ mốc cuối trở lên → đủ công của ca. Tắt = có đủ giờ vào & ra là đủ công.</div>
                <div class="ds-divider"></div>
                <div class="ds-form-grid tgs-cc-form-2">
                    <div>
                        <label class="form-label">Ngày công chuẩn / tháng</label>
                        <input type="number" min="0" max="31" class="form-control" name="standard_days" value="<?php echo esc_attr($cc_s['standard_days']); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="card-title">In báo cáo</h6></div>
            <div class="card-body">
                <label class="form-label">Tên đơn vị in trên đầu bảng công</label>
                <input type="text" class="form-control" name="report_title" value="<?php echo esc_attr($cc_s['report_title']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                <label class="form-label mt-2">Chức danh ký cuối bảng (mỗi dòng một người)</label>
                <textarea class="form-control" rows="3" name="signers"><?php echo esc_textarea($cc_s['signers']); ?></textarea>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h6 class="card-title">Chấm công trên POS</h6></div>
            <div class="card-body">
                <label class="d-block mb-2"><input type="checkbox" class="form-check-input" name="pos_button" value="1"<?php $cc_check('pos_button'); ?>> Hiện nút "Chấm công" trên màn bán hàng</label>
                <label class="d-block mb-2"><input type="checkbox" class="form-check-input" name="require_schedule" value="1"<?php $cc_check('require_schedule'); ?>> Chỉ cho chấm vào ca <b>đã xếp lịch</b> (tắt = chấm được mọi ca đang trong giờ)</label>
                <label class="d-block mb-2"><input type="checkbox" class="form-check-input" name="allow_other_account" value="1"<?php $cc_check('allow_other_account'); ?>> Cho chấm công bằng tài khoản khác trên máy quầy dùng chung (nhập tên đăng nhập + mật khẩu)</label>
                <label class="form-label mt-2">Chỉ cho chấm từ IP mạng cửa hàng (mỗi dòng một IP, tiền tố <code>113.161.12.</code> hoặc dải <code>113.161.12.0/24</code>)</label>
                <textarea class="form-control" rows="2" name="ip_whitelist" placeholder="Để trống = không giới hạn"><?php echo esc_textarea($cc_s['ip_whitelist']); ?></textarea>
                <div class="ds-hint mt-1">IP máy bạn đang dùng (theo cách lấy IP bên dưới): <code><?php echo esc_html(TGS_CC_Service::client_ip()); ?></code></div>
                <div class="ds-form-grid tgs-cc-form-2 mt-2">
                    <div>
                        <label class="form-label">Website chạy sau</label>
                        <select class="form-select" name="proxy_mode">
                            <option value=""<?php selected($cc_s['proxy_mode'], ''); ?>>Không qua proxy (mặc định)</option>
                            <option value="cloudflare"<?php selected($cc_s['proxy_mode'], 'cloudflare'); ?>>Cloudflare</option>
                            <option value="proxy"<?php selected($cc_s['proxy_mode'], 'proxy'); ?>>Reverse proxy / load balancer riêng</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">IP proxy tin cậy (chỉ cho "Reverse proxy")</label>
                        <textarea class="form-control" rows="2" name="trusted_proxies" placeholder="Trống = mạng nội bộ (10.x, 172.16-31.x, 192.168.x, 127.x)"><?php echo esc_textarea($cc_s['trusted_proxies']); ?></textarea>
                    </div>
                </div>
                <div class="ds-hint mt-1">Nếu mọi máy hiện cùng một IP (IP của Cloudflare / proxy) thì giới hạn IP không phân biệt được máy trong và ngoài cửa hàng — chọn đúng loại proxy để lấy IP thật. Header IP chỉ được tin khi request đi qua proxy đó.</div>
                <div class="ds-divider"></div>
                <div class="ds-hint">
                    Màn chấm công riêng cho điện thoại / máy tính bảng đặt ở cửa:<br>
                    <a href="<?php echo esc_url(TGS_CC_POS::standalone_url()); ?>" target="_blank" rel="noopener"><?php echo esc_html(TGS_CC_POS::standalone_url()); ?></a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="card-title">Nhân viên tham gia chấm công</h6>
                <span class="ds-hint ms-2">Tài khoản thuộc cửa hàng này (trừ khách hàng)</span>
            </div>
            <div class="card-body pb-2">
                <label class="form-label">Vai trò tự động tham gia chấm công</label>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($cc_roles as $role_key => $role_name): ?>
                        <label><input type="checkbox" class="form-check-input cc-role" value="<?php echo esc_attr($role_key); ?>"<?php echo in_array($role_key, $cc_emp_roles, true) ? ' checked' : ''; ?>> <?php echo esc_html(translate_user_role($role_name)); ?> <code><?php echo esc_html($role_key); ?></code></label>
                    <?php endforeach; ?>
                </div>
                <div class="ds-hint mt-1">Tài khoản mới tạo có vai trò được chọn sẽ tự vào danh sách chấm công. Vai trò khác (quản trị, kế toán…) không tự vào — tích tay từng người bên dưới nếu cần.</div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:420px">
                    <table class="table table-grid mb-0" data-ds-no-grid data-ds-no-export data-ds-no-filter data-ds-no-colcfg>
                        <thead><tr><th style="width:60px">Chấm công</th><th style="width:70px" title="Xem bảng chấm công, sửa công, xếp lịch, báo cáo, chốt tháng">Quản lý chấm công</th><th>Nhân viên</th><th>Tên đăng nhập</th><th>Vai trò</th></tr></thead>
                        <tbody>
                        <?php if (!$cc_employees): ?>
                            <tr><td colspan="5" class="text-muted">Chưa có tài khoản nào thuộc cửa hàng này.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($cc_employees as $e):
                            $u = isset($cc_users[$e['id']]) ? $cc_users[$e['id']] : null;
                            $is_admin = $u && user_can($u, 'manage_options');
                            $is_mgr = $is_admin || TGS_CC_Admin::is_manager_by_cap($u);
                            ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input cc-emp" value="<?php echo (int) $e['id']; ?>"<?php echo $e['excluded'] ? '' : ' checked'; ?>></td>
                                <td class="text-center"><input type="checkbox" class="form-check-input cc-mgr" value="<?php echo (int) $e['id']; ?>"<?php echo $is_mgr ? ' checked' : ''; ?><?php echo ($is_admin || !$cc_can_grant) ? ' disabled' : ''; ?><?php echo $is_admin ? ' title="Quản trị cửa hàng luôn có quyền"' : ''; ?>></td>
                                <td><?php echo esc_html($e['name']); ?></td>
                                <td><code><?php echo esc_html($e['login']); ?></code></td>
                                <td class="text-muted"><?php echo esc_html($e['roles']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-body pt-2">
                <div class="ds-hint">Cột "Quản lý chấm công" cấp quyền riêng <code>tgs_manage_attendance</code> cho cửa hàng này (không cần quyền quản trị).<?php echo $cc_can_grant ? '' : ' Chỉ quản trị cửa hàng được thay đổi cột này.'; ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h6 class="card-title">Gỡ plugin</h6></div>
            <div class="card-body">
                <label class="d-block"><input type="checkbox" class="form-check-input" name="delete_on_uninstall" value="1"<?php $cc_check('delete_on_uninstall'); ?>> Xoá toàn bộ dữ liệu chấm công của cửa hàng này khi gỡ (xoá) plugin</label>
                <div class="ds-hint mt-1">Mặc định tắt: gỡ plugin vẫn giữ lại ca, lịch, dữ liệu chấm công để cài lại không mất.</div>
            </div>
        </div>
    </div>
</div>

<div class="ds-actionbar">
    <span class="ds-hint">Đổi luật đi muộn / về sớm / làm thêm / nửa công thì công của tháng hiện tại được tính lại; các tháng trước và tháng đã chốt giữ nguyên.</span>
    <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-primary" id="cc-settings-save" data-ds-save><i class="bx bx-save"></i> Lưu cài đặt <kbd>Ctrl+S</kbd></button>
    </div>
</div>
</form>
