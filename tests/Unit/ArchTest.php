<?php

declare(strict_types=1);

use Codesmiths\CirruxCalendar\Exceptions\CirruxException;

arch()->preset()->php();
arch()->preset()->strict()->ignoring('Codesmiths\CirruxCalendar\Http');
arch()->preset()->laravel();
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
