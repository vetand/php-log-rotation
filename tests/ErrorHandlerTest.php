<?php

namespace Cesargb\Log\Test;

use Cesargb\Log\Exceptions\RotationFailed;
use Cesargb\Log\Rotation;

class ErrorHandlerTest extends TestCase
{
    public function testCallThenIfRotateWasSuccessful(): void
    {
        file_put_contents(self::DIR_WORK.'file.log', microtime(true));

        $rotation = new Rotation();
        $rotation->rotate(self::DIR_WORK.'file.log');

        $this->assertTrue($rotation->isSuccessed());
    }

    public function testNotCallThenIfRotateNotWasSuccessful(): void
    {
        $rotation = new Rotation();
        $rotation->rotate(self::DIR_WORK.'file.log');

        $this->assertFalse($rotation->isSuccessed());
    }

    public function testThrowsException(): void
    {
        $rotation = new Rotation();

        touch(self::DIR_WORK.'/file.log');
        chmod(self::DIR_WORK.'/file.log', 0444);

        $result = $rotation->rotate(self::DIR_WORK.'file.log');

        $this->assertFalse($rotation->isSuccessed());
    }
}
