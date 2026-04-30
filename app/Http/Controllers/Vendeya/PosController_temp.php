<?php

namespace App\Http\Controllers\Vendeya;

use App\Http\Controllers\Controller;
use App\Services\VendeyaApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class PosControllerTemp extends Controller
{
    protected VendeyaApiService $api;

    public function __construct(VendeyaApiService $api)
    {
        $this->api = $api;
    }

    private function getMiEmpresaFallbackProducts(): array
    {
        return [
            ['id' => 1, 'name' => 'Gasolina', 'code' => 'P001', 'price' => 12.50, 'category' => 'MiEmpresa', 'image' => ''],
            ['id' => 2, 'name' => 'Gaseosa', 'code' => 'P002', 'price' => 3.00, 'category' => 'MiEmpresa', 'image' => ''],
            ['id' => 3, 'name' => 'Perfume', 'code' => 'P003', 'price' => 150.00, 'category' => 'MiEmpresa', 'image' => ''],
            ['id' => 4, 'name' => 'Cuaderno', 'code' => 'P004', 'price' => 12.00, 'category' => 'MiEmpresa', 'image' => ''],
            ['id' => 5, 'name' => 'Gas', 'code' => 'P005', 'price' => 25.00, 'category' => 'MiEmpresa', 'image' => ''],
        ];
    }
}
