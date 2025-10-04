<?php

namespace App\Services\Extraction\Strategies;

use Illuminate\Support\Facades\Log;

/**
 * TEMPORARY FILE MANAGER
 * 
 * Manages temporary files for PDF processing with automatic cleanup.
 * Creates a single temp directory per process and cleans up on destruction.
 */
class TempFileManager
{
    private string $tempDir;
    private array $createdFiles = [];
    private bool $cleanupOnDestruct = true;

    public function __construct(bool $cleanupOnDestruct = true)
    {
        $this->cleanupOnDestruct = $cleanupOnDestruct;
        $this->tempDir = $this->createTempDirectory();
        
        Log::debug('TempFileManager initialized', [
            'temp_dir' => $this->tempDir,
            'cleanup_on_destruct' => $this->cleanupOnDestruct
        ]);
    }

    public function __destruct()
    {
        if ($this->cleanupOnDestruct) {
            $this->cleanup();
        }
    }

    /**
     * Create a unique temporary directory
     */
    private function createTempDirectory(): string
    {
        $baseTempDir = sys_get_temp_dir();
        $processId = getmypid();
        $timestamp = time();
        $randomId = uniqid();
        
        $tempDir = $baseTempDir . '/pdf_processing_' . $processId . '_' . $timestamp . '_' . $randomId;
        
        if (!mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException('Could not create temporary directory: ' . $tempDir);
        }
        
        Log::debug('Temporary directory created', [
            'temp_dir' => $tempDir,
            'process_id' => $processId
        ]);
        
        return $tempDir;
    }

    /**
     * Get the temporary directory path
     */
    public function getTempDirectory(): string
    {
        return $this->tempDir;
    }

    /**
     * Create a temporary file
     */
    public function createTempFile(string $prefix = 'temp', string $extension = 'txt'): string
    {
        $filename = $prefix . '_' . uniqid() . '.' . $extension;
        $filePath = $this->tempDir . '/' . $filename;
        
        // Create empty file
        if (touch($filePath)) {
            $this->createdFiles[] = $filePath;
            
            Log::debug('Temporary file created', [
                'file_path' => $filePath,
                'total_files' => count($this->createdFiles)
            ]);
            
            return $filePath;
        }
        
        throw new \RuntimeException('Could not create temporary file: ' . $filePath);
    }

    /**
     * Create a temporary file with content
     */
    public function createTempFileWithContent(string $content, string $prefix = 'temp', string $extension = 'txt'): string
    {
        $filePath = $this->createTempFile($prefix, $extension);
        
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException('Could not write content to temporary file: ' . $filePath);
        }
        
        Log::debug('Temporary file created with content', [
            'file_path' => $filePath,
            'content_length' => strlen($content)
        ]);
        
        return $filePath;
    }

    /**
     * Get a temporary file path (without creating the file)
     */
    public function getTempFilePath(string $filename): string
    {
        return $this->tempDir . '/' . $filename;
    }

    /**
     * Check if a temporary file exists
     */
    public function tempFileExists(string $filePath): bool
    {
        return file_exists($filePath) && str_starts_with($filePath, $this->tempDir);
    }

    /**
     * Get temporary file size
     */
    public function getTempFileSize(string $filePath): int
    {
        if ($this->tempFileExists($filePath)) {
            return filesize($filePath);
        }
        
        return 0;
    }

    /**
     * Read content from a temporary file
     */
    public function readTempFile(string $filePath): string
    {
        if (!$this->tempFileExists($filePath)) {
            throw new \RuntimeException('Temporary file does not exist: ' . $filePath);
        }
        
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new \RuntimeException('Could not read temporary file: ' . $filePath);
        }
        
        return $content;
    }

    /**
     * Write content to a temporary file
     */
    public function writeTempFile(string $filePath, string $content): void
    {
        if (!str_starts_with($filePath, $this->tempDir)) {
            throw new \RuntimeException('File path is not within temporary directory: ' . $filePath);
        }
        
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException('Could not write to temporary file: ' . $filePath);
        }
        
        // Track the file if it's not already tracked
        if (!in_array($filePath, $this->createdFiles)) {
            $this->createdFiles[] = $filePath;
        }
        
        Log::debug('Content written to temporary file', [
            'file_path' => $filePath,
            'content_length' => strlen($content)
        ]);
    }

    /**
     * Delete a specific temporary file
     */
    public function deleteTempFile(string $filePath): bool
    {
        if (!$this->tempFileExists($filePath)) {
            return false;
        }
        
        if (unlink($filePath)) {
            // Remove from tracking
            $this->createdFiles = array_filter($this->createdFiles, fn($file) => $file !== $filePath);
            
            Log::debug('Temporary file deleted', [
                'file_path' => $filePath,
                'remaining_files' => count($this->createdFiles)
            ]);
            
            return true;
        }
        
        return false;
    }

    /**
     * Clean up all temporary files and directory
     */
    public function cleanup(): void
    {
        $deletedFiles = 0;
        $deletedDirs = 0;
        
        // Delete all tracked files
        foreach ($this->createdFiles as $filePath) {
            if (file_exists($filePath) && unlink($filePath)) {
                $deletedFiles++;
            }
        }
        
        // Delete any remaining files in temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                    $deletedFiles++;
                } elseif (is_dir($file)) {
                    $this->deleteDirectory($file);
                    $deletedDirs++;
                }
            }
            
            // Remove the temp directory itself
            if (rmdir($this->tempDir)) {
                $deletedDirs++;
            }
        }
        
        Log::info('Temporary files cleaned up', [
            'temp_dir' => $this->tempDir,
            'deleted_files' => $deletedFiles,
            'deleted_dirs' => $deletedDirs,
            'total_tracked_files' => count($this->createdFiles)
        ]);
        
        $this->createdFiles = [];
    }

    /**
     * Recursively delete a directory
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $filePath = $dir . '/' . $file;
            
            if (is_dir($filePath)) {
                $this->deleteDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }
        
        return rmdir($dir);
    }

    /**
     * Get statistics about temporary files
     */
    public function getStatistics(): array
    {
        $totalSize = 0;
        $fileCount = 0;
        
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                    $fileCount++;
                }
            }
        }
        
        return [
            'temp_dir' => $this->tempDir,
            'tracked_files' => count($this->createdFiles),
            'actual_files' => $fileCount,
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'cleanup_on_destruct' => $this->cleanupOnDestruct
        ];
    }

    /**
     * Disable automatic cleanup on destruction
     */
    public function disableAutoCleanup(): void
    {
        $this->cleanupOnDestruct = false;
        
        Log::debug('Auto cleanup disabled');
    }

    /**
     * Enable automatic cleanup on destruction
     */
    public function enableAutoCleanup(): void
    {
        $this->cleanupOnDestruct = true;
        
        Log::debug('Auto cleanup enabled');
    }

    /**
     * Get list of created files
     */
    public function getCreatedFiles(): array
    {
        return $this->createdFiles;
    }

    /**
     * Check if temp directory exists
     */
    public function tempDirectoryExists(): bool
    {
        return is_dir($this->tempDir);
    }
}
