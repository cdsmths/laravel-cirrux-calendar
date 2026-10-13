<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Data\BookingStatus;
use Codesmiths\CirruxCalendar\Data\CancelledBy;
use Codesmiths\CirruxCalendar\Data\Transparency;
use Codesmiths\CirruxCalendar\Exceptions\CirruxException;
use Codesmiths\CirruxCalendar\Http\CirruxAuthConnector;
use Codesmiths\CirruxCalendar\Http\CirruxConnector;
use Codesmiths\CirruxCalendar\Models\BookingRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Connector;

arch()->preset()->php();

// Saloon defines its hooks as protected methods, so the Http layer may use them,
// Laravel facades must define a protected getFacadeAccessor(), and Eloquent models a protected casts().
// CirruxException is the abstract base class that all other exceptions extend.
arch()->preset()->strict()->ignoring([
    'Codesmiths\CirruxCalendar\Http',
    'Codesmiths\CirruxCalendar\Facades',
    BookingRecord::class,
    CirruxException::class,
]);

it('lets the booking record have casts() as its only protected method', function (): void {
    $class = new ReflectionClass(BookingRecord::class);

    // Only methods written in the class file: those of Eloquent and of its traits are not ours to police.
    $own = array_filter(
        $class->getMethods(ReflectionMethod::IS_PROTECTED),
        static fn (ReflectionMethod $method): bool => $method->getFileName() === $class->getFileName(),
    );

    expect(array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $own)))->toBe(['casts']);
});

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

// An enum is already immutable and cannot be declared readonly.
arch('data objects are readonly')
    ->expect('Codesmiths\CirruxCalendar\Data')
    ->toBeReadonly()
    ->ignoring([Transparency::class, BookingStatus::class, CancelledBy::class]);

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

// Pest only checks a list of namespaces reliably one namespace at a time, so these loop.
foreach (['Http', 'Facades', 'Commands'] as $layer) {
    arch(sprintf('the %s layer does not touch the database, the cache or the event dispatcher', $layer))
        ->expect('Codesmiths\\CirruxCalendar\\'.$layer)
        ->not->toUse([DB::class, Cache::class, Event::class]);
}

foreach (['Facades', 'Commands'] as $layer) {
    arch(sprintf('the %s layer calls actions instead of the connectors', $layer))
        ->expect('Codesmiths\\CirruxCalendar\\'.$layer)
        ->not->toUse([CirruxConnector::class, CirruxAuthConnector::class, Connector::class]);
}
