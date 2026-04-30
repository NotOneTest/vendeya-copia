<?php

namespace App\Http\Controllers\Vendeya;

use App\Http\Controllers\Controller;
use App\Services\VendeyaApiService;
use App\Services\MiEmpresaApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class PosController extends Controller
{
    protected VendeyaApiService $api;
    protected MiEmpresaApiService $miEmpresaApi;

    public function __construct(VendeyaApiService $api, MiEmpresaApiService $miEmpresaApi)
    {
        $this->api = $api;
        $this->miEmpresaApi = $miEmpresaApi;
    }

    public function index()
    {
        $user = Session::get('user', ['name' => 'Usuario', 'email' => 'demo@gmail.com']);
        $logo = asset('images/logo.png');
        $apiDomain = config('services.miempresa.domain', 'https://api.miempresa.pe');

        $products = $this->getProductsFromApi();
        $allCategories = collect($products)->pluck('category')->unique()->filter()->values()->toArray();
        $customers = $this->getCustomersFromApi();
        $cashOpened = Session::get('cash_opened', false);

        return view('vendeya.pos.dashboard', compact('user', 'logo', 'apiDomain', 'products', 'allCategories', 'customers', 'cashOpened'));
    }

    private function getProductsFromApi()
    {
        try {
            $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->query("SELECT i.id, i.description as name, i.internal_id, i.unit_type_id, i.sale_unit_price as price, i.image, c.name as category 
                                FROM items i 
                                LEFT JOIN categories c ON i.category_id = c.id 
                                WHERE i.active = 1 AND i.status = 1
                                ORDER BY i.id DESC LIMIT 100");
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            Log::info('Products from MiEmpresa DB', ['count' => count($products)]);
            
            return array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'name' => $item['name'] ?? 'Producto',
                    'price' => floatval($item['price'] ?? 0),
                    'code' => $item['internal_id'] ?? '',
                    'category' => $item['category'] ?? 'General',
                    'image' => $item['image'] ?? null,
                    'unit_type_id' => $item['unit_type_id'] ?? 'NIU',
                ];
            }, $products);
        } catch (\Exception $e) {
            Log::error('Error fetching products from DB: ' . $e->getMessage());
        }
        return [];
    }

    private function getCustomersFromApi()
    {
        try {
            // Use direct database connection like products
            $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->query("SELECT id, name, identity_document_type_id, number FROM persons WHERE type = 'customers' ORDER BY id DESC LIMIT 100");
            $customers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            Log::info('Customers from MiEmpresa DB', ['count' => count($customers)]);
            
            return array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'number' => $item['number'] ?? '',
                    'identity_document_type_id' => $item['identity_document_type_id'] ?? '',
                    'document' => ($item['identity_document_type_id'] ?? '') . ': ' . ($item['number'] ?? ''),
                ];
            }, $customers);
        } catch (\Exception $e) {
            Log::error('Error fetching customers from DB: ' . $e->getMessage());
        }
        return $this->getFallbackCustomers();
    }
    
    private function getFallbackCustomers()
    {
        return [
            ['id' => 1, 'name' => 'Clientes - Varios', 'number' => '99999999', 'identity_document_type_id' => '0', 'document' => '0: 99999999'],
        ];
    }

    public function createSale(Request $request)
    {
        $data = $request->all();
        $documentType = $data['document_type'] ?? 'nv';
        $paymentMethod = $data['payment_method'] ?? '01';
        
        // Si es vale como FORMA DE PAGO (no como tipo de documento)
        if ($paymentMethod === '05') {
            return $this->processVoucherPayment($data);
        }
        
        if ($documentType === 'vale') {
            return $this->createValeSale($data);
        }
        
        $tipoDocumentoMap = [
            'nv' => '80',
            'boleta' => '03',
            'factura' => '01',
        ];
        
        $tipoDocumento = $tipoDocumentoMap[$documentType] ?? '80';
        
        $now = now();
        $fechaEmision = $now->format('Y-m-d');
        $horaEmision = $now->format('H:i:s');
        
        $customerId = $data['customer_id'] ?? 1;
        $customerData = $this->getCustomerData($customerId);
        
        $customerDocType = $customerData['tipo_documento'] ?? '1';
        $customerDocNumber = $customerData['numero'] ?? '00000000';
        $customerName = $customerData['nombre'] ?? 'CLIENTES - VARIOS';
        $customerAddress = $customerData['direccion'] ?? 'Av. Principal';
        $customerEmail = $customerData['email'] ?? '';
        
        if ($tipoDocumento === '01') {
            $customerDocType = '6';
            if (strlen($customerDocNumber) !== 11) {
                $customerDocNumber = '10414711225';
            }
        }
        
        $items = $data['items'] ?? [];
        $paymentMethod = $data['payment_method'] ?? '01';
        $subtotal = 0;
        $igv = 0;
        $total = 0;
        
        $documentItems = [];
        $isNotaVenta = ($documentType === 'nv');
        
        foreach ($items as $item) {
            $quantity = floatval($item['quantity'] ?? 1);
            $unitPrice = floatval($item['price'] ?? 0);
            $totalItem = $quantity * $unitPrice;
            
            if ($isNotaVenta) {
                $baseGravada = $totalItem;
                $igvItem = 0;
            } else {
                $baseGravada = $totalItem / 1.18;
                $igvItem = $totalItem - $baseGravada;
            }
            
            $subtotal += $baseGravada;
            $igv += $igvItem;
            $total += $totalItem;
            
            $documentItems[] = [
                'item_id' => $item['id'] ?? 1,
                'item' => [
                    'id' => $item['id'] ?? 1,
                    'internal_id' => 'P' . str_pad($item['id'] ?? 0, 4, '0', STR_PAD_LEFT),
                    'name' => $item['name'] ?? 'Producto',
                    'unit_type_id' => 'NIU',
                    'unit_price' => round($unitPrice, 2),
                ],
                'quantity' => $quantity,
                'unit_value' => round($baseGravada / $quantity, 2),
                'unit_price' => round($unitPrice, 2),
                'total_value' => round($baseGravada, 2),
                'total_igv' => round($igvItem, 2),
                'total' => round($totalItem, 2),
            ];
        }
        
        Log::info('Total calculation', [
            'total' => $total,
            'subtotal' => $subtotal,
            'igv' => $igv,
        ]);
        
        $miEmpresaResponse = $this->sendToMiEmpresa(
            $isNotaVenta,
            $tipoDocumento,
            $fechaEmision,
            $horaEmision,
            $customerDocType,
            $customerDocNumber,
            $customerName,
            $customerAddress,
            $customerEmail,
            $paymentMethod,
            $total,
            $subtotal,
            $igv,
            $documentItems
        );
        
        if (isset($miEmpresaResponse['success']) && $miEmpresaResponse['success']) {
            $documentId = $isNotaVenta ? 'NV01-' . rand(1000, 9999) : ($data['serie'] ?? 'B001') . '-' . rand(1000, 9999);
            $externalId = uniqid('miemp_', true);
            
            return response()->json([
                'success' => true,
                'message' => 'Venta realizada con éxito en MiEmpresa',
                'data' => [
                    'document_id' => $documentId,
                    'external_id' => $externalId,
                    'total' => round($total, 2),
                    'tipo_documento' => $tipoDocumento,
                ],
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => $miEmpresaResponse['error'] ?? 'Error al crear el documento en MiEmpresa',
        ], 400);
    }

    private function sendToMiEmpresa(bool $isNotaVenta, string $tipoDocumento, string $fechaEmision, string $horaEmision, string $customerDocType, string $customerDocNumber, string $customerName, string $customerAddress, string $customerEmail, string $paymentMethod, float $total, float $subtotal, float $igv, array $documentItems): array
    {
        try {
            if ($isNotaVenta) {
                $miEmpresaPayload = [
                    'serie_documento' => 'NV01',
                    'numero_documento' => '#',
                    'fecha_de_emision' => $fechaEmision,
                    'hora_de_emision' => $horaEmision,
                    'codigo_tipo_operacion' => '0101',
                    'codigo_tipo_documento' => '80',
                    'codigo_tipo_moneda' => 'PEN',
                    'fecha_de_vencimiento' => $fechaEmision,
                    'datos_del_cliente_o_receptor' => [
                        'codigo_tipo_documento_identidad' => $customerDocType,
                        'numero_documento' => $customerDocNumber,
                        'apellidos_y_nombres_o_razon_social' => $customerName,
                        'codigo_pais' => 'PE',
                        'direccion' => $customerAddress,
                        'correo_electronico' => $customerEmail,
                    ],
                    'totales' => [
                        'total_operaciones_gravadas' => round($total, 2),
                        'total_igv' => 0,
                        'total_venta' => round($total, 2),
                    ],
                    'items' => array_map(function($item) {
                        return [
                            'codigo_interno' => $item['item']['internal_id'] ?? 'P0001',
                            'descripcion' => $item['item']['name'] ?? 'Producto',
                            'cantidad' => $item['quantity'],
                            'valor_unitario' => round($item['unit_value'], 2),
                            'precio_unitario' => round($item['unit_price'], 2),
                            'total_item' => round($item['total'], 2),
                        ];
                    }, $documentItems),
                    'pagos' => [
                        [
                            'fecha_de_emision' => $fechaEmision,
                            'codigo_metodo_pago' => $paymentMethod,
                            'monto' => round($total, 2),
                        ]
                    ],
                ];
                
                $response = $this->miEmpresaApi->createSaleNote($miEmpresaPayload);
                Log::info('MiEmpresa Sale Note Response', ['response' => $response]);
                return $response;
            } else {
                $serie = 'B001';
                
                $miEmpresaPayload = [
                    'serie_documento' => $serie,
                    'numero_documento' => '#',
                    'fecha_de_emision' => $fechaEmision,
                    'hora_de_emision' => $horaEmision,
                    'codigo_tipo_operacion' => '0101',
                    'codigo_tipo_documento' => $tipoDocumento,
                    'codigo_tipo_moneda' => 'PEN',
                    'fecha_de_vencimiento' => $fechaEmision,
                    'datos_del_cliente_o_receptor' => [
                        'codigo_tipo_documento_identidad' => $customerDocType,
                        'numero_documento' => $customerDocNumber,
                        'apellidos_y_nombres_o_razon_social' => $customerName,
                        'codigo_pais' => 'PE',
                        'direccion' => $customerAddress,
                        'correo_electronico' => $customerEmail,
                    ],
                    'totales' => [
                        'total_operaciones_gravadas' => round($subtotal, 2),
                        'total_igv' => round($igv, 2),
                        'total_venta' => round($total, 2),
                    ],
                    'items' => array_map(function($item) {
                        return [
                            'codigo_interno' => $item['item']['internal_id'] ?? 'P0001',
                            'descripcion' => $item['item']['name'] ?? 'Producto',
                            'cantidad' => $item['quantity'],
                            'valor_unitario' => round($item['unit_value'], 2),
                            'precio_unitario' => round($item['unit_price'], 2),
                            'total_item' => round($item['total'], 2),
                        ];
                    }, $documentItems),
                    'pagos' => [
                        [
                            'fecha_de_emision' => $fechaEmision,
                            'codigo_metodo_pago' => $paymentMethod,
                            'monto' => round($total, 2),
                        ]
                    ],
                ];
                
                $response = $this->miEmpresaApi->createDocument($miEmpresaPayload);
                Log::info('MiEmpresa Document Response', ['response' => $response]);
                return $response;
            }
        } catch (\Exception $e) {
            Log::error('Error sending to MiEmpresa', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getProducts(Request $request)
    {
        $products = $this->getProductsFromApi();
        return response()->json(['success' => true, 'data' => $products]);
    }

    public function getCustomers(Request $request)
    {
        $customers = $this->getCustomersFromApi();
        return response()->json(['success' => true, 'data' => $customers]);
    }

    public function createCustomer(Request $request)
    {
        $response = $this->miEmpresaApi->createCustomer($request->all());
        return response()->json($response);
    }

    public function checkCashStatus()
    {
        $cashOpened = Session::get('cash_opened', false);
        return response()->json(['success' => true, 'opened' => $cashOpened]);
    }

    public function openCash(Request $request)
    {
        Session::put('cash_opened', true);
        return response()->json(['success' => true, 'message' => 'Caja abierta']);
    }

    public function closeCash(Request $request)
    {
        Session::put('cash_opened', false);
        return response()->json(['success' => true, 'message' => 'Caja cerrada']);
    }

    public function getCashRecords()
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function testSeries()
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function getDocumentByExternalId($externalId)
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function getVoucherBalance($doc)
    {
        $response = $this->miEmpresaApi->getVoucherBalance($doc);
        return response()->json($response);
    }

    public function discountVoucher(Request $request)
    {
        $data = $request->all();
        $response = $this->miEmpresaApi->discountVoucher($data['voucher_id'] ?? 0, $data['amount'] ?? 0);
        return response()->json($response);
    }

    public function createVoucher(Request $request)
    {
        $data = $request->all();
        $response = $this->miEmpresaApi->createVoucher($data);
        return response()->json($response);
    }

    private function createValeSale($data)
    {
        try {
            $customerId = $data['customer_id'] ?? null;
            $customerDoc = $data['customer_doc'] ?? '';
            $total = floatval($data['total'] ?? 0);
            $items = $data['items'] ?? [];
            
            if (!$customerId && !$customerDoc) {
                return response()->json(['success' => false, 'error' => 'Cliente no especificado'], 400);
            }
            
            // Obtener datos del cliente
            $customerData = $this->getCustomerDataFromId($customerId);
            $customerName = $customerData['name'] ?? 'Cliente';
            $customerNumber = $customerData['number'] ?? $customerDoc;
            
            // Crear el vale en MiEmpresa (similar a una nota de venta)
            $voucherData = [
                'document_type' => 'vale',
                'serie' => $data['serie'] ?? 'V001',
                'customer_id' => $customerId,
                'customer_doc' => $customerNumber,
                'customer_name' => $customerName,
                'amount' => $total,
                'items' => $items,
                'observation' => 'Vale emitido desde Vendeya POS',
            ];
            
            Log::info('Creating voucher in MiEmpresa', ['data' => $voucherData]);
            
            $response = $this->miEmpresaApi->createVoucher($voucherData);
            
            if (isset($response['success']) && $response['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vale emitido correctamente en MiEmpresa',
                    'data' => $response
                ]);
            }
            
            return response()->json([
                'success' => false,
                'error' => $response['error'] ?? 'Error al crear el vale en MiEmpresa'
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('Error creating vale sale: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }
    
    private function processVoucherPayment($data)
    {
        try {
            $customerId = $data['customer_id'] ?? null;
            $customerDoc = $data['customer_doc'] ?? '';
            $total = floatval($data['total'] ?? 0);
            $items = $data['items'] ?? [];
            
            if (!$customerId && !$customerDoc) {
                return response()->json(['success' => false, 'error' => 'Cliente no especificado'], 400);
            }
            
            // Obtener número de documento del cliente
            if (!$customerDoc) {
                $customerData = $this->getCustomerDataFromId($customerId);
                $customerDoc = $customerData['number'] ?? '';
            }
            
            if (empty($customerDoc)) {
                return response()->json(['success' => false, 'error' => 'Número de documento del cliente no encontrado'], 400);
            }
            
            // Consultar saldo del vale
            Log::info('Checking voucher balance for customer', ['doc' => $customerDoc]);
            $balanceResponse = $this->miEmpresaApi->getVoucherBalance($customerDoc);
            
            if (!isset($balanceResponse['success']) || !$balanceResponse['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Error al consultar saldo del vale: ' . ($balanceResponse['error'] ?? 'Error desconocido')
                ], 400);
            }
            
            $balance = floatval($balanceResponse['balance'] ?? $balanceResponse['data']['balance'] ?? 0);
            
            Log::info('Voucher balance check', ['customer_doc' => $customerDoc, 'balance' => $balance, 'total' => $total]);
            
            if ($balance < $total) {
                return response()->json([
                    'success' => false,
                    'error' => "Saldo insuficiente. Saldo disponible: S/ " . number_format($balance, 2) . ", Total a pagar: S/ " . number_format($total, 2)
                ], 400);
            }
            
            // Obtener voucher_id del cliente (asumiendo que tiene uno activo)
            $vouchersResponse = $this->miEmpresaApi->getVouchersByCustomer($customerDoc);
            $voucherId = null;
            
            if (isset($vouchersResponse['data']) && is_array($vouchersResponse['data'])) {
                // Buscar el primer vale activo
                foreach ($vouchersResponse['data'] as $voucher) {
                    if (($voucher['status'] ?? '') !== 'used') {
                        $voucherId = $voucher['id'] ?? null;
                        break;
                    }
                }
            }
            
            if (!$voucherId) {
                return response()->json([
                    'success' => false,
                    'error' => 'No se encontró un vale activo para este cliente'
                ], 400);
            }
            
            // Descontar del vale
            Log::info('Discounting from voucher', ['voucher_id' => $voucherId, 'amount' => $total]);
            $discountResponse = $this->miEmpresaApi->discountVoucher($voucherId, $total);
            
            if (!isset($discountResponse['success']) || !$discountResponse['success']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Error al descontar del vale: ' . ($discountResponse['error'] ?? 'Error desconocido')
                ], 400);
            }
            
            // Crear el documento de venta (nota de venta o boleta)
            $documentType = $data['document_type'] ?? 'nv';
            $data['payment_method'] = '05'; // Vale de Venta
            
            // Generar la venta normal pero con forma de pago vale
            return $this->createSaleWithVoucherPayment($data, $customerDoc, $total, $items);
            
        } catch (\Exception $e) {
            Log::error('Error processing voucher payment: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }
    
    private function createSaleWithVoucherPayment($data, $customerDoc, $total, $items)
    {
        // Aquí creamos el comprobante (nota de venta) que viaja a MiEmpresa
        $now = now();
        $fechaEmision = $now->format('Y-m-d');
        $horaEmision = $now->format('H:i:s');
        
        $customerData = $this->getCustomerDataFromDoc($customerDoc);
        
        $miEmpresaPayload = [
            'serie_documento' => 'NV01',
            'numero_documento' => '#',
            'fecha_de_emision' => $fechaEmision,
            'hora_de_emision' => $horaEmision,
            'codigo_tipo_operacion' => '0101',
            'codigo_tipo_documento' => '80', // Nota de venta
            'codigo_tipo_moneda' => 'PEN',
            'fecha_de_vencimiento' => $fechaEmision,
            'datos_del_cliente_o_receptor' => [
                'codigo_tipo_documento_identidad' => $customerData['identity_document_type_id'] ?? '1',
                'numero_documento' => $customerDoc,
                'apellidos_y_nombres_o_razon_social' => $customerData['name'] ?? 'CLIENTE',
                'codigo_pais' => 'PE',
                'direccion' => $customerData['address'] ?? 'Av. Principal',
                'correo_electronico' => $customerData['email'] ?? '',
            ],
            'totales' => [
                'total_operaciones_gravadas' => round($total, 2),
                'total_igv' => 0,
                'total_venta' => round($total, 2),
            ],
            'items' => array_map(function($item) {
                return [
                    'codigo_interno' => 'P' . str_pad($item['id'] ?? 0, 4, '0', STR_PAD_LEFT),
                    'descripcion' => $item['name'] ?? 'Producto',
                    'cantidad' => floatval($item['quantity'] ?? 1),
                    'valor_unitario' => round(floatval($item['price'] ?? 0), 2),
                    'precio_unitario' => round(floatval($item['price'] ?? 0), 2),
                    'total_item' => round(floatval($item['price'] ?? 0) * floatval($item['quantity'] ?? 1), 2),
                ];
            }, $items),
            'pagos' => [
                [
                    'fecha_de_emision' => $fechaEmision,
                    'codigo_metodo_pago' => '05', // Vale de venta
                    'monto' => round($total, 2),
                ]
            ],
        ];
        
        $response = $this->miEmpresaApi->createSaleNote($miEmpresaPayload);
        
        if (isset($response['success']) && $response['success']) {
            return response()->json([
                'success' => true,
                'message' => 'Venta realizada con éxito usando vale. Saldo restante: S/ ' . number_format($total, 2),
                'data' => $response
            ]);
        }
        
        return response()->json([
            'success' => false,
            'error' => $response['error'] ?? 'Error al crear el documento en MiEmpresa'
        ], 400);
    }
    
    private function getCustomerDataFromDoc($docNumber)
    {
        try {
            $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("SELECT id, name, number, identity_document_type_id, address FROM persons WHERE number = ? AND type = 'customers' LIMIT 1");
            $stmt->execute([$docNumber]);
            $customer = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($customer) {
                return [
                    'name' => $customer['name'],
                    'number' => $customer['number'],
                    'identity_document_type_id' => $customer['identity_document_type_id'] ?? '1',
                    'address' => $customer['address'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            Log::error('Error fetching customer data by doc: ' . $e->getMessage());
        }
        
        return ['name' => 'CLIENTE', 'number' => $docNumber, 'identity_document_type_id' => '1', 'address' => ''];
    }
    
    private function getCustomerDataFromId($customerId)
    {
        try {
            $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->prepare("SELECT id, name, number, identity_document_type_id FROM persons WHERE id = ? AND type = 'customers' LIMIT 1");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($customer) {
                return [
                    'name' => $customer['name'],
                    'number' => $customer['number'] ?? '',
                    'identity_document_type_id' => $customer['identity_document_type_id'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            Log::error('Error fetching customer data: ' . $e->getMessage());
        }
        
        return ['name' => 'Cliente', 'number' => '', 'identity_document_type_id' => ''];
    }

    private function getCustomerData($customerId)
    {
        return [
            'tipo_documento' => '1',
            'numero' => '00000000',
            'nombre' => 'CLIENTES - VARIOS',
            'direccion' => 'Av. Principal',
            'email' => '',
        ];
    }
}
