<?php

declare(strict_types=1);

/*
 * What phil/dev-tools reads of this package: its paths, its fuzz target and its exceptions to the shared configurations.
 */
return [
    'paths' => ['src', 'tests'],
    'phpstan' => [
        // The fuzz targets run inside nikic/php-fuzzer, which gives them `$config`; PHPStan knows neither.
        'excludePaths' => ['tests/Fuzz/*'],
    ],
    'infection' => [
        'mutators' => [
            // `preg_replace_callback()` and `stream_get_contents()` give `null` or `false` only on an error that the
            // patterns and the memory stream cannot meet: the cast to a string changes nothing that can be observed.
            'CastString' => [
                'ignoreSourceCodeByRegex' => [
                    '.*\\(string\\) preg_replace_callback\\(.*',
                    '.*\\(string\\) stream_get_contents\\(.*',
                ],
            ],
        ],
    ],
    'fuzz' => [
        // The pattern matcher reads what an administrator types and what every request not found carries.
        'targets' => ['pattern' => 'tests/Fuzz/pattern.php'],
        'budget' => 60,
        'corpus' => 'tests/Fuzz/corpus',
    ],
];
