<?php
declare(strict_types=1);
namespace CaliChat\Memory;

interface MemoryProvider {
    /** Long-term memory block for the prompt (empty string if none). Content is unverified customer-stated data. */
    public function recall(array $tenant, array $contact, string $query): string;

    /** Called after each AI turn (from the worker, off the customer's critical path). */
    public function capture(array $tenant, array $contact, int $conversationId, string $userText, string $aiText): void;
}
