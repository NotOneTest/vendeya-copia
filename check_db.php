<?php
echo "Checking database structure...\n\n";

try {
    // Connect to MySQL without specifying database
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to MySQL\n";
    
    // List all databases
    $stmt = $pdo->query("SHOW DATABASES");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Databases: " . implode(', ', $databases) . "\n\n";
    
    // Check if tenancy database exists and has items table
    if (in_array('tenancy', $databases)) {
        echo "Checking tenancy database...\n";
        $pdo2 = new PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy;charset=utf8", "root", "");
        $stmt2 = $pdo2->query("SHOW TABLES");
        $tables = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables in tenancy: " . implode(', ', $tables) . "\n";
        
        if (in_array('items', $tables)) {
            $stmt3 = $pdo2->query("SELECT COUNT(*) as count FROM items");
            $count = $stmt3->fetch(PDO::FETCH_ASSOC);
            echo "Items count: " . $count['count'] . "\n";
        }
    }
    
    // Check for other databases that might contain miempresa data
    foreach ($databases as $db) {
        if (strpos($db, 'miempresa') !== false || strpos($db, 'tenant') !== false) {
            echo "\nChecking database: $db\n";
            $pdo3 = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$db;charset=utf8", "root", "");
            $stmt4 = $pdo3->query("SHOW TABLES");
            $tables2 = $stmt4->fetchAll(PDO::FETCH_COLUMN);
            echo "Tables: " . implode(', ', $tables2) . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
