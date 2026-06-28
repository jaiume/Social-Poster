<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Task;

use App\DAO\PostDao;
use App\DAO\ProductProfileDao;
use App\Services\PublishPlanBuilder;
use App\Services\Task\PipelineBuilder;
use PHPUnit\Framework\TestCase;

class PipelineBuilderTest extends TestCase
{
    public function testGeneratePostIncludesImageStepsWhenEnabled(): void
    {
        $profileDao = $this->createMock(ProductProfileDao::class);
        $profileDao->method('findById')->willReturn([
            'id' => 1,
            'generate_post_image' => 1,
        ]);

        $builder = new PipelineBuilder(
            $profileDao,
            $this->createMock(PostDao::class),
            $this->createMock(PublishPlanBuilder::class)
        );

        $steps = $builder->build(PipelineBuilder::RECIPE_GENERATE_POST, [
            'product_profile_id' => 1,
            'schedule_date' => '2026-06-19',
        ]);

        $keys = array_column($steps, 'key');
        $this->assertSame(
            ['generation.content', 'generation.image_prep', 'generation.image_render', 'generation.finalize'],
            $keys
        );
    }

    public function testGeneratePostOmitsImageStepsWhenDisabled(): void
    {
        $profileDao = $this->createMock(ProductProfileDao::class);
        $profileDao->method('findById')->willReturn([
            'id' => 1,
            'generate_post_image' => 0,
        ]);

        $builder = new PipelineBuilder(
            $profileDao,
            $this->createMock(PostDao::class),
            $this->createMock(PublishPlanBuilder::class)
        );

        $steps = $builder->build(PipelineBuilder::RECIPE_GENERATE_POST, [
            'product_profile_id' => 1,
            'schedule_date' => '2026-06-19',
        ]);

        $this->assertSame(['generation.content', 'generation.finalize'], array_column($steps, 'key'));
    }

    public function testRegenerateImageBuildsImageOnlySteps(): void
    {
        $profileDao = $this->createMock(ProductProfileDao::class);
        $profileDao->method('findById')->willReturn([
            'id' => 1,
            'generate_post_image' => 1,
        ]);

        $builder = new PipelineBuilder(
            $profileDao,
            $this->createMock(PostDao::class),
            $this->createMock(PublishPlanBuilder::class)
        );

        $steps = $builder->build(PipelineBuilder::RECIPE_REGENERATE_IMAGE, [
            'product_profile_id' => 1,
            'post_id' => 5,
        ]);

        $this->assertSame(
            ['generation.image_prep', 'generation.image_render', 'generation.finalize'],
            array_column($steps, 'key')
        );
    }

    public function testRegenerateImageReturnsNoStepsWhenDisabled(): void
    {
        $profileDao = $this->createMock(ProductProfileDao::class);
        $profileDao->method('findById')->willReturn([
            'id' => 1,
            'generate_post_image' => 0,
        ]);

        $builder = new PipelineBuilder(
            $profileDao,
            $this->createMock(PostDao::class),
            $this->createMock(PublishPlanBuilder::class)
        );

        $steps = $builder->build(PipelineBuilder::RECIPE_REGENERATE_IMAGE, [
            'product_profile_id' => 1,
            'post_id' => 5,
        ]);

        $this->assertSame([], $steps);
    }

    public function testPublishPostBuildsDynamicPublishingSteps(): void
    {
        $planBuilder = $this->createMock(PublishPlanBuilder::class);
        $planBuilder->method('build')->willReturn([
            ['label' => 'Posting to Facebook', 'platform' => 'facebook', 'action' => 'post', 'session_account_id' => 1],
        ]);

        $builder = new PipelineBuilder(
            $this->createMock(ProductProfileDao::class),
            $this->createMock(PostDao::class),
            $planBuilder
        );

        $steps = $builder->build(PipelineBuilder::RECIPE_PUBLISH_POST, [
            'post_id' => 5,
            'product_profile_id' => 1,
        ]);

        $this->assertCount(1, $steps);
        $this->assertSame('publishing.facebook.post', $steps[0]['key']);
        $this->assertSame(1, $steps[0]['meta']['session_account_id']);
    }
}
