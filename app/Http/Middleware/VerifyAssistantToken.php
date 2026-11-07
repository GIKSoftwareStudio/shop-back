<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAssistantToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.assistant.token');
        $providedToken = $request->bearerToken();

        if (
            empty($expectedToken) ||
            empty($providedToken) ||
            ! hash_equals($expectedToken, $providedToken)
        ) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }
}
