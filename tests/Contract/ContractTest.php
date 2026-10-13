<?php

declare(strict_types=1);

it('has credentials for the contract suite', function (): void {
    expect(getenv('CIRRUX_TEST_CALENDAR'))->toBeString()->not->toBeEmpty();
})->skip(
    fn (): bool => in_array(getenv('CIRRUX_TEST_CALENDAR'), [false, ''], true)
        || in_array(getenv('CIRRUX_REFRESH_TOKEN'), [false, ''], true),
    'CIRRUX_* credentials are not set.',
);
