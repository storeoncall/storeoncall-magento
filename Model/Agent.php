<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

/** Builds and sends one heartbeat. Used by cron and by the console commands. */
class Agent
{
    public function __construct(
        private readonly HeartbeatBuilder $builder,
        private readonly Config $config,
        private readonly Sender $sender
    ) {
    }

    public function preview(): array
    {
        return $this->builder->build(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $this->config->lastSent());
    }

    /** @return array{ok: bool, message: string} */
    public function send(): array
    {
        if (!$this->config->enabled()) {
            return ['ok' => false, 'message' => 'StoreOnCall is switched off in Stores > Configuration > Advanced > StoreOnCall.'];
        }
        $key = $this->config->key();
        if ($key === null) {
            return ['ok' => false, 'message' => 'No store key yet. Run: bin/magento storeoncall:connect <your key>'];
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $payload = $this->builder->build($now, $this->config->lastSent());
        $res = $this->sender->request('POST', $this->config->apiUrl() . '/agent/v1/heartbeat', $key, (string)json_encode($payload));
        $ok = $res['status'] >= 200 && $res['status'] < 300;
        $message = $ok ? 'Sent' : sprintf('StoreOnCall answered HTTP %d: %s', $res['status'], substr($res['body'], 0, 300));
        if ($ok || $res['status'] === 429) {
            $this->config->markSent($now, $ok ? 'ok' : 'too frequent');
        } else {
            $this->config->markSent($this->config->lastSent() ?? $now, $message);
        }
        return ['ok' => $ok, 'message' => $message];
    }

    /** @return array{ok: bool, message: string} */
    public function ping(string $key): array
    {
        $res = $this->sender->request('GET', $this->config->apiUrl() . '/agent/v1/ping', $key);
        $body = json_decode($res['body'], true);
        if ($res['status'] === 200 && is_array($body)) {
            return ['ok' => true, 'message' => 'Connected to StoreOnCall as "' . ($body['store'] ?? 'your store') . '".'];
        }
        return ['ok' => false, 'message' => is_array($body) && isset($body['error']) ? (string)$body['error'] : 'StoreOnCall answered HTTP ' . $res['status']];
    }

    /**
     * Asks StoreOnCall to check the store now (after a deployment) and waits for the answer.
     * @return array{status: string, summary: string}
     */
    public function verify(string $key, ?string $note, int $maxSeconds = 240): array
    {
        $base = $this->config->apiUrl() . '/agent/v1/verify';
        $res = $this->sender->request('POST', $base, $key, (string)json_encode(['note' => $note]));
        $body = json_decode($res['body'], true);
        if (!is_array($body) || !isset($body['id'])) {
            throw new \RuntimeException(is_array($body) && isset($body['error']) ? (string)$body['error'] : 'StoreOnCall answered HTTP ' . $res['status']);
        }
        $deadline = time() + max(30, $maxSeconds);
        while (empty($body['done']) && time() < $deadline) {
            $wait = min(25, max(1, $deadline - time()));
            $res = $this->sender->request('GET', $base . '/' . rawurlencode((string)$body['id']) . '?wait=' . $wait, $key, null, $wait + 15);
            $next = json_decode($res['body'], true);
            if (!is_array($next) || !isset($next['status'])) {
                throw new \RuntimeException('StoreOnCall answered HTTP ' . $res['status']);
            }
            $body = $next;
        }
        return ['status' => empty($body['done']) ? 'timeout' : (string)$body['status'], 'summary' => (string)($body['summary'] ?? '')];
    }
}
