<?php
session_name('MOCTRA_SESSION');
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (mb_strlen($q) < 2) {
    echo json_encode(['keywords' => [], 'products' => []]);
    exit;
}

try {
    $searchTerm = "%$q%";
    
    // 1. Lấy gợi ý từ khóa (từ danh mục hoặc tên sản phẩm ngắn)
    $stmtKey = $conn->prepare("SELECT name FROM categories WHERE name LIKE ? LIMIT 3");
    $stmtKey->bind_param('s', $searchTerm);
    $stmtKey->execute();
    $resKey = $stmtKey->get_result();
    $keywords = [];
    while($row = $resKey->fetch_assoc()) {
        $keywords[] = $row['name'];
    }

    // 2. Lấy tổng số sản phẩm
    $sqlCount = "SELECT COUNT(id) as total FROM products WHERE name LIKE ? OR description LIKE ?";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->bind_param('ss', $searchTerm, $searchTerm);
    $stmtCount->execute();
    $total_products = $stmtCount->get_result()->fetch_assoc()['total'];

    // 3. Lấy danh sách sản phẩm
    $sqlProd = "SELECT p.id, p.name, p.price, p.price_old, p.image, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.name LIKE ? OR p.description LIKE ? 
                ORDER BY 
                    CASE 
                        WHEN p.name LIKE ? THEN 1 
                        WHEN p.name LIKE ? THEN 2 
                        ELSE 3 
                    END, p.created_at DESC 
                LIMIT 6";
            
    $stmtProd = $conn->prepare($sqlProd);
    $searchTermStart = "$q%";
    $stmtProd->bind_param('ssss', $searchTerm, $searchTerm, $searchTermStart, $searchTerm);
    $stmtProd->execute();
    $resProd = $stmtProd->get_result();

    $products = [];
    while ($row = $resProd->fetch_assoc()) {
        $products[] = [
            'id' => $row['id'],
            'name' => htmlspecialchars($row['name']),
            'category' => htmlspecialchars($row['category_name']),
            'price' => number_format($row['price'], 0, ',', '.') . '₫',
            'price_old' => $row['price_old'] ? number_format($row['price_old'], 0, ',', '.') . '₫' : null,
            'image' => $row['image'] ? 'images/' . $row['image'] : 'images/traden.png',
            'url' => 'product_detail.php?id=' . $row['id']
        ];
    }

    echo json_encode([
        'keywords' => $keywords,
        'products' => $products,
        'total_products' => $total_products
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
