# Kiến trúc, API và kiểm thử SAAS-NAC

## Phạm vi dữ liệu

DB SaaS lưu tenants, users, memberships, sites, environments, operations, audit_events và service_subscriptions. DB của từng NAC20 lưu thiết bị và RADIUS riêng; bản đầu chưa proxy API quản lý MAC từ SaaS.

Mọi API dữ liệu công ty gọi `TenantPolicy` trước khi truy vấn. Danh sách công ty chỉ lấy theo memberships của người đăng nhập. Tài nguyên con được truy vấn thêm điều kiện tenant_id. Tham số người dùng không được mass-assign tenant_id. Chi nhánh được kiểm tra bằng exists có tenant_id; DB còn có khóa ngoại ghép làm lớp bảo vệ thứ hai.

Không dùng quyền TN20 toàn cục để bypass tất cả policy; thao tác vận hành bản đầu thực hiện từ console tin cậy. Chưa có màn hình ops hoặc cơ chế hỗ trợ truy cập dữ liệu công ty.

Mỗi công ty có tối đa một môi trường trong MVP. Khóa duy nhất DB và khóa hàng tenant trong transaction bảo vệ chống yêu cầu cạnh tranh. UUID chống lặp có phạm vi công ty. Khi cùng mã do người khác gửi, API trả conflict. Worker lấy khóa Redis theo công ty, kiểm tra lại trạng thái công ty, quyền người yêu cầu và thuê bao live.

## API hiện có

API dùng cùng origin và phiên cookie; mọi POST thuộc middleware web có CSRF. Gửi `Accept: application/json`, `Content-Type: application/json`, `X-CSRF-TOKEN` lấy từ trang. Không có token kỹ thuật mặc định.

| Phương thức | Đường dẫn | Quyền |
|---|---|---|
| POST | `/saas/api/v1/login` | Email/mật khẩu, giới hạn tốc độ |
| POST | `/saas/api/v1/logout` | Đã đăng nhập |
| GET | `/saas/api/v1/me` | Đã đăng nhập |
| GET | `/saas/api/v1/tenants` | Chỉ công ty có membership |
| GET | `/saas/api/v1/tenants/{id}` | Thành viên công ty |
| POST | `/saas/api/v1/tenants/{id}/sites` | Owner/admin, công ty active |
| POST | `/saas/api/v1/tenants/{id}/environments` | Owner/admin; live cần thuê bao active |
| GET | `/saas/api/v1/tenants/{id}/operations/{operation}` | Thành viên đúng công ty |

Body tạo chi nhánh: `name`, `code`. Body yêu cầu môi trường: `idempotency_key` UUID, `site_id` tùy chọn cùng công ty. Trình duyệt không được chọn provider, IP, hostname, URL artifact hay lệnh cài đặt.

GET tổng quan trả tối đa 100 chi nhánh, 20 tác vụ và 20 audit gần nhất; chưa có phân trang đầy đủ cho quy mô lớn. Giao diện không coi những giới hạn này là hạn mức thuê bao đã bán.

## Cấp phát

Mặc định simulation chỉ cập nhật trạng thái, không gọi nhà cung cấp. Live adapter kiểm tra config trước khi tạo VM, gọi hai origin cố định Hetzner/Cloudflare, từ chối URL artifact ngoài release NAC, xác minh SHA-256 và chạy Ansible bằng mảng argv. Không nối lệnh shell từ dữ liệu khách hàng.

Không retry POST tạo máy một cách mù quáng; nếu có máy cùng tên mà DB chưa có ID, dừng để đối soát. Không tự xóa VM khi thất bại vì có thể có dữ liệu cần phục hồi. TN20 chịu trách nhiệm dọn tài nguyên đã xác nhận không dùng.

Sau khi cài gói, trạng thái awaiting_configuration chờ cấu hình/kiểm thử thật. `completed` là tác vụ đã hoàn thành phần của nó, không phải chứng nhận mạng khách đã chạy. Không có thao tác tự đánh dấu ready từ trình duyệt.

## Bằng chứng kiểm thử

- Kiểm tra chưa đăng nhập, danh sách công ty, đọc/ghi chéo công ty.
- Viewer không ghi; payload giả tenant_id bị từ chối; membership bị thu hồi mất quyền ngay yêu cầu kế tiếp.
- Hai công ty dùng cùng mã chi nhánh độc lập; site_id khác công ty bị từ chối cả tại API và FK.
- Gửi lặp idempotency_key không tạo thêm môi trường/tác vụ; không đọc tác vụ công ty khác.
- Mô phỏng không tạo server; live tắt mặc định; cấu hình thiếu/artifact URL tùy ý không phát sinh gọi hạ tầng.
- Tình huống API có máy chưa đối soát không tạo thêm máy; adapter gọi install và giữ trạng thái chờ cấu hình.
- CI chạy trên MySQL kiểm tra InnoDB, utf8mb4 và index tenant_id; Redis test đẩy job thật, worker xử lý và lấy khóa Redis.
- Build Vue và kiểm tra cú pháp Ansible. HTTP nhà cung cấp, SSH và máy NAC thật chưa được nghiệm thu.

## Tiếp theo trước production

MFA, khôi phục mật khẩu, lời mời có hạn, webhook thanh toán được xác minh, phục hồi tác vụ running sau sự cố worker, host CA, secret manager, TLS tự động, kết nối API NAC, backup/restore, kiểm thử tải và nghiệm thu thiết bị mạng thật. Không đưa hệ thống nhận thanh toán công khai khi các yêu cầu vận hành chưa đạt.
