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
 * Holds the latest WebRuntimeCollector snapshot of each web node as a small JSON file under var/.
 * A file rather than the Magento cache so the "is a refresh due" check on a sampled storefront
 * request is a single stat() call, not a cache backend round trip, and so cache:flush doesn't wipe
 * it. One file per host: with a shared var/ the nodes no longer overwrite each other, and with a
 * local var/ the reporter simply sees its own node's file. The single-file snapshot older versions
 * wrote (web_runtime.json) is still read, until a per-host file exists.
 */
class WebRuntimeStore
{
    private const DIRECTORY = 'stacknuts_stackgaugeperformance';
    private const LEGACY_FILE = self::DIRECTORY . '/web_runtime.json';
    private const FILE_PREFIX = 'web_runtime_';
    private const FILE_PATTERN = '/^web_runtime_[A-Za-z0-9_.-]+\.json$/';

    private ?string $hostname = null;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * Whether this host has no snapshot yet, or its existing one is older than $maxAgeSeconds. A single stat().
     *
     * @param int $maxAgeSeconds
     */
    public function isStale(int $maxAgeSeconds): bool
    {
        try {
            $dir = $this->dir();
            $file = $this->hostFile();
            if (!$dir->isExist($file)) {
                return true;
            }

            return (time() - (int)($dir->stat($file)['mtime'] ?? 0)) >= $maxAgeSeconds;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Writes $snapshot for this host atomically (temp file + rename) so a reader never sees a half-written file.
     *
     * @param array<string, mixed> $snapshot
     */
    public function write(array $snapshot): void
    {
        $dir = $this->dir();
        $dir->create(self::DIRECTORY);

        $snapshot['host'] = $this->hostname();
        $file = $this->hostFile();
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $dir->writeFile($temp, (string)json_encode($snapshot));
        $dir->renameFile($temp, $file);
    }

    /**
     * Every node's latest snapshot keyed by host name, including a legacy single-file snapshot when no
     * per-host file exists yet. Empty when nothing has been captured or the directory is unreadable.
     *
     * @return array<string, array<string, mixed>>
     */
    public function readAll(): array
    {
        $snapshots = [];
        try {
            $dir = $this->dir();
            if (!$dir->isExist(self::DIRECTORY)) {
                return [];
            }
            foreach ($dir->read(self::DIRECTORY) as $path) {
                if (preg_match(self::FILE_PATTERN, basename($path)) !== 1) {
                    continue;
                }
                $decoded = $this->decode($dir->readFile($path));
                if ($decoded !== null) {
                    $snapshots[(string)($decoded['host'] ?? basename($path, '.json'))] = $decoded;
                }
            }

            if ($snapshots === [] && $dir->isExist(self::LEGACY_FILE)) {
                $legacy = $this->decode($dir->readFile(self::LEGACY_FILE));
                if ($legacy !== null) {
                    $snapshots[(string)($legacy['host'] ?? 'unknown')] = $legacy;
                }
            }
        } catch (Throwable) {
            return $snapshots;
        }

        return $snapshots;
    }

    /**
     * The most recently collected snapshot of any node, or null if none exists or it is unreadable.
     *
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $latest = null;
        foreach ($this->readAll() as $snapshot) {
            if ($latest === null || (int)($snapshot['collected_at'] ?? 0) > (int)($latest['collected_at'] ?? 0)) {
                $latest = $snapshot;
            }
        }

        return $latest;
    }

    /**
     * @param string $json
     * @return array<string, mixed>|null
     */
    private function decode(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * This node's name, resolved once per process.
     */
    private function hostname(): string
    {
        return $this->hostname ??= (string)(gethostname() ?: 'unknown');
    }

    /**
     * Relative path of this node's snapshot file, with anything outside [A-Za-z0-9_.-] replaced.
     */
    private function hostFile(): string
    {
        $safe = substr((string)preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->hostname()), 0, 64);

        return self::DIRECTORY . '/' . self::FILE_PREFIX . $safe . '.json';
    }

    /**
     * The var/ directory writer.
     */
    private function dir(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
