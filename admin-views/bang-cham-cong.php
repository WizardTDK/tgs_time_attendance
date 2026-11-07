<?php
/**
 * Bảng chấm công — dòng = nhân viên, cột = ngày, mỗi ô là các ca (có lịch / có chấm).
 * Bấm vào một ca để sửa giờ vào/ra hoặc ghi nghỉ phép. Dữ liệu do assets/js/admin.js vẽ.
 */
defined('ABSPATH') || exit;
if (!TGS_CC_Admin::page_boot('board')) {
    return;
}
?>
<div class="ds-page-head">
    <div>
        <h1 class="ds-page-head__title">Bảng chấm công</h1>
        <p class="ds-page-head__meta">Bấm vào một ca để sửa giờ vào/ra, ghi nghỉ phép · bấm ô trống để thêm công ngoài lịch</p>
    </div>
    <div class="ds-page-head__actions">
        <button type="button" class="btn btn-secondary" id="cc-fill" title="Ca đã xếp lịch, đã hết giờ mà chưa chấm → điền đủ công theo giờ ca">
            <i class="bx bx-check-double"></i> Duyệt đủ công theo lịch
        </button>
        <button type="button" class="btn btn-secondary" id="cc-export"><i class="bx bx-export"></i> Xuất Excel</button>
    </div>
</div>

<?php TGS_CC_Admin::tabs('cham-cong-bang'); ?>

<div class="card">
    <div class="card-body">
        <div class="ds-form-grid tgs-cc-filter">
            <div>
                <label class="form-label">Xem theo</label>
                <select class="form-select" id="cc-mode">
                    <option value="week">Tuần</option>
                    <option value="month">Tháng</option>
                </select>
            </div>
            <div>
                <label class="form-label">Thời gian</label>
                <div class="input-group">
                    <button class="btn btn-secondary btn-icon" type="button" id="cc-prev" title="Kỳ trước"><i class="bx bx-chevron-left"></i></button>
                    <input type="date" class="form-control" id="cc-anchor">
                    <button class="btn btn-secondary btn-icon" type="button" id="cc-next" title="Kỳ sau"><i class="bx bx-chevron-right"></i></button>
                </div>
            </div>
            <div>
                <label class="form-label">Nhân viên</label>
                <select class="form-select" id="cc-user"><option value="">Tất cả</option></select>
            </div>
            <div>
                <label class="form-label">Ca làm việc</label>
                <select class="form-select" id="cc-shift"><option value="">Tất cả</option></select>
            </div>
            <div class="d-flex align-items-end gap-2">
                <button class="btn btn-secondary" type="button" id="cc-today">Hôm nay</button>
                <button class="btn btn-primary flex-grow-1" type="button" id="cc-load"><i class="bx bx-filter-alt"></i> Xem</button>
            </div>
        </div>
    </div>
</div>

<div class="ds-toolbar">
    <strong id="cc-range-label"></strong>
    <div class="ds-toolbar__spacer"></div>
    <div class="tgs-cc-legend" id="cc-legend"></div>
</div>
<div class="tgs-cc-board-wrap" id="cc-board" data-ds-no-filter data-ds-no-colcfg><div class="tgs-cc-empty">Đang tải…</div></div>

<!-- Hộp sửa công -->
<div class="modal fade" id="cc-record-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Chấm công</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <div class="tgs-cc-rec-head" id="cc-rec-head"></div>
                <div class="ds-form-grid tgs-cc-form-2">
                    <div>
                        <label class="form-label">Ca làm việc</label>
                        <select class="form-select" id="cc-rec-shift"></select>
                    </div>
                    <div>
                        <label class="form-label">Hình thức</label>
                        <select class="form-select" id="cc-rec-mode">
                            <option value="work">Đi làm</option>
                            <option value="co_phep">Nghỉ có phép</option>
                            <option value="khong_phep">Nghỉ không phép</option>
                        </select>
                    </div>
                    <div class="cc-work-only">
                        <label class="form-label">Giờ vào</label>
                        <input type="time" class="form-control" id="cc-rec-in">
                    </div>
                    <div class="cc-work-only">
                        <label class="form-label">Giờ ra</label>
                        <input type="time" class="form-control" id="cc-rec-out">
                    </div>
                </div>
                <div class="mt-2">
                    <label class="form-label">Ghi chú</label>
                    <input type="text" class="form-control" id="cc-rec-note" maxlength="255" placeholder="Lý do sửa, đổi ca…">
                </div>
                <div class="tgs-cc-rec-calc" id="cc-rec-calc"></div>
                <details class="tgs-cc-logs mt-2" id="cc-rec-logs-wrap">
                    <summary>Lịch sử chấm công</summary>
                    <div id="cc-rec-logs" class="mt-1"></div>
                </details>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto" id="cc-rec-delete"><i class="bx bx-trash"></i> Xoá công</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-primary" id="cc-rec-save"><i class="bx bx-save"></i> Lưu</button>
            </div>
        </div>
    </div>
</div>
