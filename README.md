# TGS - Chấm công nhân viên (`tgs_time_attendance`)

Plugin chấm công theo ca cho cửa hàng. Plugin **cắm vào** hai plugin gốc qua hook có sẵn và **không sửa file nào** của chúng:

| Plugin gốc | Cắm bằng | Kết quả |
|---|---|---|
| `tgs_shop_managerment` | filter `tgs_shop_dashboard_routes`, `tgs_shop_workflow_nav` | Menu **Nhân sự** trên thanh mega-nav; các màn vẽ trong `main-layout.php` (Design System, thanh tab) |
| `tgs_pos` | filter `page_template` (ưu tiên 99) + `wp_footer` | Nút **Chấm công** nổi ở góc trái dưới màn `/pos` |

## Cài đặt

1. Chép thư mục `tgs_time_attendance` vào `wp-content/plugins/`.
2. Kích hoạt plugin (multisite: kích hoạt toàn mạng được). Bảng dữ liệu của mỗi shop **tự tạo** khi shop mở trang lần đầu, kèm 2 ca mẫu: Ca sáng 07:00–14:00, Ca chiều 14:00–21:30.
3. Vào **Quản lý Shop → Nhân sự → Cài đặt chấm công**: chọn nhân viên tham gia chấm công, chỉnh luật đi muộn / về sớm.
4. **Nhân sự → Ca làm việc**: sửa ca theo thực tế cửa hàng.
5. (Tuỳ chọn) **Nhân sự → Xếp lịch làm việc**: xếp ca cho nhân viên.

## Các màn quản trị (`admin.php?page=tgs-shop-management&view=…`)

| View | Màn | Chức năng |
|---|---|---|
| `cham-cong-bang` | Bảng chấm công | Lưới nhân viên × ngày (tuần/tháng). Bấm ca để sửa giờ vào/ra, ghi nghỉ có phép / không phép, xem lịch sử ai chấm / ai sửa. Nút "Duyệt đủ công theo lịch". Xuất Excel. |
| `cham-cong-lich` | Xếp lịch làm việc | Lưới tuần. Bấm ô chọn ca. Xếp hàng loạt (nhiều nhân viên × nhiều ca, lặp theo thứ trong tuần, tối đa 93 ngày). Chép lịch tuần trước. |
| `cham-cong-bao-cao` | Báo cáo | **Bảng công tháng** (mẫu in cuối tháng: ký hiệu theo ngày, tổng công, chữ ký) · **Tổng hợp theo nhân viên** · **Chi tiết chấm công**. Mỗi tab có Xuất Excel có định dạng và In A4 ngang. |
| `cham-cong-ca` | Ca làm việc | Tên, mã, giờ vào/ra (ca qua đêm), nghỉ giữa ca, số công, khoảng cho phép chấm vào sớm / ra muộn, màu, ngừng hoạt động. |
| `cham-cong-cai-dat` | Cài đặt | Đi muộn / về sớm, làm thêm giờ, nửa công, ngày công chuẩn, luật chấm trên POS, giới hạn IP, danh sách nhân viên, chức danh ký. |

## Chấm công trên POS

- Nhân viên chấm bằng **tài khoản được cấp đang đăng nhập POS**. Bấm nút **Chấm công**:
  - chưa vào ca → hiện các ca đang trong giờ cho phép, bấm **Chấm vào**. Vào muộn thì báo trước số phút muộn.
  - đang trong ca → bấm **Chấm ra**. Ra sớm thì hỏi xác nhận.
- **Máy quầy dùng chung một tài khoản**: "Chấm công cho tài khoản khác" → nhập tên đăng nhập + mật khẩu của mình. Hệ thống xác thực rồi chấm cho đúng người đó, **không đổi phiên bán hàng**. Mật khẩu không được lưu. Nhập sai 5 lần thì khoá 10 phút theo IP.
- Màn riêng cho điện thoại / máy tính bảng đặt ở cửa: `https://<shop>/?tgs-cham-cong=1`.
- Server kiểm tra lại mọi lần bấm (khung giờ ca, đã chấm chưa, IP cửa hàng), không tin dữ liệu gửi từ trình duyệt.

## Luật tính công

- **Khung ca** = [giờ vào, giờ ra]. Nếu giờ ra ≤ giờ vào thì là ca qua đêm.
- **Chấm vào**: được từ `giờ vào − checkin_before` tới trước giờ ra. **Chấm ra**: muộn nhất `giờ ra + checkout_after`.
- **Đi muộn / về sớm**: chỉ ghi khi lệch quá số phút cho phép (mặc định 5 phút).
- **Giờ làm** = từ max(vào, giờ vào ca) tới min(ra, giờ ra ca), trừ nghỉ giữa ca.
- **Làm thêm** (nếu bật): vào sớm / ra muộn hơn giờ ca từ N phút trở lên.
- **Công** = số công của ca. Nếu bật nửa công thì tính theo giờ làm thực tế.
- **Chấm công thiếu**: có vào mà quá giờ chấm ra vẫn chưa có ra → 0 công, quản lý sửa ở bảng chấm công.
- **Vắng**: có lịch, đã tới giờ, không chấm.
- Lưu cài đặt hoặc sửa giờ ca thì công **của tháng hiện tại** được tính lại. Các tháng trước giữ nguyên.

## Dữ liệu (mỗi site một bộ, `$wpdb->prefix`)

`tgs_cc_shifts` (ca) · `tgs_cc_schedules` (lịch, unique nhân viên+ca+ngày) · `tgs_cc_records` (chấm công, unique nhân viên+ca+ngày) · `tgs_cc_logs` (lịch sử thao tác). Option: `tgs_cc_settings`, `tgs_cc_db_version`.

## Quyền

Hàm `tgs_cc_user_can_manage()` cho phép: super admin, người có `manage_options`, người có quyền riêng `tgs_manage_attendance`. Nếu có hệ phân quyền `TGS_Permission` thì thêm người được cấp view `cham-cong-bang`. Plugin khác đổi luật qua filter `tgs_cc_user_can_manage`.

Danh sách nhân viên = tài khoản thuộc site, trừ role `customer` và `subscriber`. Đổi qua filter `tgs_cc_excluded_roles` / `tgs_cc_employees`.

## Hook cho plugin khác

- `tgs_cc_user_can_manage` (bool, user_id)
- `tgs_cc_employees` (array, include_excluded)
- `tgs_cc_excluded_roles` (array)
