<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin'             => \App\Http\Middleware\AdminMiddleware::class,
            'internal'          => \App\Http\Middleware\InternalAccessMiddleware::class,
            'approved_partner'  => \App\Http\Middleware\ApprovedPartnerMiddleware::class,
            'partner_session'   => \App\Http\Middleware\EnsureTemporaryAccessIsLive::class,
        ]);

        // Access checks run before route-model binding, so a caller who is refused gets the same
        // 401/403 whether or not the requested record exists (no ID-existence disclosure via 404).
        // None of these middleware read bound models; they only check the signed-in user and session.
        foreach ([
            \App\Http\Middleware\EnsureTemporaryAccessIsLive::class,
            \App\Http\Middleware\AdminMiddleware::class,
            \App\Http\Middleware\InternalAccessMiddleware::class,
            \App\Http\Middleware\ApprovedPartnerMiddleware::class,
        ] as $accessCheck) {
            $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, $accessCheck);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
