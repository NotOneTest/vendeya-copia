<?php

class TestSyntax {
    private function getMiEmpresaFallbackProducts(): array
    {
        $products = array();
        
        $products[] = ['id' => 1, 'name' => 'Gasolina', 'code' => 'P001', 'price' => 12.50, 'category' => 'MiEmpresa', 'image' => ''];
        $products[] = ['id' => 2, 'name' => 'Gaseosa', 'code' => 'P002', 'price' => 3.00, 'category' => 'MiEmpresa', 'image' => ''];
        $products[] = ['id' => 3, 'name' => 'Perfume', 'code' => 'P003', 'price' => 150.00, 'category' => 'MiEmpresa', 'image' => ''];
        $products[] = ['id' => 4, 'name' => 'Cuaderno', 'code' => 'P004', 'price' => 12.00, 'category' => 'MiEmpresa', 'image' => ''];
        $products[] = ['id' => 5, 'name' => 'Gas', 'code' => 'P005', 'price' => 25.00, 'category' => 'MiEmpresa', 'image' => ''];
        
        return $products;
    }
}
