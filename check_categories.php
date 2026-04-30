<?php
echo "Checking categories table...\n\n";

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'categories'");
    $table = $stmt->fetch();
    
    if ($table) {
        echo "Categories table exists\n";
        $stmt2 = $pdo->query("SELECT * FROM categories LIMIT 5");
        $categories = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        echo "Categories:\n";
        print_r($categories);
    } else {
        echo "Categories table does not exist\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
