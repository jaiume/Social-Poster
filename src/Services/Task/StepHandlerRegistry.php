<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\Services\Task\StepHandlers\GenerationContentStepHandler;
use App\Services\Task\StepHandlers\GenerationFinalizeStepHandler;
use App\Services\Task\StepHandlers\GenerationImagePrepStepHandler;
use App\Services\Task\StepHandlers\GenerationImageRenderStepHandler;

class StepHandlerRegistry
{
    public function __construct(
        private readonly GenerationContentStepHandler $generationContent,
        private readonly GenerationImagePrepStepHandler $generationImagePrep,
        private readonly GenerationImageRenderStepHandler $generationImageRender,
        private readonly GenerationFinalizeStepHandler $generationFinalize
    ) {
    }

    /**
     * @param array<string, mixed> $job
     * @param array{key: string, label: string, status: string, meta?: array<string, mixed>} $step
     * @return array{success: bool, error?: string, data?: array<string, mixed>}
     */
    public function handle(string $jobId, array $job, array $step): array
    {
        return match ($step['key']) {
            'generation.content' => $this->generationContent->handle($jobId, $job, $step),
            'generation.image_prep' => $this->generationImagePrep->handle($jobId, $job, $step),
            'generation.image_render' => $this->generationImageRender->handle($jobId, $job, $step),
            'generation.finalize' => $this->generationFinalize->handle($jobId, $job, $step),
            default => ['success' => false, 'error' => 'Unknown step: ' . $step['key']],
        };
    }
}
