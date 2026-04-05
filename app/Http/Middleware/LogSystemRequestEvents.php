<?php

namespace App\Http\Middleware;

use App\Services\SystemEventLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogSystemRequestEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->user()) {
            return $response;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        SystemEventLogger::log(
            eventType: 'http.request',
            metadata: [
                'payload_keys' => array_values(array_keys($request->except([
                    'password',
                    'password_confirmation',
                    'current_password',
                    '_token',
                ]))),
            ],
            request: $request,
            statusCode: $response->getStatusCode(),
        );

        return $response;
    }
}
