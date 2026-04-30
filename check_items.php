<?php
echo "=== Checking items table structure ===\n\n";

try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get table structure
    $stmt = $pdo->query("DESCRIBE items");
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Fields in items table:\n";
    foreach ($fields as $field) {
        echo "- {$field['Field']} ({$field['Type']})\n";
    }
    
    // Get all items
    echo "\n=== All items ===\n";
    $stmt2 = $pdo->query("SELECT * FROM items LIMIT 10");
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($items as $item) {
        echo "\nItem ID: {$item['id']}\n";
        foreach ($item as $key => $value) {
            if (!is_null($value) && $value !== '') {
                echo "  $key: $value\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
