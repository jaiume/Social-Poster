<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\DAO\PostDao;
use App\DAO\ProductProfileDao;

class PipelineBuilder
{
    public const RECIPE_GENERATE_POST = 'generate_post';
    public const RECIPE_REGENERATE_IMAGE = 'regenerate_image';

    public function __construct(
        private readonly ProductProfileDao $profileDao,
        private readonly PostDao $postDao
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{key: string, label: string, status: string, meta?: array<string, mixed>}>
     */
    public function build(string $recipe, array $context): array
    {
        return match ($recipe) {
            self::RECIPE_GENERATE_POST => $this->buildGenerationSteps($context),
            self::RECIPE_REGENERATE_IMAGE => $this->buildImageOnlySteps($context),
            default => throw new \InvalidArgumentException('Unknown recipe: ' . $recipe),
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{key: string, label: string, status: string, meta?: array<string, mixed>}>
     */
    private function buildGenerationSteps(array $context): array
    {
        $profile = $this->resolveProfile($context);
        $steps = [
            $this->step('generation.content', 'Generating post content'),
        ];

        if ((int) ($profile['generate_post_image'] ?? 0) === 1) {
            $steps[] = $this->step('generation.image_prep', 'Preparing image references');
            $steps[] = $this->step('generation.image_render', 'Generating post image');
        }

        $steps[] = $this->step('generation.finalize', 'Finalizing post');

        return $steps;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array{key: string, label: string, status: string, meta?: array<string, mixed>}>
     */
    private function buildImageOnlySteps(array $context): array
    {
        $profile = $this->resolveProfile($context);
        if ((int) ($profile['generate_post_image'] ?? 0) !== 1) {
            return [];
        }

        return [
            $this->step('generation.image_prep', 'Preparing image references'),
            $this->step('generation.image_render', 'Generating post image'),
            $this->step('generation.finalize', 'Finalizing post'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveProfile(array $context): array
    {
        $profileId = (int) ($context['product_profile_id'] ?? 0);
        if ($profileId <= 0 && !empty($context['post_id'])) {
            $post = $this->postDao->findById((int) $context['post_id']);
            if ($post !== null) {
                $profileId = (int) $post['product_profile_id'];
            }
        }

        $profile = $profileId > 0 ? $this->profileDao->findById($profileId) : null;
        if ($profile === null) {
            throw new \InvalidArgumentException('Profile not found for pipeline context.');
        }

        return $profile;
    }

    /**
     * @return array{key: string, label: string, status: string}
     */
    private function step(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'pending',
        ];
    }
}
