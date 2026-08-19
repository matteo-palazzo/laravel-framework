<?php

$options = getopt('', [
    'framework::',
    'vendor::',
    'scenario::',
    'rows::',
    'rules::',
    'iterations::',
    'format::',
]);

$framework = realpath($options['framework'] ?? dirname(__DIR__, 3));
$vendor = realpath($options['vendor'] ?? $framework.'/vendor');
$scenario = $options['scenario'] ?? 'flat-rule-parsing';
$rows = array_map('intval', explode(',', $options['rows'] ?? '500,1000,3000,7000'));
$ruleCount = (int) ($options['rules'] ?? 17);
$iterations = (int) ($options['iterations'] ?? 3);
$format = $options['format'] ?? 'table';
$worker = __DIR__.'/worker.php';
$results = [];

foreach ($rows as $rowCount) {
    $samples = [];

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $command = [
            PHP_BINARY,
            $worker,
            '--framework='.$framework,
            '--vendor='.$vendor,
            '--scenario='.$scenario,
            '--rows='.$rowCount,
            '--rules='.$ruleCount,
        ];

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            fwrite(STDERR, $stderr);
            exit($exitCode);
        }

        $samples[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    }

    $result = $samples[0];

    foreach (['build_ms', 'run_ms', 'total_ms', 'build_memory_mb', 'run_memory_mb', 'build_peak_mb', 'run_peak_mb'] as $metric) {
        $result[$metric] = array_sum(array_column($samples, $metric)) / count($samples);
    }

    $result['iterations'] = $iterations;
    $results[] = $result;
}

if ($format === 'json') {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

    exit;
}

printf(
    "PHP %s | Laravel %s | %s | %d iteration(s)\n\n",
    $results[0]['php'],
    $results[0]['commit'],
    $scenario,
    $iterations,
);

printf("%7s %10s %10s %10s %10s %10s %14s %12s\n", 'Rows', 'Build ms', 'Run ms', 'Total ms', 'Build MB', 'Run MB', 'Build peak MB', 'Run peak MB');

foreach ($results as $result) {
    printf(
        "%7d %10.2f %10.2f %10.2f %10.2f %10.2f %14.2f %12.2f\n",
        $result['rows'],
        $result['build_ms'],
        $result['run_ms'],
        $result['total_ms'],
        $result['build_memory_mb'],
        $result['run_memory_mb'],
        $result['build_peak_mb'],
        $result['run_peak_mb'],
    );
}
