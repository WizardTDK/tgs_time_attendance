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
                <label class="form-label mt-2">Chỉ cho chấm từ IP mạng cửa hàng (mỗi dòng một IP hoặc tiền tố, vd <code>113.161.12.</code>)</label>
                <textarea class="form-control" rows="2" name="ip_whitelist" placeholder="Để trống = không giới hạn"><?php echo esc_textarea($cc_s['ip_whitelist']); ?></textarea>
                <div class="ds-hint mt-1">IP máy bạn đang dùng: <code><?php echo esc_html(TGS_CC_Service::client_ip()); ?></code></div>
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
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:420px">
                    <table class="table table-grid mb-0" data-ds-no-grid data-ds-no-export data-ds-no-filter data-ds-no-colcfg>
                        <thead><tr><th style="width:60px">Chấm công</th><th>Nhân viên</th><th>Tên đăng nhập</th><th>Vai trò</th></tr></thead>
                        <tbody>
                        <?php if (!$cc_employees): ?>
                            <tr><td colspan="4" class="text-muted">Chưa có tài khoản nhân viên nào thuộc cửa hàng này.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($cc_employees as $e): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="form-check-input cc-emp" value="<?php echo (int) $e['id']; ?>"<?php echo $e['excluded'] ? '' : ' checked'; ?>></td>
                                <td><?php echo esc_html($e['name']); ?></td>
                                <td><code><?php echo esc_html($e['login']); ?></code></td>
                                <td class="text-muted"><?php echo esc_html($e['roles']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="ds-actionbar">
    <span class="ds-hint">Lưu xong, công của tháng hiện tại được tính lại theo cài đặt mới; các tháng trước giữ nguyên.</span>
    <div class="ms-auto d-flex gap-2">
        <button type="button" class="btn btn-primary" id="cc-settings-save" data-ds-save><i class="bx bx-save"></i> Lưu cài đặt <kbd>Ctrl+S</kbd></button>
    </div>
</div>
</form>
