# TGS - Chấm công nhân viên (`tgs_time_attendance`)

Plugin chấm công theo ca cho cửa hàng. Plugin **cắm vào** hai plugin gốc qua hook có sẵn và **không sửa file nào** của chúng:

| Plugin gốc | Cắm bằng | Kết quả |
|---|---|---|
| `tgs_shop_managerment` | filter `tgs_shop_dashboard_routes`, `tgs_shop_workflow_nav` | Menu **Nhân sự** trên thanh mega-nav; các màn vẽ trong `main-layout.php` (Design System, thanh tab) |
| `tgs_pos` | filter `page_template` (ưu tiên 99) + `wp_footer` | Nút **Chấm công** nổi ở góc trái dưới màn `/pos` |

## Cài đặt

1. Chép thư mục `tgs_time_attendance` vào `wp-content/plugins/`.
2. Kích hoạt plugin (multisite: kích hoạt toàn mạng được). Bảng dữ liệu của mỗi shop chỉ được tạo khi shop **thật sự dùng** chấm công — lần đầu quản lý mở một màn chấm công — kèm 2 ca mẫu: Ca sáng 07:00–14:00, Ca chiều 14:00–21:30. Shop chưa mở màn chấm công thì không có bảng và không hiện nút Chấm công trên POS.
3. Vào **Quản lý Shop → Nhân sự → Cài đặt chấm công**: chọn vai trò / nhân viên tham gia chấm công, cấp quyền quản lý chấm công, chỉnh luật đi muộn / về sớm.
4. **Nhân sự → Ca làm việc**: sửa ca theo thực tế cửa hàng.
5. (Tuỳ chọn) **Nhân sự → Xếp lịch làm việc**: xếp ca cho nhân viên.

## Các màn quản trị (`admin.php?page=tgs-shop-management&view=…`)

| View | Màn | Chức năng |
|---|---|---|
| `cham-cong-bang` | Bảng chấm công | Lưới nhân viên × ngày (tuần/tháng). Bấm ca để sửa giờ vào/ra, ghi nghỉ có phép / không phép, xem lịch sử ai chấm / ai sửa. Nút "Duyệt đủ công theo lịch". Xuất Excel. |
| `cham-cong-lich` | Xếp lịch làm việc | Lưới tuần. Bấm ô chọn ca. Xếp hàng loạt (nhiều nhân viên × nhiều ca, lặp theo thứ trong tuần, tối đa 93 ngày). Chép lịch tuần trước (tuần trước trống thì báo lỗi, không xoá lịch tuần đang xem). |
| `cham-cong-bao-cao` | Báo cáo | **Bảng công tháng** (mẫu in cuối tháng: ký hiệu theo ngày, tổng công, chữ ký) · **Tổng hợp theo nhân viên** · **Chi tiết chấm công**. Mỗi tab có Xuất Excel có định dạng và In A4 ngang. Tab Bảng công tháng có nút **Chốt / Mở chốt** tháng. |
| `cham-cong-ca` | Ca làm việc | Tên, mã, giờ vào/ra (ca qua đêm), nghỉ giữa ca, số công, khoảng cho phép chấm vào sớm / ra muộn, màu, ngừng hoạt động. |
| `cham-cong-cai-dat` | Cài đặt | Đi muộn / về sớm, làm thêm giờ, nửa công, ngày công chuẩn, luật chấm trên POS, giới hạn IP (IP / tiền tố / CIDR), cách lấy IP khi chạy sau Cloudflare / proxy, vai trò + danh sách nhân viên chấm công, người quản lý chấm công, chức danh ký, xoá dữ liệu khi gỡ plugin. |

## Chấm công trên POS

- Nhân viên chấm bằng **tài khoản được cấp đang đăng nhập POS**. Bấm nút **Chấm công**:
  - chưa vào ca → hiện các ca đang trong giờ cho phép, bấm **Chấm vào**. Vào muộn thì báo trước số phút muộn.
  - đang trong ca → bấm **Chấm ra**. Ra sớm thì hỏi xác nhận.
- **Máy quầy dùng chung một tài khoản**: "Chấm công cho tài khoản khác" → nhập tên đăng nhập + mật khẩu của mình. Hệ thống xác thực rồi chấm cho đúng người đó, **không đổi phiên bán hàng**. Mật khẩu không được lưu. Nhập sai 5 lần thì khoá 10 phút theo IP.
- Màn riêng cho điện thoại / máy tính bảng đặt ở cửa: `https://<shop>/?tgs-cham-cong=1`.
- Server kiểm tra lại mọi lần bấm (khung giờ ca, đã chấm chưa, IP cửa hàng, tháng đã chốt), không tin dữ liệu gửi từ trình duyệt. Mỗi nhân viên có khoá riêng (MySQL `GET_LOCK`) nên bấm cùng lúc từ 2 máy không mở được 2 ca.
- **Site chạy sau Cloudflare / reverse proxy**: chọn ở Cài đặt → "Website chạy sau". Header IP (`CF-Connecting-IP`, `X-Forwarded-For`) chỉ được tin khi request đi qua đúng proxy đó.

