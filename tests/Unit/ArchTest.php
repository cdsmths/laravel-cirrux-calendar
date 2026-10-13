<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Exceptions\CirruxException;

arch()->preset()->php();

// Saloon defines its hooks as protected methods, so the Http layer may use them,
// and Laravel facades must define a protected getFacadeAccessor().
// CirruxException is the abstract base class that all other exceptions extend.
arch()->preset()->strict()->ignoring([
    'Codesmiths\CirruxCalendar\Http',
    'Codesmiths\CirruxCalendar\Facades',
    CirruxException::class,
]);

// The laravel preset only inspects the app's `App\` namespace, which a package
// does not have. laravel/pint registers its own `App\` namespace in vendor, so
// we skip it to avoid checking Pint's internals.
arch()->preset()->laravel()->ignoring('App');
arch()->preset()->security()->ignoring([
    'assert',
]);

arch('exceptions extend the base exception')
    ->expect('Codesmiths\CirruxCalendar\Exceptions')
    ->toExtend(CirruxException::class)
    ->ignoring(CirruxException::class);

arch('env() is only used in config')
    ->expect('env')
    ->not->toBeUsed();
