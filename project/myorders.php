<?php
session_start();
include 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- Pagination ---
$per_page = 8;
$page     = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset   = ($page - 1) * $per_page;

// --- Filters ---
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search        = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause
$where  = "WHERE o.user_id = ?";
$params = [$user_id];
$types  = "i";

if (!empty($status_filter)) {
    $where   .= " AND o.status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}
if (!empty($search)) {
    $where   .= " AND (o.id LIKE ? OR p.name LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}

// Total count
$count_sql  = "SELECT COUNT(DISTINCT o.id) AS total FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id LEFT JOIN products p ON oi.product_id = p.id $where";
$stmt       = $conn->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_rows = $stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $per_page);
$stmt->close();

// Fetch orders
$orders_sql = "
    SELECT o.id, o.total_amount, o.status, o.created_at, o.shipping_address,
           COUNT(oi.id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    $where
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT ? OFFSET ?
";
$params[] = $per_page;
$params[] = $offset;
$types   .= "ii";

$stmt = $conn->prepare($orders_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch order items for detail modal
$order_detail = null;
if (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
    $oid  = (int)$_GET['order_id'];
    $stmt = $conn->prepare("
        SELECT o.id, o.total_amount, o.status, o.created_at, o.shipping_address, o.notes
        FROM orders o WHERE o.id = ? AND o.user_id = ?
    ");
    $stmt->bind_param("ii", $oid, $user_id);
    $stmt->execute();
    $order_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($order_detail) {
        $stmt = $conn->prepare("
            SELECT oi.quantity, oi.unit_price, p.name, p.image_url
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order_detail['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// Summary counts
$summary_stmt = $conn->prepare("
    SELECT status, COUNT(*) AS cnt FROM orders WHERE user_id = ? GROUP BY status
");
$summary_stmt->bind_param("i", $user_id);
$summary_stmt->execute();
$summary_rows = $summary_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$summary_stmt->close();

$summary = ['pending'=>0,'processing'=>0,'shipped'=>0,'delivered'=>0,'cancelled'=>0];
foreach ($summary_rows as $row) {
    $key = strtolower($row['status']);
    if (isset($summary[$key])) $summary[$key] = $row['cnt'];
}
$total_orders = array_sum($summary);

$conn->close();

// Helper
function statusBadge($status) {
    $map = [
        'pending'    => ['#fff3cd','#856404'],
        'processing' => ['#cce5ff','#004085'],
        'shipped'    => ['#d1ecf1','#0c5460'],
        'delivered'  => ['#d4edda','#155724'],
        'cancelled'  => ['#f8d7da','#721c24'],
    ];
    $s = strtolower($status);
    [$bg, $color] = $map[$s] ?? ['#e2e3e5','#383d41'];
    return "<span style='background:$bg;color:$color;padding:3px 12px;border-radius:20px;font-size:.78rem;font-weight:600;text-transform:capitalize;'>$status</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - IT Shop.LK</title>
    <style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family:'Poppins',sans-serif;
    background:#f0f2f5;
    min-height:100vh;
    padding:20px;
}

/* Navbar */
.navbar {
    background:#0a9101ff;
    color:white;
    padding:15px 30px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-radius:12px;
    margin-bottom:25px;
    box-shadow:0 4px 15px rgba(10,145,1,.25);
}
.navbar .logo { display:flex; align-items:center; gap:12px; }
.navbar .logo img { height:40px; }
.navbar .logo span { font-size:1.4rem; font-weight:600; }
.navbar .nav-links { display:flex; gap:20px; align-items:center; }
.navbar .nav-links a {
    color:rgba(255,255,255,.85);
    text-decoration:none;
    font-size:.9rem;
    padding:6px 14px;
    border-radius:6px;
    transition:background .2s;
}
.navbar .nav-links a:hover,
.navbar .nav-links a.active { background:rgba(255,255,255,.2); color:#fff; }
.navbar .nav-links .logout { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); }

/* Layout */
.page-wrapper { max-width:1050px; margin:0 auto; }
.page-title { font-size:1.6rem; font-weight:600; color:#333; margin-bottom:4px; }
.page-subtitle { color:#888; font-size:.9rem; margin-bottom:22px; }

/* Summary tiles */
.summary-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
    gap:14px;
    margin-bottom:22px;
}
.summary-tile {
    background:white;
    border-radius:10px;
    padding:16px 18px;
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    border-top:4px solid #0a9101ff;
    cursor:pointer;
    transition:transform .2s, box-shadow .2s;
}
.summary-tile:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.1); }
.summary-tile .num { font-size:1.7rem; font-weight:700; color:#0a9101ff; }
.summary-tile .lbl { font-size:.78rem; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }

/* Card */
.card { background:white; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,.07); overflow:hidden; margin-bottom:20px; }
.card-header { background:linear-gradient(135deg,#0a9101ff,#05c200); padding:18px 25px; color:white; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.card-header h3 { font-size:1.05rem; font-weight:600; }
.card-body { padding:0; }

/* Filter bar */
.filter-bar {
    padding:18px 25px;
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
    border-bottom:1px solid #f0f2f5;
}
.filter-bar input[type="text"] {
    flex:1; min-width:180px;
    padding:9px 14px;
    border:2px solid #e1e5e9;
    border-radius:8px;
    font-size:.875rem;
    font-family:inherit;
    transition:border-color .3s;
}
.filter-bar input:focus { outline:none; border-color:#0098fdff; }
.filter-bar select {
    padding:9px 14px;
    border:2px solid #e1e5e9;
    border-radius:8px;
    font-size:.875rem;
    font-family:inherit;
    background:white;
    cursor:pointer;
}
.filter-bar .btn-filter {
    padding:9px 20px;
    background:#0a9101ff;
    color:white;
    border:none;
    border-radius:8px;
    font-size:.875rem;
    font-weight:600;
    cursor:pointer;
    font-family:inherit;
    transition:all .2s;
}
.filter-bar .btn-filter:hover { background:#07780000+1; box-shadow:0 4px 12px rgba(10,145,1,.3); transform:translateY(-1px); }
.btn-clear { padding:9px 16px; border:2px solid #e1e5e9; border-radius:8px; background:white; font-size:.875rem; font-family:inherit; cursor:pointer; color:#666; transition:all .2s; }
.btn-clear:hover { border-color:#ccc; }

/* Table */
.orders-table { width:100%; border-collapse:collapse; }
.orders-table th {
    background:#f8f9fa;
    padding:13px 18px;
    text-align:left;
    font-size:.8rem;
    font-weight:600;
    color:#555;
    text-transform:uppercase;
    letter-spacing:.4px;
    border-bottom:2px solid #e9ecef;
}
.orders-table td { padding:14px 18px; border-bottom:1px solid #f0f2f5; vertical-align:middle; font-size:.9rem; color:#444; }
.orders-table tr:last-child td { border-bottom:none; }
.orders-table tr:hover td { background:#fafffe; }

.btn-view {
    padding:6px 16px;
    background:transparent;
    border:2px solid #0a9101ff;
    color:#0a9101ff;
    border-radius:6px;
    font-size:.8rem;
    font-weight:600;
    cursor:pointer;
    font-family:inherit;
    transition:all .2s;
    white-space:nowrap;
}
.btn-view:hover { background:#0a9101ff; color:white; }

/* Empty state */
.empty-state { text-align:center; padding:60px 20px; color:#aaa; }
.empty-state .icon { font-size:3.5rem; margin-bottom:14px; }
.empty-state h4 { font-size:1.1rem; color:#666; margin-bottom:8px; }
.empty-state p { font-size:.875rem; }

/* Pagination */
.pagination { display:flex; justify-content:center; gap:6px; padding:20px; flex-wrap:wrap; }
.pagination a, .pagination span {
    padding:7px 14px;
    border-radius:7px;
    font-size:.875rem;
    font-weight:600;
    border:2px solid #e1e5e9;
    color:#555;
    text-decoration:none;
    transition:all .2s;
}
.pagination a:hover { border-color:#0a9101ff; color:#0a9101ff; }
.pagination .current { background:#0a9101ff; border-color:#0a9101ff; color:white; }
.pagination .disabled { opacity:.45; pointer-events:none; }

/* Modal */
.modal-overlay {
    position:fixed; inset:0;
    background:rgba(0,0,0,.5);
    display:flex; align-items:center; justify-content:center;
    z-index:1000; padding:20px;
    opacity:0; pointer-events:none;
    transition:opacity .25s;
}
.modal-overlay.open { opacity:1; pointer-events:all; }
.modal {
    background:white;
    border-radius:14px;
    max-width:600px; width:100%;
    max-height:90vh;
    overflow-y:auto;
    transform:translateY(20px);
    transition:transform .25s;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
}
.modal-overlay.open .modal { transform:translateY(0); }
.modal-header {
    background:linear-gradient(135deg,#0a9101ff,#05c200);
    color:white;
    padding:20px 25px;
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0;
}
.modal-header h3 { font-size:1.1rem; }
.modal-close { background:none; border:none; color:white; font-size:1.5rem; cursor:pointer; line-height:1; }
.modal-body { padding:25px; }

.order-meta { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:22px; }
.meta-item .mlabel { font-size:.75rem; color:#888; text-transform:uppercase; letter-spacing:.4px; }
.meta-item .mvalue { font-size:.93rem; color:#333; font-weight:600; margin-top:3px; }

.items-title { font-size:.85rem; font-weight:700; color:#555; text-transform:uppercase; letter-spacing:.4px; margin-bottom:12px; }
.order-item-row {
    display:flex; align-items:center; gap:14px;
    padding:12px 0;
    border-bottom:1px solid #f0f2f5;
}
.order-item-row:last-child { border-bottom:none; }
.item-img { width:52px; height:52px; border-radius:8px; object-fit:cover; background:#f0f2f5; flex-shrink:0; }
.item-img-placeholder { width:52px; height:52px; border-radius:8px; background:#e8f5e9; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.item-info { flex:1; }
.item-info .iname { font-size:.9rem; color:#333; font-weight:600; }
.item-info .iqty  { font-size:.8rem; color:#888; margin-top:2px; }
.item-price { font-size:.95rem; font-weight:700; color:#0a9101ff; white-space:nowrap; }

.order-total { margin-top:16px; text-align:right; font-size:1rem; font-weight:700; color:#333; padding-top:14px; border-top:2px solid #f0f2f5; }
.order-total span { color:#0a9101ff; font-size:1.15rem; }

/* Responsive */
@media (max-width:750px) {
    .navbar { flex-direction:column; gap:10px; border-radius:10px; }
    .navbar .nav-links { flex-wrap:wrap; justify-content:center; }
    .orders-table th:nth-child(3),
    .orders-table td:nth-child(3),
    .orders-table th:nth-child(4),
    .orders-table td:nth-child(4) { display:none; }
    .order-meta { grid-template-columns:1fr; }
}
@media (max-width:480px) {
    body { padding:10px; }
    .page-title { font-size:1.3rem; }
    .orders-table th, .orders-table td { padding:10px 12px; font-size:.82rem; }
    .filter-bar { padding:14px; }
    input[type="text"],input[type="email"],input[type="password"] { font-size:16px; }
}
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="logo">
        <img src="assets/revised-04.png" alt="IT Shop.LK">
        <span>IT Shop.LK</span>
    </div>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="myorders.php" class="active">My Orders</a>
        <a href="profile.php">Profile</a>
        <a href="logout.php" class="logout">Logout</a>
    </div>
</nav>

<div class="page-wrapper">
    <div class="page-title">My Orders</div>
    <div class="page-subtitle">Track and manage all your purchases</div>

    <!-- Summary tiles -->
    <div class="summary-grid">
        <a href="myorders.php" style="text-decoration:none;">
            <div class="summary-tile">
                <div class="num"><?php echo $total_orders; ?></div>
                <div class="lbl">All Orders</div>
            </div>
        </a>
        <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $s): ?>
        <a href="myorders.php?status=<?php echo $s; ?>" style="text-decoration:none;">
            <div class="summary-tile">
                <div class="num"><?php echo $summary[$s]; ?></div>
                <div class="lbl"><?php echo ucfirst($s); ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Orders table card -->
    <div class="card">
        <div class="card-header">
            <h3>Order History</h3>
            <span style="font-size:.85rem;opacity:.9;"><?php echo $total_rows; ?> order<?php echo $total_rows!=1?'s':''; ?> found</span>
        </div>
        <div class="card-body">
            <!-- Filter bar -->
            <form method="GET" class="filter-bar">
                <input type="text" name="search" placeholder="Search by order # or product…" value="<?php echo htmlspecialchars($search); ?>">
                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_filter==$s?'selected':''; ?>><?php echo ucfirst($s); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
                <?php if ($search || $status_filter): ?>
                <a href="myorders.php" class="btn-clear" style="text-decoration:none;display:inline-flex;align-items:center;">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="icon">📦</div>
                <h4>No orders found</h4>
                <p><?php echo ($search || $status_filter) ? 'Try adjusting your filters.' : 'You haven\'t placed any orders yet.'; ?></p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Shipping To</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><strong>#<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></strong></td>
                            <td><?php echo date('d M Y', strtotime($order['created_at'])); ?></td>
                            <td><?php echo $order['item_count']; ?> item<?php echo $order['item_count']!=1?'s':''; ?></td>
                            <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($order['shipping_address']??''); ?>"><?php echo htmlspecialchars($order['shipping_address']??'—'); ?></td>
                            <td><strong>LKR <?php echo number_format($order['total_amount'], 2); ?></strong></td>
                            <td><?php echo statusBadge($order['status']); ?></td>
                            <td>
                                <a href="?order_id=<?php echo $order['id']; ?>&page=<?php echo $page; ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>" class="btn-view">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $base = "myorders.php?status=" . urlencode($status_filter) . "&search=" . urlencode($search) . "&page=";
                ?>
                <a href="<?php echo $base.1; ?>" class="<?php echo $page==1?'disabled':''; ?>">«</a>
                <a href="<?php echo $base.max(1,$page-1); ?>" class="<?php echo $page==1?'disabled':''; ?>">‹</a>
                <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
                    <?php if ($i==$page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo $base.$i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <a href="<?php echo $base.min($total_pages,$page+1); ?>" class="<?php echo $page==$total_pages?'disabled':''; ?>">›</a>
                <a href="<?php echo $base.$total_pages; ?>" class="<?php echo $page==$total_pages?'disabled':''; ?>">»</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Order Detail Modal -->
<div class="modal-overlay <?php echo $order_detail ? 'open' : ''; ?>" id="orderModal">
    <div class="modal">
        <?php if ($order_detail): ?>
        <div class="modal-header">
            <h3>Order #<?php echo str_pad($order_detail['id'],5,'0',STR_PAD_LEFT); ?></h3>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="order-meta">
                <div class="meta-item">
                    <div class="mlabel">Date Placed</div>
                    <div class="mvalue"><?php echo date('d M Y, h:i A', strtotime($order_detail['created_at'])); ?></div>
                </div>
                <div class="meta-item">
                    <div class="mlabel">Status</div>
                    <div class="mvalue"><?php echo statusBadge($order_detail['status']); ?></div>
                </div>
                <div class="meta-item">
                    <div class="mlabel">Shipping Address</div>
                    <div class="mvalue"><?php echo htmlspecialchars($order_detail['shipping_address']??'—'); ?></div>
                </div>
                <?php if (!empty($order_detail['notes'])): ?>
                <div class="meta-item">
                    <div class="mlabel">Notes</div>
                    <div class="mvalue"><?php echo htmlspecialchars($order_detail['notes']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="items-title">Items Ordered</div>
            <?php if (!empty($order_detail['items'])): ?>
                <?php foreach ($order_detail['items'] as $item): ?>
                <div class="order-item-row">
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="item-img">
                    <?php else: ?>
                        <div class="item-img-placeholder">🖥️</div>
                    <?php endif; ?>
                    <div class="item-info">
                        <div class="iname"><?php echo htmlspecialchars($item['name']); ?></div>
                        <div class="iqty">Qty: <?php echo $item['quantity']; ?> × LKR <?php echo number_format($item['unit_price'],2); ?></div>
                    </div>
                    <div class="item-price">LKR <?php echo number_format($item['quantity'] * $item['unit_price'],2); ?></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color:#aaa;font-size:.875rem;">No items found for this order.</p>
            <?php endif; ?>

            <div class="order-total">
                Total Amount: <span>LKR <?php echo number_format($order_detail['total_amount'],2); ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function closeModal() {
    document.getElementById('orderModal').classList.remove('open');
    // Remove order_id from URL without reload
    const url = new URL(window.location);
    url.searchParams.delete('order_id');
    history.replaceState({}, '', url);
}
// Close on overlay click
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>