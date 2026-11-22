#!/usr/bin/env php
<?php

// Запуск через веб запрещён.
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('CLI only');
}

date_default_timezone_set('Europe/Moscow');

// Настройки очистки БД.
$keepDays = 180;
$batchSize = 1000;
$pauseMicroseconds = 200000; // 0,2 секунды между порциями.

// Временный каталог: очищается полностью, независимо от возраста файлов.
$mp3Directory = __DIR__ . '/mp3';

$lockFile = '/var/lock/qstat-cleanup.lock';

$tables = array(
    'queuelog'          => 'time',
    'cc_calls_report'   => 'call_time',
    'cc_outbound_calls' => 'call_time'
);

function cleanupLog($message)
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function cleanupTemporaryDirectory($directory, $execute)
{
    $result = array(
        'files'  => 0,
        'dirs'   => 0,
        'errors' => 0
    );

    if (is_link($directory)) {
        throw new Exception(
            'Временный каталог не должен быть символической ссылкой: '
            . $directory
        );
    }

    if (!is_dir($directory)) {
        throw new Exception(
            'Временный каталог не существует: ' . $directory
        );
    }

    $root = realpath($directory);
    $projectRoot = realpath(__DIR__);

    // Разрешена очистка только каталога mp3 внутри проекта.
    if ($root === false
        || $projectRoot === false
        || $root !== $projectRoot . DIRECTORY_SEPARATOR . 'mp3') {
        throw new Exception(
            'Отказ от очистки: неожиданный путь каталога ' . $directory
        );
    }

    cleanupLog(
        'TMP START; directory=' . $root
        . '; mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN')
    );

    // Сначала содержимое вложенных каталогов, затем сами каталоги.
    // Переход по символическим ссылкам не включён.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();

        // Ссылки удаляются как ссылки, без очистки их целевого содержимого.
        $isDirectory = !$item->isLink() && $item->isDir();

        if (!$execute) {
            cleanupLog(
                'TMP WOULD DELETE '
                . ($isDirectory ? 'DIR: ' : 'FILE/LINK: ')
                . $path
            );

            if ($isDirectory) {
                $result['dirs']++;
            } else {
                $result['files']++;
            }

            continue;
        }

        $success = $isDirectory
            ? @rmdir($path)
            : @unlink($path);

        if (!$success) {
            cleanupLog('TMP ERROR: не удалось удалить ' . $path);
            $result['errors']++;
            continue;
        }

        if ($isDirectory) {
            $result['dirs']++;
        } else {
            $result['files']++;
        }

        cleanupLog('TMP DELETED: ' . $path);
    }

    cleanupLog(
        'TMP FINISH; '
        . ($execute ? 'deleted_files=' : 'would_delete_files=')
        . $result['files']
        . '; '
        . ($execute ? 'deleted_dirs=' : 'would_delete_dirs=')
        . $result['dirs']
        . '; errors=' . $result['errors']
    );

    return $result;
}

// По умолчанию — только проверка.
$execute = false;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--execute') {
        $execute = true;
    } elseif ($argument === '--dry-run') {
        // Проверочный режим и так включён по умолчанию.
    } else {
        fwrite(STDERR, 'Неизвестный аргумент: ' . $argument . PHP_EOL);
        exit(1);
    }
}

if (in_array('--execute', $argv, true)
    && in_array('--dry-run', $argv, true)) {
    fwrite(
        STDERR,
        'Нельзя одновременно указать --execute и --dry-run.' . PHP_EOL
    );
    exit(1);
}

if (!is_int($keepDays) || $keepDays < 1
    || !is_int($batchSize) || $batchSize < 1
    || !is_int($pauseMicroseconds) || $pauseMicroseconds < 0) {
    fwrite(STDERR, 'Некорректные настройки очистки.' . PHP_EOL);
    exit(1);
}

// Файл блокировки не удаляем после завершения.
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false) {
    fwrite(
        STDERR,
        'Не удалось открыть файл блокировки: ' . $lockFile . PHP_EOL
    );
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    cleanupLog('Другой экземпляр уже работает. Выход.');
    fclose($lockHandle);
    exit(0);
}

