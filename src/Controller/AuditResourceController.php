<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Controller;

use AaiEduHr\HeartPhrameModuleApi\Http\ApiResponseFactory;
use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleApi\Service\ApiCursorPaginator;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use AaiEduHr\HeartPhrameModuleAuth\Service\AuthAuditLogService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * HR: Administratorski API adapter za redigirani Auth audit dnevnik.
 * EN: Administrative API adapter for the redacted Auth audit log.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Controller\AuditResourceControllerTest
 */
final readonly class AuditResourceController
{
    /**
     * HR: Prima zajedničke API odgovore i Auth audit servis.
     * EN: Receives shared API responses and the Auth audit service.
     */
    public function __construct(
        private ApiResponseFactory $responses,
        private AuthAuditLogService $audit,
        private ApiCursorPaginator $paginator,
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
            $result = $this->audit->listEvents(
                intdiv($parameters['offset'], $parameters['limit']) + 1,
                $parameters['limit'],
                $this->queryString($request, 'event_key'),
                $this->queryNullableInt($request, 'actor_user_id'),
                $this->queryNullableInt($request, 'target_user_id'),
                $this->queryString($request, 'created_from'),
                $this->queryString($request, 'created_to'),
            );

            return $this->responses->success(
                $request,
                $this->paginator->pageFromWindow(
                    $request,
                    $result['items'],
                    $result['total'],
                ),
                links: ['self' => $this->responses->requestTarget($request)],
            );
        } catch (Throwable) {
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
