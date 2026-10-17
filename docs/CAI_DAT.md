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

Điền `DB_PASSWORD` và thêm `MYSQL_ROOT_PASSWORD` bằng hai mật khẩu ngẫu nhiên riêng. Không dùng tài khoản DB root cho Laravel. Giữ `PROVISIONING_ENABLED=false` trong lần cài đầu. Mô phỏng chỉ hoạt động trong môi trường phát triển/kiểm thử; container có `APP_ENV=production` không chạy mô phỏng, kể cả còn cấu hình `PROVISIONING_DRIVER=simulation`. Với cài đặt SSH do admin quản lý, chọn `PROVISIONING_DRIVER=manual`.

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

**Đổi mật khẩu:** vào **Bảo mật tài khoản** ở khu khách hàng hoặc admin TN20. Nhập mật khẩu hiện tại và mật khẩu mới ít nhất 12 ký tự, xác nhận lại rồi lưu. Phiên đang dùng được thay mã phiên/CSRF; các phiên đã ghi nhận mật khẩu cũ phải đăng nhập lại ở yêu cầu tiếp theo. Mỗi tài khoản tối đa 5 lần thử đổi trong một phút. Chức năng này không cấp quyền đổi mật khẩu người khác và chưa phải khôi phục mật khẩu qua email.

Danh sách môi trường/tác vụ trong **Cài đặt cho khách** tự tải lại mỗi 10 giây khi tab hiển thị; tạm dừng khi đang xử lý, có lỗi hoặc mở phần thử lại/nghiệm thu. Nút **Cập nhật** tải lại thủ công. Tự tải danh sách không gửi lại yêu cầu cài đặt, không tạo VM hoặc xác nhận bàn giao.

Khi nâng cấp từ bản chưa có kiểm tra mật khẩu theo phiên, TN20 cần kết thúc các phiên cũ trong kho session trước khi mở lại dịch vụ; phiên cũ chưa từng qua middleware mới chưa có dấu kiểm tra mật khẩu. Thực hiện trong thời gian bảo trì, theo đúng session driver đang dùng, không xóa dữ liệu nghiệp vụ hoặc dùng lệnh xóa toàn bộ Redis dùng chung. Bản mới ghi dấu này ngay khi đăng ký/đăng nhập.

## 13. Admin tạo khách hàng và cài NAC20 qua mật khẩu SSH

Luồng này dùng **máy chủ đã có**, không gọi API tạo VM Hetzner. Mỗi khách hàng một máy riêng. Bản hiện tại nhận IPv4 công khai; không nhận hostname, IP nội bộ, lệnh shell hoặc tùy chọn SSH tùy ý từ form.

### Chuẩn bị một lần trên SaaS

