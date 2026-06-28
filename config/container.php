<?php

declare(strict_types=1);

use App\Controllers\Web\AuthController;
use App\Controllers\Web\PostController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\SessionController;
use App\Controllers\Web\SettingsController;
use App\Controllers\Web\TaskController;
use App\DAO\AppSettingsDao;
use App\DAO\Database;
use App\DAO\BrowserSessionDao;
use App\DAO\PostDao;
use App\DAO\PostPublicationDao;
use App\DAO\PublicationAttemptStateDao;
use App\DAO\ProductProfileDao;
use App\DAO\ProfilePostingAccountDao;
use App\DAO\ProfileRepostAccountDao;
use App\DAO\ProfileSourceDao;
use App\DAO\SessionAccountDao;
use App\DAO\TaskJobDao;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Services\AppSettingsService;
use App\Services\AuthService;
use App\Services\BrowserAutomationService;
use App\Services\ConfigService;
use App\Services\ContentGenerationService;
use App\Services\EncryptionService;
use App\Services\FetchAgentToolkit;
use App\Services\ImageGuidanceResolver;
use App\Services\ImageGenerationService;
use App\Services\OpenRouterClient;
use App\Services\BrowserSessionService;
use App\Services\PostingOrchestratorService;
use App\Services\PosterAutomationService;
use App\Services\ProfileAccountService;
use App\Services\PublishPlanBuilder;
use App\Services\PostingSchedulerService;
use App\Services\PostWorkflowService;
use App\Services\ProductProfileService;
use App\Services\SessionAccountService;
use App\Services\SessionCaptureService;
use App\Services\SourceFetchService;
use App\Services\Task\PipelineBuilder;
use App\Services\Task\StepHandlerRegistry;
use App\Services\Task\StepHandlers\GenerationContentStepHandler;
use App\Services\Task\StepHandlers\GenerationFinalizeStepHandler;
use App\Services\Task\StepHandlers\GenerationImagePrepStepHandler;
use App\Services\Task\StepHandlers\GenerationImageRenderStepHandler;
use App\Services\Task\StepHandlers\PublishingStepHandler;
use App\Services\Task\TaskEngine;
use App\Services\Task\TaskJobContext;
use App\Services\Task\TaskJobPipelineExtender;
use App\Services\Task\TaskJobRecovery;
use App\Services\Task\TaskWorkerService;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;

$containerBuilder = new ContainerBuilder();

