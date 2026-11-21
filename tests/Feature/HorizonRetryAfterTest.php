<?php

use App\Jobs\ProcessDataImportJob;

// Queue workers take retry_after from the queue connection, Horizon ignores it
// on a supervisor. If a supervisor's timeout is not shorter than its
// connection's retry_after, a job that is still running is handed out again,
// e.g. the hour-long data import on the low queue.
test('every Horizon supervisor times out before its connection retries the job', function (): void {
    foreach (config('horizon.defaults') as $supervisor => $options) {
        $retryAfter = config("queue.connections.{$options['connection']}.retry_after");

        expect($retryAfter)->toBeInt($supervisor)
            ->and($options['timeout'])->toBeLessThan($retryAfter, $supervisor);
    }
});

test('the data import runs on a supervisor that allows its timeout', function (): void {
    $timeout = (new ReflectionClass(ProcessDataImportJob::class))->getProperty('timeout')->getDefaultValue();

    $supervisor = collect(config('horizon.defaults'))
        ->first(fn (array $options): bool => in_array('low', $options['queue'], true));

    expect($supervisor['timeout'])->toBeGreaterThanOrEqual($timeout)
        ->and(config("queue.connections.{$supervisor['connection']}.retry_after"))->toBeGreaterThan($timeout);
});
