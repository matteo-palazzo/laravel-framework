<?php

$options = getopt('', [
    'framework:',
    'vendor:',
    'scenario:',
    'rows:',
    'rules:',
]);

$framework = realpath($options['framework']);
$vendor = realpath($options['vendor']);
$scenario = $options['scenario'];
$rows = (int) $options['rows'];
$ruleCount = (int) $options['rules'];

require $vendor.'/autoload.php';

$loader = new Composer\Autoload\ClassLoader;
$loader->addPsr4('Illuminate\\', $framework.'/src/Illuminate');
$loader->register(true);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

function benchmark(callable $callback): array
{
    $start = hrtime(true);
    $result = $callback();

    return [$result, (hrtime(true) - $start) / 1_000_000];
}

function scenario(string $scenario, int $rows, int $ruleCount): array
{
    if ($scenario === 'wildcard-expansion') {
        $items = array_fill(0, $rows, ['field1' => 'value']);
        $rules = ['items' => ['array']];

        foreach (range(1, $ruleCount) as $index) {
            $rules["items.*.field{$index}"] = ['nullable', 'string'];
        }

        return [['items' => $items], $rules];
    }

    $item = [
        'sku' => 'SKU-123',
        'quantity' => 2,
        'price' => 9.99,
        'status' => 'active',
        'email' => null,
    ];

    if ($scenario === 'rule-parsing') {
        return [
            ['items' => array_fill(0, $rows, $item)],
            [
                'items.*.sku' => ['required', 'string', 'max:32'],
                'items.*.quantity' => ['required', 'integer', 'min:1'],
                'items.*.price' => ['required', 'numeric'],
                'items.*.status' => ['required', 'in:active,inactive'],
                'items.*.email' => ['nullable', 'email'],
            ],
        ];
    }

    if ($scenario !== 'flat-rule-parsing') {
        throw new InvalidArgumentException("Unknown scenario [$scenario].");
    }

    $items = array_fill(0, $rows, $item);
    $rules = [];

    foreach (array_keys($items) as $index) {
        $rules["items.{$index}.sku"] = ['required', 'string', 'max:32'];
        $rules["items.{$index}.quantity"] = ['required', 'integer', 'min:1'];
        $rules["items.{$index}.price"] = ['required', 'numeric'];
        $rules["items.{$index}.status"] = ['required', 'in:active,inactive'];
        $rules["items.{$index}.email"] = ['nullable', 'email'];
    }

    return [['items' => $items], $rules];
}

[$data, $rules] = scenario($scenario, $rows, $ruleCount);
$translator = new Translator(new ArrayLoader, 'en');

(new Validator($translator, ['value' => 'test'], ['value' => ['required', 'string']]))->passes();

memory_reset_peak_usage();

[$validator, $build] = benchmark(fn () => new Validator($translator, $data, $rules));

$buildMemory = memory_get_usage(true) / 1024 / 1024;
$buildPeak = memory_get_peak_usage(true) / 1024 / 1024;

memory_reset_peak_usage();

[$passes, $run] = benchmark(fn () => $validator->passes());

$runMemory = memory_get_usage(true) / 1024 / 1024;
$runPeak = memory_get_peak_usage(true) / 1024 / 1024;
$commit = trim(shell_exec('git -C '.escapeshellarg($framework).' describe --always --dirty'));

echo json_encode([
    'scenario' => $scenario,
    'php' => PHP_VERSION,
    'commit' => $commit,
    'rows' => $rows,
    'rules' => $ruleCount,
    'passes' => $passes,
    'build_ms' => $build,
    'run_ms' => $run,
    'total_ms' => $build + $run,
    'build_memory_mb' => $buildMemory,
    'run_memory_mb' => $runMemory,
    'build_peak_mb' => $buildPeak,
    'run_peak_mb' => $runPeak,
], JSON_THROW_ON_ERROR).PHP_EOL;
