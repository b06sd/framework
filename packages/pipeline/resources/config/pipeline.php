<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Pipelines are discovered as app/Pipelines/*.php (class App\Pipelines\<File>). Add other pipeline
// classes to the list by hand if you keep them elsewhere. `job` is the one job every pipeline shares
// (written once by `trunk make:pipeline`, under app/Jobs so the queue's own discovery finds it).
return static function (Runtime $runtime): array {
    $pipelines = [];

    foreach (glob($runtime->basePath . '/app/Pipelines/*.php') ?: [] as $file) {
        $pipelines[] = 'App\\Pipelines\\' . basename($file, '.php');
    }

    return [
        'pipelines' => $pipelines,
        'table' => 'trunk_pipeline_runs',
        'job' => 'App\\Jobs\\RunPipelineChunk',
    ];
};
