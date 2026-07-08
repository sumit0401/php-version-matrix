<?php

namespace VersionMatrix;

class Docker
{
    public const PHP_VERSIONS = ['5.6', '7.4', '8.1', '8.4'];

    public const MYSQL_IMAGES = [
        '5.7' => 'mysql:5.7',
        '8.0' => 'mysql:8.0',
    ];

    private string $imagesDir;
    private string $buildsDir;

    public function __construct(string $imagesDir, string $buildsDir)
    {
        $this->imagesDir = $imagesDir;
        $this->buildsDir = $buildsDir;
    }

    private function imageTag(string $phpVersion): string
    {
        return 'qlomatrix-php:' . $phpVersion;
    }

    private function run(string $cmd): array
    {
        exec($cmd . ' 2>&1', $output, $exitCode);
        return [$exitCode, implode("\n", $output)];
    }

    public function imageExists(string $phpVersion): bool
    {
        [, $out] = $this->run('docker images -q ' . escapeshellarg($this->imageTag($phpVersion)));
        return trim($out) !== '';
    }

    public function builtImages(): array
    {
        $result = [];
        foreach (self::PHP_VERSIONS as $v) {
            $result[$v] = $this->imageExists($v);
        }
        return $result;
    }

    private function logFile(string $phpVersion): string
    {
        return $this->buildsDir . "/php-{$phpVersion}.log";
    }

    private function doneFile(string $phpVersion): string
    {
        return $this->buildsDir . "/php-{$phpVersion}.done";
    }

    /** Kicks off `docker build` in the background; poll buildStatus() for progress. */
    public function startBuild(string $phpVersion): void
    {
        if (!in_array($phpVersion, self::PHP_VERSIONS, true)) {
            throw new \InvalidArgumentException("Unknown PHP version: $phpVersion");
        }
        $contextDir = $this->imagesDir . "/php-{$phpVersion}";
        $logFile = $this->logFile($phpVersion);
        $doneFile = $this->doneFile($phpVersion);
        @unlink($doneFile);
        @unlink($logFile);

        $inner = 'docker build -t ' . escapeshellarg($this->imageTag($phpVersion)) . ' '
            . escapeshellarg($contextDir)
            . '; echo $? > ' . escapeshellarg($doneFile);
        $full = 'nohup bash -c ' . escapeshellarg($inner)
            . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
        exec($full);
    }

    /** @return array{building: bool, done: bool, ok: ?bool, log: string} */
    public function buildStatus(string $phpVersion): array
    {
        $logFile = $this->logFile($phpVersion);
        $doneFile = $this->doneFile($phpVersion);
        $log = file_exists($logFile) ? file_get_contents($logFile) : '';
        if (!file_exists($doneFile)) {
            return ['building' => file_exists($logFile), 'done' => false, 'ok' => null, 'log' => $log];
        }
        $exitCode = trim((string) file_get_contents($doneFile));
        return ['building' => false, 'done' => true, 'ok' => $exitCode === '0', 'log' => $log];
    }

    public function isPortFree(int $port): bool
    {
        $sock = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    public function findFreePort(int $startingAt, array $usedPorts): int
    {
        $port = $startingAt;
        while (in_array($port, $usedPorts, true) || !$this->isPortFree($port)) {
            $port++;
            if ($port > $startingAt + 2000) {
                throw new \RuntimeException('No free port found');
            }
        }
        return $port;
    }

    public function networkCreate(string $name): void
    {
        $this->run('docker network create ' . escapeshellarg($name));
    }

    public function networkRemove(string $name): void
    {
        $this->run('docker network rm ' . escapeshellarg($name));
    }

    public function containerRemove(string $name): void
    {
        $this->run('docker rm -f ' . escapeshellarg($name));
    }

    public function startDb(string $name, string $network, string $mysqlVersion, int $hostPort): void
    {
        $image = self::MYSQL_IMAGES[$mysqlVersion] ?? null;
        if ($image === null) {
            throw new \InvalidArgumentException("Unknown MySQL version: $mysqlVersion");
        }
        $extra = $mysqlVersion === '8.0' ? '--default-authentication-plugin=mysql_native_password' : '';
        $cmd = sprintf(
            'docker run -d --name %s --network %s -p %d:3306 '
            . '-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=qloapps '
            . '-e MYSQL_USER=qloapps -e MYSQL_PASSWORD=qloapps %s %s',
            escapeshellarg($name),
            escapeshellarg($network),
            $hostPort,
            $extra,
            escapeshellarg($image)
        );
        [$code, $out] = $this->run($cmd);
        if ($code !== 0) {
            throw new \RuntimeException("Failed to start DB container: $out");
        }
    }

    public function startPhp(string $name, string $network, string $phpVersion, int $hostPort, string $folderPath): void
    {
        $cmd = sprintf(
            'docker run -d --name %s --network %s -p %d:80 -v %s:/var/www/html '
            . '-e APACHE_DOCUMENT_ROOT=/var/www/html %s',
            escapeshellarg($name),
            escapeshellarg($network),
            $hostPort,
            escapeshellarg($folderPath),
            escapeshellarg($this->imageTag($phpVersion))
        );
        [$code, $out] = $this->run($cmd);
        if ($code !== 0) {
            throw new \RuntimeException("Failed to start PHP container: $out");
        }
    }

    public function dbReady(string $dbContainerName): bool
    {
        [$code] = $this->run(
            'docker exec ' . escapeshellarg($dbContainerName) . ' mysqladmin ping -uroot -proot --silent'
        );
        return $code === 0;
    }

    public function containerLogs(string $containerName, int $tail = 200): string
    {
        [, $out] = $this->run('docker logs --tail ' . (int) $tail . ' ' . escapeshellarg($containerName));
        return $out;
    }
}
