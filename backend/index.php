<?php
declare(strict_types=1);

session_start();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requiredText(string $key, string $label, int $maxLength): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '' || strlen($value) > $maxLength) {
        throw new RuntimeException("$label không được để trống hoặc quá dài.");
    }
    return $value;
}

function positiveInt(string $key, string $label): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 1000000],
    ]);
    if ($value === false) {
        throw new RuntimeException("$label phải là số nguyên từ 1 đến 1.000.000.");
    }
    return $value;
}

function redirectTo(string $section): never
{
    header('Location: /#' . $section, true, 303);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        (string) getenv('DB_USER'),
        (string) getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Lỗi kết nối cơ sở dữ liệu. Kiểm tra Docker logs.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $section = match ($action) {
        'add_supplier' => 'suppliers',
        'add_product' => 'add-product',
        'stock_in', 'stock_out' => 'movements',
        default => 'dashboard',
    };

    try {
        if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Phiên làm việc không hợp lệ. Vui lòng tải lại trang.');
        }

        switch ($action) {
            case 'add_supplier':
                $name = requiredText('name', 'Tên nhà cung cấp', 150);
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $address = trim((string) ($_POST['address'] ?? ''));
                if (strlen($phone) > 20 || strlen($email) > 150) {
                    throw new RuntimeException('Số điện thoại hoặc email quá dài.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Email không hợp lệ.');
                }
                $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone, email, address) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $phone ?: null, $email ?: null, $address ?: null]);
                $message = 'Đã thêm nhà cung cấp.';
                break;

            case 'add_product':
                $sku = requiredText('sku', 'Mã sản phẩm', 50);
                $name = requiredText('name', 'Tên sản phẩm', 150);
                $unit = requiredText('unit', 'Đơn vị tính', 30);
                $price = trim((string) ($_POST['price'] ?? ''));
                if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $price)) {
                    throw new RuntimeException('Đơn giá phải là số không âm, tối đa 2 chữ số thập phân.');
                }
                $supplierId = null;
                if (($_POST['supplier_id'] ?? '') !== '') {
                    $supplierId = positiveInt('supplier_id', 'Nhà cung cấp');
                    $check = $pdo->prepare('SELECT id FROM suppliers WHERE id = ?');
                    $check->execute([$supplierId]);
                    if (!$check->fetchColumn()) {
                        throw new RuntimeException('Nhà cung cấp không tồn tại.');
                    }
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO products (sku, name, unit, price, supplier_id) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$sku, $name, $unit, $price, $supplierId]);
                $message = 'Đã thêm sản phẩm. Muốn tăng tồn kho, hãy tạo phiếu nhập.';
                break;

            case 'stock_in':
            case 'stock_out':
                $productId = positiveInt('product_id', 'Sản phẩm');
                $quantity = positiveInt('quantity', 'Số lượng');
                $note = trim((string) ($_POST['note'] ?? ''));
                if (strlen($note) > 2000) {
                    throw new RuntimeException('Ghi chú quá dài.');
                }
                $type = $action === 'stock_in' ? 'IN' : 'OUT';

                $pdo->beginTransaction();
                // Khoá dòng sản phẩm: các thao tác nhập/xuất qua ứng dụng được tuần tự hoá.
                $lock = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
                $lock->execute([$productId]);
                if (!$lock->fetchColumn()) {
                    throw new RuntimeException('Sản phẩm không tồn tại.');
                }

                $stockQuery = $pdo->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN movement_type = 'IN'
                        THEN CAST(quantity AS SIGNED)
                        ELSE -CAST(quantity AS SIGNED) END), 0)
                     FROM stock_movements WHERE product_id = ?"
                );
                $stockQuery->execute([$productId]);
                $currentStock = (int) $stockQuery->fetchColumn();
                if ($type === 'OUT' && $quantity > $currentStock) {
                    throw new RuntimeException("Tồn kho chỉ còn $currentStock, không thể xuất $quantity.");
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO stock_movements (product_id, movement_type, quantity, note) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$productId, $type, $quantity, $note ?: null]);
                $pdo->commit();
                $message = $type === 'IN' ? 'Nhập kho thành công.' : 'Xuất kho thành công.';
                break;

            default:
                throw new RuntimeException('Thao tác không hợp lệ.');
        }

        $_SESSION['flash'] = ['type' => 'success', 'text' => $message];
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex instanceof PDOException) {
            error_log($ex->getMessage());
            $message = $ex->getCode() === '23000'
                ? 'Mã sản phẩm đã tồn tại hoặc dữ liệu tham chiếu không hợp lệ.'
                : 'Không lưu được dữ liệu. Xem Docker logs để biết chi tiết.';
        } elseif ($ex instanceof RuntimeException) {
            $message = $ex->getMessage();
        } else {
            error_log($ex->getMessage());
            $message = 'Đã xảy ra lỗi không mong muốn.';
        }
        $_SESSION['flash'] = ['type' => 'error', 'text' => $message];
    }
    redirectTo($section);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    $stats = [
        'Sản phẩm' => (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn(),
        'Nhà cung cấp' => (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn(),
        'Tổng hàng tồn' => (int) $pdo->query('SELECT COALESCE(SUM(stock_quantity), 0) FROM inventory_stock')->fetchColumn(),
        'Giao dịch kho' => (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(),
    ];
    $products = $pdo->query(
        'SELECT i.id, i.sku, i.name, i.unit, i.stock_quantity, p.price, s.name AS supplier
         FROM inventory_stock i
         JOIN products p ON p.id = i.id
         LEFT JOIN suppliers s ON s.id = p.supplier_id
         ORDER BY i.id DESC'
    )->fetchAll();
    $suppliers = $pdo->query('SELECT id, name, phone, email FROM suppliers ORDER BY id DESC')->fetchAll();
    $movements = $pdo->query(
        'SELECT m.movement_type, m.quantity, m.note, m.created_at, p.sku, p.name AS product_name
         FROM stock_movements m JOIN products p ON p.id = m.product_id
         ORDER BY m.id DESC LIMIT 15'
    )->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Lỗi đọc dữ liệu kho. Kiểm tra Docker logs.');
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventory Management</title>
<style>
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Arial,sans-serif;background:#f3f6fb;color:#1e293b}
header{background:#152a4a;color:#fff;padding:22px 7%}header h2{margin:0}header p{color:#cbd5e1}
nav{display:flex;gap:18px;flex-wrap:wrap;margin-top:18px}nav a{color:#dbeafe;text-decoration:none;font-weight:bold;font-size:14px}
nav a:hover{text-decoration:underline}main{max-width:1200px;margin:35px auto;padding:0 20px}h1{font-size:26px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin:25px 0 35px}
.card,.panel{background:white;border-radius:12px;padding:24px;box-shadow:0 3px 15px #0000000c}
.card{border-left:5px solid #2563eb}.card h3{color:#64748b;font-size:15px}.card strong{color:#1d4ed8;font-size:32px}
.panel{margin-bottom:22px;overflow-x:auto}.panel h2{margin-top:0;font-size:21px}
table{width:100%;border-collapse:collapse;min-width:620px}th,td{padding:13px;text-align:left}th{background:#eff6ff}tr{border-bottom:1px solid #e2e8f0}tbody tr:hover{background:#f8fafc}
.ok{color:#15803d;font-weight:bold}.low,.out{color:#dc2626;font-weight:bold}.in{color:#15803d;font-weight:bold}
.forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:20px}
label{display:block;font-weight:bold;font-size:13px;margin-top:12px;margin-bottom:5px;color:#475569}
input,select,textarea{width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:10px;font:inherit;background:#fff}
input:focus,select:focus,textarea:focus{outline:2px solid #93c5fd;border-color:#2563eb}
button{border:0;border-radius:8px;padding:12px 17px;color:white;background:#2563eb;font-weight:bold;cursor:pointer;margin-top:15px}
button:hover{background:#1d4ed8}.btn-out{background:#b91c1c}.btn-out:hover{background:#991b1b}
.notice{padding:13px 18px;border-radius:8px;margin:20px 0;font-weight:bold}.success{background:#dcfce7;color:#166534}.error{background:#fee2e2;color:#991b1b}
.muted{color:#64748b}.empty{padding:18px;text-align:center;color:#64748b}
</style>
</head>
<body>
<header>
    <h2>📦 INVENTORY MANAGEMENT SYSTEM</h2>
    <p>Hệ thống quản lý kho hàng</p>
    <nav>
        <a href="#dashboard">Tổng quan</a>
        <a href="#products">Sản phẩm</a>
        <a href="#suppliers">Nhà cung cấp</a>
        <a href="#add-product">Thêm sản phẩm</a>
        <a href="#movements">Nhập / Xuất kho</a>
        <a href="#history">Lịch sử giao dịch</a>
    </nav>
</header>
<main>
    <?php if ($flash): ?>
        <div class="notice <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="alert">
            <?= e($flash['text']) ?>
        </div>
    <?php endif; ?>

    <section id="dashboard">
        <h1>Dashboard tổng quan</h1>
        <p class="muted">Thống kê dữ liệu kho hàng theo thời gian thực khi tải trang.</p>
        <div class="cards">
            <?php foreach ($stats as $label => $value): ?>
                <div class="card"><h3><?= e($label) ?></h3><strong><?= number_format($value) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="products" class="panel">
        <h2>Danh sách sản phẩm trong kho</h2>
        <table>
            <thead><tr><th>Mã SP</th><th>Tên sản phẩm</th><th>Nhà cung cấp</th><th>Đơn giá</th><th>Tồn kho</th></tr></thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?= e($p['sku']) ?></td>
                        <td><?= e($p['name']) ?></td>
                        <td><?= e($p['supplier'] ?? 'Chưa có') ?></td>
                        <td><?= number_format((float) $p['price']) ?> đ</td>
                        <td class="<?= (int) $p['stock_quantity'] <= 10 ? 'low' : 'ok' ?>">
                            <?= e($p['stock_quantity']) ?> <?= e($p['unit']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr><td colspan="5" class="empty">Chưa có sản phẩm.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <div class="forms">
        <section id="suppliers" class="panel">
            <h2>Thêm nhà cung cấp</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add_supplier">
                <label for="supplier-name">Tên nhà cung cấp *</label>
                <input id="supplier-name" name="name" maxlength="150" required>
                <label for="supplier-phone">Số điện thoại</label>
                <input id="supplier-phone" name="phone" maxlength="20">
                <label for="supplier-email">Email</label>
                <input id="supplier-email" name="email" type="email" maxlength="150">
                <label for="supplier-address">Địa chỉ</label>
                <textarea id="supplier-address" name="address" rows="2"></textarea>
                <button type="submit">Thêm nhà cung cấp</button>
            </form>
            <p class="muted">Đã có <?= count($suppliers) ?> nhà cung cấp.</p>
            <?php foreach ($suppliers as $supplier): ?>
                <p>• <?= e($supplier['name']) ?><?= $supplier['phone'] ? ' — ' . e($supplier['phone']) : '' ?></p>
            <?php endforeach; ?>
        </section>

        <section id="add-product" class="panel">
            <h2>Thêm sản phẩm</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add_product">
                <label for="sku">Mã sản phẩm (SKU) *</label>
                <input id="sku" name="sku" maxlength="50" required placeholder="SP005">
                <label for="product-name">Tên sản phẩm *</label>
                <input id="product-name" name="name" maxlength="150" required>
                <label for="unit">Đơn vị tính *</label>
                <input id="unit" name="unit" maxlength="30" value="Cái" required>
                <label for="price">Đơn giá (VNĐ) *</label>
                <input id="price" name="price" type="number" min="0" step="0.01" value="0" required>
                <label for="supplier-id">Nhà cung cấp</label>
                <select id="supplier-id" name="supplier_id">
                    <option value="">Chưa có</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int) $supplier['id'] ?>"><?= e($supplier['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Thêm sản phẩm</button>
            </form>
        </section>
    </div>

    <section id="movements" class="panel">
        <h2>Nhập / Xuất kho</h2>
        <div class="forms">
            <?php foreach (['stock_in' => 'Nhập kho', 'stock_out' => 'Xuất kho'] as $action => $title): ?>
                <form method="post">
                    <h3><?= e($title) ?></h3>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= e($action) ?>">
                    <label for="product-<?= e($action) ?>">Sản phẩm *</label>
                    <select id="product-<?= e($action) ?>" name="product_id" required>
                        <option value="">-- Chọn sản phẩm --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?= (int) $p['id'] ?>">
                                <?= e($p['sku'] . ' - ' . $p['name'] . ' (tồn ' . $p['stock_quantity'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="quantity-<?= e($action) ?>">Số lượng *</label>
                    <input id="quantity-<?= e($action) ?>" name="quantity" type="number" min="1" max="1000000" required>
                    <label for="note-<?= e($action) ?>">Ghi chú</label>
                    <textarea id="note-<?= e($action) ?>" name="note" rows="2"></textarea>
                    <button class="<?= $action === 'stock_out' ? 'btn-out' : '' ?>" type="submit">
                        Xác nhận <?= e($title) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="history" class="panel">
        <h2>15 giao dịch kho gần nhất</h2>
        <table>
            <thead><tr><th>Thời gian</th><th>Sản phẩm</th><th>Loại</th><th>Số lượng</th><th>Ghi chú</th></tr></thead>
            <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr>
                        <td><?= e($m['created_at']) ?></td>
                        <td><?= e($m['sku'] . ' - ' . $m['product_name']) ?></td>
                        <td class="<?= $m['movement_type'] === 'IN' ? 'in' : 'out' ?>">
                            <?= $m['movement_type'] === 'IN' ? 'Nhập' : 'Xuất' ?>
                        </td>
                        <td><?= e($m['quantity']) ?></td>
                        <td><?= e($m['note'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$movements): ?><tr><td colspan="5" class="empty">Chưa có giao dịch.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
