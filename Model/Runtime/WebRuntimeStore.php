<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGaugePerformance\Model\Runtime;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Throwable;

/**
 * Holds the latest WebRuntimeCollector snapshot as a small JSON file under var/. A file rather
 * than the Magento cache so the "is a refresh due" check on a sampled storefront request is a
 * single stat() call, not a cache backend round trip, and so cache:flush doesn't wipe it.
 */
class WebRuntimeStore
{
    private const DIRECTORY = 'stacknuts_stackgaugeperformance';
    private const FILE = self::DIRECTORY . '/web_runtime.json';

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * Whether there is no snapshot yet, or the existing one is older than $maxAgeSeconds.
     *
     * @param int $maxAgeSeconds
     */
    public function isStale(int $maxAgeSeconds): bool
    {
        try {
            $dir = $this->dir();
            if (!$dir->isExist(self::FILE)) {
                return true;
            }

            return (time() - (int)($dir->stat(self::FILE)['mtime'] ?? 0)) >= $maxAgeSeconds;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Writes $snapshot atomically (temp file + rename) so a reader never sees a half-written file.
     *
     * @param array<string, mixed> $snapshot
     */
    public function write(array $snapshot): void
    {
        $dir = $this->dir();
        $dir->create(self::DIRECTORY);

        $temp = self::FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $dir->writeFile($temp, (string)json_encode($snapshot));
        $dir->renameFile($temp, self::FILE);
    }

    /**
     * The latest snapshot, or null if none exists or it is unreadable.
     *
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        try {
            $dir = $this->dir();
            if (!$dir->isExist(self::FILE)) {
                return null;
            }

            $decoded = json_decode($dir->readFile(self::FILE), true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The var/ directory writer.
     */
    private function dir(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
