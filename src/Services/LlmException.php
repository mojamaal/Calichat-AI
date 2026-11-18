<?php
declare(strict_types=1);
namespace CaliChat\Services;

class LlmException extends \RuntimeException {
    public bool $retryable;
    public ?int $status;
    public string $kind;
    public int $retryAfter;
    public function __construct(string $msg, bool $retryable = false, ?int $status = null, string $kind = 'error', int $retryAfter = 0) {
        parent::__construct($msg);
        $this->retryable = $retryable; $this->status = $status; $this->kind = $kind; $this->retryAfter = $retryAfter;
    }
}
