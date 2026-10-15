# Cài SAAS-NAC và triển khai môi trường NAC20

## 1. Phân biệt máy chủ

- Máy SaaS chạy Laravel/Vue, MySQL và Redis; dùng Docker Engine/Compose trên Linux. Bắt đầu với tài nguyên đủ cho pilot rồi đo tải; không dùng chung máy đang chạy NAC20.
- Worker cấp phát có quyền gọi API hạ tầng và SSH tới máy NAC mới. Web không cần mount khóa SSH.
- Mỗi công ty có máy NAC riêng Debian 12 amd64, tối thiểu 4 vCPU, RAM 16 GB và đĩa 200 GB theo hướng dẫn bộ NAC. Chọn loại máy thực tế đáp ứng các yêu cầu này.
- Connector đặt tại mạng khách hàng, không cài trên máy SaaS. Cài và ghép theo [hướng dẫn bộ NAC20](https://github.com/lehuunghi/NAC/blob/main/packaging/debian12/README_VI.md).

## 2. Tải mã và cấu hình

```bash
git clone https://github.com/lehuunghi/SAAS-NAC.git
cd SAAS-NAC
cp .env.example .env
chmod 600 .env
```

Điền `DB_PASSWORD` và thêm `MYSQL_ROOT_PASSWORD` bằng hai mật khẩu ngẫu nhiên riêng. Không dùng tài khoản DB root cho Laravel. Giữ `PROVISIONING_DRIVER=simulation`, `PROVISIONING_ENABLED=false` trong lần cài đầu.

Tạo `APP_KEY` bằng lệnh sau trên Linux có OpenSSL, rồi dán toàn bộ kết quả vào `.env`:

```bash
printf 'base64:'; openssl rand -base64 32
```

Giữ khóa an toàn cùng bản sao lưu. Không đổi khóa tùy ý khi đang có dữ liệu/phiên mã hóa. `.env` không được commit. Khi bàn giao HTTPS đặt `APP_URL=https://saas.20.com.vn`, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`; giữ cookie theo host, không đặt `SESSION_DOMAIN=.20.com.vn`.

## 3. Dựng ứng dụng và khởi tạo

```bash
docker compose build
docker compose up -d mysql redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan saas:company cong-ty-a "Công ty A" admin@congty.vn
docker compose up -d
docker compose ps
```

Lệnh tạo công ty hỏi mật khẩu tương tác, tối thiểu 12 ký tự. Nếu email đã tồn tại, lệnh gán chủ công ty cho người dùng đó, không đổi mật khẩu; chỉ người vận hành có quyền console được dùng. Không có tài khoản công khai tự đăng ký hoặc mật khẩu mặc định.

Web chỉ bind loopback `127.0.0.1:8088`. Đặt reverse proxy TLS tin cậy phía trước. Khi proxy truyền giao thức HTTPS vào ứng dụng, cấu hình danh sách trusted proxy cụ thể trong Laravel; không tin mọi header từ Internet. Cấu hình Nginx trong repo chưa tự cấp chứng thư cho cổng SaaS.

Trong môi trường nội bộ chưa cấu hình HTTPS có thể mở qua SSH tunnel đến cổng 8088. Không đặt secure cookie trong một phiên HTTP cục bộ nếu cần đăng nhập thử. MySQL/Redis không publish cổng ra host.

## 4. Chạy thử từ giao diện

1. Đăng nhập bằng tài khoản vừa tạo, xem đúng tên công ty ở thanh trái.
2. Vào **Chi nhánh**, nhập tên và mã như `ha-noi`.
3. Vào **Hạ tầng NAC20**, chọn yêu cầu khởi tạo.
4. Worker chạy hàng đợi `provisioning`; trang cập nhật theo chu kỳ.
5. Kết quả phải là **Đã mô phỏng**. Không có IP máy chủ hoặc DNS được tạo.
6. Tạo công ty B và dùng hai tài khoản để kiểm tra phạm vi độc lập.

Lệnh gán người dùng đã tồn tại vào công ty:

```bash
docker compose exec app php artisan saas:member cong-ty-a nguoidung@congty.vn viewer
```

Vai trò bản đầu: owner/admin đọc và tạo chi nhánh/môi trường; operator/viewer chỉ đọc. Chưa có phân quyền riêng từng chi nhánh. Lệnh không thay vai trò owner. Quản lý người dùng bổ sung và quy trình mời qua email là phần phát triển tiếp theo.

## 5. Xác nhận thuê bao

Chỉ sau khi TN20 đã đối soát thanh toán:

```bash
docker compose exec app php artisan saas:activate cong-ty-a THAM-CHIEU-GIAO-DICH --days=30
```

Lệnh yêu cầu xác nhận tại console, ghi audit và hạn dùng. Đây là xác nhận thủ công, chưa có cổng thanh toán hoặc webhook tự động. Không có API công khai để khách tự đánh dấu đã trả tiền. Thuê bao hết hạn chặn cấp phát mới; không tự xóa máy hoặc cắt RADIUS đang hoạt động.

## 6. Bật adapter thực tế (tùy chọn)

Chưa cần bật để thử giao diện hoặc kiểm tra cách ly. Bật sẽ tạo tài nguyên tính phí trên tài khoản hạ tầng đã cấu hình. TN20 phải kiểm tra giá/hạn mức, vùng dữ liệu và chính sách dịch vụ trước.

Adapter hiện có: **Hetzner Cloud + Cloudflare DNS**. Nhà cung cấp khác cần triển khai lớp tương ứng với `Provisioner`. Quy trình chưa phải giải pháp cài mới tự động hoàn toàn để bán dịch vụ ngay.

Điền cấu hình phía vận hành:

| Biến | Giá trị cần chuẩn bị |
|---|---|
| `HCLOUD_TOKEN` | Token dự án hạ tầng riêng cho dịch vụ |
| `HCLOUD_SERVER_TYPE`, `HCLOUD_LOCATION` | Loại máy và vùng đã lựa chọn, đủ RAM/đĩa |
| `HCLOUD_SSH_KEY_ID` | ID khóa công khai đã nhập vào nhà cung cấp |
| `HCLOUD_FIREWALL_ID` | Firewall đã tạo, chỉ mở luồng quản trị/Connector cần thiết |
| `CLOUDFLARE_API_TOKEN` | Quyền DNS giới hạn theo zone, kèm quyền đọc/liệt kê cần thiết |
| `CLOUDFLARE_ZONE_ID` | Zone chứa `NAC_DOMAIN` |
| `NAC_DOMAIN` | Vùng tên như `nac.20.com.vn` mà TN20 kiểm soát |
| `PROVISIONING_SSH_KEY` | Đường dẫn khóa riêng trong worker |
| `PROVISIONING_KNOWN_HOSTS` | File SSH host key đã đối chiếu nguồn tin cậy |
| `NAC_ARTIFACT_URL` | URL bộ cài từ release cụ thể của `lehuunghi/NAC` |
| `NAC_ARTIFACT_SHA256` | SHA-256 của chính file tar.gz đó |

Chỉ mount khóa SSH và known_hosts read-only vào worker, ví dụ qua compose override cục bộ không commit bí mật:

```yaml
services:
  worker:
    volumes:
      - /srv/saas-secrets/id_ed25519:/run/secrets/nac_ssh:ro
      - /srv/saas-secrets/known_hosts:/run/secrets/known_hosts:ro
```

Đặt biến đường dẫn tương ứng. UID worker là `www-data`; cấp quyền đọc file phù hợp, không mở quyền world-readable. File known_hosts phải tồn tại trước preflight. Máy mới chưa có host key trong file sẽ không SSH được cho tới khi TN20 xác minh qua console nhà cung cấp và thêm khóa. Không tự dùng `StrictHostKeyChecking=no`; `ssh-keyscan` đơn lẻ chưa xác minh được danh tính máy.

Để tiến tới cấp phát không cần người can thiệp phải xây cơ chế tin cậy host key từ nguồn kiểm soát như SSH host CA; phiên bản này chưa cung cấp. Khi host key đã xác minh, dùng lệnh thử lại ở bước 7 cho đúng tác vụ.

Sau khi chuẩn bị xong, đổi `PROVISIONING_DRIVER=hetzner`, `PROVISIONING_ENABLED=true`, tạo lại app/worker để nhận biến:

```bash
docker compose up -d --force-recreate app worker
```

Cần thuê bao active còn hạn. Yêu cầu từ khách không được tùy ý chọn URL tải, địa chỉ API, lệnh SSH hoặc loại máy. Loại máy do TN20 cấu hình. Cloudflare tạo A record DNS-only; không proxy đường hầm qua dịch vụ chưa nghiệm thu. Nếu bản ghi cùng tên đã khác IP hoặc loại, tác vụ dừng, không ghi đè.

Ansible đợi cloud-init, kiểm tra OS/RAM/đĩa, tải và kiểm tổng kiểm, chạy `install.sh server --check` rồi `--install`. Không có khóa API hạ tầng/DNS trong cloud-init hoặc máy NAC.

## 7. Lỗi và thử lại

```bash
docker compose logs --tail=100 worker
docker compose exec worker php artisan queue:failed
docker compose exec worker php artisan saas:retry ID_TAC_VU
```

Lệnh retry chỉ dành cho TN20, yêu cầu xác nhận, chỉ cho tác vụ lỗi và live environment có `server_id`. Nếu API tạo máy timeout mà chưa ghi được ID, đối soát tên máy `nac20-<tenant_id>-<environment_id>` và tài nguyên nhà cung cấp trước; không tự bấm tạo lần nữa. Việc gắn lại server_id phải thực hiện bằng quy trình vận hành đã kiểm tra quyền sở hữu; chưa có công cụ reconcile tự động.

Tác vụ lưu mã lỗi chung và bước lỗi, không lưu nguyên exception chứa token. Chẩn đoán chi tiết qua bảng điều khiển nhà cung cấp, kết quả Ansible tại môi trường kiểm soát và mã tác vụ; không bật log HTTP chứa Authorization để chia sẻ.

Worker timeout 2400 giây, retry_after Redis 2700 giây. Không giảm retry_after xuống dưới thời gian tác vụ. Scheduler mỗi phút đối soát tác vụ queued cũ và dispatch lại cùng ID nếu enqueue bị gián đoạn; worker lấy khóa và đọc lại trạng thái trước khi làm. Chưa tự phục hồi tác vụ running bị mất worker đột ngột: phải đối soát tài nguyên trước khi thử lại, không tự tạo máy mới.

## 8. Sau khi cài gói NAC20

Trạng thái `awaiting_configuration` có nghĩa **chưa sẵn sàng bàn giao**. TN20 phải hoàn tất trình cấu hình NAC, tạo tài khoản riêng, cấu hình TLS tin cậy, giới hạn truy cập mạng, ghép Connector và thử RADIUS/MAB/CoA trên thiết bị. Bản này chưa tự cấp chứng thư NAC hoặc tự tạo tài khoản quản trị trong môi trường NAC.

Đọc kỹ phạm vi preview của bộ NAC20. Cần chốt digest runtime, diễn tập sao lưu/khôi phục, đặt quy trình nâng cấp và nghiệm thu hai công ty cùng MAC/IP trước khi bán dịch vụ chính thức.

## 9. Sao lưu và cập nhật SaaS

Sao lưu MySQL, khóa ứng dụng và cấu hình vận hành theo chính sách TN20; bảo vệ bản sao lưu như dữ liệu khách hàng. Named volume không thay thế bản sao lưu. Kiểm thử phục hồi trên môi trường cách ly.

Triển khai release đã kiểm tra, build image, sao lưu DB rồi chạy migration có kế hoạch. Không tự chạy `migrate:fresh` trên máy có dữ liệu. Không tự đổi APP_KEY. Worker phải khởi động lại sau khi thay mã/cấu hình; giữ phiên bản app và worker nhất quán.