1. Dùng cổng HTTPS, `APP_DEBUG=false`, `APP_KEY` ổn định và lưu bản sao khóa tại nơi quản trị an toàn. Mật khẩu SSH được mã hóa bằng khóa ứng dụng; thay/mất khóa sẽ ảnh hưởng việc giải mã các tác vụ đang chờ và sao lưu DB còn giữ mật khẩu mã hóa.
2. Cấu hình Redis worker, scheduler, URL/SHA-256 bộ cài như các bước trên. Image app mới có Ansible và `sshpass`; cần dựng lại image khi nâng cấp.
3. Khai báo `NAC_DOMAIN`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID` của vùng DNS TN20. Luồng SSH cũng tự tạo bản ghi A dạng DNS-only; không ghi đè bản ghi xung đột. Không cần token Hetzner, khóa SSH riêng hoặc file known_hosts chung cho luồng mật khẩu này.
4. Sau khi đã chuẩn bị và nghiệm thu hạ tầng, đặt `PROVISIONING_ENABLED=true`, `SSH_INSTALL_ENABLED=true`, `PROVISIONING_DRIVER=manual` cho cài SSH do admin quản lý; chọn `hetzner` nếu dùng cấp VM tự động. Không đặt mặc định `ssh`, vì thông tin máy có sẵn chỉ do admin nhập cho từng khách. Production chặn hoàn toàn driver mô phỏng.
5. Cập nhật và khởi động lại:

```bash
docker compose build
docker compose run --rm app php artisan migrate --force
docker compose up -d --force-recreate
```

### Chuẩn bị máy đích

- Debian 12 amd64 riêng, tối thiểu 16 GB RAM và 200 GB đĩa gốc. Chưa cài NAC/PacketFence khác. Không dùng máy đang chạy ứng dụng khách cần giữ.
- SSH cho phép xác thực mật khẩu; tài khoản `root` hoặc có quyền sudo. Bản này dùng cùng mật khẩu cho SSH và sudo; tài khoản có mật khẩu sudo khác cần điều chỉnh ở vận hành trước.
- Worker SaaS truy cập được cổng SSH máy đích; máy đích truy cập được kho gói và bộ cài. Giới hạn firewall SSH theo IP worker của TN20.
- Tại console tin cậy của VPS/nhà cung cấp, lấy **khóa công khai máy chủ**, không lấy private key:

```bash
cat /etc/ssh/ssh_host_ed25519_key.pub
```

Sao chép dòng `ssh-ed25519 AAAA...`, có thể kèm chú thích cuối dòng. Đây là danh tính máy chủ để SSH kiểm tra trước khi gửi mật khẩu. Nếu máy được cài lại hoặc khóa thay đổi, TN20 cần đối soát; hệ thống không tự bỏ kiểm tra host key.

### Thao tác tại khu admin

1. Đăng nhập `/admin` → **Khách hàng → Thêm khách hàng**. Nhập tên, mã công ty và email chủ tài khoản. Nếu chưa có tài khoản, bật **Tạo tài khoản mới cho chủ công ty**, nhập họ tên/mật khẩu ban đầu, nhập lại mật khẩu và xác nhận mật khẩu admin hiện tại. Tài khoản mới chỉ là khách hàng, không được cấp quyền admin TN20. Hệ thống chưa tự gửi email; TN20 bàn giao qua kênh riêng.
2. Kích hoạt thuê bao bằng quy trình báo giá/đối soát hợp lệ ở phần 10. Trong form đối soát bật **Để admin chọn máy và cài SSH sau đối soát**. Thuê bao được kích hoạt, chưa tạo môi trường; tránh tạo nhầm môi trường mô phỏng trước khi admin chọn máy. Không dùng xác nhận thanh toán giả để bỏ điều kiện thuê bao.
3. Vào **Cài đặt cho khách**, chọn đúng công ty → **Máy chủ có sẵn — SSH bằng mật khẩu**.
4. Điền IPv4, cổng (thường 22), tài khoản, mật khẩu SSH, khóa công khai Ed25519 từ console máy chủ và mật khẩu admin TN20 hiện tại. Đánh dấu xác nhận máy riêng/được phép cài và xác nhận đúng khách hàng.
5. Bấm **Tạo tác vụ cài đặt NAC20**. Trình duyệt chỉ gửi tác vụ một lần; worker kiểm tra quyền admin/thuê bao/công tắc triển khai, tạo DNS, chuẩn bị Python nếu thiếu, kiểm tra Debian/tài nguyên, tải đúng bộ cài và SHA-256 rồi cài qua Ansible. Trang cập nhật danh sách định kỳ, không cần giữ trình duyệt mở để worker chạy.
6. Khi trạng thái **Chờ cấu hình / nghiệm thu**, mật khẩu SSH đã được xóa khỏi DB. TN20 hoàn tất trình cấu hình NAC20, TLS, Connector và kiểm tra RADIUS/MAB thực tế, rồi nghiệm thu/bàn giao theo phần 12. Chỉ cài xong gói chưa đồng nghĩa mạng đã được kiểm soát hoàn chỉnh.

### Khi có lỗi

Mật khẩu không được trả lại qua API, không nằm trong tham số dòng lệnh hoặc nhật ký nghiệp vụ. Inventory tạm có quyền `0600`, thư mục `0700`, ưu tiên RAM `/dev/shm` trên Linux và xóa khi tiến trình kết thúc bình thường; playbook tắt ghi nội dung tác vụ nhạy cảm. Nếu worker bị kill cứng, vận hành cần dọn inventory tạm sau khi chắc chắn không còn tác vụ chạy; không sao lưu các file này.

Tác vụ lỗi giữ mật khẩu dạng mã hóa để thử lại. Ở danh sách môi trường, chọn **Thay / xóa mật khẩu SSH**, xác nhận mật khẩu admin, thay mật khẩu đúng hoặc xóa bản đã lưu. Thao tác này không tự chạy lại. Sau khi xử lý nguyên nhân, dùng **Thử lại** và xác nhận đã đối soát. Xóa mật khẩu thì cần nhập lại trước khi thử cài. Môi trường SSH đã thành công không giữ mật khẩu để tiếp tục quản trị máy; dùng quy trình vận hành riêng.

Backend giữ duy nhất một môi trường SSH cho mỗi IP đích, đồng thời playbook ghi dấu gắn máy với môi trường SaaS tại `/opt/nac20-installer/saas-environment`. Máy đã gắn với môi trường khác hoặc có NAC từ trước mà chưa được quy trình này quản lý sẽ bị chặn. Không tự xóa dấu này để tái sử dụng máy; TN20 phải đối soát và chuẩn bị lại máy riêng trước.

Luồng này chưa được nghiệm thu trên VPS thật trong quá trình phát triển: kiểm thử dùng HTTP/Ansible giả lập và CI kiểm tra cú pháp/image. Cần thử một máy Debian 12 riêng của TN20 trước khi bàn giao cho khách trả phí.

## 14. Kiểm tra trước cài, hủy tác vụ, xử lý tác vụ treo và thông báo

**Kiểm tra trước khi cài:** sau khi nhập thông tin SSH tại khu admin, bấm **Kiểm tra SSH trước khi cài**. Backend xác nhận mật khẩu admin, kết nối SSH với host key đã cung cấp, thử quyền root/sudo, kiểm tra Debian 12 amd64, RAM, dung lượng đĩa và việc máy đã gắn môi trường khác. Không cài Python/gói, không ghi dấu sở hữu máy và không tạo DNS trong bước này. Mật khẩu kiểm tra không được lưu DB. Kiểm tra tối đa 45 giây; nếu không đạt, giao diện chỉ trả thông báo an toàn, không trả nội dung stdout/stderr có thể chứa bí mật.

Kết quả kiểm tra chỉ có giá trị tại thời điểm thực hiện. Khi nhập khác thông tin máy, giao diện bỏ kết quả cũ. Worker luôn kiểm tra lại trước DNS, kể cả admin không bấm kiểm tra riêng. Nếu không đạt, tác vụ chuyển lỗi `SSH_PREFLIGHT_FAILED`, giữ mật khẩu mã hóa cho quy trình sửa/thử lại; chưa gọi DNS hoặc bước cài gói. Lỗi này chưa tự phân loại chính xác thành sai mật khẩu, thiếu sudo hay thiếu tài nguyên; TN20 kiểm tra các điều kiện nêu trên tại console tin cậy.

**Hủy tác vụ chờ:** trong danh sách tác vụ chọn **Hủy tác vụ chờ**, kiểm tra đúng khách hàng rồi xác nhận. Backend khóa trạng thái để phân định với worker: nếu worker đã nhận việc, yêu cầu hủy bị từ chối. Hủy xóa mật khẩu SSH trong DB và đánh dấu tác vụ/môi trường đã hủy. Không xóa VM, DNS hoặc hoàn tác phần cài từ lần chạy trước. Không có chức năng dừng cưỡng bức tác vụ đang chạy trên giao diện.

**Khởi động lại sau hủy/lỗi:** đối soát máy và nguyên nhân trước; với SSH đã xóa mật khẩu, dùng **Thay / xóa mật khẩu SSH** để nhập lại. Sau đó chọn **Thử lại / Khởi động lại** và xác nhận. Backend kiểm tra lại thuê bao, khách hàng, quyền admin và công tắc triển khai trước khi xếp hàng. Mỗi lần chạy có số lần riêng; job và callback của lần cũ không được cập nhật kết quả của lần mới. Không dùng luồng này để tự cài lại máy đã bàn giao hoặc chuyển môi trường giữa các khách hàng.

Lệnh console dùng cùng dịch vụ thử lại với API. Nếu người yêu cầu cũ chưa phải admin TN20 đang hoạt động, chỉ định admin chịu trách nhiệm tại console tin cậy:

```bash
docker compose exec app php artisan saas:retry ID_TAC_VU --actor=EMAIL_ADMIN_TN20
```

**Tác vụ treo:** giữ scheduler và Redis hoạt động. Mỗi phút scheduler đối soát các tác vụ running đã quá 50 phút tính từ lúc nhận việc (tác vụ cũ thiếu thời điểm bắt đầu dùng thời gian cập nhật), và chỉ xử lý khi lấy được khóa worker của công ty. Tác vụ được chuyển lỗi `WORKER_STALE`, môi trường lỗi và phát thông báo. Không tự đưa lại vào hàng đợi hoặc tạo lại VM. Đây là đối soát quá hạn, không chứng minh tiến trình từ xa đã dừng; TN20 cần kiểm tra máy trước khi thử lại.

```bash
docker compose exec app php artisan saas:recover-stale
```

Worker timeout bình thường có thể ghi `WORKER_INTERRUPTED` sớm hơn. Các mốc hiện tại: job tối đa 2400 giây, khóa công ty 2500 giây, Redis retry_after 2700 giây, đối soát quá hạn 3000 giây. Không giảm các mốc tùy ý hoặc cấu hình retry_after ngắn hơn thời gian chạy job.

**Thông báo:** admin TN20 và thành viên đang hoạt động của công ty nhận thông báo hoàn tất, lỗi hoặc hủy trong mục **Thông báo**. Mỗi người đánh dấu đã đọc riêng; nội dung không chứa mật khẩu hoặc stdout/stderr. Mất quyền công ty hoặc quyền admin thì không còn xem được thông báo vượt quyền, kể cả thông báo đã phát trước đó. Chỉ phát cho các kết quả mới sau khi nâng cấp, chưa nhập lại lịch sử cũ, chưa gửi email/SMS.

Khi nâng cấp, chạy migration mới và khởi động lại worker/scheduler/app để dùng mã và schema đồng nhất. Job Redis cũ không mang số lần chạy được coi là lần đầu; các lần thử lại sau nâng cấp có số lần riêng. Tiếp tục giữ chế độ mô phỏng đến khi TN20 nghiệm thu VPS thật.

## 15. Mật khẩu ban đầu và khôi phục tài khoản

Tài khoản khách mới do admin tạo tại **Khách hàng → Tạo tài khoản mới cho chủ công ty**, hoặc tạo qua `saas:company`, phải đổi mật khẩu ban đầu ở lần đăng nhập đầu tiên. Backend chỉ cho phép xem tài khoản hiện tại, đổi mật khẩu và đăng xuất trong giai đoạn này; API công ty, thanh toán, cài đặt và quản trị đều bị chặn. Tài khoản tự đăng ký tự đặt mật khẩu và tài khoản đã tồn tại không bị tự chuyển sang chế độ bắt buộc đổi.

Sau khi nâng cấp, chạy migration rồi khởi động lại ứng dụng. Khách dùng mật khẩu ban đầu TN20 đã bàn giao, đặt mật khẩu mới tối thiểu 12 ký tự và nhập lại để xác nhận. TN20 cần bàn giao mật khẩu qua kênh riêng, không ghi vào ghi chú triển khai hoặc ticket.

Trang đăng nhập khách/admin có liên kết **Quên mật khẩu?**, mở `/khoi-phuc-mat-khau`. Chức năng mặc định chưa bật. Để vận hành thật, cấu hình trong `.env` trên máy SaaS, dùng thông tin SMTP do TN20 sở hữu:

```dotenv
APP_URL=https://TEN_MIEN_SAAS_CUA_TN20
PASSWORD_RECOVERY_ENABLED=true
MAIL_MAILER=smtp
MAIL_HOST=TEN_MAY_CHU_SMTP
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=TAI_KHOAN_SMTP
MAIL_PASSWORD=MAT_KHAU_SMTP
MAIL_TIMEOUT=15
MAIL_FROM_ADDRESS=noreply@20.com.vn
MAIL_FROM_NAME="TN20"
```

Thay toàn bộ giá trị minh họa bằng giá trị thật. Chọn cổng/scheme theo dịch vụ thư: cổng 465 thường dùng `MAIL_SCHEME=smtps`; với STARTTLS trên 587 dùng `smtp` và bảo đảm máy chủ hỗ trợ TLS. Cấu hình SPF/DKIM/DMARC, địa chỉ gửi được cấp quyền và reverse proxy HTTPS; không lưu bí mật vào Git. Không dùng `MAIL_URL` ghi đè các thông số trên. Sau thay đổi:

```bash
docker compose up -d --force-recreate app worker scheduler
docker compose exec app php artisan config:clear
```

Khu admin **Cấu hình** hiển thị việc đã bật cấu hình khôi phục; đây chưa phải kiểm tra SMTP gửi thành công. Thử bằng một tài khoản đang hoạt động của TN20: yêu cầu khôi phục, kiểm tra cả thư rác, mở liên kết trong 60 phút và đặt mật khẩu mới. Sau thành công phải đăng nhập lại; phiên cũ bị từ chối ở lần truy cập kế tiếp. Dùng lại liên kết hoặc dùng liên kết hết hạn phải bị từ chối. Tài khoản bị khóa không nhận thư và không thể dùng khôi phục để tự mở khóa.

Email chỉ trỏ đến `APP_URL` tin cậy. Token nằm trong phần fragment của liên kết, được xóa khỏi thanh địa chỉ khi mở giao diện và không lưu localStorage; DB chỉ lưu giá trị đã băm. API không trả token, không báo email tồn tại hay chưa; giới hạn 3 yêu cầu/email/giờ và 10 yêu cầu/IP/giờ, đặt lại tối đa 5 lượt/IP/phút. Lỗi SMTP vẫn trả phản hồi chung để tránh lộ tài khoản, nhật ký chỉ ghi cảnh báo chung không kèm thư/token. Vận hành cần theo dõi cảnh báo và thử gửi thực tế trước khi công bố dịch vụ. Scheduler dọn token hết hạn hằng ngày.

Chức năng không sử dụng mailer ghi log, bộ nhớ hoặc failover để tránh ghi liên kết bí mật vào nhật ký. Chưa có MFA/xác minh email và chưa thử gửi SMTP thật trong môi trường phát triển này.

## 16. Kiểm thử trước khi chuyển sang vận hành thật

Kiểm thử mã nguồn và kiểm tra cấu hình không thay thế nghiệm thu hạ tầng. Không đổi tên bản preview thành bản ổn định, không đổi dữ liệu `simulated` thành `ready`, không xóa dữ liệu thử trên một database đang dùng chung để tạo cảm giác đã chạy thật.

### Phạm vi kiểm thử tự động

| Quy trình | Kiểm tra |
|---|---|
| Tài khoản | Đăng nhập/đăng xuất, khóa tài khoản, đổi mật khẩu ban đầu, thu hồi phiên, khôi phục một lần/hết hạn |
| Phân quyền | Cách ly công ty, vai trò owner/admin/operator/viewer, thu hồi quyền, khóa ngoại và chỉ mục tenant |
| Mua bán | Yêu cầu báo giá → phát hành → chấp nhận → báo chuyển khoản → admin đối soát → kích hoạt thuê bao |
| Giao dịch lặp/sai | Đúng số tiền, tham chiếu duy nhất, không cộng thuê bao/tạo máy trùng, gia hạn, hủy/hết hạn |
| Cài đặt | Xác nhận mật khẩu admin, kiểm tra SSH/tài nguyên trước DNS, giữ bí mật, hủy/thử lại, worker mất kết nối |
| Bàn giao/hỗ trợ | Chờ nghiệm thu sau cài, điều kiện bàn giao, phản hồi hỗ trợ và thông báo theo từng người |
| Production | Từ chối mô phỏng qua API/admin/đối soát/worker/thử lại, CSRF và cờ cookie bảo mật |
| Đóng gói | Build Vue, kiểm tra cú pháp Ansible, MySQL/Redis thật trong CI, Docker migrate/HTTP/assets/API/CSRF, audit phụ thuộc |

Bài kiểm thử tổng hợp dùng database riêng và giả lập API DNS/tiến trình Ansible; không chuyển tiền, không SSH vào IP trong dữ liệu kiểm thử và không tạo VPS. Bài Redis và MySQL trong CI kiểm tra dịch vụ thật, nhưng không chứng minh NAC20 đã xác thực MAC thành công trên switch của khách.

### Kiểm tra cấu hình trên worker thật

```bash
docker compose exec worker php artisan saas:production-check --connections
```

Lệnh chỉ đọc cấu hình, schema MySQL và PING các kết nối Redis; không gửi thư, không tạo DNS/VM, không cài NAC20 và không hiển thị mật khẩu/token. Mã thoát `1` nghĩa là còn điều kiện chưa đạt. Không có `--connections` thì chỉ kiểm tra cấu hình và file, không xác nhận kết nối. Ngay cả mã `0` cũng chưa thay thế các nghiệm thu bên dưới.

Chuẩn bị database MySQL production riêng, InnoDB/utf8mb4, không chép database SQLite xem thử hoặc tài khoản thử sang production. Cấu hình Redis cho session/cache/queue, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `APP_DEBUG=false`, `APP_URL` HTTPS tin cậy. Với SSH theo từng máy, dùng `PROVISIONING_DRIVER=manual`; với tự tạo VM, dùng `hetzner` và đầy đủ thông tin provider. Chỉ bật công tắc triển khai khi hạ tầng đã được chuẩn bị cho nghiệm thu.

Production không cho phép mô phỏng: ẩn lựa chọn ở admin, từ chối ở backend, không tự tạo môi trường giả sau đối soát. Tác vụ mô phỏng còn trong hàng đợi bị chuyển lỗi `SIMULATION_DISABLED`, không thực hiện giả và không được thử lại trên production. Lịch sử thử không tự bị xóa. Kiểm tra database cũng báo chưa đạt nếu còn môi trường mô phỏng.

### Các nghiệm thu thực tế bắt buộc trước khi bán dịch vụ chính thức

1. Chốt bộ NAC20 được nghiệm thu và URL/SHA-256 tương ứng. Bản phát hành hiện có `nac20-16.0.0-preview.1` là prerelease; không coi là bản ổn định chỉ vì build thành công.
2. Dùng một VPS Debian 12 riêng của TN20, xác minh host key từ console, kiểm tra SSH/root hoặc sudo, cài thành công và ghi lại biên bản. Không dùng máy đang chứa dữ liệu quan trọng để diễn tập.
3. Xác minh DNS đúng vùng của TN20 và TLS tin cậy; hoàn tất trình cấu hình NAC20. Cấu hình Connector tại công ty và xác thực MAC/RADIUS/MAB với switch/Wi-Fi thật. Thử hai công ty có cùng MAC/IP để chứng minh cách ly.
4. Thử gửi/nhận thư SMTP thật, đặt lại mật khẩu và từ chối liên kết cũ. Kiểm tra thông tin ngân hàng do TN20 cung cấp; thử đối soát với một giao dịch được TN20 xác minh, không dùng tham chiếu giả của kiểm thử.
5. Diễn tập sao lưu/khôi phục database, cấu hình NAC20 và khóa ứng dụng; xác minh worker/scheduler đang chạy, giám sát lỗi, thông báo và quy trình phục hồi tác vụ bị gián đoạn.
6. Chỉ ghi nhận bàn giao sau nghiệm thu thực tế. Giữ bằng chứng và phương án quay lui; hệ thống không tự đánh dấu các tiêu chí này chỉ dựa trên CI hoặc checkbox được điền trong kiểm thử.

Kiểm tra môi trường phát triển ngày 17/10/2026: vẫn dùng SQLite, queue database, mail log và driver simulation; chưa khai báo DNS/provider/ngân hàng/bộ cài cho runtime, chưa có VPS để nghiệm thu. Vì vậy môi trường này chưa được chuyển sang vận hành thật. Không có VPS, DNS hoặc giao dịch thanh toán thật nào được tạo trong đợt kiểm thử.

### Tên miền SaaS nac20.com

TN20 đã chọn `nac20.com` cho SaaS. Mẫu `.env.production.example` chuẩn bị `APP_URL=https://nac20.com`, Redis/MySQL, cookie Secure và phương thức `manual` cho SSH do admin điều phối. Vùng `nac.nac20.com` là đề xuất cho các máy NAC riêng của khách, cần TN20 xác nhận/quản lý DNS. Địa chỉ gửi SMTP, khóa ứng dụng, mật khẩu DB, token DNS và URL/SHA-256 bộ cài được để trống; các công tắc triển khai, SMTP khôi phục, nhận chuyển khoản và đăng ký khách đều chưa bật.

