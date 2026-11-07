<?php
/**
 * Xếp lịch làm việc theo tuần — dòng = nhân viên, cột = thứ Hai … Chủ nhật.
 * Bấm ô để chọn ca; "Xếp lịch hàng loạt" để lặp lại theo tuần cho nhiều người.
 */
defined('ABSPATH') || exit;
if (!TGS_CC_Admin::page_boot('schedule')) {
    return;
}
?>
<div class="ds-page-head">
    <div>
        <h1 class="ds-page-head__title">Xếp lịch làm việc</h1>
        <p class="ds-page-head__meta">Bấm vào ô để chọn ca cho nhân viên trong ngày · lịch dùng để tính vắng và gợi ý ca khi chấm trên POS</p>
    </div>
    <div class="ds-page-head__actions">
        <button type="button" class="btn btn-primary" id="cc-bulk-open"><i class="bx bx-calendar-plus"></i> Xếp lịch hàng loạt</button>
    </div>
</div>

<?php TGS_CC_Admin::tabs('cham-cong-lich'); ?>

<div class="ds-toolbar">
    <div class="input-group" style="width:auto">
        <button class="btn btn-secondary btn-sm btn-icon" type="button" id="cc-prev" title="Tuần trước"><i class="bx bx-chevron-left"></i></button>
        <input type="date" class="form-control form-control-sm" id="cc-anchor" style="width:150px">
        <button class="btn btn-secondary btn-sm btn-icon" type="button" id="cc-next" title="Tuần sau"><i class="bx bx-chevron-right"></i></button>
    </div>
    <button class="btn btn-secondary btn-sm" type="button" id="cc-today">Tuần này</button>
    <strong id="cc-range-label" class="ms-2"></strong>
    <span class="ds-toolbar__divider"></span>
    <button class="btn btn-secondary btn-sm" type="button" id="cc-copy-week" title="Chép toàn bộ lịch của tuần trước sang tuần đang xem (ghi đè)"><i class="bx bx-copy"></i> Chép lịch tuần trước</button>
    <button class="btn btn-outline-danger btn-sm" type="button" id="cc-clear-week"><i class="bx bx-trash"></i> Xoá lịch tuần này</button>
    <div class="ds-toolbar__spacer"></div>
    <select class="form-select form-select-sm" id="cc-user" style="width:200px"><option value="">Tất cả nhân viên</option></select>
</div>
<div class="tgs-cc-board-wrap" id="cc-board" data-ds-no-filter data-ds-no-colcfg><div class="tgs-cc-empty">Đang tải…</div></div>

<!-- Chọn ca cho một ô -->
<div class="modal fade" id="cc-cell-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chọn ca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <div class="tgs-cc-rec-head" id="cc-cell-head"></div>
                <div id="cc-cell-shifts" class="tgs-cc-checklist"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="cc-cell-save">Lưu</button>
            </div>
        </div>
    </div>
</div>

<!-- Xếp lịch hàng loạt -->
<div class="modal fade" id="cc-bulk-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Xếp lịch hàng loạt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="ds-fieldset-title d-flex align-items-center">
                            <span>Nhân viên</span>
                            <label class="ms-auto small fw-normal"><input type="checkbox" class="form-check-input" id="cc-bulk-all"> Chọn tất cả</label>
                        </div>
                        <div id="cc-bulk-users" class="tgs-cc-checklist tgs-cc-checklist--scroll"></div>
                    </div>
                    <div class="col-md-6">
                        <div class="ds-fieldset-title">Ca làm việc</div>
                        <div id="cc-bulk-shifts" class="tgs-cc-checklist"></div>
                        <div class="ds-fieldset-title mt-3">Lặp lại hàng tuần vào</div>
                        <div id="cc-bulk-weekdays" class="tgs-cc-weekdays"></div>
                        <div class="ds-form-grid tgs-cc-form-2 mt-3">
                            <div><label class="form-label">Từ ngày</label><input type="date" class="form-control" id="cc-bulk-from"></div>
                            <div><label class="form-label">Đến ngày</label><input type="date" class="form-control" id="cc-bulk-to"></div>
                        </div>
                        <label class="mt-2 d-block"><input type="checkbox" class="form-check-input" id="cc-bulk-replace"> Xoá lịch cũ của các nhân viên đã chọn trong khoảng ngày này trước khi xếp</label>
                        <div class="ds-hint mt-1">Tối đa 93 ngày mỗi lần.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="cc-bulk-save"><i class="bx bx-save"></i> Xếp lịch</button>
            </div>
        </div>
    </div>
</div>
