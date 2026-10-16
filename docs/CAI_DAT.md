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

Lệnh tạo công ty hỏi mật khẩu tương tác, tối thiểu 12 ký tự. Nếu email đã tồn tại, lệnh gán chủ công ty cho người dùng đó, không đổi mật khẩu; chỉ người vận hành có quyền console được dùng. Không có mật khẩu mặc định. Khách có thể tự tạo tài khoản mới khi `CUSTOMER_REGISTRATION_ENABLED=true`; mỗi đăng ký chỉ tạo công ty riêng, chưa có thuê bao hoặc máy chủ. Đặt false nếu chỉ cấp tài khoản qua TN20. Bản đầu chưa xác minh email/MFA; cần hoàn thiện trước khi mở rộng công khai.

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

## 10. Giới thiệu sản phẩm, báo giá và thanh toán

Trang `/san-pham` công khai giới thiệu giải pháp, quy trình và ba nhóm gói. Tất cả hiển thị **Liên hệ báo giá**, theo lựa chọn của TN20. Không có giá thử nghiệm hoặc giá bán tự đặt. Khách đăng nhập/đăng ký rồi vào **Tư vấn & báo giá**, gửi số thiết bị, chi nhánh và yêu cầu; yêu cầu chưa tự tạo đơn có giá hoặc cấp dịch vụ.

TN20 xem yêu cầu và phát hành báo giá riêng từ console:

```bash
docker compose exec app php artisan saas:sales
docker compose exec app php artisan saas:quote ID_YEU_CAU SO_TIEN_VND --days=SO_NGAY --terms="Điền đầy đủ phạm vi, giá, thuế/phí, thời hạn và điều kiện nghiệm thu đã thống nhất"
```

Các chuỗi viết hoa là giá trị cần thay, không phải lệnh mẫu có thể chạy nguyên văn. Giá phải nguyên VND, từ 1.000 đến 1.000.000.000.000; thời hạn 1–366 ngày. Báo giá có hạn xác nhận 7 ngày. Để sửa báo giá đã phát hành, hủy đơn cũ theo quy trình và tạo yêu cầu/báo giá mới; không sửa trực tiếp số tiền của đơn đã xác nhận.

Khách xem báo giá tại **Đơn hàng**, đọc điều khoản, xác nhận hoặc hủy trước khi báo chuyển khoản. Báo giá lưu số tiền và điều khoản riêng, không bị đổi theo danh mục sản phẩm. Chi tiết đơn không thay thế hóa đơn thuế.

Chỉ bật chuyển khoản sau khi có tài khoản ngân hàng chính thức của TN20:

```dotenv
BANK_PAYMENT_ENABLED=true
BANK_NAME=
BANK_ACCOUNT_NUMBER=
BANK_ACCOUNT_HOLDER=
```

Điền đầy đủ ba trường rồi khởi động lại app/worker. Nếu thiếu hoặc tắt, cổng không hiển thị số tài khoản giả và không cho báo đã chuyển khoản. Không lưu thông tin thẻ. Chưa có QR ngân hàng, cổng thẻ, webhook hoặc thao tác chuyển tiền tự động.

Sau khi khách xác nhận đơn, nội dung chuyển khoản là mã đơn `N20-...`. Khách bấm **Tôi đã chuyển khoản** chỉ chuyển đơn sang **Chờ đối soát**, không tự kích hoạt. TN20 kiểm tra số tiền và giao dịch thực nhận trước khi chạy:

```bash
docker compose exec app php artisan saas:order-paid ID_DON THAM_CHIEU_THUC_NHAN SO_TIEN_VND
```

Số tiền phải đúng báo giá; tham chiếu chỉ được dùng một lần. Gọi lặp đúng đơn/tham chiếu/số tiền không cộng thêm thời gian hoặc tạo thêm máy. Gia hạn cộng từ ngày hết hạn còn hiệu lực, nếu đã hết hạn thì tính từ thời điểm đối soát. Đã có môi trường thì giữ môi trường đó; đổi gói không tự nâng cấu hình máy. Khi chưa có môi trường, hệ thống tự tạo tác vụ nếu simulation hoặc đã bật cấp phát thật. Cài NAC xong vẫn chờ cấu hình/nghiệm thu.

**Lịch sử thanh toán** chỉ hiện giao dịch TN20 đã xác nhận. **Dịch vụ của tôi** hiển thị gói/thời hạn. Dữ liệu theo công ty; owner/admin được xem tài chính, operator/viewer không được xem. Chưa có hoàn tiền, điều chỉnh thuế hoặc xuất hóa đơn điện tử tự động; các trường hợp chuyển sai/thiếu/thừa phải xử lý đối soát riêng, không ép đơn thành paid.

## 11. Yêu cầu hỗ trợ trong cổng

