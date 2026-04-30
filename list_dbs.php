<?php
echo "=== Checking available databases ===\n\n";

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW DATABASES");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Available databases:\n";
    foreach ($databases as $db) {
        echo "- $db\n";
    }
    
    echo "\n=== Checking for items tables ===\n";
    
    foreach ($databases as $db) {
        if ($db == 'information_schema' || $db == 'performance_schema' || $db == 'mysql' || $db == 'sys') {
            continue;
        }
        
        try {
            $pdo2 = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$db;charset=utf8", "root", "");
            $stmt2 = $pdo2->query("SHOW TABLES");
            $tables = $stmt2->fetchAll(PDO::FETCH_COLUMN);
            
            if (in_array('items', $tables)) {
                echo "\nFound 'items' table in database: $db\n";
                $stmt3 = $pdo2->query("SELECT COUNT(*) as count FROM items");
                $count = $stmt3->fetch(PDO::FETCH_ASSOC);
                echo "Items count: " . $count['count'] . "\n";
                
                $stmt4 = $pdo2->query("SELECT id, name, internal_id, sale_unit_price, unit_type_id FROM items LIMIT 3");
                $samples = $stmt4->fetchAll(PDO::FETCH_ASSOC);
                echo "Sample items:\n";
                print_r($samples);
            }
        } catch (Exception $e) {
            // Skip databases we can't access
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
