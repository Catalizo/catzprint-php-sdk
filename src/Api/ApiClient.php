<?php

namespace CatzPrint\Api;

use CatzPrint\Exceptions\ApiConnectionException;
use CatzPrint\Exceptions\PrintingException;

class ApiClient
{
    protected $apiKey;
    protected $baseUrl = 'http://localhost:4040/api/v1/sdk';
    protected $timeout = 10;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function sendRequest(string $endpoint, string $method = 'POST', array $data = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if (curl_errno($ch)) {
            $error = 'Curl error: ' . $curlError . ' [' . curl_errno($ch) . ']';
            curl_close($ch);
            throw new ApiConnectionException($error);
        }
        curl_close($ch);

        $result = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            $error = $result['error']['message'] ?? $result['error'] ?? $response;
            throw new PrintingException("API Error $httpCode: " . substr($error, 0, 200));
        }

        return $result;
    }
}