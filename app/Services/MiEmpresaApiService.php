<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class MiEmpresaApiService
{
    private $baseUrl;
    private $token;

    public function __construct()
    {
        $this->baseUrl = config('miempresa.base_url', 'http://miempresa.pro-8-2026.test');
        $this->token = Session::get('miempresa_token', config('miempresa.token', ''));
    }

    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];
        
        if (!empty($this->token)) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }
        
        return $headers;
    }

    public function login(string $email, string $password): bool
    {
        try {
            $response = Http::timeout(30)->post($this->baseUrl . '/api/login', [
                'email' => $email,
                'password' => $password,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['token'] ?? null;
                if ($token) {
                    Session::put('miempresa_token', $token);
                    $this->token = $token;
                    Log::info('MiEmpresa API login successful');
                    return true;
                }
            }
            
            Log::error('MiEmpresa API login failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
            return false;
        } catch (\Exception $e) {
            Log::error('MiEmpresa login exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function get(string $endpoint, array $params = []): array
    {
        try {
            $url = $this->baseUrl . $endpoint;
            Log::info('MiEmpresa API GET request', ['url' => $url, 'params' => $params]);
            
            $response = Http::withHeaders($this->headers())
                ->timeout(config('miempresa.timeout', 30))
                ->get($url, $params);

            Log::info('MiEmpresa API GET response status', ['status' => $response->status()]);
            
            if ($response->successful()) {
                $json = $response->json();
                Log::info('MiEmpresa API GET response JSON', ['json_type' => gettype($json)]);
                return $json ?? [];
            }
            
            Log::error('MiEmpresa API GET failed', ['status' => $response->status(), 'body' => substr($response->body(), 0, 500)]);
            return ['success' => false, 'error' => 'HTTP ' . $response->status()];
        } catch (\Exception $e) {
            Log::error('MiEmpresa API GET exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function post(string $endpoint, array $data = []): array
    {
        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(config('miempresa.timeout', 30))
                ->post($this->baseUrl . $endpoint, $data);

            return $response->successful() ? $response->json() : ['success' => false, 'error' => $response->body()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function createDocument(array $data): array
    {
        return $this->post('/documents', $data);
    }

    public function createSaleNote(array $data): array
    {
        return $this->post('/sale-notes', $data);
    }

    public function createVoucher(array $data): array
    {
        return $this->post('/vouchers/create', $data);
    }

    public function createCustomer(array $data): array
    {
        return $this->post('/persons/customers', $data);
    }

    public function getVouchersByCustomer(string $customerDoc): array
    {
        return $this->get('/vouchers/customer/' . $customerDoc);
    }

    public function getVoucherBalance(string $customerDoc): array
    {
        return $this->get('/vouchers/balance/' . $customerDoc);
    }

    public function discountVoucher($voucherId, $amount): array
    {
        return $this->post('/vouchers/discount', [
            'voucher_id' => $voucherId,
            'amount' => $amount,
        ]);
    }

    public function getProducts(array $params = []): array
    {
        // Use the public items endpoint (no tenant context needed)
        $params = array_merge(['input' => '', 'limit' => 100], $params);
        $result = $this->get('/public/document/search-items', $params);

        Log::info('MiEmpresa getProducts raw result', ['result_preview' => substr(json_encode($result), 0, 500)]);

        // Handle success wrapper with data key
        if (is_array($result) && isset($result['success']) && $result['success'] === true) {
            $data = $result['data'] ?? [];
            if (is_array($data)) {
                Log::info('Products fetched successfully (wrapped)', ['count' => count($data)]);
                return $data;
            }
        }

        // Handle direct array response
        if (is_array($result) && isset($result[0]) && is_array($result[0])) {
            Log::info('Products fetched successfully (direct array)', ['count' => count($result)]);
            return $result;
        }

        Log::warning('Failed to fetch products from public API', ['result' => substr(json_encode($result), 0, 500)]);
        return [];
    }

    public function getCustomers(array $params = []): array
    {
        // Use the public endpoint for customers (no auth required)
        $result = $this->get('/public/document/customers', $params);

        Log::info('MiEmpresa getCustomers result', ['result_preview' => substr(json_encode($result), 0, 500)]);
        
        Log::info('MiEmpresa getCustomers result', ['result' => substr(json_encode($result), 0, 500)]);
        
        if (is_array($result) && isset($result['data']) && is_array($result['data'])) {
            return ['data' => $result['data']];
        }
        
        if (is_array($result) && count($result) > 0) {
            return ['data' => $result];
        }
        
        return ['data' => []];
    }

    public function getTenantProducts(array $params = []): array
    {
        $params = array_merge(['limit' => 100], $params);
        $result = $this->get('/api/items', $params);
        
        Log::info('MiEmpresa getTenantProducts result', ['result_preview' => substr(json_encode($result), 0, 500)]);
        
        if (is_array($result)) {
            if (isset($result['data']) && is_array($result['data'])) {
                return $result['data'];
            }
            
            if (isset($result['success']) && $result['success'] === true && isset($result['data'])) {
                return is_array($result['data']) ? $result['data'] : [];
            }
            
            if (count($result) > 0 && is_array($result[0])) {
                return $result;
            }
        }
        
        return [];
    }
}
