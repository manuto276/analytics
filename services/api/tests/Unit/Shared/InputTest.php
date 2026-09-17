<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Identity\Domain\GlobalRole;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Validation\Input;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testCollectsFieldErrors(): void
    {
        $input = new Input(['name' => ' ok ', 'n' => '12', 'bad' => 'x', 'role' => 'nope', 'list' => ['a', '', 'a'], 'flag' => 'true', 'nested' => ['x' => 5]]);
        self::assertSame('ok', $input->string('name'));
        self::assertSame(12, $input->int('n', null, 1, 20));
        self::assertSame(0, $input->int('bad'));
        self::assertNull($input->enum('role', GlobalRole::class));
        self::assertSame(['a'], $input->stringList('list'));
        self::assertTrue($input->bool('flag'));
        self::assertSame('', $input->string('missing'));
        $nested = $input->nested('nested');
        self::assertNotNull($nested);
        self::assertSame(0, $nested->int('x', null, 10));
        $input->merge($nested);

        $errors = $input->errors();
        self::assertArrayHasKey('bad', $errors);
        self::assertArrayHasKey('role', $errors);
        self::assertArrayHasKey('list.1', $errors);
        self::assertArrayHasKey('missing', $errors);
        self::assertArrayHasKey('nested.x', $errors);

        try {
            $input->assertValid();
            self::fail('expected validation problem');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertSame($errors, $problem->errors);
        }
    }

    public function testEmailAndChoiceAndSecret(): void
    {
        $input = new Input(['email' => 'nope', 'locale' => 'fr', 'password' => ' spaced ']);
        $input->email('email');
        self::assertSame('en', $input->choice('locale', ['en', 'it'], 'en'));
        self::assertSame(' spaced ', $input->secret('password'));
        self::assertSame(['email', 'locale'], array_keys($input->errors()));
    }

    public function testRejectsNonObjectBody(): void
    {
        $this->expectException(ApiProblem::class);
        Input::fromBody('string');
    }
}
