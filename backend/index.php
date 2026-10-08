<?php
declare(strict_types=1);

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

try {
    $pdo = new PDO(
        "mysql:host=" . getenv('DB_HOST') .
        ";dbname=" . getenv('DB_NAME') . ";charset=utf8mb4",
        getenv('DB_USER'),
        getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );

    $stats = [
        'Sản phẩm' => $pdo->query(
            "SELECT COUNT(*) FROM products"
        )->fetchColumn(),
        'Nhà cung cấp' => $pdo->query(
            "SELECT COUNT(*) FROM suppliers"
        )->fetchColumn(),
        'Tổng hàng tồn' => $pdo->query(
            "SELECT COALESCE(SUM(stock_quantity),0)
             FROM inventory_stock"
        )->fetchColumn(),
        'Giao dịch kho' => $pdo->query(
            "SELECT COUNT(*) FROM stock_movements"
        )->fetchColumn()
    ];

    $products = $pdo->query("
        SELECT i.sku, i.name, i.unit, i.stock_quantity,
               p.price, s.name AS supplier
        FROM inventory_stock i
        JOIN products p ON p.id = i.id
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        ORDER BY i.id DESC
    ")->fetchAll();

} catch (Throwable $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit("Lỗi kết nối cơ sở dữ liệu. Kiểm tra Docker logs.");
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Management</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f6fb;
    color: #1e293b;
}
header {
    background: #152a4a;
    color: white;
    padding: 22px 7%;
}
header h2 { margin: 0; }
header p { color: #cbd5e1; }
main { max-width: 1200px; margin: 35px auto; padding: 0 20px; }
h1 { font-size: 26px; }
.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 25px 0 35px;
}
.card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 3px 15px #0000000c;
    border-left: 5px solid #2563eb;
}
.card h3 { color: #64748b; font-size: 15px; }
.card strong { color: #1d4ed8; font-size: 32px; }
.panel {
    background: white;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 3px 15px #0000000c;
    overflow-x: auto;
}
table { width: 100%; border-collapse: collapse; }
th, td { padding: 14px; text-align: left; }
th { background: #eff6ff; }
tr { border-bottom: 1px solid #e2e8f0; }
tr:hover { background: #f8fafc; }
.ok { color: #15803d; font-weight: bold; }
.low { color: #dc2626; font-weight: bold; }
</style>
</head>
<body>
<header>
    <h2>📦 INVENTORY MANAGEMENT SYSTEM</h2>
    <p>Hệ thống quản lý kho hàng</p>
</header>

<main>
    <h1>Dashboard tổng quan</h1>
    <p>Thống kê dữ liệu kho hàng theo thời gian thực khi tải trang</p>

    <div class="cards">
        <?php foreach ($stats as $label => $value): ?>
        <div class="card">
            <h3><?= e($label) ?></h3>
            <strong><?= number_format((float)$value) ?></strong>
        </div>
        <?php endforeach; ?>
    </div>

    <section class="panel">
        <h2>Danh sách sản phẩm trong kho</h2>
        <table>
            <thead>
                <tr>
                    <th>Mã SP</th>
                    <th>Tên sản phẩm</th>
                    <th>Nhà cung cấp</th>
                    <th>Đơn giá</th>
                    <th>Tồn kho</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= e($p['sku']) ?></td>
                    <td><?= e($p['name']) ?></td>
                    <td><?= e($p['supplier'] ?? 'Chưa có') ?></td>
                    <td><?= number_format((float)$p['price']) ?> đ</td>
                    <td class="<?= $p['stock_quantity'] <= 10 ? 'low' : 'ok' ?>">
                        <?= e($p['stock_quantity']) ?>
                        <?= e($p['unit']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>