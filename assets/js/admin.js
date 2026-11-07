/**
 * Chấm công — phần quản trị. Một file, khởi chạy theo TGS_CC.page:
 *   board | schedule | shifts | report | settings
 * Dùng Bootstrap modal + TGS_Toast + TGS_XLSX_STYLE mà main-layout.php đã nạp sẵn.
 */
(function () {
    'use strict';
    var C = window.TGS_CC;
    if (!C) return;

    /* ═══════════════════════════ TIỆN ÍCH ═══════════════════════════ */

    var $ = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function toast(msg, type) {
        if (window.TGS_Toast && window.TGS_Toast[type || 'success']) window.TGS_Toast[type || 'success'](msg);
        else window.alert(msg);
    }

    function api(op, payload) {
        var fd = new FormData();
        fd.append('action', 'tgs_cc_admin');
        fd.append('nonce', C.nonce);
        fd.append('op', op);
        fd.append('payload', JSON.stringify(payload || {}));
        return fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (j) {
                if (!j) throw new Error('Máy chủ trả về dữ liệu không hợp lệ.');
                if (!j.success) throw new Error((j.data && j.data.message) || 'Có lỗi xảy ra.');
                return j.data;
            })
            .catch(function (e) { toast(e.message, 'error'); throw e; });
    }

    function busy(btn, on) {
        if (!btn) return;
        btn.disabled = !!on;
        btn.classList.toggle('is-busy', !!on);
    }

    function modal(id) {
        var el = document.getElementById(id);
        return window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(el) : { show: function () {}, hide: function () {} };
    }

    var num = function (v, d) {
        v = +v || 0;
        return v.toLocaleString('vi-VN', { maximumFractionDigits: d == null ? 2 : d });
    };

    /* Ngày: luôn làm việc với chuỗi Y-m-d, đổi qua Date ở 12h trưa để khỏi lệch múi giờ */
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toDate(ymd) { var p = ymd.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function addDays(s, n) { var d = toDate(s); d.setDate(d.getDate() + n); return ymd(d); }
    function monday(s) { var d = toDate(s); var w = (d.getDay() + 6) % 7; d.setDate(d.getDate() - w); return ymd(d); }
    function monthStart(s) { return s.slice(0, 8) + '01'; }
    function monthEnd(s) { var d = toDate(monthStart(s)); d.setMonth(d.getMonth() + 1); d.setDate(0); return ymd(d); }
    function addMonths(s, n) { var d = toDate(monthStart(s)); d.setMonth(d.getMonth() + n); return ymd(d); }
    var WD = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    var WD_FULL = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    function wd(s) { return WD[toDate(s).getDay()]; }
    function dm(s) { return s.slice(8, 10) + '/' + s.slice(5, 7); }
    function dmy(s) { return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4); }

    var shiftMap = {};
    function indexShifts() {
        shiftMap = {};
        (C.shifts || []).forEach(function (s) { shiftMap[s.id] = s; });
    }
    indexShifts();

    function fillUsers(sel, allLabel) {
        if (!sel) return;
        sel.innerHTML = '<option value="">' + esc(allLabel || 'Tất cả') + '</option>' + (C.employees || []).map(function (e) {
            return '<option value="' + e.id + '">' + esc(e.name) + ' (' + esc(e.login) + ')</option>';
        }).join('');
    }

    function fillShifts(sel, allLabel, activeOnly) {
        if (!sel) return;
        sel.innerHTML = (allLabel ? '<option value="">' + esc(allLabel) + '</option>' : '') + (C.shifts || []).filter(function (s) {
            return !activeOnly || s.status;
        }).map(function (s) {
            return '<option value="' + s.id + '">' + esc(s.name) + ' (' + s.start + ' - ' + s.end + ')' + (s.status ? '' : ' — ngừng') + '</option>';
        }).join('');
    }

    function legendHtml() {
        var order = ['dung_gio', 'di_muon', 've_som', 'thieu', 'dang_lam', 'chua_vao', 'chua_cham', 'nghi_co_phep', 'nghi_khong_phep', 'lich'];
        return order.map(function (k) {
            return '<span class="tgs-cc-lg"><i class="tgs-cc-sw st-' + k + '"></i>' + esc(C.statuses[k][0]) + '</span>';
        }).join('');
    }

    function empty(msg) { return '<div class="tgs-cc-empty">' + esc(msg) + '</div>'; }

    /** Dòng báo các tháng đã chốt trong khoảng đang xem (bảng chấm công / xếp lịch) */
    function lockedBanner(d) {
        if (!d || !d.locked || !d.locked.length) return '';
        var names = d.locked.map(function (m) { return m.slice(5, 7) + '/' + m.slice(0, 4); }).join(', ');
        return '<div class="tgs-cc-lockbar is-locked"><i class="bx bx-lock-alt"></i> Bảng công tháng ' + esc(names)
            + ' đã chốt: không sửa được công và lịch của các ngày thuộc tháng này.</div>';
    }

    /* ═══════════════════════════ XUẤT EXCEL CÓ ĐỊNH DẠNG ═══════════════════════════ */

    var XL = {
        border: { top: { style: 'thin', color: { rgb: 'FFB7BEC9' } }, bottom: { style: 'thin', color: { rgb: 'FFB7BEC9' } }, left: { style: 'thin', color: { rgb: 'FFB7BEC9' } }, right: { style: 'thin', color: { rgb: 'FFB7BEC9' } } },
        font: 'Times New Roman'
    };

    /**
     * o = { file, sheet, titles:[str], header:[[...], ...], rows:[[...]], foot:[[...]], widths:[n],
     *       cellStyle(r, c, v) → {fill, color, bold, align}, after:[[...]], afterMerges:[[r,c1,c2]] }
     */
    function exportSheet(o) {
        var X = window.TGS_XLSX_STYLE || window.XLSX;
        if (!X) { toast('Thư viện Excel chưa tải xong, thử lại sau vài giây.', 'warning'); return; }
        var styled = !!window.TGS_XLSX_STYLE;
        var ncol = o.header[o.header.length - 1].length;
        var aoa = [];
        var merges = [];
        (o.titles || []).forEach(function (t, i) {
            var row = [t];
            for (var k = 1; k < ncol; k++) row.push('');
            aoa.push(row);
            merges.push({ s: { r: i, c: 0 }, e: { r: i, c: ncol - 1 } });
        });
        aoa.push([]);
        var headStart = aoa.length;
        o.header.forEach(function (h) { aoa.push(h); });
        var bodyStart = aoa.length;
        o.rows.forEach(function (r) { aoa.push(r); });
        var footStart = aoa.length;
        (o.foot || []).forEach(function (r) { aoa.push(r); });
        var tableEnd = aoa.length;
        var afterStart = aoa.length + 1;
        if (o.after && o.after.length) {
            aoa.push([]);
            o.after.forEach(function (r) { aoa.push(r); });
        }
        (o.headMerges || []).forEach(function (m) {
            merges.push({ s: { r: headStart + m[0], c: m[1] }, e: { r: headStart + m[2], c: m[3] } });
        });
        (o.afterMerges || []).forEach(function (m) {
            merges.push({ s: { r: afterStart + m[0], c: m[1] }, e: { r: afterStart + m[0], c: m[2] } });
        });

        var ws = X.utils.aoa_to_sheet(aoa);
        ws['!merges'] = merges;
        ws['!cols'] = (o.widths || []).map(function (w) { return { wch: w }; });

        if (styled) {
            Object.keys(ws).forEach(function (addr) {
                if (addr.charAt(0) === '!') return;
                var rc = X.utils.decode_cell(addr);
                var cell = ws[addr];
                var r = rc.r, c = rc.c;
                var s = { font: { name: XL.font, sz: 11 }, alignment: { vertical: 'center', wrapText: true } };
                if (r < headStart - 1) {
                    s.font = { name: XL.font, sz: r === 1 ? 15 : 12, bold: r <= 1 };
                    s.alignment = { horizontal: 'center', vertical: 'center' };
                } else if (r >= headStart && r < bodyStart) {
                    s.font = { name: XL.font, sz: 10, bold: true };
                    s.fill = { fgColor: { rgb: 'FFDCE6F2' } };
                    s.alignment = { horizontal: 'center', vertical: 'center', wrapText: true };
                    s.border = XL.border;
                } else if (r >= bodyStart && r < tableEnd) {
                    s.border = XL.border;
                    if (typeof cell.v === 'number') s.alignment.horizontal = 'right';
                    if (r >= footStart) { s.font.bold = true; s.fill = { fgColor: { rgb: 'FFF3F6FA' } }; }
                    var cs = o.cellStyle ? o.cellStyle(r - bodyStart, c, cell.v, r >= footStart) : null;
                    if (cs) {
                        if (cs.fill) s.fill = { fgColor: { rgb: cs.fill } };
                        if (cs.color) s.font.color = { rgb: cs.color };
                        if (cs.bold) s.font.bold = true;
                        if (cs.align) s.alignment.horizontal = cs.align;
                    }
                } else if (r >= afterStart) {
                    // Dòng sau bảng (bảng công tháng): 0 = ký hiệu, 1 = ngày tháng, 2 = chức danh, 3 = (Ký, ghi rõ họ tên)
                    var k = r - afterStart;
                    s.alignment = { horizontal: k === 0 ? 'left' : 'center', vertical: 'center', wrapText: k === 0 };
                    s.font = { name: XL.font, sz: k === 0 ? 10 : 11, bold: k === 2, italic: k !== 2 };
                }
                if (typeof cell.v === 'number' && Math.round(cell.v) !== cell.v) cell.z = '0.##';
                cell.s = s;
            });
            ws['!rows'] = [];
            ws['!rows'][1] = { hpt: 24 };
            for (var hr = headStart; hr < bodyStart; hr++) ws['!rows'][hr] = { hpt: 30 };
        }

        var wb = X.utils.book_new();
        X.utils.book_append_sheet(wb, ws, (o.sheet || 'Sheet1').slice(0, 31));
        X.writeFile(wb, o.file);
    }

    function fileSlug(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D')
            .replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
    }

    /* ═══════════════════════════ BẢNG CHẤM CÔNG ═══════════════════════════ */

    function pageBoard() {
        var mode = 'week';
        var anchor = C.today;
        var data = null;
        var editing = null;

        fillUsers($('#cc-user'));
        fillShifts($('#cc-shift'), 'Tất cả');
        $('#cc-legend').innerHTML = legendHtml();
        $('#cc-anchor').value = anchor;

        function range() {
            if (mode === 'month') return [monthStart(anchor), monthEnd(anchor)];
            var m = monday(anchor);
            return [m, addDays(m, 6)];
        }

        function load() {
            var r = range();
            $('#cc-board').innerHTML = empty('Đang tải…');
            $('#cc-range-label').textContent = (mode === 'month' ? 'Tháng ' + r[0].slice(5, 7) + '/' + r[0].slice(0, 4) + ': ' : 'Tuần: ') + dmy(r[0]) + ' – ' + dmy(r[1]);
            var u = $('#cc-user').value;
            return api('board', { from: r[0], to: r[1], users: u ? [+u] : [], shift_id: +$('#cc-shift').value || 0 }).then(function (d) {
                data = d;
                render();
            });
        }

        function chip(e, uid, day) {
            var s = shiftMap[e.shift_id] || { name: '#' + e.shift_id, color: '#999' };
            var r = e.record;
            var time = '';
            if (r && (r.in || r.out)) time = (r.in || '--:--') + '–' + (r.out || '--:--');
            else if (e.status === 'lich') time = s.start + '–' + s.end;
            var tip = s.name + ' (' + s.start + ' - ' + s.end + ')\n' + e.status_label
                + (r && r.late ? '\nĐi muộn ' + r.late + ' phút' : '') + (r && r.early ? '\nVề sớm ' + r.early + ' phút' : '')
                + (r && r.note ? '\nGhi chú: ' + r.note : '') + (!e.scheduled ? '\n(Ngoài lịch)' : '');
            return '<button type="button" class="tgs-cc-chip st-' + e.status + (e.scheduled ? '' : ' is-unscheduled') + '" data-u="' + uid + '" data-d="' + day + '" data-s="' + e.shift_id + '" title="' + esc(tip) + '" style="--cc-shift:' + esc(s.color) + '">'
                + '<span class="tgs-cc-chip__name">' + esc(s.name) + '</span>'
                + '<span class="tgs-cc-chip__time">' + esc(time || e.status_label) + '</span></button>';
        }

        function render() {
            if (!data.employees.length) {
                $('#cc-board').innerHTML = empty('Chưa có nhân viên tham gia chấm công. Vào Cài đặt chấm công để chọn nhân viên.');
                return;
            }
            var cells = data.cells || {};
            var h = '<table class="tgs-cc-grid" data-ds-no-resize data-ds-no-export><thead><tr><th class="tgs-cc-grid__name">Nhân viên</th>';
            data.days.forEach(function (d) {
                var cls = (d === data.today ? ' is-today' : '') + (toDate(d).getDay() === 0 ? ' is-sun' : '');
                h += '<th class="tgs-cc-grid__day' + cls + '"><span>' + wd(d) + '</span>' + dm(d) + '</th>';
            });
            h += '<th class="tgs-cc-grid__sum">Công</th></tr></thead><tbody>';
            data.employees.forEach(function (emp) {
                var total = 0;
                h += '<tr><th class="tgs-cc-grid__name"><b>' + esc(emp.name) + '</b><small>' + esc(emp.login) + '</small></th>';
                data.days.forEach(function (d) {
                    var list = cells[emp.id + '|' + d] || [];
                    var cls = (d === data.today ? ' is-today' : '') + (toDate(d).getDay() === 0 ? ' is-sun' : '');
                    h += '<td class="tgs-cc-grid__cell' + cls + '">';
                    list.forEach(function (e) {
                        if (e.record) total += +e.record.work_value || 0;
                        h += chip(e, emp.id, d);
                    });
                    h += '<button type="button" class="tgs-cc-add" data-u="' + emp.id + '" data-d="' + d + '" title="Thêm công">+</button></td>';
                });
                h += '<td class="tgs-cc-grid__sum">' + num(total) + '</td></tr>';
            });
            h += '</tbody></table>';
            $('#cc-board').innerHTML = lockedBanner(data) + h;
        }

        function find(uid, day, sid) {
            var list = (data.cells || {})[uid + '|' + day] || [];
            for (var i = 0; i < list.length; i++) if (list[i].shift_id === sid) return list[i];
            return null;
        }

        function syncMode() {
            var m = $('#cc-rec-mode').value;
            $$('.cc-work-only').forEach(function (el) { el.style.display = m === 'work' ? '' : 'none'; });
        }

        function openRecord(uid, day, sid) {
            var emp = (C.employees || []).filter(function (e) { return e.id === uid; })[0] || { name: '#' + uid };
            var entry = sid ? find(uid, day, sid) : null;
            var r = entry && entry.record;
            editing = { user_id: uid, work_date: day, entry: entry };
            $('#cc-rec-head').innerHTML = '<b>' + esc(emp.name) + '</b> · ' + WD_FULL[toDate(day).getDay()] + ', ' + dmy(day)
                + (entry ? ' <span class="badge bg-label-' + entry.status_tone + '">' + esc(entry.status_label) + '</span>' : '');
            var sel = $('#cc-rec-shift');
            fillShifts(sel, '', !entry);
            if (entry) sel.value = String(entry.shift_id);
            sel.disabled = !!entry;
            var s = shiftMap[+sel.value] || {};
            $('#cc-rec-mode').value = r && r.leave_type ? r.leave_type : 'work';
            $('#cc-rec-in').value = r ? r.in : (s.start || '');
            $('#cc-rec-out').value = r ? r.out : (s.end || '');
            $('#cc-rec-note').value = r ? r.note || '' : '';
            var calc = '';
            if (r && !r.leave_type) {
                calc = 'Giờ làm: <b>' + num(r.worked / 60) + ' giờ</b> · Công: <b>' + num(r.work_value) + '</b>'
                    + (r.late ? ' · Đi muộn <b>' + r.late + '</b> phút' : '') + (r.early ? ' · Về sớm <b>' + r.early + '</b> phút' : '')
                    + (r.overtime ? ' · Làm thêm <b>' + num(r.overtime / 60) + '</b> giờ' : '')
                    + '<br><span class="ds-hint">Vào: ' + esc(r.in_full || '—') + ' (' + esc(srcLabel(r.in_source)) + ') · Ra: ' + esc(r.out_full || '—') + ' (' + esc(srcLabel(r.out_source)) + ')</span>';
            }
            $('#cc-rec-calc').innerHTML = calc;
            $('#cc-rec-delete').style.display = r ? '' : 'none';
            $('#cc-rec-logs-wrap').style.display = r ? '' : 'none';
            $('#cc-rec-logs-wrap').open = false;
            $('#cc-rec-logs').innerHTML = '';
            syncMode();
            modal('cc-record-modal').show();
        }

        function srcLabel(s) {
            return { pos: 'POS', pos_khac: 'POS - tài khoản khác', pos_ho: 'POS - đồng nghiệp điểm danh hộ', admin: 'quản lý nhập', auto: 'duyệt theo lịch' }[s] || (s || '—');
        }

        $('#cc-rec-mode').addEventListener('change', syncMode);
        $('#cc-rec-shift').addEventListener('change', function () {
            var s = shiftMap[+this.value];
            if (s) { $('#cc-rec-in').value = s.start; $('#cc-rec-out').value = s.end; }
        });
        $('#cc-rec-logs-wrap').addEventListener('toggle', function () {
            var r = editing && editing.entry && editing.entry.record;
            if (!this.open || !r || $('#cc-rec-logs').innerHTML) return;
            $('#cc-rec-logs').innerHTML = '<span class="ds-hint">Đang tải…</span>';
            api('record_logs', { record_id: r.record_id }).then(function (rows) {
                $('#cc-rec-logs').innerHTML = rows.length ? '<table class="table table-sm mb-0" data-ds-no-export data-ds-no-resize><tbody>' + rows.map(function (l) {
                    return '<tr><td class="text-nowrap">' + esc(l.time) + '</td><td>' + esc(l.action) + '</td><td>' + esc(l.actor) + '</td><td class="text-muted">' + esc(l.ip) + '</td></tr>';
                }).join('') + '</tbody></table>' : '<span class="ds-hint">Chưa có lịch sử.</span>';
            });
        });

        $('#cc-rec-save').addEventListener('click', function () {
            var btn = this;
            busy(btn, true);
            api('record_save', {
                user_id: editing.user_id,
                work_date: editing.work_date,
                shift_id: +$('#cc-rec-shift').value,
                mode: $('#cc-rec-mode').value,
                check_in: $('#cc-rec-in').value,
                check_out: $('#cc-rec-out').value,
                note: $('#cc-rec-note').value
            }).then(function (d) {
                toast(d.message);
                modal('cc-record-modal').hide();
                load();
            }).finally(function () { busy(btn, false); });
        });

        $('#cc-rec-delete').addEventListener('click', function () {
            var r = editing.entry && editing.entry.record;
            if (!r || !window.confirm('Xoá dữ liệu chấm công của ca này? Lịch làm việc (nếu có) vẫn giữ.')) return;
            api('record_delete', { record_id: r.record_id }).then(function (d) {
                toast(d.message);
                modal('cc-record-modal').hide();
                load();
            });
        });

        $('#cc-board').addEventListener('click', function (e) {
            var b = e.target.closest('.tgs-cc-chip, .tgs-cc-add');
            if (!b) return;
            openRecord(+b.dataset.u, b.dataset.d, b.dataset.s ? +b.dataset.s : 0);
        });

        function step(n) {
            anchor = mode === 'month' ? addMonths(anchor, n) : addDays(anchor, 7 * n);
            $('#cc-anchor').value = anchor;
            load();
        }
        $('#cc-prev').addEventListener('click', function () { step(-1); });
        $('#cc-next').addEventListener('click', function () { step(1); });
        $('#cc-today').addEventListener('click', function () { anchor = C.today; $('#cc-anchor').value = anchor; load(); });
        $('#cc-anchor').addEventListener('change', function () { if (this.value) { anchor = this.value; load(); } });
        $('#cc-mode').addEventListener('change', function () { mode = this.value; load(); });
        $('#cc-load').addEventListener('click', load);
        $('#cc-user').addEventListener('change', load);
        $('#cc-shift').addEventListener('change', load);

        $('#cc-fill').addEventListener('click', function () {
            var r = range();
            if (!window.confirm('Điền ĐỦ CÔNG (vào = giờ vào ca, ra = giờ ra ca) cho mọi ca đã xếp lịch, đã hết giờ mà chưa chấm, từ ' + dmy(r[0]) + ' đến ' + dmy(r[1]) + '?\nCa đã có dữ liệu chấm hoặc nghỉ phép không bị đụng tới.')) return;
            var btn = this;
            busy(btn, true);
            var u = $('#cc-user').value;
            api('fill_schedule', { from: r[0], to: r[1], users: u ? [+u] : [] }).then(function (d) {
                toast(d.message);
                load();
            }).finally(function () { busy(btn, false); });
        });

        $('#cc-export').addEventListener('click', function () {
            if (!data) return;
            var cells = data.cells || {};
            var header = ['STT', 'Mã NV', 'Nhân viên'].concat(data.days.map(function (d) { return wd(d) + ' ' + dm(d); })).concat(['Tổng công']);
            var rows = data.employees.map(function (emp, i) {
                var total = 0;
                var row = [i + 1, emp.login, emp.name];
                data.days.forEach(function (d) {
                    row.push((cells[emp.id + '|' + d] || []).map(function (e) {
                        var s = shiftMap[e.shift_id] || { name: '#' + e.shift_id };
                        var r = e.record;
                        if (r) total += +r.work_value || 0;
                        var t = r && (r.in || r.out) ? ' ' + (r.in || '--') + '-' + (r.out || '--') : '';
                        return s.name + t + ' (' + e.status_label + ')';
                    }).join('\n'));
                });
                row.push(Math.round(total * 100) / 100);
                return row;
            });
            exportSheet({
                file: 'Bang_cham_cong_' + fileSlug(C.shopName) + '_' + data.from + '_' + data.to + '.xlsx',
                sheet: 'Bang cham cong',
                titles: [C.settings.report_title || C.shopName, 'BẢNG CHẤM CÔNG', 'Từ ngày ' + dmy(data.from) + ' đến ngày ' + dmy(data.to)],
                header: [header],
                rows: rows,
                widths: [5, 12, 24].concat(data.days.map(function () { return 18; })).concat([10])
            });
        });

        load();
    }

    /* ═══════════════════════════ XẾP LỊCH ═══════════════════════════ */

    function pageSchedule() {
        var anchor = C.today;
        var data = null;
        var cellCtx = null;

        fillUsers($('#cc-user'), 'Tất cả nhân viên');
        $('#cc-anchor').value = anchor;

        function range() { var m = monday(anchor); return [m, addDays(m, 6)]; }

        function load() {
            var r = range();
            $('#cc-range-label').textContent = dmy(r[0]) + ' – ' + dmy(r[1]);
            $('#cc-board').innerHTML = empty('Đang tải…');
            var u = $('#cc-user').value;
            return api('board', { from: r[0], to: r[1], users: u ? [+u] : [] }).then(function (d) { data = d; render(); });
        }

        function scheduledIds(uid, day) {
            return ((data.cells || {})[uid + '|' + day] || []).filter(function (e) { return e.scheduled; }).map(function (e) { return e.shift_id; });
        }

        function render() {
            if (!data.employees.length) {
                $('#cc-board').innerHTML = empty('Chưa có nhân viên tham gia chấm công. Vào Cài đặt chấm công để chọn nhân viên.');
                return;
            }
            var count = {};
            var h = '<table class="tgs-cc-grid tgs-cc-grid--week" data-ds-no-resize data-ds-no-export><thead><tr><th class="tgs-cc-grid__name">Nhân viên</th>';
            data.days.forEach(function (d) {
                h += '<th class="tgs-cc-grid__day' + (d === data.today ? ' is-today' : '') + (toDate(d).getDay() === 0 ? ' is-sun' : '') + '"><span>' + WD_FULL[toDate(d).getDay()] + '</span>' + dm(d) + '</th>';
            });
            h += '</tr></thead><tbody>';
            data.employees.forEach(function (emp) {
                h += '<tr><th class="tgs-cc-grid__name"><b>' + esc(emp.name) + '</b><small>' + esc(emp.login) + '</small></th>';
                data.days.forEach(function (d) {
                    var ids = scheduledIds(emp.id, d);
                    h += '<td class="tgs-cc-grid__cell tgs-cc-sched' + (d === data.today ? ' is-today' : '') + (toDate(d).getDay() === 0 ? ' is-sun' : '') + '" data-u="' + emp.id + '" data-d="' + d + '" title="Bấm để chọn ca">';
                    ids.forEach(function (sid) {
                        var s = shiftMap[sid] || { name: '#' + sid, color: '#999', start: '', end: '' };
                        count[d + '|' + sid] = (count[d + '|' + sid] || 0) + 1;
                        h += '<span class="tgs-cc-pill" style="--cc-shift:' + esc(s.color) + '">' + esc(s.name) + '<small>' + s.start + '–' + s.end + '</small></span>';
                    });
                    if (!ids.length) h += '<span class="tgs-cc-pill-empty">+</span>';
                    h += '</td>';
                });
                h += '</tr>';
            });
            h += '</tbody><tfoot><tr><th class="tgs-cc-grid__name">Số người / ca</th>';
            data.days.forEach(function (d) {
                h += '<td class="tgs-cc-grid__cell">' + (C.shifts || []).filter(function (s) { return count[d + '|' + s.id]; }).map(function (s) {
                    return '<div class="tgs-cc-count"><i style="background:' + esc(s.color) + '"></i>' + esc(s.name) + ': <b>' + count[d + '|' + s.id] + '</b></div>';
                }).join('') + '</td>';
            });
            h += '</tr></tfoot></table>';
            $('#cc-board').innerHTML = lockedBanner(data) + h;
        }

        $('#cc-board').addEventListener('click', function (e) {
            var td = e.target.closest('.tgs-cc-sched');
            if (!td) return;
            var uid = +td.dataset.u, day = td.dataset.d;
            var emp = (C.employees || []).filter(function (x) { return x.id === uid; })[0] || {};
            var ids = scheduledIds(uid, day);
            cellCtx = { user_id: uid, date: day };
            $('#cc-cell-head').innerHTML = '<b>' + esc(emp.name) + '</b><br>' + WD_FULL[toDate(day).getDay()] + ', ' + dmy(day);
            $('#cc-cell-shifts').innerHTML = (C.shifts || []).filter(function (s) { return s.status || ids.indexOf(s.id) >= 0; }).map(function (s) {
                return '<label><input type="checkbox" class="form-check-input" value="' + s.id + '"' + (ids.indexOf(s.id) >= 0 ? ' checked' : '') + '> '
                    + '<i class="tgs-cc-dot" style="background:' + esc(s.color) + '"></i>' + esc(s.name) + ' <span class="text-muted">' + s.start + ' - ' + s.end + '</span></label>';
            }).join('') || '<span class="ds-hint">Chưa có ca nào đang hoạt động.</span>';
            modal('cc-cell-modal').show();
        });

        $('#cc-cell-save').addEventListener('click', function () {
            var btn = this;
            busy(btn, true);
            var ids = $$('#cc-cell-shifts input:checked').map(function (i) { return +i.value; });
            api('schedule_cell', { user_id: cellCtx.user_id, date: cellCtx.date, shift_ids: ids }).then(function () {
                modal('cc-cell-modal').hide();
                load();
            }).finally(function () { busy(btn, false); });
        });

        // Xếp hàng loạt
        $('#cc-bulk-open').addEventListener('click', function () {
            var r = range();
            $('#cc-bulk-users').innerHTML = (C.employees || []).map(function (e) {
                return '<label><input type="checkbox" class="form-check-input" value="' + e.id + '"> ' + esc(e.name) + ' <span class="text-muted">' + esc(e.login) + '</span></label>';
            }).join('') || '<span class="ds-hint">Chưa có nhân viên.</span>';
            $('#cc-bulk-shifts').innerHTML = (C.shifts || []).filter(function (s) { return s.status; }).map(function (s) {
                return '<label><input type="checkbox" class="form-check-input" value="' + s.id + '"> <i class="tgs-cc-dot" style="background:' + esc(s.color) + '"></i>' + esc(s.name) + ' <span class="text-muted">' + s.start + ' - ' + s.end + '</span></label>';
            }).join('');
            $('#cc-bulk-weekdays').innerHTML = [1, 2, 3, 4, 5, 6, 0].map(function (w) {
                return '<label><input type="checkbox" class="form-check-input" value="' + w + '"' + (w !== 0 ? ' checked' : '') + '> ' + WD[w] + '</label>';
            }).join('');
            $('#cc-bulk-from').value = r[0];
            // Mặc định xếp tới hết tháng (hoặc hết tuần nếu tuần vắt sang tháng sau)
            $('#cc-bulk-to').value = monthEnd(r[0]) > r[1] ? monthEnd(r[0]) : r[1];
            $('#cc-bulk-all').checked = false;
            $('#cc-bulk-replace').checked = false;
            modal('cc-bulk-modal').show();
        });
        $('#cc-bulk-all').addEventListener('change', function () {
            var on = this.checked;
            $$('#cc-bulk-users input').forEach(function (i) { i.checked = on; });
        });
        $('#cc-bulk-save').addEventListener('click', function () {
            var p = {
                users: $$('#cc-bulk-users input:checked').map(function (i) { return +i.value; }),
                shift_ids: $$('#cc-bulk-shifts input:checked').map(function (i) { return +i.value; }),
                weekdays: $$('#cc-bulk-weekdays input:checked').map(function (i) { return +i.value; }),
                from: $('#cc-bulk-from').value,
                to: $('#cc-bulk-to').value,
                replace: $('#cc-bulk-replace').checked ? 1 : 0
            };
            if (!p.users.length || !p.shift_ids.length || !p.weekdays.length) { toast('Chọn nhân viên, ca và thứ trong tuần.', 'warning'); return; }
            var btn = this;
            busy(btn, true);
            api('schedule_bulk', p).then(function (d) {
                toast(d.message);
                modal('cc-bulk-modal').hide();
                load();
            }).finally(function () { busy(btn, false); });
        });

        $('#cc-copy-week').addEventListener('click', function () {
            var r = range();
            var src = addDays(r[0], -7);
            if (!window.confirm('Chép lịch tuần ' + dmy(src) + ' – ' + dmy(addDays(src, 6)) + ' sang tuần đang xem? Lịch hiện có của tuần đang xem sẽ bị thay thế.')) return;
            var u = $('#cc-user').value;
            api('schedule_copy_week', { src: src, dst: r[0], users: u ? [+u] : [] }).then(function (d) { toast(d.message); load(); });
        });
        $('#cc-clear-week').addEventListener('click', function () {
            var r = range();
            if (!window.confirm('Xoá toàn bộ lịch làm việc từ ' + dmy(r[0]) + ' đến ' + dmy(r[1]) + '? Dữ liệu đã chấm công không bị xoá.')) return;
            var u = $('#cc-user').value;
            api('schedule_clear', { from: r[0], to: r[1], users: u ? [+u] : [] }).then(function (d) { toast(d.message); load(); });
        });

        $('#cc-prev').addEventListener('click', function () { anchor = addDays(anchor, -7); $('#cc-anchor').value = anchor; load(); });
        $('#cc-next').addEventListener('click', function () { anchor = addDays(anchor, 7); $('#cc-anchor').value = anchor; load(); });
        $('#cc-today').addEventListener('click', function () { anchor = C.today; $('#cc-anchor').value = anchor; load(); });
        $('#cc-anchor').addEventListener('change', function () { if (this.value) { anchor = this.value; load(); } });
        $('#cc-user').addEventListener('change', load);

        load();
    }

    /* ═══════════════════════════ CA LÀM VIỆC ═══════════════════════════ */

    function pageShifts() {
        function render() {
            var rows = C.shifts || [];
            $('#cc-shift-table tbody').innerHTML = rows.length ? rows.map(function (s, i) {
                return '<tr>'
                    + '<td class="text-muted">' + (i + 1) + '</td>'
                    + '<td><code>' + esc(s.code) + '</code></td>'
                    + '<td><i class="tgs-cc-dot" style="background:' + esc(s.color) + '"></i><b>' + esc(s.name) + '</b></td>'
                    + '<td>' + s.start + ' – ' + s.end + (s.overnight ? ' <span class="badge bg-label-info">qua đêm</span>' : '') + '</td>'
                    + '<td class="ds-col-num">' + s.break_minutes + '</td>'
                    + '<td class="ds-col-num">' + num(s.hours) + '</td>'
                    + '<td>Vào sớm ' + s.checkin_before + '′ · ra muộn ' + s.checkout_after + '′</td>'
                    + '<td class="ds-col-num">' + num(s.work_value) + '</td>'
                    + '<td>' + (s.status ? '<span class="badge bg-label-success">Hoạt động</span>' : '<span class="badge bg-label-secondary">Ngừng hoạt động</span>') + '</td>'
                    + '<td class="ds-col-act"><button class="btn btn-sm btn-icon btn-secondary" data-edit="' + s.id + '" title="Sửa"><i class="bx bx-edit"></i></button> '
                    + '<button class="btn btn-sm btn-icon btn-secondary" data-del="' + s.id + '" title="Xoá"><i class="bx bx-trash"></i></button></td>'
                    + '</tr>';
            }).join('') : '<tr><td colspan="10" class="text-muted">Chưa có ca nào. Bấm "Thêm ca".</td></tr>';
        }

        function reload() {
            return api('shift_list').then(function (list) { C.shifts = list; indexShifts(); render(); });
        }

        function updateWindowHint() {
            var st = $('#cc-sf-start').value, en = $('#cc-sf-end').value;
            if (!st || !en) { $('#cc-sf-window').textContent = ''; return; }
            var m = function (t) { var p = t.split(':'); return +p[0] * 60 + +p[1]; };
            var f = function (x) { x = ((x % 1440) + 1440) % 1440; return pad(Math.floor(x / 60)) + ':' + pad(x % 60); };
            var b = +$('#cc-sf-before').value || 0, a = +$('#cc-sf-after').value || 0;
            $('#cc-sf-window').textContent = 'Nhân viên chấm VÀO được từ ' + f(m(st) - b) + ' đến trước ' + en
                + '; chấm RA muộn nhất ' + f(m(en) + a) + (m(en) <= m(st) ? ' (hôm sau — ca qua đêm)' : '') + '.';
        }
        ['#cc-sf-start', '#cc-sf-end', '#cc-sf-before', '#cc-sf-after'].forEach(function (s) { $(s).addEventListener('input', updateWindowHint); });

        function open(s) {
            s = s || { id: '', name: '', code: '', start: '08:00', end: '17:00', break_minutes: 0, work_value: 1, checkin_before: 60, checkout_after: 120, color: '#1e4fa3', sort_order: (C.shifts || []).length + 1, status: 1 };
            $('#cc-sf-id').value = s.id;
            $('#cc-sf-name').value = s.name;
            $('#cc-sf-code').value = s.code;
            $('#cc-sf-start').value = s.start;
            $('#cc-sf-end').value = s.end;
            $('#cc-sf-break').value = s.break_minutes;
            $('#cc-sf-value').value = s.work_value;
            $('#cc-sf-before').value = s.checkin_before;
            $('#cc-sf-after').value = s.checkout_after;
            $('#cc-sf-color').value = s.color;
            $('#cc-sf-sort').value = s.sort_order;
            $('#cc-sf-status').checked = !!s.status;
            updateWindowHint();
            modal('cc-shift-modal').show();
        }

        $('#cc-shift-add').addEventListener('click', function () { open(null); });
        $('#cc-shift-table').addEventListener('click', function (e) {
            var ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
            if (ed) open(shiftMap[+ed.dataset.edit]);
            if (del) {
                var s = shiftMap[+del.dataset.del];
                if (!window.confirm('Xoá ca "' + s.name + '"? Lịch làm việc của ca này cũng bị xoá.')) return;
                api('shift_delete', { id: s.id }).then(function (d) { toast(d.message, d.deleted ? 'success' : 'warning'); reload(); });
            }
        });
        $('#cc-sf-save').addEventListener('click', function () {
            var btn = this;
            busy(btn, true);
            api('shift_save', {
                id: +$('#cc-sf-id').value || 0,
                name: $('#cc-sf-name').value,
                code: $('#cc-sf-code').value,
                start: $('#cc-sf-start').value,
                end: $('#cc-sf-end').value,
                break_minutes: $('#cc-sf-break').value,
                work_value: $('#cc-sf-value').value,
                checkin_before: $('#cc-sf-before').value,
                checkout_after: $('#cc-sf-after').value,
                color: $('#cc-sf-color').value,
                sort_order: $('#cc-sf-sort').value,
                status: $('#cc-sf-status').checked ? 1 : 0
            }).then(function (d) {
                toast(d.message);
                modal('cc-shift-modal').hide();
                reload();
            }).finally(function () { busy(btn, false); });
        });

        render();
    }

    /* ═══════════════════════════ BÁO CÁO ═══════════════════════════ */

    function pageReport() {
        var tab = 'month';
        var data = null;
        fillUsers($('#cc-user'));
        fillShifts($('#cc-shift'), 'Tất cả');
        $('#cc-month').value = C.today.slice(0, 7);
        $('#cc-from').value = monthStart(C.today);
        $('#cc-to').value = C.today;

        function syncFilters() {
            $$('.cc-f-month').forEach(function (el) { el.style.display = tab === 'month' ? '' : 'none'; });
            $$('.cc-f-range').forEach(function (el) { el.style.display = tab === 'month' ? 'none' : ''; });
            $$('#cc-rtabs .nav-link').forEach(function (a) { a.classList.toggle('active', a.dataset.rt === tab); });
        }

        function users() { var u = $('#cc-user').value; return u ? [+u] : []; }

        function load() {
            data = null;
            $('#cc-report').innerHTML = empty('Đang tải…');
            var p = tab === 'month'
                ? { month: $('#cc-month').value, users: users() }
                : { from: $('#cc-from').value, to: $('#cc-to').value, users: users(), shift_id: +$('#cc-shift').value || 0 };
            api('report_' + tab, p).then(function (d) {
                data = d;
                ({ month: renderMonth, summary: renderSummary, detail: renderDetail })[tab]();
            }, function () { $('#cc-report').innerHTML = empty('Không tải được báo cáo.'); });
        }

        var TONE_CLASS = { ok: 'm-ok', late: 'm-late', warn: 'm-warn', leave: 'm-leave', bad: 'm-bad', info: 'm-info', plan: 'm-plan' };

        function monthTitle() {
            return 'BẢNG CHẤM CÔNG THÁNG ' + data.month.slice(5, 7) + '/' + data.month.slice(0, 4);
        }

        function lockBar(d) {
            var label = d.month.slice(5, 7) + '/' + d.month.slice(0, 4);
            if (d.lock) {
                return '<div class="tgs-cc-lockbar is-locked tgs-cc-noprint"><i class="bx bx-lock-alt"></i> Bảng công tháng ' + label + ' đã chốt'
                    + (d.lock.by ? ' bởi <b>' + esc(d.lock.by) + '</b>' : '') + (d.lock.at ? ' lúc ' + esc(d.lock.at) : '')
                    + '. Công, lịch và giờ ca của tháng này không còn bị thay đổi.'
                    + (d.can_unlock ? ' <button type="button" class="btn btn-sm btn-secondary ms-auto" id="cc-unlock" data-m="' + esc(d.month) + '"><i class="bx bx-lock-open-alt"></i> Mở chốt</button>' : '')
                    + '</div>';
            }
            if (d.can_lock) {
                return '<div class="tgs-cc-lockbar tgs-cc-noprint"><i class="bx bx-info-circle"></i> Tháng ' + label + ' chưa chốt: sửa ca, sửa công hoặc đổi cài đặt vẫn có thể làm số liệu thay đổi. Kiểm tra xong thì chốt để khoá lại.'
                    + ' <button type="button" class="btn btn-sm btn-primary ms-auto" id="cc-lock" data-m="' + esc(d.month) + '"><i class="bx bx-lock-alt"></i> Chốt bảng công tháng ' + label + '</button></div>';
            }
            return '<div class="tgs-cc-lockbar tgs-cc-noprint"><i class="bx bx-time-five"></i> Tháng ' + label + ' chưa kết thúc — chốt được từ ngày đầu tháng sau.</div>';
        }

        $('#cc-report').addEventListener('click', function (e) {
            var b = e.target.closest('#cc-lock, #cc-unlock');
            if (!b) return;
            var m = b.dataset.m, label = m.slice(5, 7) + '/' + m.slice(0, 4);
            var lock = b.id === 'cc-lock';
            if (!window.confirm(lock
                ? 'Chốt bảng công tháng ' + label + '?\nSau khi chốt: không sửa được công, lịch của tháng này; sửa giờ ca hay đổi cài đặt cũng không làm thay đổi công tháng này.'
                : 'Mở chốt bảng công tháng ' + label + '?\nSố liệu tháng này sẽ sửa được trở lại.')) return;
            busy(b, true);
            api(lock ? 'month_lock' : 'month_unlock', { month: m }).then(function (d) { toast(d.message); load(); }).finally(function () { busy(b, false); });
        });

        function renderMonth() {
            var d = data;
            if (!d.rows.length) { $('#cc-report').innerHTML = lockBar(d) + empty('Không có nhân viên nào.'); return; }
            var h = lockBar(d) + '<div class="tgs-cc-print-head"><div class="tgs-cc-print-head__org">' + esc(d.report_title || d.shop_name) + '</div>'
                + '<div class="tgs-cc-print-head__title">' + monthTitle() + '</div>'
                + '<div class="tgs-cc-print-head__meta">Từ ngày ' + dmy(d.from) + ' đến ngày ' + dmy(d.to) + ' · Ngày công chuẩn: ' + d.standard_days + '</div></div>';
            h += '<div class="tgs-cc-month-wrap"><table class="tgs-cc-month" data-ds-no-resize data-ds-no-export><thead><tr>'
                + '<th rowspan="2">STT</th><th rowspan="2">Mã NV</th><th rowspan="2" class="tgs-cc-month__name">Họ và tên</th>';
            d.days.forEach(function (day) { h += '<th class="' + (toDate(day).getDay() === 0 ? 'is-sun' : '') + '">' + +day.slice(8, 10) + '</th>'; });
            h += '<th rowspan="2">Tổng công</th><th rowspan="2">Ngày công</th><th rowspan="2">Giờ làm</th><th rowspan="2">Làm thêm (giờ)</th><th rowspan="2">Muộn / sớm (lần)</th><th rowspan="2">P</th><th rowspan="2">KP</th><th rowspan="2">V</th><th rowspan="2">T</th><th rowspan="2" class="tgs-cc-month__sign">Ký nhận</th></tr><tr>';
            d.days.forEach(function (day) { h += '<th class="tgs-cc-month__wd' + (toDate(day).getDay() === 0 ? ' is-sun' : '') + '">' + wd(day) + '</th>'; });
            h += '</tr></thead><tbody>';
            d.rows.forEach(function (r, i) {
                h += '<tr><td>' + (i + 1) + '</td><td>' + esc(r.login) + '</td><td class="tgs-cc-month__name">' + esc(r.name) + '</td>';
                d.days.forEach(function (day) {
                    var c = r.days[day] || {};
                    h += '<td class="' + (TONE_CLASS[c.tone] || '') + (toDate(day).getDay() === 0 ? ' is-sun' : '') + '">' + esc(c.sym || '') + '</td>';
                });
                h += '<td class="n"><b>' + num(r.tong_cong) + '</b></td><td class="n">' + r.ngay_cong + '</td><td class="n">' + num(r.gio_lam, 1) + '</td><td class="n">' + num(r.gio_lam_them, 1) + '</td>'
                    + '<td class="n">' + (r.muon_lan + r.som_lan) + '</td><td class="n">' + r.nghi_co_phep + '</td><td class="n">' + r.nghi_khong_phep + '</td><td class="n">' + r.vang + '</td><td class="n">' + r.cham_thieu + '</td><td></td></tr>';
            });
            h += '</tbody></table></div>';
            h += '<div class="tgs-cc-month-legend"><b>Ký hiệu:</b> X = đủ 1 công · X/2 = nửa công · số = tổng công khi làm nhiều ca · P = nghỉ có phép · KP = nghỉ không phép · V = vắng (có lịch, không chấm) · T = chấm công thiếu · Đ = đang trong ca'
                + ' &nbsp; <span class="tgs-cc-sw m-late"></span> có đi muộn / về sớm</div>';
            h += '<div class="tgs-cc-signs"><div class="tgs-cc-signs__date">Ngày ..... tháng ..... năm ' + d.month.slice(0, 4) + '</div><div class="tgs-cc-signs__row">'
                + (d.signers || []).map(function (s) { return '<div><b>' + esc(s) + '</b><i>(Ký, ghi rõ họ tên)</i></div>'; }).join('') + '</div></div>';
            $('#cc-report').innerHTML = h;
        }

        var SUM_COLS = [
            ['so_ca_lich', 'Ca có lịch'], ['so_ca_di_lam', 'Ca đi làm'], ['ngay_cong', 'Ngày công'], ['tong_cong', 'Tổng công'],
            ['gio_lam', 'Giờ làm'], ['gio_lam_them', 'Làm thêm (giờ)'], ['muon_lan', 'Đi muộn (lần)'], ['muon_phut', 'Đi muộn (phút)'],
            ['som_lan', 'Về sớm (lần)'], ['som_phut', 'Về sớm (phút)'], ['cham_thieu', 'Chấm thiếu'], ['nghi_co_phep', 'Nghỉ có phép'],
            ['nghi_khong_phep', 'Nghỉ không phép'], ['vang', 'Vắng']
        ];

        function renderSummary() {
            var d = data;
            if (!d.rows.length) { $('#cc-report').innerHTML = empty('Không có dữ liệu.'); return; }
            var h = '<div class="ds-toolbar"><strong>Tổng hợp chấm công từ ' + dmy(d.from) + ' đến ' + dmy(d.to) + '</strong><div class="ds-toolbar__spacer"></div><span class="ds-hint">Ngày công chuẩn: ' + d.standard_days + '</span></div>'
                + '<div class="table-responsive"><table class="table table-hover table-grid" data-ds-no-grid data-ds-no-export><thead><tr><th style="width:40px">#</th><th>Mã NV</th><th>Nhân viên</th>'
                + SUM_COLS.map(function (c) { return '<th class="ds-col-num">' + c[1] + '</th>'; }).join('') + '</tr></thead><tbody>';
            d.rows.forEach(function (r, i) {
                h += '<tr><td class="text-muted">' + (i + 1) + '</td><td>' + esc(r.login) + '</td><td><b>' + esc(r.name) + '</b></td>'
                    + SUM_COLS.map(function (c) {
                        var v = r[c[0]];
                        var warn = (c[0] === 'vang' || c[0] === 'cham_thieu' || c[0] === 'nghi_khong_phep') && v > 0;
                        return '<td class="ds-col-num' + (warn ? ' text-danger fw-semibold' : '') + '">' + (v ? num(v) : '<span class="text-muted">0</span>') + '</td>';
                    }).join('') + '</tr>';
            });
            h += '</tbody><tfoot><tr><td colspan="3" class="text-end">Tổng cộng:</td>'
                + SUM_COLS.map(function (c) { return '<td class="ds-col-num">' + num(d.total[c[0]]) + '</td>'; }).join('') + '</tr></tfoot></table></div>';
            $('#cc-report').innerHTML = h;
        }

        var DET_COLS = ['Ngày', 'Thứ', 'Mã NV', 'Nhân viên', 'Ca', 'Giờ ca', 'Giờ vào', 'Giờ ra', 'Muộn (phút)', 'Sớm (phút)', 'Giờ làm', 'Làm thêm (giờ)', 'Công', 'Trạng thái', 'Nguồn', 'Ghi chú'];

        function renderDetail() {
            var d = data;
            if (!d.rows.length) { $('#cc-report').innerHTML = empty('Không có dữ liệu chấm công trong khoảng này.'); return; }
            var h = '<div class="ds-toolbar"><strong>Chi tiết chấm công từ ' + dmy(d.from) + ' đến ' + dmy(d.to) + '</strong><div class="ds-toolbar__spacer"></div><span class="ds-hint">' + d.rows.length + ' dòng</span></div>'
                + '<div class="table-responsive"><table class="table table-hover table-grid" data-ds-no-grid data-ds-no-export><thead><tr>'
                + DET_COLS.map(function (c, i) { return '<th' + (i >= 8 && i <= 12 ? ' class="ds-col-num"' : '') + '>' + c + '</th>'; }).join('') + '</tr></thead><tbody>';
            d.rows.forEach(function (r) {
                h += '<tr><td>' + dmy(r.date) + '</td><td>' + wd(r.date) + '</td><td>' + esc(r.login) + '</td><td>' + esc(r.name) + '</td>'
                    + '<td>' + esc(r.shift) + (r.scheduled ? '' : ' <span class="badge bg-label-secondary">ngoài lịch</span>') + '</td><td>' + esc(r.shift_time) + '</td>'
                    + '<td>' + esc(r.in) + '</td><td>' + esc(r.out) + '</td>'
                    + '<td class="ds-col-num' + (r.late ? ' text-warning fw-semibold' : '') + '">' + (r.late || '') + '</td>'
                    + '<td class="ds-col-num' + (r.early ? ' text-warning fw-semibold' : '') + '">' + (r.early || '') + '</td>'
                    + '<td class="ds-col-num">' + (r.worked ? num(r.worked) : '') + '</td><td class="ds-col-num">' + (r.overtime ? num(r.overtime) : '') + '</td>'
                    + '<td class="ds-col-num">' + (r.work_value ? num(r.work_value) : '') + '</td>'
                    + '<td><span class="badge bg-label-' + r.status_tone + '">' + esc(r.status_label) + '</span></td><td class="text-muted">' + esc(r.source) + '</td><td>' + esc(r.note) + '</td></tr>';
            });
            h += '</tbody></table></div>';
            $('#cc-report').innerHTML = h;
        }

        /* Xuất Excel theo từng tab */
        var MONTH_FILL = { ok: null, late: 'FFFFF1C2', warn: 'FFFDE2E1', leave: 'FFE3ECFA', bad: 'FFFDE2E1', info: 'FFE0F2F9', plan: null };
        var MONTH_FONT = { bad: 'FFB42318', warn: 'FFB42318', leave: 'FF1E4FA3' };

        function exportMonth() {
            var d = data;
            var nDay = d.days.length;
            var head1 = ['STT', 'Mã NV', 'Họ và tên'].concat(d.days.map(function (x) { return +x.slice(8, 10); }))
                .concat(['Tổng công', 'Ngày công', 'Giờ làm', 'Làm thêm (giờ)', 'Muộn/sớm (lần)', 'Nghỉ P', 'Nghỉ KP', 'Vắng', 'Thiếu', 'Ký nhận']);
            var head2 = ['', '', ''].concat(d.days.map(wd)).concat(['', '', '', '', '', '', '', '', '', '']);
            var ncol = head1.length;
            var tones = [];
            var rows = d.rows.map(function (r, i) {
                var t = [];
                var row = [i + 1, r.login, r.name];
                d.days.forEach(function (day) { var c = r.days[day] || {}; row.push(c.sym || ''); t.push(c.tone || ''); });
                tones.push(t);
                return row.concat([r.tong_cong, r.ngay_cong, r.gio_lam, r.gio_lam_them, r.muon_lan + r.som_lan, r.nghi_co_phep, r.nghi_khong_phep, r.vang, r.cham_thieu, '']);
            });
            var tot = rows.reduce(function (a, r) {
                for (var k = 3 + nDay; k < 3 + nDay + 9; k++) a[k] = Math.round(((a[k] || 0) + (+r[k] || 0)) * 100) / 100;
                return a;
            }, []);
            var foot = ['', '', 'Tổng cộng'];
            for (var k = 3; k < ncol; k++) foot.push(k >= 3 + nDay && k < 3 + nDay + 9 ? tot[k] : '');

            var headMerges = [[0, 0, 1, 0], [0, 1, 1, 1], [0, 2, 1, 2]];
            for (var m = 3 + nDay; m < ncol; m++) headMerges.push([0, m, 1, m]);

            // Khối chữ ký: chia đều các chức danh trên bề ngang bảng
            var signers = d.signers && d.signers.length ? d.signers : ['Người lập biểu'];
            var span = Math.floor(ncol / signers.length);
            var dateRow = new Array(ncol).fill('');
            dateRow[ncol - span] = 'Ngày ..... tháng ..... năm ' + d.month.slice(0, 4);
            var titleRow = new Array(ncol).fill('');
            var noteRow = new Array(ncol).fill('');
            var afterMerges = [[0, ncol - span, ncol - 1]];
            signers.forEach(function (s, i) {
                var c1 = i * span, c2 = i === signers.length - 1 ? ncol - 1 : (i + 1) * span - 1;
                titleRow[c1] = s;
                noteRow[c1] = '(Ký, ghi rõ họ tên)';
                afterMerges.push([1, c1, c2], [2, c1, c2]);
            });
            var legend = new Array(ncol).fill('');
            legend[0] = 'Ký hiệu: X = đủ 1 công; X/2 = nửa công; số = tổng công nhiều ca; P = nghỉ có phép; KP = nghỉ không phép; V = vắng; T = chấm công thiếu; nền vàng = có đi muộn/về sớm.';

            exportSheet({
                file: 'Bang_cong_thang_' + d.month.replace('-', '_') + '_' + fileSlug(d.shop_name) + '.xlsx',
                sheet: 'Bang cong ' + d.month,
                titles: [d.report_title || d.shop_name, monthTitle(), 'Từ ngày ' + dmy(d.from) + ' đến ngày ' + dmy(d.to) + ' — Ngày công chuẩn: ' + d.standard_days],
                header: [head1, head2],
                headMerges: headMerges,
                rows: rows,
                foot: [foot],
                widths: [5, 11, 24].concat(d.days.map(function () { return 4.5; })).concat([8, 8, 8, 9, 9, 6, 6, 6, 6, 14]),
                cellStyle: function (r, c, v, isFoot) {
                    if (isFoot) return null;
                    if (c >= 3 && c < 3 + nDay) {
                        var tone = (tones[r] || [])[c - 3];
                        var sun = toDate(d.days[c - 3]).getDay() === 0;
                        return { fill: MONTH_FILL[tone] || (sun ? 'FFF1F3F6' : null), color: MONTH_FONT[tone] || null, bold: !!v, align: 'center' };
                    }
                    if (c === 3 + nDay) return { bold: true };
                    return null;
                },
                after: [legend, dateRow, titleRow, noteRow],
                afterMerges: [[0, 0, ncol - 1]].concat(afterMerges.map(function (a) { return [a[0] + 1, a[1], a[2]]; }))
            });
        }

        function exportSummary() {
            var d = data;
            exportSheet({
                file: 'Tong_hop_cham_cong_' + d.from + '_' + d.to + '_' + fileSlug(C.shopName) + '.xlsx',
                sheet: 'Tong hop',
                titles: [C.settings.report_title || C.shopName, 'BÁO CÁO TỔNG HỢP CHẤM CÔNG', 'Từ ngày ' + dmy(d.from) + ' đến ngày ' + dmy(d.to) + ' — Ngày công chuẩn: ' + d.standard_days],
                header: [['STT', 'Mã NV', 'Nhân viên'].concat(SUM_COLS.map(function (c) { return c[1]; }))],
                rows: d.rows.map(function (r, i) { return [i + 1, r.login, r.name].concat(SUM_COLS.map(function (c) { return r[c[0]]; })); }),
                foot: [['', '', 'Tổng cộng'].concat(SUM_COLS.map(function (c) { return d.total[c[0]]; }))],
                widths: [5, 12, 26].concat(SUM_COLS.map(function () { return 11; }))
            });
        }

        function exportDetail() {
            var d = data;
            exportSheet({
                file: 'Chi_tiet_cham_cong_' + d.from + '_' + d.to + '_' + fileSlug(C.shopName) + '.xlsx',
                sheet: 'Chi tiet',
                titles: [C.settings.report_title || C.shopName, 'CHI TIẾT CHẤM CÔNG', 'Từ ngày ' + dmy(d.from) + ' đến ngày ' + dmy(d.to)],
                header: [DET_COLS],
                rows: d.rows.map(function (r) {
                    return [dmy(r.date), wd(r.date), r.login, r.name, r.shift + (r.scheduled ? '' : ' (ngoài lịch)'), r.shift_time, r.in, r.out,
                        r.late || '', r.early || '', r.worked || '', r.overtime || '', r.work_value || '', r.status_label, r.source, r.note];
                }),
                widths: [11, 5, 12, 22, 14, 13, 16, 16, 8, 8, 8, 9, 7, 16, 10, 24],
                cellStyle: function (r, c) {
                    var row = d.rows[r];
                    if (!row || c !== 13) return null;
                    var tone = row.status_tone;
                    return { color: tone === 'danger' ? 'FFB42318' : tone === 'warning' ? 'FFA26206' : tone === 'success' ? 'FF157347' : null, bold: true };
                }
            });
        }

        $('#cc-rtabs').addEventListener('click', function (e) {
            var a = e.target.closest('[data-rt]');
            if (!a) return;
            e.preventDefault();
            tab = a.dataset.rt;
            syncFilters();
            load();
        });
        $('#cc-load').addEventListener('click', load);
        $('#cc-month').addEventListener('change', load);
        $('#cc-export').addEventListener('click', function () {
            if (!data) { toast('Chưa có dữ liệu để xuất.', 'warning'); return; }
            if (!data.rows.length) { toast('Báo cáo trống.', 'warning'); return; }
            ({ month: exportMonth, summary: exportSummary, detail: exportDetail })[tab]();
        });
        $('#cc-print').addEventListener('click', function () {
            if (!data) { toast('Chưa có dữ liệu để in.', 'warning'); return; }
            window.print();
        });

        syncFilters();
        load();
    }

    /* ═══════════════════════════ CÀI ĐẶT ═══════════════════════════ */

    function pageSettings() {
        var form = $('#cc-settings-form');
        $('#cc-settings-save').addEventListener('click', function () {
            var btn = this;
            var p = {};
            $$('input[name], textarea[name], select[name]', form).forEach(function (el) {
                if (el.type === 'checkbox') p[el.name] = el.checked ? 1 : 0;
                else p[el.name] = el.value;
            });
            p.members = $$('.cc-emp', form).filter(function (c) { return c.checked; }).map(function (c) { return +c.value; });
            p.employee_roles = $$('.cc-role', form).filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
            if (C.canUnlock) p.managers = $$('.cc-mgr', form).filter(function (c) { return c.checked && !c.disabled; }).map(function (c) { return +c.value; });
            busy(btn, true);
            api('settings_save', p).then(function (d) { toast(d.message); }).finally(function () { busy(btn, false); });
        });
    }

    var pages = { board: pageBoard, schedule: pageSchedule, shifts: pageShifts, report: pageReport, settings: pageSettings };
    function boot() { if (pages[C.page]) pages[C.page](); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