Trong thư mục cài đặt production **mới**, sao chép mẫu này thành `.env`, điền bí mật trực tiếp trên máy chủ, tạo `APP_KEY` riêng rồi cấu hình reverse proxy HTTPS cho `nac20.com`. Không ghi đè `.env` đang vận hành hoặc dùng chung database với bản xem thử. Không bật các công tắc chỉ để vượt qua bảng kiểm.

Tại thời điểm kiểm tra ngày 17/10/2026, truy vấn DNS công khai cho `nac20.com` chưa phân giải được qua Cloudflare (`1.1.1.1`) và Google (`8.8.8.8`). Cần xác nhận tên miền, cấu hình nameserver/vùng DNS và bản ghi A/AAAA theo IP VPS trước khi cấp TLS hoặc nhận khách. Kết quả này không xác nhận tình trạng đăng ký hay quyền sở hữu tên miền.

## 17. Giao diện và công bố thông tin truy cập cho khách

Giao diện giới thiệu sản phẩm, khu khách hàng và khu admin dùng cùng kiểu chữ, màu trạng thái, biểu mẫu và bảng dữ liệu. Trên điện thoại/tablet, nút **Mở điều hướng** mở menu thu gọn; Escape hoặc nút đóng trả lại tiêu điểm. Phím Tab đi trong menu khi đang mở. Liên kết **Đi đến nội dung chính** hỗ trợ người dùng bàn phím. Các trang trong cổng có fragment URL riêng; có thể tải lại và dùng Back/Forward.

