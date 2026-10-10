<?php

declare(strict_types=1);

namespace Velundo\Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Velundo\Connect\Http\ApiException;
use Velundo\Connect\Http\ApiRequest;
use Velundo\Connect\Http\Validate;

final class RequestValidationTest extends TestCase
{
    public function testQueryDefaultsAndBounds(): void
    {
        $request = new ApiRequest('GET', [], '');
        self::assertSame(50, $request->limit());
        self::assertSame(0, $request->offset());
        self::assertSame(0, $request->languageId());
        self::assertSame('visible', $request->hiddenMode());

        $request = new ApiRequest('GET', ['limit' => '200', 'offset' => '10', 'language' => '1', 'hidden' => 'all'], '');
        self::assertSame(200, $request->limit());
        self::assertSame(10, $request->offset());
        self::assertSame(1, $request->languageId());
        self::assertSame('all', $request->hiddenMode());
    }

    public function testLimitAbove200Is422(): void
    {
        try {
            (new ApiRequest('GET', ['limit' => '201'], ''))->limit();
            self::fail('limit 201 hätte 422 ergeben müssen');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('validation_failed', $e->errorCode);
        }
    }

    public function testInvalidHiddenModeIs422(): void
    {
        $this->expectException(ApiException::class);
        (new ApiRequest('GET', ['hidden' => 'maybe'], ''))->hiddenMode();
    }

    public function testInvalidJsonIs400(): void
    {
        try {
            (new ApiRequest('PATCH', [], '{"title": '))->body();
            self::fail('Ungültiges JSON hätte 400 ergeben müssen');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame('invalid_json', $e->errorCode);
        }
    }

    public function testUnknownFieldIs422(): void
    {
        try {
            (new ApiRequest('PATCH', [], '{"title":"x","deleted":1}'))->fields(['title', 'hidden']);
            self::fail('Unbekanntes Feld hätte 422 ergeben müssen');
        } catch (ApiException $e) {
            self::assertSame(422, $e->status);
            self::assertSame('unknown_field', $e->errorCode);
            self::assertSame(['deleted'], $e->details['unknown']);
        }
    }

    public function testEmptyBodyIs422(): void
    {
        try {
            (new ApiRequest('PATCH', [], '{}'))->fields(['title']);
            self::fail('Leerer Rumpf hätte 422 ergeben müssen');
        } catch (ApiException $e) {
            self::assertSame('empty_body', $e->errorCode);
        }
        self::assertSame([], (new ApiRequest('POST', [], ''))->fields(['pages'], false));
    }

    public function testValidateTypes(): void
    {
        $body = ['title' => 'Hallo', 'hidden' => true, 'flag' => 1, 'count' => 5, 'when' => '2026-10-10T12:00:00+02:00', 'cats' => [3, 1, 3]];
        self::assertSame('Hallo', Validate::string($body, 'title'));
        self::assertNull(Validate::string($body, 'missing'));
        self::assertTrue(Validate::bool($body, 'hidden'));
        self::assertTrue(Validate::bool($body, 'flag'));
        self::assertSame(5, Validate::int($body, 'count', 0, 10));
        self::assertSame(1_791_626_400, Validate::datetime($body, 'when')); // 2026-10-10 10:00 UTC
        self::assertSame(1_791_626_400, Validate::datetime(['when' => '2026-10-10T10:00:00Z'], 'when'));
        self::assertSame(1_791_626_400, Validate::datetime(['when' => '2026-10-10 10:00:00.5+0000'], 'when'));
        self::assertNotNull(Validate::datetime(['when' => '2026-10-10'], 'when'));
        self::assertSame([3, 1], Validate::intList($body, 'cats'));
    }

    public function testValidateRejectsWrongTypes(): void
    {
        foreach ([
            fn() => Validate::string(['title' => 5], 'title'),
            fn() => Validate::bool(['hidden' => 'yes'], 'hidden'),
            fn() => Validate::int(['count' => 1.5], 'count'),
            fn() => Validate::int(['count' => 11], 'count', 0, 10),
            fn() => Validate::datetime(['when' => 'gestern irgendwann'], 'when'),
            fn() => Validate::datetime(['when' => 'tomorrow'], 'when'),
            fn() => Validate::datetime(['when' => '10.10.2026 12:00'], 'when'),
            fn() => Validate::datetime(['when' => '2026-13-45'], 'when'),
            fn() => Validate::datetime(['when' => '@1700000000'], 'when'),
            fn() => Validate::intList(['cats' => [1, 'a']], 'cats'),
            fn() => Validate::intList(['cats' => ['a' => 1]], 'cats'),
            fn() => Validate::string(['title' => str_repeat('x', 300)], 'title', 255),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Fall $i hätte 422 ergeben müssen");
            } catch (ApiException $e) {
                self::assertSame(422, $e->status, "Fall $i");
            }
        }
    }
}
