<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;

final class CheckAdminRole
{
    private AuthorizeRole $authorizeRole;

    public function __construct(?AuthorizeRole $authorizeRole = null)
    {
        $this->authorizeRole = $authorizeRole ?? new AuthorizeRole();
    }

    public function handle(array $request, Closure $next): mixed
    {
        return $this->authorizeRole->handle($request, $next, ['admin']);
    }
}
