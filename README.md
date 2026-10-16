# NAC20 Cloud — SAAS-NAC

**Công Ty TNHH TN20 · 20.com.vn**

Cổng quản trị nhiều công ty độc lập bằng **Laravel 13 + Vue 3 + MySQL 8.4 + Redis**, kèm cloud-init/Ansible để cài máy NAC20 riêng. Tiếng Việt mặc định.

**Trạng thái: bản nền MVP để kiểm thử/pilot.** Mã đã có chức năng và kiểm thử, chưa nghiệm thu cài NAC20 trên hạ tầng thật hoặc tích hợp cổng thanh toán. Mặc định mô phỏng, không tạo tài nguyên tính phí.

## Chức năng đã triển khai

- Đăng nhập bằng phiên máy chủ, chống CSRF, giới hạn tốc độ đăng nhập và đăng xuất.
- Chọn công ty theo tư cách thành viên; quyền owner/admin được tạo chi nhánh và yêu cầu môi trường. Operator/viewer chỉ đọc trong bản đầu.
- Policy kiểm tra quyền ở backend mỗi yêu cầu; từ chối ghi đè `tenant_id` từ payload. Tác vụ nền kiểm tra lại quyền và thuê bao trước khi tạo máy.
- MySQL mặc định InnoDB, `utf8mb4`, khóa ngoại, chỉ mục theo `tenant_id`; khóa ngoại ghép ngăn gán chi nhánh/môi trường khác công ty.
- Mỗi công ty một môi trường, yêu cầu có UUID chống lặp, hàng đợi Redis, khóa tác vụ và nhật ký hoạt động.
- Tạo công ty/thành viên và xác nhận thuê bao từ console TN20; không có mật khẩu quản trị mặc định.
- Mô phỏng cấp phát chạy được; adapter tùy chọn Hetzner Cloud + Cloudflare gọi API tạo VM/DNS rồi chạy Ansible cài bộ NAC20 có SHA-256 cố định.
- Giao diện xem trạng thái thật của tác vụ; môi trường mô phỏng ghi rõ chưa tạo máy. Cài gói xong chuyển `awaiting_configuration`, không tự nhận đã sẵn sàng vận hành.
- Trang `/san-pham` giới thiệu giải pháp và ba nhóm gói, tất cả **Liên hệ báo giá**; không có giá bán mặc định.
- Khách đăng ký không gian mới, gửi nhu cầu, xem báo giá riêng, xác nhận/hủy đơn và báo đã chuyển khoản. Chủ công ty/admin được xem lịch sử giao dịch và thuê bao; operator/viewer không xem tài chính.
- TN20 phát hành báo giá và đối soát từ console; kiểm tra đúng số tiền, tham chiếu chống trùng, gia hạn theo thời gian còn lại. Chỉ sau xác nhận mới kích hoạt dịch vụ và tạo tác vụ cấp phát nếu được cấu hình.
- Yêu cầu hỗ trợ và phản hồi trong cổng, phân trang lịch sử theo công ty. Chưa có email tự động hoặc hóa đơn thuế điện tử.
- Khu `/admin` riêng cho admin TN20: tổng quan doanh thu đã đối soát, khách hàng, phát hành báo giá, đối soát, hỗ trợ, khóa tài khoản và nhật ký quản trị.
- Admin chọn khách hàng để cài NAC20, theo dõi tác vụ, thử lại sau đối soát và ghi nhận nghiệm thu/bàn giao. Bản cài admin được lưu URL/SHA-256 theo môi trường; không nhận lệnh shell hoặc token từ khách.
- Khu khách hàng có hồ sơ công ty, tên hiển thị và danh sách thành viên. Chủ công ty phải xác minh mật khẩu hiện tại khi cấp/thu hồi quyền; không thể cấp admin TN20 qua API.

## Cài và vận hành

Đọc [hướng dẫn cài đặt tiếng Việt](docs/CAI_DAT.md) và [API, cách ly, kiểm thử](docs/KIEN_TRUC.md).

