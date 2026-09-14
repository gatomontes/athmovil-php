<?php
declare(strict_types=1);

namespace AthMovil\Simulation;

/** Local development storage. Use a private directory outside your web root. */
final readonly class FileStore implements Store
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create simulator storage directory.');
        }
    }

    public function transaction(string $scope, callable $callback): mixed
    {
        $base = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $scope);
        $lock = @fopen($base . '.lock', 'c+b');
        if ($lock === false) { throw new \RuntimeException('Cannot open simulator lock.'); }
        @chmod($base . '.lock', 0600);
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) { throw new \RuntimeException('Cannot lock simulator storage.'); }
            $state = [];
            if (is_file($base . '.json')) {
                $bytes = @file_get_contents($base . '.json');
                if ($bytes === false) { throw new \RuntimeException('Cannot read simulator storage.'); }
                try { $state = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { throw new \RuntimeException('Simulator storage is corrupt; restore or explicitly reset it.'); }
                if (!is_array($state)) { throw new \RuntimeException('Simulator storage must contain an object.'); }
            }
            $result = $callback($state);
            $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $temporary = tempnam($this->directory, '.athm-');
            if ($temporary === false) { throw new \RuntimeException('Cannot create simulator state file.'); }
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $bytes) !== strlen($bytes)
                || !rename($temporary, $base . '.json')) {
                throw new \RuntimeException('Cannot commit simulator state.');
            }
            $temporary = null;
            return $result;
        } finally {
            if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
