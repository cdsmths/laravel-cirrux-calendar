<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
arch()->preset()->security();

arch('exceptions extend the base exception')
    ->expect('Codesmiths\CirruxCalendar\Exceptions')
    ->toExtend(CirruxException::class)
    ->ignoring(CirruxException::class);

arch('env() is only used in config')
    ->expect('env')
    ->not->toBeUsed();

arch('data objects are readonly')
    ->expect('Codesmiths\CirruxCalendar\Data')
    ->toBeReadonly();

arch('actions are readonly and have a handle method')
    ->expect('Codesmiths\CirruxCalendar\Actions')
    ->toBeReadonly()
    ->toHaveMethod('handle');

it('gives every action exactly one public method, handle()', function (): void {
    $actions = glob(dirname(__DIR__, 2).'/src/Actions/*/*.php') ?: [];

    expect($actions)->not->toBeEmpty();

    foreach ($actions as $file) {
        $class = 'Codesmiths\\CirruxCalendar\\Actions\\'.basename(dirname($file)).'\\'.basename($file, '.php');
        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
            ),
        );

        expect(array_values($methods))->toBe(['handle'], $class.' must only expose handle()');
    }
});

arch('events are readonly and dispatchable')
    ->expect('Codesmiths\CirruxCalendar\Events')
    ->toBeReadonly()
    ->toUseTrait(Dispatchable::class);

arch('only actions dispatch events')
    ->expect('Codesmiths\CirruxCalendar\Events')
    ->toOnlyBeUsedIn('Codesmiths\CirruxCalendar\Actions');

arch('the http layer and facades do not touch the database, the cache or the event dispatcher')
    ->expect([
        'Codesmiths\CirruxCalendar\Http',
        'Codesmiths\CirruxCalendar\Facades',
    ])
    ->not->toUse([DB::class, Cache::class, Event::class]);