## Luật tính công

- **Khung ca** = [giờ vào, giờ ra]. Nếu giờ ra ≤ giờ vào thì là ca qua đêm.
- **Chấm vào**: được từ `giờ vào − checkin_before` tới trước giờ ra. **Chấm ra**: muộn nhất `giờ ra + checkout_after`.
- **Đi muộn / về sớm**: chỉ ghi khi lệch quá số phút cho phép (mặc định 5 phút).
- **Giờ làm** = từ max(vào, giờ vào ca) tới min(ra, giờ ra ca), trừ nghỉ giữa ca: có mặt cả ca thì trừ đủ, có mặt một phần thì trừ theo tỷ lệ. Nghỉ giữa ca phải ngắn hơn độ dài ca.
- **Làm thêm** (nếu bật): vào sớm / ra muộn hơn giờ ca từ N phút trở lên.
- **Công** = số công của ca. Nếu bật nửa công thì tính theo giờ làm thực tế.
- **Chấm công thiếu**: có vào mà quá giờ chấm ra vẫn chưa có ra → 0 công, quản lý sửa ở bảng chấm công.
- **Chưa vào ca**: có lịch, ca đang diễn ra, chưa chấm — vẫn chấm vào được, **chưa** tính là vắng.
- **Vắng**: có lịch, hết ca rồi vẫn không chấm.
- Đổi luật tính công trong Cài đặt (muộn / sớm / làm thêm / nửa công) thì công **tháng hiện tại** được tính lại; sửa giờ / nghỉ / số công của một ca thì chỉ tính lại **các lượt của ca đó** trong tháng hiện tại. Chỉ dòng nào đổi mới được ghi lại. Các tháng trước và tháng đã chốt giữ nguyên.

## Chốt bảng công tháng

- Báo cáo → Bảng công tháng → **Chốt bảng công tháng MM/YYYY** (chỉ chốt được tháng đã kết thúc).
- Tháng đã chốt: không sửa / xoá công, không xếp / xoá / chép lịch, không "Duyệt đủ công", không chấm POS vào ngày của tháng đó; sửa ca hay đổi cài đặt không tính lại công tháng đó; không xoá được ca có lịch trong tháng đó (chỉ ngừng hoạt động).
- **Mở chốt**: chỉ quản trị cửa hàng (`manage_options`) / super admin. Chốt và mở chốt đều ghi vào `tgs_cc_logs`.

## Dữ liệu (mỗi site một bộ, `$wpdb->prefix`)

`tgs_cc_shifts` (ca) · `tgs_cc_schedules` (lịch, unique nhân viên+ca+ngày) · `tgs_cc_records` (chấm công, unique nhân viên+ca+ngày) · `tgs_cc_logs` (lịch sử thao tác). Option: `tgs_cc_settings`, `tgs_cc_db_version`, `tgs_cc_locks` (các tháng đã chốt).

Gỡ plugin (Plugins → Xoá): mặc định **giữ** dữ liệu. Shop nào bật "Xoá toàn bộ dữ liệu chấm công khi gỡ plugin" trong Cài đặt thì `uninstall.php` xoá bảng, option và quyền đã cấp của shop đó.

## Quyền

Hàm `tgs_cc_user_can_manage()` cho phép: super admin, người có `manage_options`, người có quyền riêng `tgs_manage_attendance`. Nếu có hệ phân quyền `TGS_Permission` thì thêm người được cấp view `cham-cong-bang`. Plugin khác đổi luật qua filter `tgs_cc_user_can_manage`.

Quyền `tgs_manage_attendance` cấp cho từng tài khoản ở Cài đặt → cột **Quản lý chấm công** (lưu theo từng shop; chỉ quản trị cửa hàng đổi được).

Danh sách nhân viên chấm công = tài khoản thuộc site có vai trò nằm trong **Vai trò tự động tham gia** (mặc định `tmd_pos_user`), trừ người bị bỏ tích, cộng người được tích tay. Tài khoản quản trị / kế toán / vai trò khác không tự vào danh sách. Khi nâng cấp từ 1.0.0, người ngoài vai trò mặc định mà đã có lịch hoặc dữ liệu chấm công được giữ lại (tích tay). Chỉ tạo / sửa công và xếp lịch được cho tài khoản thuộc cửa hàng. Đổi qua filter `tgs_cc_excluded_roles` / `tgs_cc_employees`.

## Hook cho plugin khác

- `tgs_cc_user_can_manage` (bool, user_id)
- `tgs_cc_employees` (array, include_excluded)
- `tgs_cc_excluded_roles` (array)
- `tgs_cc_cloudflare_ranges` (array) — dải IP Cloudflare