```bash
cp .env.example .env
# Điền DB_PASSWORD, MYSQL_ROOT_PASSWORD và APP_KEY riêng theo hướng dẫn.
docker compose build
docker compose up -d mysql redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan saas:company cong-ty-a "Công ty A" admin@congty.vn
docker compose up -d
```

Web lắng nghe `http://127.0.0.1:8088`; đặt reverse proxy HTTPS phía trước khi truy cập từ bên ngoài. Dữ liệu MySQL/Redis giữ trong named volume. Không dùng `docker compose down -v` nếu còn cần dữ liệu.

## Hai kho mã phối hợp

- [NAC](https://github.com/lehuunghi/NAC): máy chủ kiểm soát mạng, RADIUS, MAC và Connector.
- Kho này: tài khoản công ty, cấp phát và điều phối. Không sửa trực tiếp DB NAC, không chia sẻ một DB thiết bị cho mọi khách hàng.

`.env.example` trỏ đến bộ cài preview có tổng kiểm đã công bố. Cần nghiệm thu và chốt runtime trước khi dùng cho khách hàng thật. Các ảnh runtime trong bộ NAC hiện chưa được khóa toàn bộ bằng digest.

## Phần chưa cung cấp

Thanh toán/webhook tự động, MFA/khôi phục mật khẩu, xác minh email, mời thành viên qua email, quản lý MAC qua cổng SaaS, SSO sang NAC, đăng ký Connector một lần, tự hoàn tất trình cấu hình NAC/TLS, backup/khôi phục tự động và HA. Các mục này không được coi là đã hoạt động chỉ vì có thiết kế. Chuyển khoản hiện đối soát thủ công, cần TN20 cấu hình thông tin ngân hàng thật trước khi tiếp nhận.

Chưa có thông tin nhà cung cấp hạ tầng/DNS của TN20. Hetzner và Cloudflare là adapter tùy chọn, có kiểm thử HTTP giả lập; không khẳng định đó là hạ tầng TN20 đang dùng. Không có máy thật, DNS hoặc giao dịch thanh toán nào được tạo bởi quá trình phát triển này.

## Cấp tài khoản admin TN20

Sau khi đã có tài khoản người vận hành, chạy tại console tin cậy:

```bash
docker compose exec app php artisan saas:admin EMAIL_NGUOI_VAN_HANH
```

Thay email thực tế; lệnh yêu cầu xác nhận. `--revoke` thu hồi quyền. Không có mật khẩu hoặc admin mặc định. Quyền `admin` trong một công ty khác với `is_platform_admin`; khách đăng ký không được tự cấp cờ này. Admin đăng nhập tại `/admin`, khách dùng `/`.

## Kiểm tra

Cả hai khu quản trị có mục **Bảo mật tài khoản** để đổi mật khẩu (tối thiểu 12 ký tự, xác nhận mật khẩu hiện tại). Các phiên đã đăng nhập với mật khẩu cũ bị từ chối ở yêu cầu kế tiếp; phiên đang đổi được giữ lại và thay mã phiên/CSRF. Admin đổi mật khẩu được ghi vào nhật ký, không lưu mật khẩu trong nhật ký. Khôi phục qua email và MFA chưa được cung cấp.

```bash
composer install
npm ci
npm run build
php artisan test
vendor/bin/pint --test
ansible-playbook -i localhost, infrastructure/ansible/install-nac20.yml --syntax-check
```

GitHub Actions chạy bộ kiểm thử với MySQL 8.4 và Redis thật, build Vue và kiểm tra cú pháp Ansible. Chạy tại máy không có MySQL/Redis có thể dùng SQLite cho test nhanh; hai kiểm tra tích hợp được bỏ qua và không thay thế CI.

Laravel skeleton giữ giấy phép MIT trong `LICENSE`. Bộ NAC20 là sản phẩm riêng có giấy phép của kho NAC; kho này không sao chép hoặc đổi giấy phép phần lõi đó.
