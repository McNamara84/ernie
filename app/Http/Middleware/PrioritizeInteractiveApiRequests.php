<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\InteractiveApiRequestContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class PrioritizeInteractiveApiRequests
{
    public function __construct(private InteractiveApiRequestContextService $context) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->context->run(fn (): Response => $next($request));
    }
}
