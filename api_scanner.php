<?php
// api_scanner.php - Backend API for CO2 Scanner
header('Content-Type: application/json');
require_once 'config.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

// Get the barcode from POST parameters or raw JSON input
$barcode = $_POST['barcode'] ?? null;
if (!$barcode) {
    $raw_input = file_get_contents('php://input');
    $json_input = json_decode($raw_input, true);
    $barcode = $json_input['barcode'] ?? null;
}

if (!$barcode) {
    $response['message'] = 'No barcode provided.';
    echo json_encode($response);
    exit;
}

try {
    // Look up the product in the database
    $stmt = $conn->prepare("SELECT * FROM co2_products WHERE barcode = ?");
    $stmt->execute([$barcode]);
    $product = $stmt->fetch();

    // Backend fallback: if the scanned code is not registered, force Bisleri (8901152010118)
    if (!$product) {
        $barcode = '8901152010118';
        $stmt = $conn->prepare("SELECT * FROM co2_products WHERE barcode = ?");
        $stmt->execute([$barcode]);
        $product = $stmt->fetch();
    }

    if (!$product) {
        $response['message'] = 'Product not registered in our Eco-Database and default Bisleri fallback failed.';
        echo json_encode($response);
        exit;
    }

    $is_logged_in = is_logged_in();
    $userData = null;
    $unlocked_badges = [];

    if ($is_logged_in) {
        $user = get_logged_in_user($conn);
        $user_id = $user['id'];
        
        // Log the scan in the database
        $points_earned = $product['points_reward'];
        $stmt = $conn->prepare("INSERT INTO co2_user_scans (user_id, product_id, points_earned) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $product['id'], $points_earned]);
        
        // Update user's eco points
        $stmt = $conn->prepare("UPDATE users SET eco_points = eco_points + ? WHERE id = ?");
        $stmt->execute([$points_earned, $user_id]);
        
        // Credit user's wallet balance
        $wallet_credit = 5.00; // ₹5.00 reward
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
        $stmt->execute([$wallet_credit, $user_id]);
        
        // Log the wallet transaction
        $desc = "Eco Scan: Scanned " . $product['name'] . " (" . $product['weight'] . ")";
        $stmt = $conn->prepare("INSERT INTO reward_transactions (user_id, amount, transaction_type, description) VALUES (?, ?, 'credit', ?)");
        $stmt->execute([$user_id, $wallet_credit, $desc]);
        
        // Fetch updated user information
        $user = get_logged_in_user($conn);
        
        // Get user scan stats to determine achievements/badges
        $stmt = $conn->prepare("SELECT COUNT(*) as total_scans, SUM(p.co2_impact) as total_co2_impact 
                                FROM co2_user_scans s 
                                JOIN co2_products p ON s.product_id = p.id 
                                WHERE s.user_id = ?");
        $stmt->execute([$user_id]);
        $stats = $stmt->fetch();
        
        $total_scans = (int)$stats['total_scans'];
        $total_co2_impact = (float)($stats['total_co2_impact'] ?? 0.0);
        
        // Gamified badge evaluation logic
        // 1. Eco Cadet: Checked on first scan
        if ($total_scans === 1) {
            $unlocked_badges[] = [
                'title' => 'Eco Cadet',
                'description' => 'Logged your very first carbon footprint scan!',
                'icon' => '🚀'
            ];
        }
        
        // 2. Bisleri Recycler: If they scanned a Bisleri product
        if (strpos(strtolower($product['name']), 'bisleri') !== false) {
            // Check if this is their first Bisleri scan to make it an achievement
            $stmt = $conn->prepare("SELECT COUNT(*) FROM co2_user_scans s 
                                    JOIN co2_products p ON s.product_id = p.id 
                                    WHERE s.user_id = ? AND p.name LIKE '%Bisleri%'");
            $stmt->execute([$user_id]);
            $bisleri_count = (int)$stmt->fetchColumn();
            if ($bisleri_count === 1) {
                $unlocked_badges[] = [
                    'title' => 'Bisleri Recycler',
                    'description' => 'Scanned a Bisleri water bottle! Make sure to recycle.',
                    'icon' => '💧'
                ];
            }
        }
        
        // 3. Carbon Crusader: Checked on 5 scans
        if ($total_scans === 5) {
            $unlocked_badges[] = [
                'title' => 'Carbon Crusader',
                'description' => 'Successfully logged 5 product scans!',
                'icon' => '🛡️'
            ];
        }

        // Calculate level based on points
        $level = floor($user['eco_points'] / 50) + 1;
        
        $userData = [
            'logged_in' => true,
            'eco_points' => (int)$user['eco_points'],
            'wallet_balance' => (float)$user['wallet_balance'],
            'total_scans' => $total_scans,
            'total_co2_impact' => $total_co2_impact,
            'level' => $level,
            'points_to_next_level' => 50 - ($user['eco_points'] % 50)
        ];
    } else {
        $userData = [
            'logged_in' => false,
            'message' => 'Sign in to save this scan history and earn points!'
        ];
    }

    $response['success'] = true;
    $response['data'] = [
        'product' => $product,
        'user' => $userData,
        'unlocked_badges' => $unlocked_badges
    ];
    echo json_encode($response);
    
} catch (\PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    echo json_encode($response);
}
?>
