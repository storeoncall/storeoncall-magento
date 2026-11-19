<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

/**
 * Sends data out over HTTPS. Outbound only: StoreOnCall never connects into the store.
 */
class Sender
{
    /** @return array{status: int, body: string} */
    public function request(string $method, string $url, string $key, ?string $json = null, int $timeout = 15): array
    {
        // The store key travels in this request, so it only ever goes over HTTPS (plain HTTP only to this machine, for testing).
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true))) {
            throw new \RuntimeException('StoreOnCall only connects over HTTPS. Check the StoreOnCall address in the configuration.');
        }
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $key, 'User-Agent: StoreOnCall-Agent/' . HeartbeatBuilder::AGENT_VERSION];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Could not reach StoreOnCall: ' . $error . '. Check that the server allows outbound HTTPS.');
        }
        return ['status' => $status, 'body' => (string)$body];
    }
}
