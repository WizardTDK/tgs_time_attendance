<?php
/**
 * Thiết lập ca làm việc: giờ vào/ra, nghỉ giữa ca, khoảng giờ cho phép chấm công, số công.
 */
defined('ABSPATH') || exit;
if (!TGS_CC_Admin::page_boot('shifts')) {
    return;
}
?>
<div class="ds-page-head">
    <div>
        <h1 class="ds-page-head__title">Ca làm việc</h1>
        <p class="ds-page-head__meta">Giờ ra nhỏ hơn giờ vào = ca qua đêm · ca đã có chấm công không xoá được, chỉ ngừng hoạt động</p>
    </div>
    <div class="ds-page-head__actions">
        <button type="button" class="btn btn-primary" id="cc-shift-add"><i class="bx bx-plus"></i> Thêm ca</button>
    </div>
</div>

<?php TGS_CC_Admin::tabs('cham-cong-ca'); ?>

<div class="table-responsive">
    <table class="table table-hover table-grid" data-ds-no-grid id="cc-shift-table">
        <thead>
            <tr>
                <th style="width:40px">#</th>
                <th>Mã ca</th>
                <th>Tên ca</th>
                <th>Giờ làm việc</th>
                <th class="ds-col-num">Nghỉ giữa ca (phút)</th>
                <th class="ds-col-num">Số giờ</th>
                <th>Cho phép chấm công</th>
                <th class="ds-col-num">Số công</th>
                <th>Trạng thái</th>
                <th class="ds-col-act">Thao tác</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<div class="modal fade" id="cc-shift-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ca làm việc</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="cc-sf-id">
                <div class="ds-form-grid tgs-cc-form-2">
                    <div><label class="form-label">Tên ca <span class="text-danger">*</span></label><input type="text" class="form-control" id="cc-sf-name" maxlength="100" placeholder="VD: Ca sáng"></div>
                    <div><label class="form-label">Mã ca</label><input type="text" class="form-control" id="cc-sf-code" maxlength="30" placeholder="Tự sinh nếu để trống"></div>
                    <div><label class="form-label">Giờ vào <span class="text-danger">*</span></label><input type="time" class="form-control" id="cc-sf-start"></div>
                    <div><label class="form-label">Giờ ra <span class="text-danger">*</span></label><input type="time" class="form-control" id="cc-sf-end"></div>
                    <div><label class="form-label">Nghỉ giữa ca (phút)</label><input type="number" min="0" class="form-control" id="cc-sf-break"></div>
                    <div><label class="form-label">Số công của ca</label><input type="number" min="0" step="0.25" class="form-control" id="cc-sf-value"></div>
                </div>
                <div class="ds-divider"></div>
                <div class="ds-fieldset-title"><i class="bx bx-time"></i> Giờ cho phép chấm công</div>
                <div class="ds-form-grid tgs-cc-form-2">
                    <div><label class="form-label">Chấm vào sớm nhất trước giờ vào (phút)</label><input type="number" min="0" class="form-control" id="cc-sf-before"></div>
                    <div><label class="form-label">Chấm ra muộn nhất sau giờ ra (phút)</label><input type="number" min="0" class="form-control" id="cc-sf-after"></div>
                </div>
                <div class="ds-hint mt-1" id="cc-sf-window"></div>
                <div class="ds-divider"></div>
                <div class="ds-form-grid tgs-cc-form-2">
                    <div><label class="form-label">Màu hiển thị</label><input type="color" class="form-control form-control-color" id="cc-sf-color"></div>
                    <div><label class="form-label">Thứ tự</label><input type="number" class="form-control" id="cc-sf-sort"></div>
                </div>
                <label class="mt-2 d-block"><input type="checkbox" class="form-check-input" id="cc-sf-status"> Đang hoạt động</label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="cc-sf-save"><i class="bx bx-save"></i> Lưu</button>
            </div>
        </div>
    </div>
</div>
