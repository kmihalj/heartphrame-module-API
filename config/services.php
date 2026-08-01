<?php

declare(strict_types=1);

use AaiEduHr\HeartPhrameModuleApi\Controller\ApiKeyController;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiKeyRequestController;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiPreflightController;
use AaiEduHr\HeartPhrameModuleApi\Account\ApiKeyAccountSectionProvider;
use AaiEduHr\HeartPhrameModuleApi\Controller\ApiRootController;
use AaiEduHr\HeartPhrameModuleApi\Controller\AuditResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\AuthResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\CalendarResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\EditorHtmlResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\MeController;
use AaiEduHr\HeartPhrameModuleApi\Controller\NotificationResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\OpenApiController;
use AaiEduHr\HeartPhrameModuleApi\Controller\TaskResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\WorkspaceResourceController;
use AaiEduHr\HeartPhrameModuleApi\Controller\WebhookResourceController;
use AaiEduHr\HeartPhrameModuleApi\Command\HpApiCommand;
use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiAuthenticationMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Middleware\ApiCorsMiddleware;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCorsRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiEntityTagService;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiMenuIntegration;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestNotifier;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiKeyRequestService;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiModuleViewRenderer;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiRequestGuard;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiScopeRegistry;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiWebhookPublisher;
use AaiEduHr\HeartPhrameModuleApi\Service\CalendarApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\EditorHtmlApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\NotificationApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\OpenApiDocumentService;
use AaiEduHr\HeartPhrameModuleApi\Service\TaskApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\WorkspaceApiRouteRegistrar;
use AaiEduHr\HeartPhrameModuleApi\Service\StreamWebhookTransport;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookConfig;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookOutboxWorker;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookSubscriptionService;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookTargetPolicy;
use AaiEduHr\HeartPhrameModuleApi\Service\WebhookTransportInterface;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAdministrationApiService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthApiKeyService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAuditLogService;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthUserService;
use AaiEduHr\HeartPhrameModuleCalendar\Api\CalendarApiService;
use AaiEduHr\HeartPhrameModuleEditorHtml\Api\EditorHtmlApiService;
use AaiEduHr\HeartPhrameModuleNotification\Service\NotificationService;
use AaiEduHr\HeartPhrameModuleTask\Api\TaskApiService;
use AaiEduHr\HeartPhrameModuleWorkspace\Api\WorkspaceApiService;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use HeartPhrame\Alert\AlertHandler;
use HeartPhrame\Authn\AuthnHandlerInterface;
use HeartPhrame\Bridge\ComposerBridge;
use HeartPhrame\Config\ConfigInterface;
use HeartPhrame\Http\ResponseFactory;
use HeartPhrame\Encryption\EncryptionInterface;
use HeartPhrame\Routing\Routes;
use HeartPhrame\Routing\UrlGenerator;
use HeartPhrame\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    HpApiCommand::class => static fn(ContainerInterface $container): HpApiCommand =>
        new HpApiCommand(
            $container->get(ConfigInterface::class),
            $container->get(WebhookOutboxWorker::class),
        ),

    ApiScopeRegistry::class => static fn(ContainerInterface $container): ApiScopeRegistry =>
        new ApiScopeRegistry(
            $container->get(ComposerBridge::class),
            $container->get(ConfigInterface::class),
        ),

    ApiEntityTagService::class => static fn(ContainerInterface $container): ApiEntityTagService =>
        new ApiEntityTagService($container->get(ConfigInterface::class)),

    ApiResponseFactory::class => static fn(ContainerInterface $container): ApiResponseFactory =>
        new ApiResponseFactory(
            $container->get(ResponseFactory::class),
            $container->get(ApiEntityTagService::class),
        ),

    ApiCursorPaginator::class => static fn(): ApiCursorPaginator => new ApiCursorPaginator(),

    ApiRequestGuard::class => static fn(ContainerInterface $container): ApiRequestGuard =>
        new ApiRequestGuard(
            $container->get(Database::class),
            $container->get(ConfigInterface::class),
            $container->get(ApiResponseFactory::class),
            $container->get(ResponseFactory::class),
            $container->get(LoggerInterface::class),
        ),

    ApiAuthenticationMiddleware::class => static fn(
        ContainerInterface $container,
    ): ApiAuthenticationMiddleware => new ApiAuthenticationMiddleware(
        $container->get(AuthApiKeyService::class),
        $container->get(ApiResponseFactory::class),
        $container->get(ApiRequestGuard::class),
        $container->get(ApiWebhookPublisher::class),
    ),

    WebhookConfig::class => static fn(ContainerInterface $container): WebhookConfig =>
        new WebhookConfig($container->get(ConfigInterface::class)),

    WebhookTargetPolicy::class => static fn(ContainerInterface $container): WebhookTargetPolicy =>
        new WebhookTargetPolicy($container->get(WebhookConfig::class)),

    WebhookTransportInterface::class => static fn(): WebhookTransportInterface =>
        new StreamWebhookTransport(),

    WebhookSubscriptionService::class =>
        static fn(ContainerInterface $container): WebhookSubscriptionService =>
            new WebhookSubscriptionService(
                $container->get(Database::class),
                $container->get(EncryptionInterface::class),
                $container->get(WebhookTargetPolicy::class),
                $container->get(WebhookConfig::class),
            ),

    WebhookOutboxWorker::class => static fn(ContainerInterface $container): WebhookOutboxWorker =>
        new WebhookOutboxWorker(
            $container->get(Database::class),
            $container->get(WebhookSubscriptionService::class),
            $container->get(WebhookTargetPolicy::class),
            $container->get(WebhookTransportInterface::class),
            $container->get(WebhookConfig::class),
        ),

    ApiWebhookPublisher::class => static fn(ContainerInterface $container): ApiWebhookPublisher =>
        new ApiWebhookPublisher($container->get(WebhookSubscriptionService::class)),

    ApiCorsMiddleware::class => static fn(ContainerInterface $container): ApiCorsMiddleware =>
        new ApiCorsMiddleware(
            $container->get(ConfigInterface::class),
            $container->get(ApiResponseFactory::class),
        ),

    ApiCorsRouteRegistrar::class => static fn(ContainerInterface $container): ApiCorsRouteRegistrar =>
        new ApiCorsRouteRegistrar($container->get(Routes::class)),

    OpenApiDocumentService::class => static fn(ContainerInterface $container): OpenApiDocumentService =>
        new OpenApiDocumentService(
            $container->get(Routes::class),
            $container->get(ApiScopeRegistry::class),
        ),

    ApiModuleViewRenderer::class => static fn(ContainerInterface $container): ApiModuleViewRenderer =>
        new ApiModuleViewRenderer(
            $container->get(ResponseFactory::class),
            $container->get(ConfigInterface::class),
        ),

    ApiMenuIntegration::class => static fn(ContainerInterface $container): ApiMenuIntegration =>
        new ApiMenuIntegration($container),

    ApiKeyRequestService::class => static fn(ContainerInterface $container): ApiKeyRequestService =>
        new ApiKeyRequestService(
            $container->get(Database::class),
            $container->get(AuthApiKeyService::class),
            $container->get(AuthUserService::class),
            $container->get(EncryptionInterface::class),
        ),

    ApiKeyRequestNotifier::class => static fn(ContainerInterface $container): ApiKeyRequestNotifier =>
        new ApiKeyRequestNotifier(
            $container,
            $container->get(AuthUserService::class),
            $container->get(UrlGenerator::class),
        ),

    ApiKeyAccountSectionProvider::class =>
        static fn(ContainerInterface $container): ApiKeyAccountSectionProvider =>
            new ApiKeyAccountSectionProvider(
                $container->get(ApiKeyRequestService::class),
                $container->get(ApiScopeRegistry::class),
                $container->get(UrlGenerator::class),
            ),

    ApiRootController::class => static fn(ContainerInterface $container): ApiRootController =>
        new ApiRootController(
            $container->get(ApiResponseFactory::class),
            $container->get(ApiScopeRegistry::class),
            $container->get(WebhookConfig::class),
        ),

    MeController::class => static fn(ContainerInterface $container): MeController =>
        new MeController($container->get(ApiResponseFactory::class)),

    OpenApiController::class => static fn(ContainerInterface $container): OpenApiController =>
        new OpenApiController(
            $container->get(OpenApiDocumentService::class),
            $container->get(ResponseFactory::class),
        ),

    ApiPreflightController::class => static fn(ContainerInterface $container): ApiPreflightController =>
        new ApiPreflightController($container->get(ResponseFactory::class)),

    AuditResourceController::class => static fn(ContainerInterface $container): AuditResourceController =>
        new AuditResourceController(
            $container->get(ApiResponseFactory::class),
            $container->get(AuthAuditLogService::class),
            $container->get(ApiCursorPaginator::class),
        ),

    AuthResourceController::class => static fn(ContainerInterface $container): AuthResourceController =>
        new AuthResourceController(
            $container->get(ApiResponseFactory::class),
            $container->get(AuthAdministrationApiService::class),
            $container->get(ApiCursorPaginator::class),
            $container->get(ApiEntityTagService::class),
        ),

    CalendarResourceController::class => static fn(ContainerInterface $container): CalendarResourceController =>
        new CalendarResourceController(
            $container->get(ApiResponseFactory::class),
            $container->get(ResponseFactory::class),
            $container->get(CalendarApiService::class),
            $container->get(ApiCursorPaginator::class),
            $container->get(ApiEntityTagService::class),
        ),

    WorkspaceResourceController::class => static fn(ContainerInterface $container): WorkspaceResourceController =>
        new WorkspaceResourceController(
            $container->get(ApiResponseFactory::class),
            $container->get(WorkspaceApiService::class),
            $container->get(ConfigInterface::class),
            $container->get(ApiCursorPaginator::class),
            $container->get(ApiEntityTagService::class),
        ),

    EditorHtmlResourceController::class => static fn(ContainerInterface $container): EditorHtmlResourceController =>
        new EditorHtmlResourceController(
            $container->get(ApiResponseFactory::class),
            $container->get(ResponseFactory::class),
            $container->get(EditorHtmlApiService::class),
            $container->get(ConfigInterface::class),
            $container->get(ApiCursorPaginator::class),
            $container->get(ApiEntityTagService::class),
        ),

    TaskResourceController::class => static fn(ContainerInterface $container): TaskResourceController =>
        new TaskResourceController(
            $container->get(ApiResponseFactory::class),
            $container->get(TaskApiService::class),
            $container->get(ConfigInterface::class),
            $container->get(ApiCursorPaginator::class),
            $container->get(ApiEntityTagService::class),
        ),

    NotificationResourceController::class =>
        static fn(ContainerInterface $container): NotificationResourceController =>
            new NotificationResourceController(
                $container->get(ApiResponseFactory::class),
                $container->get(NotificationService::class),
                $container->get(ApiCursorPaginator::class),
                $container->get(ApiEntityTagService::class),
            ),

    WebhookResourceController::class =>
        static fn(ContainerInterface $container): WebhookResourceController =>
            new WebhookResourceController(
                $container->get(ApiResponseFactory::class),
                $container->get(WebhookSubscriptionService::class),
                $container->get(ApiCursorPaginator::class),
                $container->get(ApiEntityTagService::class),
            ),

    EditorHtmlApiRouteRegistrar::class =>
        static fn(ContainerInterface $container): EditorHtmlApiRouteRegistrar =>
            new EditorHtmlApiRouteRegistrar(
                $container->get(ComposerBridge::class),
                $container->get(ConfigInterface::class),
                $container->get(Routes::class),
            ),

    CalendarApiRouteRegistrar::class =>
        static fn(ContainerInterface $container): CalendarApiRouteRegistrar =>
            new CalendarApiRouteRegistrar(
                $container->get(ComposerBridge::class),
                $container->get(ConfigInterface::class),
                $container->get(Routes::class),
            ),

    TaskApiRouteRegistrar::class =>
        static fn(ContainerInterface $container): TaskApiRouteRegistrar =>
            new TaskApiRouteRegistrar(
                $container->get(ComposerBridge::class),
                $container->get(ConfigInterface::class),
                $container->get(Routes::class),
            ),

    WorkspaceApiRouteRegistrar::class =>
        static fn(ContainerInterface $container): WorkspaceApiRouteRegistrar =>
            new WorkspaceApiRouteRegistrar(
                $container->get(ComposerBridge::class),
                $container->get(ConfigInterface::class),
                $container->get(Routes::class),
            ),

    NotificationApiRouteRegistrar::class =>
        static fn(ContainerInterface $container): NotificationApiRouteRegistrar =>
            new NotificationApiRouteRegistrar(
                $container->get(ComposerBridge::class),
                $container->get(ConfigInterface::class),
                $container->get(Routes::class),
            ),

    ApiKeyController::class => static fn(ContainerInterface $container): ApiKeyController =>
        new ApiKeyController(
            $container->get(ResponseFactory::class),
            $container->get(UrlGenerator::class),
            $container->get(ApiModuleViewRenderer::class),
            $container->get(AuthApiKeyService::class),
            $container->get(ApiKeyRequestService::class),
            $container->get(ApiScopeRegistry::class),
            $container->get(AuthUserService::class),
            $container->get(AuthnHandlerInterface::class),
            $container->get(SessionInterface::class),
            $container->get(AlertHandler::class),
            $container,
        ),

    ApiKeyRequestController::class =>
        static fn(ContainerInterface $container): ApiKeyRequestController =>
            new ApiKeyRequestController(
                $container->get(ResponseFactory::class),
                $container->get(UrlGenerator::class),
                $container->get(ApiModuleViewRenderer::class),
                $container->get(ApiKeyRequestService::class),
                $container->get(ApiScopeRegistry::class),
                $container->get(ApiKeyRequestNotifier::class),
                $container->get(AuthnHandlerInterface::class),
                $container->get(AlertHandler::class),
            ),
];
