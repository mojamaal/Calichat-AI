<?php
declare(strict_types=1);
namespace CaliChat\Memory;

class Memory {
    public static function for(array $tenant): MemoryProvider {
        $p = $tenant['cfg']['memory_provider'] ?? env('MEMORY_PROVIDER', 'mysql');
        return $p === 'tencentdb' ? new TencentMemory() : new MySqlMemory();
    }
}