$exitCode = 0;
$startedAt = microtime(true);

try {
    require __DIR__ . '/qstat.inc.php';

    if (!isset($dsn, $dbUser, $dbPass)) {
        throw new Exception(
            'В qstat.inc.php должны быть определены $dsn, $dbUser и $dbPass.'
        );
    }

    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false
        )
    );

    // Не допускаем очистку случайно выбранной другой БД.
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();

    if ($database !== 'asteriskcdrdb') {
        throw new Exception(
            'Подключение должно быть к asteriskcdrdb; текущая БД: '
            . (string) $database
        );
    }

    // Для TIMESTAMP задаём часовой пояс сессии.
    // Для DATETIME значения должны храниться в этом же локальном времени.
    $pdo->exec("SET SESSION time_zone = '+03:00'");

    $cutoffDate = new DateTime('today');
    $cutoffDate->modify('-' . $keepDays . ' days');
    $cutoff = $cutoffDate->format('Y-m-d H:i:s');

    cleanupLog(
        'START; mode=' . ($execute ? 'EXECUTE' : 'DRY-RUN')
        . '; keep_days=' . $keepDays
        . '; cutoff=' . $cutoff
        . '; batch_size=' . $batchSize
        . '; tmp_directory=' . $mp3Directory
    );

    // Сначала проверяем доступность всех таблиц и колонок.
    foreach ($tables as $table => $column) {
        $pdo->query(
            'SELECT `' . $column . '` FROM `' . $table . '` LIMIT 0'
        );
    }

    $grandTotal = 0;

    foreach ($tables as $table => $column) {
        $where = '`' . $column . '` < :cutoff';

        if (!$execute) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where
            );
            $stmt->execute(array(':cutoff' => $cutoff));

            $count = (int) $stmt->fetchColumn();
            $grandTotal += $count;

            cleanupLog($table . ': к удалению ' . $count . ' строк');
            continue;
        }

        $sql = 'DELETE FROM `' . $table . '`'
             . ' WHERE ' . $where
             . ' ORDER BY `' . $column . '` ASC'
             . ' LIMIT ' . $batchSize;

        $stmt = $pdo->prepare($sql);
        $tableTotal = 0;

        do {
            $stmt->execute(array(':cutoff' => $cutoff));
            $deleted = $stmt->rowCount();

            $tableTotal += $deleted;

            if ($deleted > 0) {
                cleanupLog(
                    $table . ': удалено ' . $deleted
                    . '; всего по таблице ' . $tableTotal
                );

                if ($pauseMicroseconds > 0) {
                    usleep($pauseMicroseconds);
                }
            }
        } while ($deleted > 0);

        $grandTotal += $tableTotal;

        cleanupLog(
            $table . ': завершено; удалено ' . $tableTotal . ' строк'
        );
    }

    // Полностью очищаем временный каталог.
    // Срок хранения БД здесь не используется.
    $fileResult = cleanupTemporaryDirectory(
        $mp3Directory,
        $execute
    );

    if ($fileResult['errors'] > 0) {
        $exitCode = 1;
    }

    cleanupLog(
        'FINISH; '
        . ($execute ? 'db_deleted=' : 'db_would_delete=')
        . $grandTotal
        . '; '
        . ($execute ? 'tmp_files_deleted=' : 'tmp_files_would_delete=')
        . $fileResult['files']
        . '; '
        . ($execute ? 'tmp_dirs_deleted=' : 'tmp_dirs_would_delete=')
        . $fileResult['dirs']
        . '; tmp_errors=' . $fileResult['errors']
        . '; seconds='
        . number_format(microtime(true) - $startedAt, 2, '.', '')
    );
} catch (Exception $e) {
    fwrite(
        STDERR,
        '[' . date('Y-m-d H:i:s') . '] ERROR: '
        . $e->getMessage() . PHP_EOL
    );

    $exitCode = 1;
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

exit($exitCode);
