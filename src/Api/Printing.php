<?php

namespace CatzPrint\Api;

use CatzPrint\Exceptions\PrintingException;

class Printing
{
    protected $apiClient;
    protected $content;
    protected $orderId;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public static function newPrintTask(ApiClient $apiClient): self
    {
        return new self($apiClient);
    }

    public function content(string $json): self
    {
        $this->content = $json;
        return $this;
    }

    public function orderId($orderId): self
    {
        $this->orderId = $orderId;
        return $this;
    }

    public function send(): string
    {
        if (empty($this->content)) {
            throw new PrintingException("Print content cannot be empty");
        }

        $payload = [
            'source' => 'php',
            'content' => $this->content,
            'orderId' => $this->orderId,
        ];

        $response = $this->apiClient->sendRequest('/print-job', 'POST', $payload);
        return $response['data']['pjId'] ?? '';
    }
}