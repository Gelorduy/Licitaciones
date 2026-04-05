<?php

namespace App\Services;

use App\Models\SystemEventLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SystemEventLogger
{
    public static function log(
        string $eventType,
        array $metadata = [],
        ?Request $request = null,
        ?int $statusCode = null,
        ?string $entityType = null,
        ?int $entityId = null,
    ): void {
        try {
            $user = $request?->user();
            $routeName = $request?->route()?->getName();

            SystemEventLog::query()->create([
                'user_id' => $user?->id,
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'request_method' => $request?->method(),
                'request_path' => $request?->path(),
                'route_name' => $routeName,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'status_code' => $statusCode,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::warning('System event logging failed', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
