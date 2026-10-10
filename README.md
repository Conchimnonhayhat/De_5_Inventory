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
## Centralized Logging - Loki + Promtail

Hệ thống sử dụng Promtail để thu thập Docker container logs,
đẩy về Loki và truy vấn tập trung qua Grafana Explore.

### Thành phần

- Loki: Lưu trữ log tập trung
- Promtail: Thu thập Docker logs
- Grafana: Truy vấn và trực quan hóa log
- LogQL: Lọc, thống kê và phân tích log

### Truy cập

- Loki API: http://localhost:3100
- Grafana: http://localhost:3000

### Các truy vấn LogQL

**Truy vấn 1: Xem toàn bộ Docker logs**

```logql
{job="docker"}
**Truy vấn 2: Lọc các log chứa "error"**

{job="docker"} |= "error"

**Truy vấn 3: Thống kê số dòng log trong 5 phút**
sum(count_over_time({job="docker"}[5m]))
## Hardening – Bảo mật hệ thống

Hệ thống áp dụng 4 biện pháp Hardening:

### 1. Nginx Security Headers

Cấu hình các HTTP Security Headers:
- X-Frame-Options: SAMEORIGIN
- X-Content-Type-Options: nosniff
- Referrer-Policy: strict-origin-when-cross-origin
- Permissions-Policy

Kiểm tra: `curl -I http://localhost:8080`

### 2. Network Isolation

Hệ thống sử dụng hai Docker network:
- `app-network`: Kết nối website, MySQL, Nginx và các dịch vụ liên quan.
- `monitoring`: Kết nối Prometheus, Grafana, Loki và các thành phần giám sát.

Các Exporter cần truy cập cả hai mạng được cấu hình kết nối tương ứng.

### 3. Non-root Container

Blackbox Exporter chạy bằng UID/GID `65534:65534`, thay vì tài khoản root.

Kiểm tra: `docker compose exec blackbox-exporter id`

### 4. Least Privilege

Blackbox Exporter được áp dụng:
- `cap_drop: ALL`
- `no-new-privileges:true`

Kiểm tra cấu hình bảo mật bằng `docker inspect`.

### Kết quả kiểm thử

- Blackbox Exporter hoạt động với tài khoản non-root.
- Docker xác nhận đã loại bỏ capabilities và bật no-new-privileges.
- Prometheus trả về `probe_success = 1`, chứng minh website vẫn có thể được kiểm tra qua HTTP sau khi áp dụng Hardening.