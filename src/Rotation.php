<?php

namespace Cesargb\Log;

use Cesargb\Log\Compress\Gz;
use Cesargb\Log\Exceptions\RotationFailed;
use Cesargb\Log\Processors\RotativeProcessor;

use Closure;
use Exception;
use Throwable;

include_once 'Compress/Gz.php';
include_once 'Exceptions/RotationFailed.php';
include_once 'Processors/RotativeProcessor.php';

// https://github.com/php/php-src/blob/master/ext/standard/tests/file/flock.phpt
const __LOCK_EX = 2;
const __LOCK_UN = 3;

class Rotation
{
    private const COMPRESS_DEFAULT_LEVEL = null;

    private RotativeProcessor $processor;

    private bool $_compress = false;
    private ?int $_compressLevel = self::COMPRESS_DEFAULT_LEVEL;

    private int $_minSize = 0;

    private bool $_truncate = false;

    private ?string $_assert_on_success = null;
    private bool $_success = false;
    private ?string $_filename_rotated = null;

    private ?string $_filename = null;

    /**
     * @param mixed[] $options
     */
    public function __construct(array $options = [])
    {
        $this->processor = new RotativeProcessor();
    }

    /**
     * Function that will be executed when the rotation is successful.
     * The first argument will be the name of the destination file
     */
    public function addAssertOnSuccess(string $expected): self
    {
        $this->_assert_on_success = $expected;

        return $this;
    }

    protected function setFilename(string $filename): void
    {
        $this->_filename = $filename;
    }

    private function successful(string $filenameSource, ?string $filenameRotated): void
    {
        $this->_filename_rotated = $filenameRotated;

        if (is_null($filenameRotated)) {
            return;
        }

        if (!is_null($this->_assert_on_success)) {
            if ($this->_assert_on_success != $filenameRotated) {
                throw new Exception();
            }
        }

        $this->_success = true;
    }

    protected function exception(Throwable $exception): self
    {
        $this->_success = false;

        throw $this->convertException($exception);
        return $this;
    }

    private function convertException(Throwable $exception): RotationFailed
    {
        return new RotationFailed(
            $exception->getMessage(),
            $exception->getCode(),
            $this->_filename
        );
    }

    /**
     * Log files are rotated count times before being removed.
     */
    public function files(int $count): self
    {
        $this->processor->files($count);

        return $this;
    }

    /**
     * Old versions of log files are compressed.
     */
    public function compress(?int $level = -1): self
    {
        $this->_compress = (bool)$level;
        if ($level == -1) {
            $this->_compressLevel = self::COMPRESS_DEFAULT_LEVEL;
        } else {
            $this->_compressLevel = $level;
        }

        if ($this->_compress) {
            $this->processor->addExtension('gz');
        } else {
            $this->processor->removeExtension('gz');
        }

        return $this;
    }

    /**
     * Truncate the original log file in place after creating a copy, instead of
     * moving the old log file.
     *
     * It can be used when some program cannot be told to close its logfile and
     * thus might continue writing (appending) to the previous log file forever.
     */
    public function truncate(bool $truncate = true): self
    {
        $this->_truncate = $truncate;

        return $this;
    }

    /**
     * Log files are rotated when they grow bigger than size bytes.
     */
    public function minSize(int $bytes): self
    {
        $this->_minSize = $bytes;

        return $this;
    }

    /**
     * Rotate file.
     *
     * @return bool true if rotated was successful
     */
    public function rotate(string $filename): bool
    {
        $this->setFilename($filename);

        if (!$this->canRotate($filename)) {
            return false;
        }

        $fileTemporary = $this->_truncate
            ? $this->copyAndTruncate($filename)
            : $this->move($filename);

        if (is_null($fileTemporary)) {
            return false;
        }

        $fileTarget = $this->runProcessor(
            $filename,
            $fileTemporary
        );

        if (is_null($fileTarget)) {
            return false;
        }

        $fileTarget = $this->runCompress($fileTarget);

        $this->successful($filename, $fileTarget);

        return true;
    }

    /**
     * Run processor.
     */
    private function runProcessor(string $filenameSource, ?string $filenameTarget): ?string
    {
        $this->initProcessorFile($filenameSource);

        if (!$filenameTarget) {
            return null;
        }

        return $this->processor->handler($filenameTarget);
    }

    private function runCompress(string $filename): ?string
    {
        if (!$this->_compress) {
            return $filename;
        }

        $gz = new Gz();

        try {
            return $gz->handler($filename, $this->_compressLevel);
        } catch (Exception $error) {
            $this->exception($error);

            return null;
        }
    }

    /**
     * check if file need rotate.
     */
    private function canRotate(string $filename): bool
    {
        if (!file_exists($filename)) {
            return false;
        }

        if (!$this->fileIsValid($filename)) {
            $this->exception(
                new Exception(sprintf('the file %s not is valid.', $filename), 10)
            );

            return false;
        }

        return filesize($filename) > ($this->_minSize > 0 ? $this->_minSize : 0);
    }

    /**
     * Set original File to processor.
     */
    private function initProcessorFile(string $filename): void
    {
        $this->processor->setFilenameSource($filename);
    }

    /**
     * check if file is valid to rotate.
     */
    private function fileIsValid(string $filename): bool
    {
        return is_file($filename);
    }

    /**
     * copy data to temp file and truncate.
     */
    private function copyAndTruncate(string $filename): ?string
    {
        clearstatcache();

        $filenameTarget = $this->getTempFilename(dirname($filename));

        if (!$filenameTarget) {
            return null;
        }

        $fd = $this->openFileWithLock($filename);

        if (!$fd) {
            return null;
        }

        if (!copy($filename, $filenameTarget)) {
            fclose($fd);

            $this->exception(
                new Exception(
                    sprintf('the file %s not can copy to temp file %s.', $filename, $filenameTarget),
                    22
                )
            );

            return null;
        }

        fclose($fd);
        $fd = fopen($filename, "w");

        if (!fopen($filename, "w")) {
            unlink($filenameTarget);

            $this->exception(
                new Exception(sprintf('the file %s not can truncate.', $filename), 23)
            );

            return null;
        }

        fflush($fd);
        fclose($fd);

        return $filenameTarget;
    }

    private function move(string $filename): ?string
    {
        clearstatcache();

        $filenameTarget = $this->getTempFilename(dirname($filename));

        if (!$filenameTarget) {
            return null;
        }

        if (!rename($filename, $filenameTarget)) {
            $this->exception(
                new Exception(
                    sprintf('the file %s not can move to temp file %s.', $filename, $filenameTarget),
                    22
                )
            );

            return null;
        }

        return $filenameTarget;
    }

    private function getTempFilename(string $path): ?string
    {
        $filename = tempnam($path, 'LOG');

        if ($filename === false) {
            $this->exception(
                new Exception(sprintf('the file %s not can create temp file.', $path), 19)
            );

            return null;
        }

        return $filename;
    }

    private function openFileWithLock(string $filename)
    {
        $fd = fopen($filename, 'r+');

        if ($fd === false) {
            $this->exception(
                new Exception(sprintf('the file %s not can open.', $filename), 20)
            );

            return null;
        }

        return $fd;
    }

    public function isSuccessed(): bool
    {
        return $this->_success;
    }

    public function fileNameRotated(): ?string
    {
        return $this->_filename_rotated;
    }
}
