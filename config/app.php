<?php

declare(strict_types=1);

/*
 * Inspector settings. Nothing here is read from the inspected project.
 */
return [
    'name' => 'Laravel Project Inspector',
    'version' => '0.1.0',

    // Directory used for saved results, rendered reports and the audit log,
    // relative to the directory the command is run from.
    'output_directory' => 'reports',

    // Seconds before an external command (composer, node, ...) is killed.
    'process_timeout' => 60,

    // Composer audit/outdated contact Packagist and can be slow.
    'network_process_timeout' => 180,

    // Files larger than this are never read from the target project.
    // composer.lock / installed.json of large projects can exceed 2 MB.
    'max_file_bytes' => 8 * 1024 * 1024,

    // Directories skipped by every source scan.
    'excluded_directories' => [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        'public/build',
        'public/hot',
        '.git',
        '.idea',
        '.vscode',
        '.phpunit.cache',
    ],
];
