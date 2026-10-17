export const provisioningLabels = {
    preflight: 'Kiểm tra điều kiện', creating_server: 'Đang tạo máy chủ', server_simulated: 'Đã mô phỏng máy chủ', dns_simulated: 'Đã mô phỏng DNS', SIMULATION_DISABLED: 'Mô phỏng đã bị tắt',
};
export const planName = value => ({business:'NAC20 Doanh nghiệp',multisite:'NAC20 Đa chi nhánh',enterprise:'NAC20 Theo yêu cầu'})[value] || value;
export const driverName = value => ({simulation:'Mô phỏng kiểm thử',ssh:'Máy chủ riêng qua SSH',hetzner:'Tạo máy chủ Hetzner',manual:'TN20 triển khai theo từng máy'})[value] || value;
const activityLabels = {
    'site.created':'Thêm chi nhánh', 'company.profile_updated':'Cập nhật hồ sơ công ty', 'member.role_assigned':'Cấp hoặc đổi quyền thành viên', 'member.removed':'Thu hồi quyền thành viên',
    'environment.requested':'Yêu cầu tạo môi trường', 'environment.requested_by_admin':'TN20 yêu cầu triển khai', 'environment.handed_over_by_admin':'TN20 bàn giao môi trường', 'environment.access_published':'Công bố thông tin truy cập',
    'quote.requested':'Gửi nhu cầu báo giá', 'quote.issued':'Phát hành báo giá', 'quote.issued_by_admin':'TN20 phát hành báo giá', 'quote.issued_by_console':'Phát hành báo giá từ hệ thống',
    'order.accept':'Chấp nhận báo giá', 'order.cancel':'Hủy đơn hàng', 'order.report_transfer':'Báo đã chuyển khoản', 'payment.verified':'Xác nhận đối soát thanh toán', 'payment.verified_by_admin':'TN20 xác nhận thanh toán', 'payment.verified_by_console':'Xác nhận thanh toán từ hệ thống',
    'support.created':'Gửi yêu cầu hỗ trợ', 'support.replied':'TN20 phản hồi hỗ trợ', 'support.replied_by_console':'Phản hồi hỗ trợ từ hệ thống',
    'customer.created':'Tạo khách hàng', 'customer.updated':'Cập nhật khách hàng', 'user.status_changed':'Thay đổi trạng thái tài khoản', 'account.password_changed':'Đổi mật khẩu cá nhân', 'account.password_reset':'Khôi phục mật khẩu',
    'installation.requested':'Yêu cầu cài đặt', 'installation.ssh_checked':'Kiểm tra SSH trước cài', 'installation.retried':'Thử lại triển khai', 'installation.handed_over':'Xác nhận bàn giao', 'installation.ssh_credentials_replace':'Thay thông tin SSH đã lưu', 'installation.ssh_credentials_forget':'Xóa thông tin SSH đã lưu',
    'operation.completed':'Hoàn tất tác vụ triển khai', 'operation.failed':'Tác vụ cần xử lý', 'operation.cancelled':'Hủy tác vụ', 'operation.cancelled_by_admin':'TN20 hủy tác vụ chờ', 'operation.retry_by_console':'Thử lại tác vụ từ hệ thống',
    'subscription.activated_by_console':'Kích hoạt thuê bao từ hệ thống', 'platform_admin.granted_by_console':'Cấp quyền quản trị nền tảng', 'platform_admin.revoked_by_console':'Thu hồi quyền quản trị nền tảng',
};
export const activityLabel = value => activityLabels[value] || 'Hoạt động hệ thống';
