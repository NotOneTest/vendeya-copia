<?php
// Script para arreglar la BD de miempresa
$host = '127.0.0.1';
$port = 3306;
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=tenancy_miempresa", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Conectado a tenancy_miempresa<br>";
    
    // Columnas que faltan
    $columns = [
        'use_login_global' => 'TINYINT(1) DEFAULT 0',
        'tenant_show_ads' => 'TINYINT(1) DEFAULT 0',
        'tenant_image_ads' => 'VARCHAR(255) NULL'
    ];
    
    foreach ($columns as $col => $def) {
        $stmt = $pdo->query("SHOW COLUMNS FROM configurations LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE configurations ADD COLUMN $col $def");
            echo "✓ Columna '$col' agregada<br>";
        } else {
            echo "✓ Columna '$col' ya existe<br>";
        }
    }
    
    echo "<h3 style='color:green'>¡Listo! Ahora ejecuta:</h3>";
    echo "<p>1. Elimina este archivo</p>";
    echo "<p>2. En vendeya-copia: <code>php artisan config:clear</code></p>";
    echo "<p>3. Recarga el dashboard - los productos cargarán vía API</p>";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
