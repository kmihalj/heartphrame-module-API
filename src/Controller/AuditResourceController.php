<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleAudit\Service\AuditQueryService;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAuditLogService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Administratorski API adapter za centralni poslovni audit, uz Auth
 *     fallback kada zasebni Audit modul nije instaliran.
 * EN: Administrative API adapter for the central business audit, with an Auth
 *     fallback when the dedicated Audit module is not installed.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Controller\AuditResourceControllerTest
 */
final readonly class AuditResourceController
{
    /**
     * HR: Prima zajedničke API odgovore i dostupni audit servis.
     * EN: Receives shared API responses and the available audit service.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private object $audit,
        private ApiCursorPaginator $paginator,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * HR: Vraća filtrirani audit samo administratorskom vlasniku ključa sa scopeom.
     * EN: Returns filtered audit data only to an administrator key owner with the scope.
     */
    public function listEvents(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (!$identity->permits('audit:read') || !$identity->isAdmin()) {
            return $this->responses->problem(
                $request,
                403,
                'audit_access_denied',
                __('Pristup nije dozvoljen'),
                __('Audit API zahtijeva administratorskog vlasnika ključa i scope "audit:read".'),
            );
        }

        try {
            $parameters = $this->paginator->parameters($request);
            $result = $this->audit instanceof AuditQueryService
                ? $this->audit->search([
                    'event_key' => $this->queryString($request, 'event_key'),
                    'module' => $this->queryString($request, 'module'),
                    'action' => $this->queryString($request, 'action'),
                    'outcome' => $this->queryString($request, 'outcome'),
                    'channel' => $this->queryString($request, 'channel'),
                    'actor_user_id' => $this->queryNullableInt($request, 'actor_user_id'),
                    'workspace_id' => $this->queryNullableInt($request, 'workspace_id'),
                    'page_id' => $this->queryNullableInt($request, 'page_id'),
                    'target' => $this->queryString($request, 'target'),
                    'from' => $this->queryString($request, 'created_from'),
                    'to' => $this->queryString($request, 'created_to'),
                ], intdiv($parameters['offset'], $parameters['limit']) + 1, $parameters['limit'])
                : $this->legacyEvents($request, $parameters);

            return $this->responses->success(
                $request,
                $this->paginator->pageFromWindow(
                    $request,
                    $result['items'],
                    $result['total'],
                ),
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (Throwable $throwable) {
            $this->logger?->error('Audit API read failed.', [
                'module' => 'api',
                'exception' => $throwable,
            ]);
            return $this->responses->problem(
                $request,
                500,
                'audit_read_failed',
                __('Dohvat nije uspio'),
                __('Audit zapise trenutačno nije moguće dohvatiti.'),
            );
        }
    }

    /**
     * HR: Čuva kompatibilnost samostalnog API+Auth sustava bez Audit modula.
     * EN: Keeps standalone API+Auth applications functional without Audit.
     *
     * @param array{limit:int,offset:int} $parameters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pages:int,page_size:int}
     */
    private function legacyEvents(ServerRequestInterface $request, array $parameters): array
    {
        if (!$this->audit instanceof AuthAuditLogService) {
            throw new RuntimeException('No supported audit query service is available.');
        }

        return $this->audit->listEvents(
            intdiv($parameters['offset'], $parameters['limit']) + 1,
            $parameters['limit'],
            $this->queryString($request, 'event_key'),
            $this->queryNullableInt($request, 'actor_user_id'),
            $this->queryNullableInt($request, 'target_user_id'),
            $this->queryString($request, 'created_from'),
            $this->queryString($request, 'created_to'),
        );
    }

    /**
     * HR: Dohvaća API identitet iz zahtjeva.
     * EN: Retrieves the API identity from the request.
     */
    private function identity(ServerRequestInterface $request): AuthApiIdentity
    {
        $identity = $request->getAttribute(ModuleApi::IDENTITY_ATTRIBUTE);
        if (!$identity instanceof AuthApiIdentity) {
            throw new RuntimeException('Authenticated API identity is missing.');
        }

        return $identity;
    }

    /**
     * HR: Čita opcionalni pozitivni ID iz queryja.
     * EN: Reads an optional positive ID from the query.
     */
    private function queryNullableInt(ServerRequestInterface $request, string $name): ?int
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    /**
     * HR: Čita skalarni query parametar kao očišćeni string.
     * EN: Reads a scalar query parameter as a trimmed string.
     */
    private function queryString(ServerRequestInterface $request, string $name): string
    {
        $value = $request->getQueryParams()[$name] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
