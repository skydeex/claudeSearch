<?php

/**
 * Загрузка и применение ignore-паттернов для фильтрации файлового поиска.
 *
 * Читает: .agentignore, .claudeignore, .cursorignore, .aiderignore, .copilotignore
 * .gitignore намеренно не включён — он часто содержит генерируемые файлы
 * (dist/, build/ и т.п.), которые может понадобиться просматривать.
 *
 * node_modules, vendor, .git исключаются всегда.
 */

$_ignoreCache = null;

function loadIgnorePatterns(string $rootDir): array {
    global $_ignoreCache;
    if ($_ignoreCache !== null) return $_ignoreCache;

    $defaults = ['node_modules', 'vendor', '.git'];

    $ignoreFiles = [
        '.agentignore',
        '.claudeignore',
        '.cursorignore',
        '.aiderignore',
        '.copilotignore',
    ];

    $patterns = $defaults;
    foreach ($ignoreFiles as $file) {
        $path = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . $file;
        if (!file_exists($path)) continue;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '!') continue;
            $patterns[] = $line;
        }
    }

    $_ignoreCache = array_unique($patterns);
    return $_ignoreCache;
}

/**
 * Проверяет, должен ли относительный путь быть проигнорирован.
 *
 * Правила:
 * - Простое имя без / и без wildcards (напр. "vendor") — совпадает
 *   с любым компонентом пути
 * - Паттерн с / (напр. "dist/cache") — проверяется как префикс пути
 * - Паттерн с wildcards (* ?) — fnmatch против полного пути
 */
function isIgnoredPath(string $relPath, array $patterns): bool {
    $relPath = str_replace('\\', '/', trim($relPath, '/'));
    $parts   = explode('/', $relPath);

    foreach ($patterns as $rawPattern) {
        $pattern = str_replace('\\', '/', trim($rawPattern, '/'));
        if ($pattern === '') continue;

        $hasWild = strpos($pattern, '*') !== false || strpos($pattern, '?') !== false;
        $hasSlash = strpos($pattern, '/') !== false;

        if (!$hasWild && !$hasSlash) {
            // Простое имя — совпадает с любым компонентом пути
            if (in_array($pattern, $parts, true)) return true;
        } elseif ($hasWild) {
            // Glob-паттерн
            if (fnmatch($pattern, $relPath)) return true;
            // Попробовать против отдельных компонентов (для *.log и т.п.)
            foreach ($parts as $part) {
                if (fnmatch($pattern, $part)) return true;
            }
        } else {
            // Путь с / — проверяем как префикс
            if (strpos($relPath . '/', $pattern . '/') === 0) return true;
            if (strpos($relPath, $pattern . '/')       === 0) return true;
        }
    }
    return false;
}

/**
 * Создаёт RecursiveIteratorIterator с фильтрацией ignore-паттернов.
 * Игнорируемые директории не обходятся рекурсивно — экономия ресурсов.
 *
 * @param string $dir      Корневая директория сканирования
 * @param string $rootDir  Корень проекта (для вычисления относительных путей)
 * @param array  $patterns Список паттернов из loadIgnorePatterns()
 */
function makeFilteredIterator(string $dir, string $rootDir, array $patterns): RecursiveIteratorIterator {
    $rootReal = str_replace('\\', '/', realpath($rootDir) ?: $rootDir);

    $callback = function (\SplFileInfo $file) use ($rootReal, $patterns): bool {
        $abs = str_replace('\\', '/', $file->getPathname());
        $rel = ltrim(str_replace($rootReal, '', $abs), '/');
        return !isIgnoredPath($rel, $patterns);
    };

    $dirIter    = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
    $filterIter = new RecursiveCallbackFilterIterator($dirIter, $callback);
    return new RecursiveIteratorIterator($filterIter);
}
