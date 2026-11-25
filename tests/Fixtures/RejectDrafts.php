<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RejectDrafts
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->boolean('draft')) {
            throw new AccessDeniedHttpException('Drafts are not allowed.');
        }

        return $next($request);
    }
}
