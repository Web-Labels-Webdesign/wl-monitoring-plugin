<?php

declare(strict_types=1);

namespace WlMonitoring\Service;

use Symfony\Component\Finder\Finder;

class LogInfoCollector
{
    private const LOG_PATTERN = '/\[(?<date>[^\]]+)\] (?<channel>\w+)\.(?<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): (?<message>.*)/';
    private const MAX_RECENT_ERRORS = 100;
    private const MAX_FILE_SIZE_FULL_READ = 50 * 1024 * 1024;  // 50MB - files larger than this use tail reading
    private const TAIL_READ_SIZE = 5 * 1024 * 1024;            // 5MB - amount to read from end of large files

    public function __construct(
        private readonly string $logDir
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $files = $this->getLogFiles();
        $stats = $this->getLogStats($files);
        $recentErrors = $this->getRecentErrors($files);

        return [
            'files' => $files,
            'stats' => $stats,
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getLogFiles(): array
    {
        $logDir = rtrim($this->logDir, '/');

        if (!is_dir($logDir)) {
            return [];
        }

        $finder = new Finder();
        $finder
            ->in($logDir)
            ->files()
            ->name('*.log')
            ->ignoreDotFiles(true)
            ->sortByModifiedTime()
            ->reverseSorting();

        $files = [];
        foreach ($finder as $file) {
            $files[] = [
                'name' => $file->getFilename(),
                'size_kb' => (int) round($file->getSize() / 1024),
                'modified' => date(\DateTimeInterface::ATOM, $file->getMTime()),
            ];
        }

        return $files;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     *
     * @return array<string, mixed>
     */
    private function getLogStats(array $files): array
    {
        $stats = [
            'errors_24h' => 0,
            'errors_7d' => 0,
            'warnings_24h' => 0,
            'by_channel' => [],
        ];

        $logDir = rtrim($this->logDir, '/');
        $now = new \DateTime();
        $oneDayAgo = (clone $now)->modify('-24 hours');
        $sevenDaysAgo = (clone $now)->modify('-7 days');

        foreach ($files as $fileInfo) {
            $filePath = $logDir . '/' . $fileInfo['name'];

            if (!is_readable($filePath)) {
                continue;
            }

            // Only process files modified in the last 7 days
            $fileModified = new \DateTime($fileInfo['modified']);
            if ($fileModified < $sevenDaysAgo) {
                continue;
            }

            $this->parseLogFile($filePath, $stats, $oneDayAgo, $sevenDaysAgo);
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function parseLogFile(string $filePath, array &$stats, \DateTime $oneDayAgo, \DateTime $sevenDaysAgo): void
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return;
        }

        // For large files, only parse the tail (recent entries are at the end)
        if ($fileSize > self::MAX_FILE_SIZE_FULL_READ) {
            $readFrom = max(0, $fileSize - self::TAIL_READ_SIZE);
            fseek($handle, $readFrom);

            // Skip partial first line (we may have seeked into middle of a line)
            if ($readFrom > 0) {
                fgets($handle);
            }
        }

        while (($line = fgets($handle)) !== false) {
            if (preg_match(self::LOG_PATTERN, $line, $matches) !== 1) {
                continue;
            }

            try {
                $date = new \DateTime($matches['date']);
            } catch (\Throwable) {
                continue;
            }

            if ($date < $sevenDaysAgo) {
                continue;
            }

            $level = strtoupper($matches['level']);
            $channel = $matches['channel'];

            $isError = \in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true);
            $isWarning = $level === 'WARNING';

            if ($isError) {
                ++$stats['errors_7d'];

                if ($date >= $oneDayAgo) {
                    ++$stats['errors_24h'];

                    if (!isset($stats['by_channel'][$channel])) {
                        $stats['by_channel'][$channel] = 0;
                    }
                    ++$stats['by_channel'][$channel];
                }
            }

            if ($isWarning && $date >= $oneDayAgo) {
                ++$stats['warnings_24h'];
            }
        }

        fclose($handle);
    }

    /**
     * @param array<int, array<string, mixed>> $files
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRecentErrors(array $files): array
    {
        $errors = [];
        $logDir = rtrim($this->logDir, '/');
        $oneDayAgo = (new \DateTime())->modify('-24 hours');

        foreach ($files as $fileInfo) {
            if (\count($errors) >= self::MAX_RECENT_ERRORS) {
                break;
            }

            $filePath = $logDir . '/' . $fileInfo['name'];

            if (!is_readable($filePath)) {
                continue;
            }

            $fileErrors = $this->getErrorsFromFile($filePath, self::MAX_RECENT_ERRORS - \count($errors), $oneDayAgo);
            $errors = array_merge($errors, $fileErrors);
        }

        // Sort by date descending
        usort($errors, static fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return \array_slice($errors, 0, self::MAX_RECENT_ERRORS);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getErrorsFromFile(string $filePath, int $limit, \DateTime $cutoffDate): array
    {
        $errors = [];
        $fileSize = filesize($filePath);

        if ($fileSize === false) {
            return [];
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        // For large files, only read the tail to avoid memory exhaustion
        if ($fileSize > self::MAX_FILE_SIZE_FULL_READ) {
            $readFrom = max(0, $fileSize - self::TAIL_READ_SIZE);
            fseek($handle, $readFrom);

            // Skip partial first line (we may have seeked into middle of a line)
            if ($readFrom > 0) {
                fgets($handle);
            }
        }

        // Read lines into array
        $allLines = [];
        while (($line = fgets($handle)) !== false) {
            $allLines[] = $line;
        }
        fclose($handle);

        // Process in reverse order (newest first)
        $allLines = array_reverse($allLines);

        foreach ($allLines as $line) {
            if (\count($errors) >= $limit) {
                break;
            }

            if (preg_match(self::LOG_PATTERN, $line, $matches) !== 1) {
                continue;
            }

            $level = strtoupper($matches['level']);

            if (!\in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                continue;
            }

            try {
                $date = new \DateTime($matches['date']);

                // Skip errors older than 24h cutoff
                if ($date < $cutoffDate) {
                    // Since we're processing in reverse chronological order,
                    // once we hit an old entry, we can stop
                    break;
                }

                $dateFormatted = $date->format(\DateTimeInterface::ATOM);
            } catch (\Throwable) {
                // If date parsing fails, include the error but continue checking others
                $dateFormatted = $matches['date'];
            }

            $errors[] = [
                'date' => $dateFormatted,
                'level' => $level,
                'channel' => $matches['channel'],
                'message' => mb_substr(trim($matches['message']), 0, 500),
            ];
        }

        return $errors;
    }
}
