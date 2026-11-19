<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;

class Config
{
    private const KEY = 'storeoncall/general/api_key';
    private const ENABLED = 'storeoncall/general/enabled';
    private const URL = 'storeoncall/general/api_url';
    private const FLAG_LAST_SENT = 'storeoncall_last_sent';
    private const FLAG_LAST_RESULT = 'storeoncall_last_result';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly WriterInterface $writer,
        private readonly EncryptorInterface $encryptor,
        private readonly FlagManager $flags
    ) {
    }

    /** The environment variable wins, so Adobe Commerce Cloud projects need no database change. */
    public function key(): ?string
    {
        $env = getenv('STOREONCALL_KEY');
        if (is_string($env) && $env !== '') {
            return trim($env);
        }
        $stored = (string)$this->config->getValue(self::KEY);
        return $stored === '' ? null : trim($this->encryptor->decrypt($stored));
    }

    public function saveKey(string $key): void
    {
        $this->writer->save(self::KEY, $this->encryptor->encrypt($key));
    }

    public function enabled(): bool
    {
        return (bool)$this->config->getValue(self::ENABLED);
    }

    public function apiUrl(): string
    {
        $env = getenv('STOREONCALL_API_URL');
        return rtrim(is_string($env) && $env !== '' ? $env : (string)$this->config->getValue(self::URL), '/');
    }

    public function lastSent(): ?\DateTimeImmutable
    {
        $v = $this->flags->getFlagData(self::FLAG_LAST_SENT);
        return is_string($v) && $v !== '' ? new \DateTimeImmutable($v) : null;
    }

    public function markSent(\DateTimeImmutable $at, string $result): void
    {
        $this->flags->saveFlag(self::FLAG_LAST_SENT, $at->format(DATE_ATOM));
        $this->flags->saveFlag(self::FLAG_LAST_RESULT, $result);
    }

    public function lastResult(): ?string
    {
        $v = $this->flags->getFlagData(self::FLAG_LAST_RESULT);
        return is_string($v) ? $v : null;
    }
}
