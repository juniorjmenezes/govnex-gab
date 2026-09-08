<?php

use Illuminate\Support\Facades\File;

test('application screens use the shared shadcn select instead of native selects', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'tsx')
        ->reject(fn (SplFileInfo $file): bool => str_contains(
            $file->getPathname(),
            DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'ui'.DIRECTORY_SEPARATOR,
        ))
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/<(select|option)\b/',
            $file->getContents(),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('application buttons do not override the preset radius', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'tsx')
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/<Button\b[^>]*className="[^"]*\brounded-md\b[^"]*"/s',
            $file->getContents(),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});
test('scrollable dialogs keep overflow off the rounded dialog shell', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'tsx')
        ->reject(fn (SplFileInfo $file): bool => str_contains(
            $file->getPathname(),
            DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.'common'.DIRECTORY_SEPARATOR.'scrollable-dialog.tsx',
        ))
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/<DialogContent\b[^>]*\boverflow-y-auto\b[^>]*>/s',
            $file->getContents(),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('select triggers do not override the preset radius', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'tsx')
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/<SelectTrigger\b[^>]*className="[^"]*\brounded-md\b[^"]*"/s',
            $file->getContents(),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});
test('labels do not combine block layout with vertical space utilities', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'tsx')
        ->filter(fn (SplFileInfo $file): bool => preg_match(
            '/<Label\b[^>]*className="[^"]*\bblock\b[^"]*\bspace-y-/s',
            $file->getContents(),
        ) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('date and time inputs are presented as separate fields', function () {
    $violations = collect(File::allFiles(resource_path('js')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'tsx')
        ->filter(fn (SplFileInfo $file): bool => str_contains(
            $file->getContents(),
            'datetime-local',
        ))
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});