Thành viên tạo yêu cầu tại **Hỗ trợ**, không gửi khóa/mật khẩu. TN20 xem ID qua `saas:sales` rồi phản hồi:

```bash
docker compose exec app php artisan saas:ticket-reply ID_YEU_CAU_HO_TRO "Nội dung phản hồi" --close
```

Bỏ `--close` để giữ trạng thái đã phản hồi. Nội dung nằm trong ứng dụng, không tự gửi email. Lịch sử có phân trang và giới hạn theo công ty. Bản đầu chưa có tệp đính kèm hoặc hội thoại nhiều lượt; phản hồi console cập nhật phần trả lời của yêu cầu.

## 12. Khu quản trị TN20 và khu khách hàng

Sau khi cập nhật mã, dựng lại image, chạy migration và khởi động lại app/worker/scheduler. Migration thêm cờ admin/khóa tài khoản và nhật ký quản trị, không tự cấp quyền cho tài khoản cũ.

Tài khoản người vận hành phải tồn tại trước. Có thể đăng ký tài khoản riêng qua cổng hoặc dùng quy trình tạo tài khoản console hiện có, sau đó:

```bash
docker compose exec app php artisan saas:admin EMAIL_NGUOI_VAN_HANH
```

Chạy tại console TN20 và xác nhận đúng email. Dùng `--revoke` để thu hồi. Không có API tự cấp quyền admin, không cấp cờ này trong form đăng ký hoặc chỉnh vai trò công ty. Quyền bị thu hồi có hiệu lực ở yêu cầu kế tiếp. Không có admin/mật khẩu mặc định.

**Admin TN20 đăng nhập tại `/admin`:**

1. Tổng quan: khách hàng, yêu cầu báo giá, chờ đối soát, doanh thu đã xác nhận, tác vụ lỗi và hỗ trợ đang mở.
2. Khách hàng: tìm kiếm, tạo công ty cho tài khoản đã có, chỉnh hồ sơ, trạng thái hoạt động/tạm ngưng và xem thành viên. Tạm ngưng không tự tắt VM hoặc RADIUS.
3. Bán hàng: xem nhu cầu, lập báo giá riêng và đối soát đơn đã xác nhận. Khi ghi nhận thanh toán phải xác nhận đã kiểm tra giao dịch thực nhận; không thực hiện chuyển tiền.
4. Cài đặt cho khách: chọn đúng khách từ danh sách hoặc nhập ID và tải hồ sơ; xem thuê bao và môi trường hiện có. Chọn mô phỏng hoặc cấp phát thật nếu được bật, nhập ghi chú không chứa bí mật, xác nhận và tạo tác vụ.
5. Theo dõi VM, IP, trạng thái và bước cài. Hệ thống chặn cài đè môi trường có sẵn. Tác vụ lỗi chỉ thử lại sau đối soát; live chưa có server_id phải xử lý tại vận hành trước, không tự tạo máy lần nữa.
6. Nghiệm thu: chỉ môi trường thật ở trạng thái chờ cấu hình, có server_id/IP mới được bàn giao. TN20 kiểm tra DB/tài khoản, TLS, Connector, RADIUS thực tế và sao lưu/khôi phục, rồi ghi bằng chứng/biên bản. Các ô xác nhận là lời xác nhận của người nghiệm thu, không thay kiểm thử tự động hoặc chứng minh dịch vụ đã chạy chỉ bằng giao diện.
7. Hỗ trợ: đọc, phản hồi và đóng yêu cầu. Tài khoản: khóa/mở tài khoản khách; không tự đổi cờ admin qua màn hình này. Cấu hình: chỉ hiện tình trạng khai báo, không trả token hoặc khóa SSH.

**Khách hàng đăng nhập tại `/`:** báo giá, đơn, thanh toán, thời hạn, chi nhánh, hạ tầng, hỗ trợ, hồ sơ công ty và thành viên. Owner/admin xem thông tin tài chính; operator/viewer chỉ có quyền đọc phần được phép. Chủ công ty mới được cấp/thu hồi quyền thành viên, phải nhập mật khẩu hiện tại. Thành viên được thêm cần có tài khoản đã đăng ký; đây là gán quyền cho tài khoản hiện hữu, chưa phải lời mời qua email. Không đổi/xóa vai trò owner từ màn hình thành viên.

Cấu hình API hạ tầng, DNS, SSH và chứng thư vẫn do TN20 chuẩn bị như bước 6–8. Màn hình admin không tự cấp token nhà cung cấp, bỏ kiểm tra SSH host key hoặc hoàn tất trình cấu hình NAC chưa được tự động hóa. Bản pilot vẫn cần MFA/xác minh email, cổng thanh toán tự động và nghiệm thu hạ tầng trước khi vận hành công khai.
