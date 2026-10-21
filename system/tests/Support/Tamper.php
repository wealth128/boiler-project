<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * Step 7a: send a tampered request and prove it was refused the right way.
 * A refusal is 403, 404 or 422 with a message, never a 500, and the database
 * (SecurityWorld::state()) is exactly as it was before.
 */
final class Tamper
{
    /**
     * @param  object  $test  The Pest test (signed in already)
     * @param  int|list<int>  $status  What is expected (422 by default)
     * @param  string|null  $field  The form field the 422 message must be about
     */
    public static function refused(object $test, string $method, string $uri, array $data = [], int|array $status = 422, ?string $field = null): TestResponse
    {
        $before = SecurityWorld::state();
        $response = $test->json($method, $uri, $data);
        $got = $response->getStatusCode();

        expect($got)->toBeIn((array) $status, "{$method} {$uri} gave {$got}: ".substr((string) $response->getContent(), 0, 300));

        if ($got === 422) {
            expect($response->json('message'))->toBeString()->not->toBeEmpty();

            if ($field !== null) {
                $response->assertJsonValidationErrors([$field]);
            }
        }

        expect(SecurityWorld::state())->toBe($before, "{$method} {$uri} changed the database");

        return $response;
    }
}
