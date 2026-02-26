<?php
// Start session for user management
session_start();

// Database connection
include 'db.php';

// Redirect to login if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=quotation.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_currency = 'LKR';
$cart_items = [];
$subtotal = 0;
$shipping_cost = 500;

// Fetch user details
$user_details = [];
try {
    $stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_details = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $user_details = ['name' => 'Valued Customer', 'email' => '', 'phone' => ''];
}

// Fetch cart items
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.product_id,
            c.quantity,
            p.name,
            p.brand,
            p.price as unit_price,
            p.category
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    foreach ($cart_items as &$item) {
        $item_total = $item['unit_price'] * $item['quantity'];
        $item['line_total'] = $item_total;
        $subtotal += $item_total;
    }
    
} catch(PDOException $e) {
    $error_message = "Error fetching cart items: " . $e->getMessage();
    $cart_items = [];
}

// Calculate total (subtotal + shipping if there are items)
$total = $subtotal + ($subtotal > 0 ? $shipping_cost : 0);
$quotation_number = 'QT-' . date('Y') . '-' . str_pad($user_id, 5, '0', STR_PAD_LEFT) . '-' . time();
$quotation_date = date('F d, Y');
$valid_until = date('F d, Y', strtotime('+30 days'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation - IT Shop.LK</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #12b800ff;
            --secondary-color: #0d8300ff;
            --text-dark: #1a202c;
            --text-light: #4a5568;
            --bg-light: #f7fafc;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            background: var(--bg-light);
            margin: 0;
            padding: 0;
        }
        
        /* Hide elements when printing */
        @media print {
            .no-print, .navbar, .action-buttons, .footer {
                display: none !important;
            }
            
            body {
                background: white;
            }
            
            .quotation-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 20px !important;
                max-width: 100% !important;
            }
            
            .page-break {
                page-break-after: always;
            }
            
            @page {
                margin: 1cm;
                size: A4;
            }
            
            .items-table {
                font-size: 9pt;
            }
            
            /* Avoid breaking inside important sections */
            .customer-section,
            .terms-section,
            .notes-section {
                page-break-inside: avoid;
            }
            
            .items-table tr {
                page-break-inside: avoid;
            }
        }
        
        .quotation-wrapper {
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .quotation-container {
            background: white;
            max-width: 900px;
            margin: 0 auto;
            padding: 3rem;
            box-shadow: 0 0 30px rgba(0,0,0,0.1);
            border-radius: 10px;
        }
        
        /* Header Section */
        .quotation-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 3px solid var(--primary-color);
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-logo {
            height: 60px;
            margin-bottom: 1rem;
            max-width: 100%;
        }
        
        .company-name {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .company-details {
            font-size: 0.9rem;
            color: var(--text-light);
            line-height: 1.6;
        }
        
        .quotation-info {
            text-align: right;
        }
        
        .quotation-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }
        
        .quotation-number {
            font-size: 1.1rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
        }
        
        .quotation-date {
            font-size: 0.95rem;
            color: var(--text-light);
        }
        
        /* Customer Section */
        .customer-section {
            margin-bottom: 3rem;
            padding: 1.5rem;
            background: var(--bg-light);
            border-radius: 8px;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 1rem;
        }
        
        .customer-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .detail-row {
            display: flex;
            align-items: start;
        }
        
        .detail-label {
            font-weight: 600;
            color: var(--text-light);
            min-width: 100px;
        }
        
        .detail-value {
            color: var(--text-dark);
            word-break: break-word;
        }
        
        /* Items Table Wrapper for Mobile Scroll */
        .items-table-wrapper {
            overflow-x: auto;
            margin-bottom: 2rem;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .items-table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .items-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
        }
        
        .items-table th:last-child,
        .items-table td:last-child {
            text-align: right;
        }
        
        .items-table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s ease;
        }
        
        .items-table tbody tr:hover {
            background: #f7fafc;
        }
        
        .items-table td {
            padding: 1rem;
            font-size: 0.95rem;
        }
        
        .product-name {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.25rem;
        }
        
        .product-brand {
            color: var(--text-light);
            font-size: 0.85rem;
            line-height: 1.4;
        }
        
        /* Totals Section */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 3rem;
        }
        
        .totals-table {
            min-width: 350px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            font-size: 0.95rem;
        }
        
        .total-row.subtotal {
            border-bottom: 1px solid #e2e8f0;
        }
        
        .total-row.grand-total {
            border-top: 2px solid var(--primary-color);
            padding-top: 1rem;
            margin-top: 0.5rem;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .total-label {
            font-weight: 500;
            color: var(--text-light);
        }
        
        .total-value {
            font-weight: 600;
            color: var(--text-dark);
        }
        
        .grand-total .total-label,
        .grand-total .total-value {
            color: var(--primary-color);
        }
        
        /* Terms and Notes */
        .terms-section {
            margin-bottom: 3rem;
            padding: 1.5rem;
            background: #fff8e1;
            border-left: 4px solid #ffc107;
            border-radius: 5px;
        }
        
        .terms-section h5 {
            color: #f57c00;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        .terms-section ul {
            margin-bottom: 0;
            padding-left: 1.5rem;
        }
        
        .terms-section li {
            margin-bottom: 0.5rem;
            color: var(--text-light);
        }
        
        .notes-section {
            padding: 1.5rem;
            background: #e3f2fd;
            border-left: 4px solid var(--secondary-color);
            border-radius: 5px;
            margin-bottom: 2rem;
        }
        
        .notes-section h5 {
            color: var(--secondary-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }
        
        /* Footer */
        .quotation-footer {
            text-align: center;
            padding-top: 2rem;
            border-top: 2px solid #e2e8f0;
            color: var(--text-light);
            font-size: 0.9rem;
        }
        
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin: 3rem 0 2rem;
        }
        
        .signature-box {
            text-align: center;
            min-width: 200px;
        }
        
        .signature-line {
            border-top: 2px solid var(--text-dark);
            margin-top: 3rem;
            padding-top: 0.5rem;
            font-weight: 600;
        }
        
        /* Action Buttons */
        .action-buttons {
            text-align: center;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            max-width: 900px;
        }
        
        .btn-action {
            margin: 0.5rem;
            padding: 0.75rem 2rem;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-print {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-print:hover {
            background: #0a6400;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(18, 184, 0, 0.3);
        }
        
        .btn-download {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-download:hover {
            background: #0066cc;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 140, 255, 0.3);
        }
        
        .btn-back {
            background: white;
            color: var(--text-dark);
            border: 2px solid var(--text-light);
        }
        
        .btn-back:hover {
            background: var(--bg-light);
            border-color: var(--text-dark);
            text-decoration: none;
        }
        
        /* Empty State */
        .empty-quotation {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-icon {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 2rem;
        }
        
        /* ============================================
           RESPONSIVE DESIGN - TABLET
           ============================================ */
        @media (max-width: 991px) {
            .quotation-wrapper {
                padding: 1rem 0;
            }
            
            .quotation-container {
                padding: 2rem 1.5rem;
                margin: 0 1rem;
            }
            
            .quotation-header {
                gap: 1.5rem;
            }
            
            .company-name {
                font-size: 1.5rem;
            }
            
            .quotation-title {
                font-size: 1.6rem;
            }
            
            .customer-details {
                gap: 0.75rem;
            }
            
            .action-buttons {
                padding: 1.5rem 1rem;
                margin: 1rem;
            }
            
            .btn-action {
                padding: 0.65rem 1.5rem;
                font-size: 0.95rem;
            }
        }
        
        /* ============================================
           RESPONSIVE DESIGN - MOBILE
           ============================================ */
        @media (max-width: 767px) {
            .quotation-wrapper {
                padding: 0.5rem 0;
            }
            
            .quotation-container {
                padding: 1.5rem 1rem;
                margin: 0 0.5rem;
                border-radius: 8px;
            }
            
            /* Header adjustments */
            .quotation-header {
                flex-direction: column;
                margin-bottom: 2rem;
                padding-bottom: 1.5rem;
            }
            
            .company-logo {
                height: 50px;
            }
            
            .company-name {
                font-size: 1.3rem;
            }
            
            .company-details {
                font-size: 0.85rem;
            }
            
            .quotation-info {
                text-align: left;
                margin-top: 1.5rem;
            }
            
            .quotation-title {
                font-size: 1.4rem;
            }
            
            .quotation-number,
            .quotation-date {
                font-size: 0.9rem;
            }
            
            /* Customer section */
            .customer-section {
                padding: 1rem;
                margin-bottom: 2rem;
            }
            
            .section-title {
                font-size: 1rem;
            }
            
            .customer-details {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .detail-label {
                min-width: auto;
                font-size: 0.85rem;
            }
            
            .detail-value {
                font-size: 0.9rem;
            }
            
            /* Items table - Scrollable */
            .items-table {
                min-width: 600px;
                font-size: 0.8rem;
            }
            
            .items-table th,
            .items-table td {
                padding: 0.6rem 0.4rem;
            }
            
            .product-name {
                font-size: 0.85rem;
            }
            
            .product-brand {
                font-size: 0.75rem;
            }
            
            /* Totals section */
            .totals-section {
                margin-bottom: 2rem;
            }
            
            .totals-table {
                min-width: 100%;
            }
            
            .total-row {
                font-size: 0.85rem;
                padding: 0.6rem 0;
            }
            
            .total-row.grand-total {
                font-size: 1.1rem;
            }
            
            /* Terms and notes */
            .terms-section,
            .notes-section {
                padding: 1rem;
                margin-bottom: 1.5rem;
            }
            
            .terms-section h5,
            .notes-section h5 {
                font-size: 0.95rem;
            }
            
            .terms-section li,
            .notes-section p {
                font-size: 0.85rem;
            }
            
            /* Signature section */
            .signature-section {
                flex-direction: column;
                gap: 2rem;
                margin: 2rem 0 1.5rem;
            }
            
            .signature-box {
                min-width: 100%;
            }
            
            .signature-line {
                margin-top: 2rem;
                font-size: 0.9rem;
            }
            
            /* Footer */
            .quotation-footer {
                font-size: 0.8rem;
                padding-top: 1.5rem;
            }
            
            /* Action buttons - Stack vertically */
            .action-buttons {
                margin: 0.5rem;
                padding: 1rem;
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .btn-action {
                width: 100%;
                margin: 0;
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            /* Empty state */
            .empty-quotation {
                padding: 3rem 1rem;
            }
            
            .empty-icon {
                font-size: 3rem;
            }
            
            .empty-quotation h3 {
                font-size: 1.3rem;
            }
        }
        
        /* ============================================
           RESPONSIVE DESIGN - EXTRA SMALL
           ============================================ */
        @media (max-width: 480px) {
            .quotation-container {
                padding: 1rem 0.75rem;
                margin: 0 0.25rem;
            }
            
            .company-name {
                font-size: 1.2rem;
            }
            
            .quotation-title {
                font-size: 1.2rem;
            }
            
            .items-table {
                font-size: 0.75rem;
                min-width: 550px;
            }
            
            .items-table th,
            .items-table td {
                padding: 0.5rem 0.3rem;
            }
            
            .total-row.grand-total {
                font-size: 1rem;
            }
            
            .btn-action {
                font-size: 0.85rem;
                padding: 0.7rem 0.75rem;
            }
        }
        
        /* Landscape orientation fixes */
        @media (max-width: 767px) and (orientation: landscape) {
            .quotation-header {
                flex-direction: row;
                align-items: center;
            }
            
            .quotation-info {
                text-align: right;
                margin-top: 0;
            }
            
            .customer-details {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="quotation-wrapper">
        <!-- Action Buttons -->
        <div class="action-buttons no-print">
            <button onclick="downloadPDF()" class="btn btn-action btn-download">
                <i class="fas fa-file-pdf me-2"></i>Download PDF
            </button>
            <a href="cart.php" class="btn btn-action btn-back">
                <i class="fas fa-arrow-left me-2"></i>Back to Cart
            </a>
        </div>

        <?php if (empty($cart_items)): ?>
        <!-- Empty State -->
        <div class="quotation-container">
            <div class="empty-quotation">
                <i class="fas fa-file-invoice empty-icon"></i>
                <h3>No Items in Cart</h3>
                <p class="text-muted mb-4">Add items to your cart to generate a quotation</p>
                <a href="products.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-shopping-bag me-2"></i>Browse Products
                </a>
            </div>
        </div>
        <?php else: ?>
        
        <!-- Quotation Document -->
        <div class="quotation-container">
            <!-- Header -->
            <div class="quotation-header">
                <div class="company-info">
                    <img src="assets/revised-04.png" alt="STC Logo" class="company-logo">
                    <!--<div class="company-name">IT Shop.LK</div>-->
                    <div class="company-details">
                        <i class="fas fa-map-marker-alt me-2"></i>admin@itshop.lk<br>
                        <i class="fas fa-phone me-2"></i>+94 077 900 5652<br>
                        <i class="fas fa-envelope me-2"></i>info@itshop.lk<br>
                        <i class="fas fa-globe me-2"></i>www.itshop.lk
                    </div>
                </div>
                
                <div class="quotation-info">
                    <div class="quotation-title">QUOTATION</div>
                    <div class="quotation-number">
                        <strong>Quote #:</strong> <?php echo $quotation_number; ?>
                    </div>
                    <div class="quotation-date">
                        <strong>Date:</strong> <?php echo $quotation_date; ?>
                    </div>
                    <div class="quotation-date">
                        <strong>Valid Until:</strong> <?php echo $valid_until; ?>
                    </div>
                </div>
            </div>
            
            <!-- Customer Details -->
            <div class="customer-section">
                <h5 class="section-title">
                    <i class="fas fa-user me-2"></i>Customer Information
                </h5>
                <div class="customer-details">
                    <div class="detail-row">
                        <span class="detail-label">Name:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user_details['name']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user_details['email']); ?></span>
                    </div>
                    <?php if (!empty($user_details['phone'])): ?>
                    <div class="detail-row">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($user_details['phone']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="detail-row">
                        <span class="detail-label">Customer ID:</span>
                        <span class="detail-value">#<?php echo str_pad($user_id, 6, '0', STR_PAD_LEFT); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Items Table (Wrapped for mobile scroll) -->
            <div class="items-table-wrapper">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 5%">#</th>
                            <th style="width: 45%">Product Description</th>
                            <th style="width: 15%">Unit Price</th>
                            <th style="width: 15%">Quantity</th>
                            <th style="width: 20%">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart_items as $index => $item): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td>
                                <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="product-brand">Brand: <?php echo htmlspecialchars($item['brand']); ?></div>
                                <div class="product-brand">Category: <?php echo htmlspecialchars($item['category']); ?></div>
                            </td>
                            <td><?php echo $user_currency . ' ' . number_format($item['unit_price'], 2); ?></td>
                            <td><?php echo $item['quantity']; ?></td>
                            <td><?php echo $user_currency . ' ' . number_format($item['line_total'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Totals -->
            <div class="totals-section">
                <div class="totals-table">
                    <div class="total-row subtotal">
                        <span class="total-label">Subtotal:</span>
                        <span class="total-value"><?php echo $user_currency . ' ' . number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="total-row">
                        <span class="total-label">Shipping:</span>
                        <span class="total-value"><?php echo $user_currency . ' ' . number_format($shipping_cost, 2); ?></span>
                    </div>
                    <div class="total-row grand-total">
                        <span class="total-label">Grand Total:</span>
                        <span class="total-value"><?php echo $user_currency . ' ' . number_format($total, 2); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="quotation-footer">
                <p>
                    <strong>IT Shop.LK</strong> | Your Trusted Technology Partner<br>
                    This is a computer-generated quotation and does not require a physical signature for validity.
                </p>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    
    <!-- jsPDF Library for PDF generation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    
    <script>
        // Download as PDF
        function downloadPDF() {
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating PDF...';
            button.disabled = true;
            
            // Use html2canvas to capture the quotation
            html2canvas(document.querySelector('.quotation-container'), {
                scale: 2,
                useCORS: true,
                logging: false
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
                
                const imgWidth = 210; // A4 width in mm
                const pageHeight = 297; // A4 height in mm
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                let heightLeft = imgHeight;
                let position = 0;
                
                pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                
                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                
                pdf.save('quotation-<?php echo $quotation_number; ?>.pdf');
                
                button.innerHTML = originalText;
                button.disabled = false;
                
                showNotification('PDF downloaded successfully!', 'success');
            }).catch(error => {
                console.error('Error generating PDF:', error);
                button.innerHTML = originalText;
                button.disabled = false;
                showNotification('Error generating PDF. Please try again.', 'error');
            });
        }
        
        // Send Email
        function sendEmail() {
            const button = event.target;
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Sending...';
            button.disabled = true;
            
            // Create email content
            const subject = 'Quotation Request - <?php echo $quotation_number; ?>';
            const body = `Dear Team,

I would like to request a quotation for the items in my cart.

Quotation Number: <?php echo $quotation_number; ?>
Date: <?php echo $quotation_date; ?>
Total Amount: <?php echo $user_currency . ' ' . number_format($total, 2); ?>

Please send me the detailed quotation at your earliest convenience.

Thank you.

Best regards,
<?php echo htmlspecialchars($user_details['name']); ?>`;
            
            const mailtoLink = `mailto:info@itshop.lk?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
            
            window.location.href = mailtoLink;
            
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 2000);
        }
        
        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                min-width: 300px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            `;
            notification.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
        
        // Auto-print option (commented out by default)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>
</html>