<?php

return [
    'registration_enabled' => env('CUSTOMER_REGISTRATION_ENABLED', true),
    'bank' => [
        'enabled' => env('BANK_PAYMENT_ENABLED', false),
        'name' => env('BANK_NAME'),
        'account_number' => env('BANK_ACCOUNT_NUMBER'),
        'account_holder' => env('BANK_ACCOUNT_HOLDER'),
    ],
    'plans' => [
        'business' => ['code' => 'business', 'name' => 'NAC20 Doanh nghiệp', 'description' => 'Dành cho doanh nghiệp triển khai kiểm soát truy cập mạng tập trung.', 'features' => ['Môi trường NAC20 riêng', 'Quản lý thiết bị và chính sách mạng', 'Connector kết nối mạng nội bộ', 'Phạm vi triển khai theo báo giá']],
        'multisite' => ['code' => 'multisite', 'name' => 'NAC20 Đa chi nhánh', 'description' => 'Dành cho tổ chức cần quản lý nhiều địa điểm trong một công ty.', 'features' => ['Nhiều chi nhánh trong một không gian', 'Connector tại từng địa điểm', 'Chính sách truy cập tập trung', 'Khảo sát hạ tầng trước triển khai']],
        'enterprise' => ['code' => 'enterprise', 'name' => 'NAC20 Theo yêu cầu', 'description' => 'Thiết kế phương án phù hợp quy mô và yêu cầu vận hành của tổ chức.', 'features' => ['Đánh giá mô hình mạng', 'Lập kế hoạch tích hợp và chuyển đổi', 'Thống nhất hỗ trợ và nghiệm thu', 'Tài nguyên và SLA theo thỏa thuận']],
    ],
];