$containerBuilder->addDefinitions([
    Database::class => DI\factory(fn () => new Database()),

    EncryptionService::class => DI\factory(fn () => new EncryptionService()),

    ProductProfileDao::class => DI\factory(function (ContainerInterface $c) {
        return new ProductProfileDao($c->get(Database::class)->getConnection());
    }),
    ProfileSourceDao::class => DI\factory(function (ContainerInterface $c) {
        return new ProfileSourceDao($c->get(Database::class)->getConnection());
    }),
    SessionAccountDao::class => DI\factory(function (ContainerInterface $c) {
        return new SessionAccountDao($c->get(Database::class)->getConnection());
    }),
    ProfilePostingAccountDao::class => DI\factory(function (ContainerInterface $c) {
        return new ProfilePostingAccountDao($c->get(Database::class)->getConnection());
    }),
    ProfileRepostAccountDao::class => DI\factory(function (ContainerInterface $c) {
        return new ProfileRepostAccountDao($c->get(Database::class)->getConnection());
    }),
    AppSettingsDao::class => DI\factory(function (ContainerInterface $c) {
        return new AppSettingsDao($c->get(Database::class)->getConnection());
    }),
    BrowserSessionDao::class => DI\factory(function (ContainerInterface $c) {
        return new BrowserSessionDao($c->get(Database::class)->getConnection());
    }),
    PostDao::class => DI\factory(function (ContainerInterface $c) {
        return new PostDao($c->get(Database::class)->getConnection());
    }),
    PostPublicationDao::class => DI\factory(function (ContainerInterface $c) {
        return new PostPublicationDao($c->get(Database::class)->getConnection());
    }),
    PublicationAttemptStateDao::class => DI\factory(function (ContainerInterface $c) {
        return new PublicationAttemptStateDao($c->get(Database::class)->getConnection());
    }),
    TaskJobDao::class => DI\factory(function (ContainerInterface $c) {
        return new TaskJobDao($c->get(Database::class)->getConnection());
    }),

    AppSettingsService::class => DI\factory(function (ContainerInterface $c) {
        return new AppSettingsService(
            $c->get(AppSettingsDao::class),
            $c->get(EncryptionService::class)
        );
    }),
    AuthService::class => DI\create(AuthService::class),
    SessionAccountService::class => DI\autowire(SessionAccountService::class),
    ProfileAccountService::class => DI\autowire(ProfileAccountService::class),
    ProductProfileService::class => DI\autowire(ProductProfileService::class),
    PostingSchedulerService::class => DI\autowire(PostingSchedulerService::class),
    SourceFetchService::class => DI\create(SourceFetchService::class),
    FetchAgentToolkit::class => DI\autowire(FetchAgentToolkit::class),
    OpenRouterClient::class => DI\autowire(OpenRouterClient::class),
    ImageGuidanceResolver::class => DI\autowire(ImageGuidanceResolver::class),
    ImageGenerationService::class => DI\autowire(ImageGenerationService::class),
    ContentGenerationService::class => DI\autowire(ContentGenerationService::class),
    BrowserSessionService::class => DI\autowire(BrowserSessionService::class),
    SessionCaptureService::class => DI\autowire(SessionCaptureService::class),
    BrowserAutomationService::class => DI\autowire(BrowserAutomationService::class),
    PosterAutomationService::class => DI\autowire(PosterAutomationService::class),
    PublishPlanBuilder::class => DI\autowire(PublishPlanBuilder::class),
    PostingOrchestratorService::class => DI\autowire(PostingOrchestratorService::class),
    PostWorkflowService::class => DI\autowire(PostWorkflowService::class),

    TaskJobContext::class => DI\autowire(TaskJobContext::class),
    PipelineBuilder::class => DI\autowire(PipelineBuilder::class),
    TaskJobPipelineExtender::class => DI\autowire(TaskJobPipelineExtender::class),
    TaskJobRecovery::class => DI\factory(function (ContainerInterface $c) {
        return new TaskJobRecovery(
            $c->get(TaskJobDao::class),
        );
    }),
    TaskEngine::class => DI\autowire(TaskEngine::class),
    TaskWorkerService::class => DI\autowire(TaskWorkerService::class),
    GenerationContentStepHandler::class => DI\autowire(GenerationContentStepHandler::class),
    GenerationImagePrepStepHandler::class => DI\autowire(GenerationImagePrepStepHandler::class),
    GenerationImageRenderStepHandler::class => DI\autowire(GenerationImageRenderStepHandler::class),
    GenerationFinalizeStepHandler::class => DI\autowire(GenerationFinalizeStepHandler::class),
    PublishingStepHandler::class => DI\autowire(PublishingStepHandler::class),
    StepHandlerRegistry::class => DI\autowire(StepHandlerRegistry::class),

    Twig::class => function () {
        $twig = Twig::create(BASE_DIR . '/templates', [
            'cache' => ConfigService::get('app.debug', false) ? false : BASE_DIR . '/var/cache/twig',
            'auto_reload' => (bool) ConfigService::get('app.debug', false),
        ]);
        $twig->getEnvironment()->addGlobal('app_name', ConfigService::get('app.name', 'Social-Poster'));

        return $twig;
    },

    App::class => function (ContainerInterface $container) {
        $app = AppFactory::createFromContainer($container);
        $displayErrorDetails = (bool) ConfigService::get('app.debug', false);
        $app->addErrorMiddleware($displayErrorDetails, true, true);

        return $app;
    },
]);

return $containerBuilder->build();
