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

        $this->ensureAuthenticated();

        $products = $this->getProductsFromApi();
        $allCategories = collect($products)->pluck('category')->unique()->filter()->values()->toArray();
        $customers = $this->getCustomersFromApi();
        $cashOpened = Session::get('cash_opened', false);

        return view('vendeya.pos.dashboard', compact('user', 'logo', 'apiDomain', 'products', 'allCategories', 'customers', 'cashOpened'));
    }

    private function ensureAuthenticated()
    {
        $token = Session::get('miempresa_token');
        if (empty($token)) {
            $this->miEmpresaApi->login('admin@miempresa.com', '123456');
        }
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
            $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            
            $stmt = $pdo->query("SELECT id, name, identity_document_type_id, number FROM persons WHERE type = 'customers' ORDER BY id DESC LIMIT 100");
            $customers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return array_map(function ($item) {
                return [
                    'id' => $item['id'],
                    'name' => $item['name'],
                    'number' => $item['number'] ?? '',
                    'document_type' => $item['identity_document_type_id'] ?? '',
                ];
            }, $customers);
        } catch (\Exception $e) {
            Log::error('Error fetching customers from DB: ' . $e->getMessage());
        }
        return [];
    }

    public function createSale(Request $request)
    {
        try {
            // Registrar datos recibidos para debug
            Log::info('createSale called', ['data' => $request->all()]);
            
            $this->ensureAuthenticated();
            
            $data = $request->all();
            $documentType = $data['document_type'] ?? 'nv';
            
        // Si es tipo vale, usar createValeSale
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
        } catch (\Exception $e) {
            Log::error('Error in createSale', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error en el servidor: ' . $e->getMessage(),
            ], 500);
        }
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
        try {
            $pdo = new \PDO("mysql:host=127.0.0.1;port=3306;dbname=tenancy_miempresa;charset=utf8", "root", "");
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT id FROM persons WHERE type = 'customers' AND number = ? LIMIT 1");
            $stmt->execute([$doc]);
            $customer = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$customer) {
                return response()->json(['success' => true, 'balance' => 0, 'message' => 'Cliente no encontrado']);
            }

            $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(total_balance), 0) as balance FROM vouchers WHERE customer_id = ? AND active = 1");
            $stmt2->execute([$customer['id']]);
            $result = $stmt2->fetch(\PDO::FETCH_ASSOC);

            $balance = floatval($result['balance'] ?? 0);

            return response()->json([
                'success' => true,
                'balance' => $balance,
                'message' => $balance > 0 ? 'Saldo disponible' : 'Sin saldo'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching voucher balance from DB: ' . $e->getMessage());
            return response()->json(['success' => false, 'balance' => 0, 'error' => $e->getMessage()]);
        }
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
            Log::info('createValeSale called', ['data' => $data]);
            $this->ensureAuthenticated();
             
            $items = $data['items'] ?? [];
            $paymentMethod = $data['payment_method'] ?? '05'; // Vale de Venta
            $total = 0;
            foreach ($items as $item) {
                $total += floatval($item['price'] ?? 0) * floatval($item['quantity'] ?? 1);
            }
            
            // Obtener customer_id del request
            $customerId = null;
            if (isset($data['customer_id']) && is_numeric($data['customer_id']) && $data['customer_id'] > 0) {
                $customerId = intval($data['customer_id']);
                Log::info('Using customer_id from request', ['customerId' => $customerId]);
            } else {
                // Buscar por número de documento
                $customerDoc = $data['customer_doc'] ?? ($data['customer']['number'] ?? '00000000');
                Log::info('Searching customer by doc', ['customerDoc' => $customerDoc]);
                $customersResult = $this->miEmpresaApi->getCustomers();
                if (isset($customersResult['data']['customers'])) {
                    foreach ($customersResult['data']['customers'] as $c) {
                        if (($c['number'] ?? '') === $customerDoc) {
                            $customerId = $c['id'] ?? null;
                            break;
                        }
                    }
                }
            }
            
            if (!$customerId) {
                // Crear cliente si no existe
                $customerName = $data['customer_name'] ?? ($data['customer']['name'] ?? 'Cliente');
                $customerDoc = $data['customer_doc'] ?? '00000000';
                $newCustomer = $this->miEmpresaApi->createCustomer([
                    'tipo_documento' => '1',
                    'numero_documento' => $customerDoc,
                    'nombres' => $customerName,
                    'direccion' => '',
                ]);
                $customerId = $newCustomer['data']['id'] ?? null;
            }
            
            if (!$customerId) {
                return response()->json(['success' => false, 'message' => 'No se pudo obtener el ID del cliente'], 400);
            }
            
            $payload = [
                'customer_id' => $customerId,
                'total' => $total,
                'series' => $data['serie'] ?? 'V001', // Usar serie del request
                'number' => '#',
                'date_of_issue' => now()->format('Y-m-d'),
                'time_of_issue' => now()->format('H:i:s'),
                'plate_number' => $data['plate'] ?? null,
            ];
            
            Log::info('Creating voucher', ['payload' => $payload]);
            $response = $this->miEmpresaApi->createVoucher($payload);
            Log::info('Voucher creation response', ['response' => $response]);
            
            if (isset($response['success']) && $response['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Vale creado exitosamente',
                    'data' => $response['data'] ?? []
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => $response['error'] ?? 'Error al crear vale',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error creating vale sale', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
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