Sau nâng cấp, chạy migration mới và build lại giao diện. Các môi trường đã bàn giao từ trước không tự nhận URL/tài khoản giả; thông tin truy cập ban đầu để trống.

1. TN20 hoàn tất cấu hình NAC20, TLS, Connector, kiểm tra mạng và nghiệm thu thực tế, rồi ghi nhận **Nghiệm thu / Bàn giao** như trước.
2. Admin mở **Cài đặt cho khách → Môi trường đã tạo**, chọn **Thông tin truy cập** ở môi trường đã bàn giao.
3. Nhập URL HTTPS thực tế đúng hostname của môi trường. Cổng `1443` được gợi ý theo bộ cài; sửa theo reverse proxy/cổng thực tế nếu khác. Không dùng URL tên miền ngoài, URL chứa thông tin đăng nhập, query hoặc fragment bí mật.
4. Nhập tên tài khoản **đã tạo trong NAC20**, hướng dẫn bàn giao không chứa mật khẩu và xác nhận mật khẩu admin SaaS. Bấm **Công bố cho khách hàng**. Thao tác chỉ lưu thông tin bàn giao, không tự tạo tài khoản hay đổi mật khẩu bên NAC20.
5. Chủ công ty/quản trị viên công ty vào **Truy cập NAC20** để xem URL, tên tài khoản, ngày bàn giao và hướng dẫn; có nút mở trang NAC20 và sao chép thông tin. Mật khẩu NAC20 vẫn bàn giao qua kênh riêng đã thống nhất, không lưu trong biểu mẫu này.

Viewer/operator và công ty khác không được đọc thông tin tài khoản qua API; các trường bàn giao cũng được ẩn khỏi danh sách môi trường thông thường. Công ty bị tạm ngưng không đọc được thông tin qua endpoint này. Chưa bàn giao, dữ liệu mô phỏng hoặc chưa công bố thông tin thì khách thấy trạng thái chờ cùng nút trao đổi với TN20, không có nút đăng nhập giả.

Thao tác công bố cần mật khẩu admin hiện tại, có giới hạn lượt thử và nhật ký riêng không chứa nội dung bí mật. Chưa có tự tạo tài khoản NAC20, đăng nhập một lần hoặc gửi email bàn giao tự động. Việc hoàn thiện giao diện không tự bật triển khai thật khi chưa có VPS/DNS để nghiệm thu.
