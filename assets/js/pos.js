/**
 * Chấm công trên POS — nút nổi + hộp thoại (và màn riêng ?tgs-cham-cong=1).
 * Không phụ thuộc jQuery / Alpine của màn POS để không đụng biến của nó.
 */
(function () {
    'use strict';
    var C = window.TGS_CC_POS;
    if (!C || window.TGSChamCong) return;

    var root = null;          // overlay
    var state = null;         // dữ liệu pos_state hiện tại
    var other = null;         // { login, password } khi chấm cho tài khoản khác
    var view = 'loading';     // loading | state | login | done | error
    var errorMsg = '';
    var doneMsg = '';
    var confirmOut = false;
    var busy = false;
    var standalone = false;
    var clockTimer = null;
    var backTimer = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function send(fields, retried) {
        var body = new URLSearchParams();
        body.append('action', 'tgs_cc_pos');
        body.append('nonce', C.nonce);
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        if (other) {
            body.append('login', other.login);
            body.append('password', other.password);
        }
        return fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) {
                return r.json().catch(function () { return null; }).then(function (j) { return { status: r.status, j: j }; });
            })
            .then(function (res) {
                var j = res.j;
                if (res.status === 403 && j && j.data && j.data.code === 'nonce' && !retried) {
                    return refreshNonce().then(function () { return send(fields, true); });
                }
                if (res.status === 0 || !j) throw { message: 'Mất kết nối máy chủ. Kiểm tra mạng rồi thử lại.' };
                if (!j.success) throw { message: (j.data && j.data.message) || 'Có lỗi xảy ra.', data: j.data };
                return j.data;
            }, function () {
                throw { message: 'Mất kết nối máy chủ. Kiểm tra mạng rồi thử lại.' };
            });
    }

    function refreshNonce() {
        var body = new URLSearchParams();
        body.append('action', 'tgs_cc_pos');
        body.append('op', 'refresh');
        return fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.success) C.nonce = j.data.nonce; });
    }

    /* ── Nút nổi: chấm xanh khi đang trong ca ─────────────────────────── */
    function updateFab() {
        var fab = document.getElementById('tgs-cc-fab');
        if (!fab || other) return;
        var on = state && state.mode === 'out';
        fab.classList.toggle('is-working', !!on);
        fab.title = on ? ('Đang trong ' + state.open.shift + ' từ ' + state.open.check_in + ' — bấm để chấm ra') : 'Chấm công vào / ra ca';
    }

    function loadState() {
        view = 'loading';
        render();
        return send({ op: 'state' }).then(function (d) {
            state = d;
            view = 'state';
            confirmOut = false;
            updateFab();
            render();
        }, function (e) {
            if (other) {
                // Sai mật khẩu → quay lại form, giữ tên đăng nhập
                var login = other.login;
                other = null;
                view = 'login';
                errorMsg = e.message;
                render(login);
                return;
            }
            view = 'error';
            errorMsg = e.message;
            render();
        });
    }

    function punch(type, shiftId, workDate) {
        if (busy) return;
        busy = true;
        render();
        send({ op: 'punch', type: type, shift_id: shiftId || 0, work_date: workDate || '' }).then(function (d) {
            busy = false;
            doneMsg = d.message;
            view = 'done';
            if (!other) { state = d.state; updateFab(); }
            render();
            clearTimeout(backTimer);
            backTimer = setTimeout(function () {
                if (other) {
                    // Chấm hộ xong: xoá ngay mật khẩu khỏi bộ nhớ, trở về tài khoản đang đăng nhập
                    other = null;
                    loadState();
                } else {
                    state = d.state;
                    view = 'state';
                    render();
                }
            }, other ? 3500 : 2500);
        }, function (e) {
            busy = false;
            errorMsg = e.message;
            if (e.data && e.data.state) state = e.data.state;
            view = 'state';
            render();
        });
    }

    /* ── Vẽ ─────────────────────────────────────────────────────────────── */
    function clockHtml() {
        var now = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        var days = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
        return '<div class="tgs-cc-clock__time">' + p(now.getHours()) + ':' + p(now.getMinutes()) + '<small>:' + p(now.getSeconds()) + '</small></div>'
            + '<div class="tgs-cc-clock__date">' + days[now.getDay()] + ', ' + p(now.getDate()) + '/' + p(now.getMonth() + 1) + '/' + now.getFullYear() + '</div>';
    }

    function body(prefillLogin) {
        if (view === 'loading') {
            return '<div class="tgs-cc-loading"><span class="tgs-cc-spin"></span> Đang tải…</div>';
        }
        if (view === 'error') {
            return '<div class="tgs-cc-alert tgs-cc-alert--bad">' + esc(errorMsg) + '</div>'
                + '<button type="button" class="tgs-cc-btn tgs-cc-btn--ghost" data-act="reload">Thử lại</button>';
        }
        if (view === 'done') {
            return '<div class="tgs-cc-done"><div class="tgs-cc-done__icon">✓</div><div class="tgs-cc-done__msg">' + esc(doneMsg) + '</div>'
                + (other ? '<div class="tgs-cc-hint">Đang trở về tài khoản ' + esc(C.userName) + '…</div>' : '') + '</div>';
        }
        if (view === 'login') {
            return '<form class="tgs-cc-login" data-form="login" autocomplete="off">'
                + '<div class="tgs-cc-hint">Máy quầy dùng chung? Nhập tài khoản được cấp của bạn để chấm công cho chính mình. Phiên bán hàng hiện tại không bị đăng xuất.</div>'
                + (errorMsg ? '<div class="tgs-cc-alert tgs-cc-alert--bad">' + esc(errorMsg) + '</div>' : '')
                + '<label>Tên đăng nhập<input name="login" required autocomplete="off" autocapitalize="none" value="' + esc(prefillLogin || '') + '"></label>'
                + '<label>Mật khẩu<input name="password" type="password" required autocomplete="new-password"></label>'
                + '<div class="tgs-cc-row">'
                + '<button type="button" class="tgs-cc-btn tgs-cc-btn--ghost" data-act="cancel-other">Huỷ</button>'
                + '<button type="submit" class="tgs-cc-btn tgs-cc-btn--primary">Tiếp tục</button>'
                + '</div></form>';
        }

        var s = state || {};
        var h = '';
        if (errorMsg) h += '<div class="tgs-cc-alert tgs-cc-alert--bad">' + esc(errorMsg) + '</div>';

        if (s.mode === 'out' && s.open) {
            h += '<div class="tgs-cc-open">'
                + '<div class="tgs-cc-open__label">Đang trong ca</div>'
                + '<div class="tgs-cc-open__shift">' + esc(s.open.shift) + ' <span>' + esc(s.open.shift_time) + '</span></div>'
                + '<div class="tgs-cc-open__in">Đã chấm vào lúc <b>' + esc(s.open.check_in) + '</b></div>'
                + '</div>';
            if (confirmOut && s.open.early_minutes > 0) {
                h += '<div class="tgs-cc-alert tgs-cc-alert--warn">Còn ' + s.open.early_minutes + ' phút nữa mới hết ca — chấm ra bây giờ sẽ bị ghi <b>về sớm</b>. Vẫn chấm ra?</div>'
                    + '<div class="tgs-cc-row"><button type="button" class="tgs-cc-btn tgs-cc-btn--ghost" data-act="cancel-out">Không</button>'
                    + '<button type="button" class="tgs-cc-btn tgs-cc-btn--out" data-act="out-confirm"' + (busy ? ' disabled' : '') + '>Vẫn chấm ra</button></div>';
            } else {
                h += '<button type="button" class="tgs-cc-btn tgs-cc-btn--out tgs-cc-btn--big" data-act="out"' + (busy ? ' disabled' : '') + '>'
                    + (busy ? 'Đang chấm…' : 'CHẤM RA — KẾT THÚC CA') + '</button>';
            }
        } else if (s.mode === 'in') {
            h += '<div class="tgs-cc-sub">Chọn ca để chấm <b>VÀO</b>:</div><div class="tgs-cc-shifts">';
            s.candidates.forEach(function (c) {
                h += '<button type="button" class="tgs-cc-shift" data-act="in" data-shift="' + c.shift_id + '" data-date="' + esc(c.work_date) + '"' + (busy ? ' disabled' : '') + ' style="--tgs-cc-c:' + esc(c.color) + '">'
                    + '<span class="tgs-cc-shift__name">' + esc(c.name) + '</span>'
                    + '<span class="tgs-cc-shift__time">' + esc(c.time) + '</span>'
                    + '<span class="tgs-cc-shift__tags">'
                    + (c.scheduled ? '<em class="tgs-cc-tag">Đã xếp lịch</em>' : '')
                    + (c.late_minutes > 0 ? '<em class="tgs-cc-tag tgs-cc-tag--warn">Muộn ' + c.late_minutes + ' phút</em>' : '')
                    + '</span>'
                    + '<span class="tgs-cc-shift__go">CHẤM VÀO</span></button>';
            });
            h += '</div>';
        } else {
            h += '<div class="tgs-cc-alert tgs-cc-alert--info">' + esc(s.message || 'Không có ca nào để chấm lúc này.') + '</div>';
        }

        if (s.history && s.history.length) {
            h += '<div class="tgs-cc-sub">Hôm nay</div><table class="tgs-cc-hist"><thead><tr><th>Ca</th><th>Vào</th><th>Ra</th><th>Trạng thái</th></tr></thead><tbody>';
            s.history.forEach(function (r) {
                h += '<tr><td>' + esc(r.shift) + '</td><td>' + esc(r.in || '—') + '</td><td>' + esc(r.out || '—') + '</td>'
                    + '<td><span class="tgs-cc-st tgs-cc-st--' + esc(r.status_tone) + '">' + esc(r.status_label) + '</span></td></tr>';
            });
            h += '</tbody></table>';
        }
        return h;
    }

    function render(prefillLogin) {
        if (!root) return;
        var who = other
            ? '<span class="tgs-cc-who__name">' + esc((state && state.user && state.user.name) || other.login) + '</span><span class="tgs-cc-tag tgs-cc-tag--warn">Tài khoản khác</span>'
            : '<span class="tgs-cc-who__name">' + esc((state && state.user && state.user.name) || C.userName) + '</span>';
        var foot = '';
        if (view !== 'login' && view !== 'done') {
            if (other) {
                foot += '<button type="button" class="tgs-cc-link" data-act="cancel-other">← Trở về tài khoản của tôi</button>';
            } else if (+C.allowOther) {
                foot += '<button type="button" class="tgs-cc-link" data-act="other">Chấm công cho tài khoản khác</button>';
            }
            if (!standalone) {
                foot += '<a class="tgs-cc-link" href="' + esc(C.standaloneUrl) + '" target="_blank" rel="noopener">Mở màn chấm công riêng ↗</a>';
            }
        }
        root.querySelector('.tgs-cc-card').innerHTML =
            '<div class="tgs-cc-head"><div class="tgs-cc-title">Chấm công</div>'
            + (standalone ? '' : '<button type="button" class="tgs-cc-x" data-act="close" aria-label="Đóng">×</button>') + '</div>'
            + '<div class="tgs-cc-clock">' + clockHtml() + '</div>'
            + '<div class="tgs-cc-who">' + who + '</div>'
            + '<div class="tgs-cc-body">' + body(prefillLogin) + '</div>'
            + (foot ? '<div class="tgs-cc-foot">' + foot + '</div>' : '');
        var f = root.querySelector('[data-form="login"] input[name="' + (prefillLogin ? 'password' : 'login') + '"]');
        if (f) f.focus();
    }

    function tickClock() {
        var c = root && root.querySelector('.tgs-cc-clock');
        if (c) c.innerHTML = clockHtml();
    }

    function onClick(e) {
        var t = e.target.closest('[data-act]');
        if (!t) {
            if (e.target === root && !standalone) close();
            return;
        }
        var act = t.getAttribute('data-act');
        if (act === 'close') return close();
        if (act === 'reload') { errorMsg = ''; return loadState(); }
        if (act === 'in') { errorMsg = ''; return punch('in', t.getAttribute('data-shift'), t.getAttribute('data-date')); }
        if (act === 'out') {
            errorMsg = '';
            if (state && state.open && state.open.early_minutes > 0) { confirmOut = true; return render(); }
            return punch('out');
        }
        if (act === 'out-confirm') return punch('out');
        if (act === 'cancel-out') { confirmOut = false; return render(); }
        if (act === 'other') { other = null; errorMsg = ''; view = 'login'; return render(); }
        if (act === 'cancel-other') { other = null; errorMsg = ''; return loadState(); }
    }

    function onSubmit(e) {
        var form = e.target.closest('[data-form="login"]');
        if (!form) return;
        e.preventDefault();
        other = { login: form.login.value.trim(), password: form.password.value };
        state = null; // không hiện nhầm tên tài khoản đang đăng nhập trong lúc tải
        errorMsg = '';
        loadState();
    }

    function open(opts) {
        standalone = !!(opts && opts.standalone);
        if (!root) {
            root = document.createElement('div');
            root.className = 'tgs-cc-overlay' + (standalone ? ' tgs-cc-overlay--standalone' : '');
            root.innerHTML = '<div class="tgs-cc-card" role="dialog" aria-modal="true" aria-label="Chấm công"></div>';
            root.addEventListener('click', onClick);
            root.addEventListener('submit', onSubmit);
            document.body.appendChild(root);
        }
        root.hidden = false;
        document.documentElement.classList.add('tgs-cc-lock');
        errorMsg = '';
        other = null;
        clearInterval(clockTimer);
        clockTimer = setInterval(tickClock, 1000);
        loadState();
    }

    function close() {
        if (!root) return;
        root.hidden = true;
        other = null; // không giữ mật khẩu sau khi đóng
        clearInterval(clockTimer);
        clearTimeout(backTimer);
        document.documentElement.classList.remove('tgs-cc-lock');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root && !root.hidden && !standalone) close();
    });

    function boot() {
        var fab = document.getElementById('tgs-cc-fab');
        if (fab) {
            fab.addEventListener('click', function () { open(); });
            // Lấy trạng thái ngầm để chấm xanh trên nút nổi
            send({ op: 'state' }).then(function (d) { state = d; updateFab(); }, function () {});
        }
    }

    window.TGSChamCong = { open: open, close: close };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
