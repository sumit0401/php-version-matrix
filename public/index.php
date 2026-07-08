<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use VersionMatrix\Docker;
use VersionMatrix\State;

$root = dirname(__DIR__);
$docker = new Docker($root . '/images', $root . '/data/builds');
$state = new State($root . '/data/instances.json');

const PHP_PORT_START = 9100;
const DB_PORT_START = 9300;

function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function usedPorts(array $instances, string $field): array
{
    return array_values(array_filter(array_map(fn($i) => $i[$field] ?? null, $instances)));
}

function shortId(): string
{
    return bin2hex(random_bytes(3));
}

/** Refresh 'starting' instances in place: ping DB, flip to running/db-timeout. Persists if changed. */
function refreshInstanceStatuses(array $instances, Docker $docker, State $state): array
{
    $changed = false;
    foreach ($instances as &$instance) {
        if ($instance['status'] !== 'starting') {
            continue;
        }
        if ($docker->dbReady($instance['dbName'])) {
            $instance['status'] = 'running';
            $changed = true;
        } elseif (strtotime($instance['createdAt']) < time() - 90) {
            $instance['status'] = 'db-timeout';
            $changed = true;
        }
    }
    unset($instance);
    if ($changed) {
        $state->save($instances);
    }
    return $instances;
}

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

if ($action !== null) {
    switch ($action) {
        case 'versions':
            jsonResponse([
                'php' => Docker::PHP_VERSIONS,
                'mysql' => array_keys(Docker::MYSQL_IMAGES),
            ]);
            break;

        case 'images':
            $result = [];
            foreach (Docker::PHP_VERSIONS as $v) {
                $status = $docker->buildStatus($v);
                $result[$v] = [
                    'built' => $docker->imageExists($v),
                    'building' => $status['building'],
                ];
            }
            jsonResponse($result);
            break;

        case 'build':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'POST required'], 405);
            }
            $php = $_POST['php'] ?? '';
            if (!in_array($php, Docker::PHP_VERSIONS, true)) {
                jsonResponse(['error' => "Unknown PHP version: $php"], 400);
            }
            $docker->startBuild($php);
            jsonResponse(['started' => true]);
            break;

        case 'build_status':
            $php = $_GET['php'] ?? '';
            if (!in_array($php, Docker::PHP_VERSIONS, true)) {
                jsonResponse(['error' => "Unknown PHP version: $php"], 400);
            }
            jsonResponse($docker->buildStatus($php));
            break;

        case 'instances':
            $instances = refreshInstanceStatuses($state->load(), $docker, $state);
            jsonResponse(array_values($instances));
            break;

        case 'create_instance':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'POST required'], 405);
            }
            $phpVersion = $_POST['phpVersion'] ?? '';
            $mysqlVersion = $_POST['mysqlVersion'] ?? '';
            $folderPath = trim($_POST['folderPath'] ?? '');
            $label = trim($_POST['label'] ?? '');

            if (!in_array($phpVersion, Docker::PHP_VERSIONS, true)) {
                jsonResponse(['error' => "Unknown PHP version: $phpVersion"], 400);
            }
            if (!array_key_exists($mysqlVersion, Docker::MYSQL_IMAGES)) {
                jsonResponse(['error' => "Unknown MySQL version: $mysqlVersion"], 400);
            }
            if ($folderPath === '') {
                jsonResponse(['error' => 'folderPath is required'], 400);
            }
            $resolvedPath = realpath($folderPath);
            if ($resolvedPath === false || !is_dir($resolvedPath)) {
                jsonResponse(['error' => "Folder not found: $folderPath"], 400);
            }
            if (!$docker->imageExists($phpVersion)) {
                jsonResponse(['error' => "PHP $phpVersion image is not built yet. Build it first from the dashboard."], 409);
            }

            $instances = $state->load();
            $id = shortId();
            $network = "qlomatrix-net-$id";
            $dbName = "qlomatrix-db-$id";
            $phpName = "qlomatrix-php-$id";

            try {
                $phpPort = $docker->findFreePort(PHP_PORT_START, usedPorts($instances, 'phpPort'));
                $dbPort = $docker->findFreePort(DB_PORT_START, usedPorts($instances, 'dbPort'));

                $docker->networkCreate($network);
                $docker->startDb($dbName, $network, $mysqlVersion, $dbPort);
                $docker->startPhp($phpName, $network, $phpVersion, $phpPort, $resolvedPath);
            } catch (\Throwable $e) {
                $docker->containerRemove($phpName);
                $docker->containerRemove($dbName);
                $docker->networkRemove($network);
                jsonResponse(['error' => $e->getMessage()], 500);
            }

            $instance = [
                'id' => $id,
                'label' => $label !== '' ? $label : basename($resolvedPath),
                'phpVersion' => $phpVersion,
                'mysqlVersion' => $mysqlVersion,
                'folderPath' => $resolvedPath,
                'network' => $network,
                'dbName' => $dbName,
                'phpName' => $phpName,
                'phpPort' => $phpPort,
                'dbPort' => $dbPort,
                'status' => 'starting',
                'createdAt' => date('c'),
            ];
            $instances[] = $instance;
            $state->save($instances);
            jsonResponse($instance, 201);
            break;

        case 'stop_instance':
            if ($method !== 'POST') {
                jsonResponse(['error' => 'POST required'], 405);
            }
            $id = $_POST['id'] ?? '';
            $instances = $state->load();
            $idx = null;
            foreach ($instances as $i => $inst) {
                if ($inst['id'] === $id) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                jsonResponse(['error' => 'Instance not found'], 404);
            }
            $instance = $instances[$idx];
            $docker->containerRemove($instance['phpName']);
            $docker->containerRemove($instance['dbName']);
            $docker->networkRemove($instance['network']);
            array_splice($instances, $idx, 1);
            $state->save($instances);
            jsonResponse(['stopped' => true]);
            break;

        case 'logs':
            $id = $_GET['id'] ?? '';
            $container = ($_GET['container'] ?? 'php') === 'db' ? 'db' : 'php';
            $instances = $state->load();
            $instance = null;
            foreach ($instances as $inst) {
                if ($inst['id'] === $id) {
                    $instance = $inst;
                    break;
                }
            }
            if ($instance === null) {
                jsonResponse(['error' => 'Instance not found'], 404);
            }
            $name = $container === 'db' ? $instance['dbName'] : $instance['phpName'];
            jsonResponse(['logs' => $docker->containerLogs($name)]);
            break;

        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
    exit;
}

// ---- initial page render ----

$smarty = new Smarty();
$smarty->setTemplateDir($root . '/templates');
$smarty->setCompileDir($root . '/templates_c');

$instances = refreshInstanceStatuses($state->load(), $docker, $state);
$imageStatus = [];
foreach (Docker::PHP_VERSIONS as $v) {
    $buildStatus = $docker->buildStatus($v);
    $imageStatus[] = [
        'version' => $v,
        'built' => $docker->imageExists($v),
        'building' => $buildStatus['building'],
    ];
}

$smarty->assign('phpVersions', Docker::PHP_VERSIONS);
$smarty->assign('mysqlVersions', array_keys(Docker::MYSQL_IMAGES));
$smarty->assign('images', $imageStatus);
$smarty->assign('instances', $instances);
$smarty->display('dashboard.tpl');
