<?php
/**
 * Báo cáo chấm công:
 *   1. Bảng công tháng  — mẫu in cuối tháng (ký hiệu theo ngày + tổng hợp + chữ ký), xuất Excel có định dạng.
 *   2. Tổng hợp         — mỗi nhân viên một dòng: công, giờ, làm thêm, muộn/sớm, nghỉ, vắng.
 *   3. Chi tiết         — từng ca: giờ vào/ra thực tế, muộn, sớm, giờ làm, công.
 */
defined('ABSPATH') || exit;
if (!TGS_CC_Admin::page_boot('report')) {
    return;
}
?>
<div class="ds-page-head">
    <div>
        <h1 class="ds-page-head__title">Báo cáo chấm công</h1>
        <p class="ds-page-head__meta">Cuối tháng: chọn tháng ở tab "Bảng công tháng" → Xuất Excel hoặc In để ký</p>
    </div>
    <div class="ds-page-head__actions">
        <button type="button" class="btn btn-secondary" id="cc-print"><i class="bx bx-printer"></i> In</button>
        <button type="button" class="btn btn-primary" id="cc-export"><i class="bx bx-export"></i> Xuất Excel</button>
    </div>
</div>

<?php TGS_CC_Admin::tabs('cham-cong-bao-cao'); ?>

<ul class="nav nav-pills tgs-cc-rtabs mb-2" id="cc-rtabs">
    <li class="nav-item"><a class="nav-link active" href="#" data-rt="month"><i class="bx bx-table"></i> Bảng công tháng</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-rt="summary"><i class="bx bx-user-check"></i> Tổng hợp theo nhân viên</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-rt="detail"><i class="bx bx-list-ul"></i> Chi tiết chấm công</a></li>
</ul>

<div class="card">
    <div class="card-body">
        <div class="ds-form-grid tgs-cc-filter">
            <div class="cc-f-month">
                <label class="form-label">Tháng</label>
                <input type="month" class="form-control" id="cc-month">
            </div>
            <div class="cc-f-range">
                <label class="form-label">Từ ngày</label>
                <input type="date" class="form-control" id="cc-from">
            </div>
            <div class="cc-f-range">
                <label class="form-label">Đến ngày</label>
                <input type="date" class="form-control" id="cc-to">
            </div>
            <div>
                <label class="form-label">Nhân viên</label>
                <select class="form-select" id="cc-user"><option value="">Tất cả</option></select>
            </div>
            <div class="cc-f-range">
                <label class="form-label">Ca làm việc</label>
                <select class="form-select" id="cc-shift"><option value="">Tất cả</option></select>
            </div>
            <div class="d-flex align-items-end">
                <button class="btn btn-secondary w-100" type="button" id="cc-load"><i class="bx bx-filter-alt"></i> Xem báo cáo</button>
            </div>
        </div>
    </div>
</div>

<?php /* Tắt lọc / chọn cột tự động của hệ thống: Xuất Excel ở đây dựng từ dữ liệu, không từ DOM đã lọc */ ?>
<div id="cc-report" class="tgs-cc-report" data-ds-no-filter data-ds-no-colcfg><div class="tgs-cc-empty">Đang tải…</div></div>
