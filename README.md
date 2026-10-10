# Inventory Management System

Hệ thống quản lý kho hàng triển khai bằng Docker Compose.

## Công nghệ
- PHP
- MySQL 8.0
- phpMyAdmin
- Docker Compose

## Chức năng
- Quản lý sản phẩm
- Quản lý nhà cung cấp
- Nhập/xuất hàng
- Thống kê tồn kho

## Triển khai
1. Sao chép .env.example thành .env
2. Cấu hình mật khẩu MySQL
3. Chạy: docker compose up -d

## Giám sát và bảo mật
Sẽ bổ sung theo từng giai đoạn.
## Nginx Reverse Proxy

Hệ thống triển khai Nginx làm Reverse Proxy cho ứng dụng PHP.

### Kiến trúc

Browser → Nginx:8080 → PHP Apache:80 → MySQL:3306

### Truy cập

- Website: http://localhost:8080
- phpMyAdmin: http://localhost:8082

### Bảo mật Nginx

- X-Frame-Options: SAMEORIGIN
- X-Content-Type-Options: nosniff
- Referrer-Policy: strict-origin-when-cross-origin
- Permissions-Policy: Vô hiệu hóa camera, microphone và geolocation.

### Kiểm thử

docker compose config --quiet
docker compose up -d
docker compose ps
curl -I http://localhost:8080
## Monitoring - Prometheus + Grafana

Hệ thống sử dụng Prometheus và Grafana để giám sát
máy chủ Ubuntu, các container Docker và MySQL.

### Thành phần
- Prometheus: Thu thập metrics
- Grafana: Hiển thị dashboard
- Node Exporter: CPU, RAM, Disk, Network Ubuntu
- cAdvisor: CPU, RAM, Network container
- MySQL Exporter: Theo dõi MySQL
- Blackbox Exporter: Kiểm tra HTTP website

### Truy cập
- Prometheus: http://localhost:9090
- Grafana: http://localhost:3000
- Inventory: http://localhost:8080

### Dashboard
- Node Exporter Full: ID 1860
- cAdvisor: ID 14282
- MySQL Overview: ID 7362

Các file JSON được lưu tại monitoring/dashboards/.

### Kiểm thử
docker compose up -d
docker compose ps

Truy cập Prometheus /targets để kiểm tra trạng thái UP.