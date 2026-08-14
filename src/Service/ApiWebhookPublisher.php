<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Service;

use AaiEduHr\HeartPhrameModuleApi\ModuleApi;
use AaiEduHr\HeartPhrameModuleAuth\Api\AuthApiIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_values;
use function count;
use function is_scalar;
use function rawurldecode;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * HR: Nakon uspješne API mutacije pretvara zahtjev u sanitizirani domenski
 *     događaj i sprema ga u webhook outbox bez usporavanja HTTP odgovora.
 * EN: Converts a successful API mutation into a sanitized domain event and
 *     stores it in the webhook outbox without delaying the HTTP response.
 * @see \AaiEduHr\HeartPhrameModuleApi\Tests\Service\ApiWebhookPublisherTest
 */
final readonly class ApiWebhookPublisher
{
    /**
     * HR: Prima servis trajnih pretplata.
     * EN: Receives the durable subscription service.
     */
    public function __construct(
        private WebhookSubscriptionService $subscriptions,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * HR: Objavljuje samo završene, nereplayane write zahtjeve i nikada ne
     *     prosljeđuje tijelo zahtjeva ili odgovora.
     * EN: Publishes only completed, non-replayed write requests and never
     *     forwards request or response bodies.
     */
    public function publish(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): void {
        try {
            $eventName = $this->eventName($request, $response);
            if ($eventName === null) {
                return;
            }

            $identity = $request->getAttribute(ModuleApi::IDENTITY_ATTRIBUTE);
            if (!$identity instanceof AuthApiIdentity) {
                return;
            }

            $payload = [
                'actor' => [
                    'user_id' => $identity->userId(),
                    'api_key_id' => $identity->keyId,
                    'api_key_public_id' => $identity->publicId,
                ],
                'request' => [
                    'id' => $this->stringAttribute($request, ModuleApi::REQUEST_ID_ATTRIBUTE),
                    'method' => strtoupper($request->getMethod()),
                    'path' => $request->getUri()->getPath(),
                ],
                'response' => [
                    'status' => $response->getStatusCode(),
                    'location' => $this->nullableHeader($response, 'Location'),
                    'etag' => $this->nullableHeader($response, 'ETag'),
                ],
            ];
            $this->subscriptions->publish($eventName, $payload);
        } catch (Throwable $throwable) {
            // HR: Webhook kvar ne smije promijeniti odgovor uspješne poslovne radnje.
            // EN: A webhook failure must never alter a successful business response.
            $this->logger?->error('API webhook publication failed.', [
                'module' => 'api',
                'http_method' => strtoupper($request->getMethod()),
                'path' => $request->getUri()->getPath(),
                'exception' => $throwable,
            ]);
        }
    }

    /**
     * HR: Određuje stabilan naziv događaja iz verzionirane API putanje.
     * EN: Determines a stable event name from the versioned API path.
     */
    private function eventName(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ?string {
        $method = strtoupper($request->getMethod());
        $status = $response->getStatusCode();
        $path = $request->getUri()->getPath();
        if (
            !in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $status < 200
            || $status >= 300
            || $status === 202
            || !str_contains($path, '/api/v1/')
            || str_contains($path, '/api/v1/webhooks')
            || strtolower(trim($response->getHeaderLine('Idempotency-Replayed'))) === 'true'
        ) {
            return null;
        }

        $apiPath = substr($path, (int)strpos($path, '/api/v1/') + 8);
        $segments = array_values(array_filter(
            array_map(
                static fn(string $segment): string => rawurldecode(trim($segment)),
                explode('/', trim($apiPath, '/')),
            ),
            static fn(string $segment): bool => $segment !== '',
        ));
        if ($segments === []) {
            return null;
        }

        return match ($segments[0]) {
            'users' => $this->userEvent($method, $segments),
            'groups' => $this->groupEvent($method, $segments),
            'workspaces' => $this->workspaceEvent($method, $segments),
            'pages' => $this->pageEvent($method, $segments),
            'calendars' => $this->calendarEvent($method, $segments),
            'notifications' => $method === 'DELETE'
                ? 'notifications.deleted'
                : 'notifications.updated',
            default => 'api.mutation',
        };
    }

    /**
     * HR: Mapira Auth korisničke mutacije.
     * EN: Maps Auth user mutations.
     *
     * @param list<string> $segments
     */
    private function userEvent(string $method, array $segments): string
    {
        if ($method === 'POST' && count($segments) === 1) {
            return 'users.created';
        }

        if ($method === 'DELETE') {
            return 'users.deleted';
        }

        if ($method === 'PUT' && ($segments[2] ?? '') === 'groups') {
            return 'users.groups_replaced';
        }

        return 'users.updated';
    }

    /**
     * HR: Mapira Auth grupne mutacije.
     * EN: Maps Auth group mutations.
     *
     * @param list<string> $segments
     */
    private function groupEvent(string $method, array $segments): string
    {
        if ($method === 'POST' && count($segments) === 1) {
            return 'groups.created';
        }

        return $method === 'DELETE' ? 'groups.deleted' : 'groups.updated';
    }

    /**
     * HR: Mapira mutacije područja, stabla i ACL-a.
     * EN: Maps workspace, tree, and ACL mutations.
     *
     * @param list<string> $segments
     */
    private function workspaceEvent(string $method, array $segments): string
    {
        if (($segments[1] ?? '') === 'deleted' && ($segments[3] ?? '') === 'restore') {
            return 'workspaces.restored';
        }

        if ($method === 'POST' && count($segments) === 1) {
            return 'workspaces.created';
        }

        if ($method === 'DELETE' && count($segments) === 2) {
            return 'workspaces.deleted';
        }

        if (in_array('acl', $segments, true)) {
            return 'workspaces.acl_changed';
        }

        if (in_array('nodes', $segments, true)) {
            return $method === 'POST'
                ? 'workspace_nodes.created'
                : ($method === 'DELETE' ? 'workspace_nodes.deleted' : 'workspace_nodes.updated');
        }

        if (in_array('order', $segments, true)) {
            return 'workspace_tree.reordered';
        }

        return 'workspaces.updated';
    }

    /**
     * HR: Mapira mutacije stranica, workflowa, privitaka i zadataka.
     * EN: Maps page, workflow, attachment, and task mutations.
     *
     * @param list<string> $segments
     */
    private function pageEvent(string $method, array $segments): string
    {
        if (in_array('tasks', $segments, true)) {
            return 'tasks.state_changed';
        }

        if (in_array('attachments', $segments, true) || in_array('attachment-visibility', $segments, true)) {
            if ($method === 'POST') {
                return 'attachments.created';
            }

            return $method === 'DELETE' ? 'attachments.deleted' : 'attachments.updated';
        }

        if (in_array('review', $segments, true)) {
            return 'pages.review_submitted';
        }

        if (in_array('publish', $segments, true)) {
            return 'pages.published';
        }

        if (in_array('restore', $segments, true)) {
            return 'pages.restored';
        }

        if (in_array('draft', $segments, true) && $method === 'DELETE') {
            return 'pages.draft_discarded';
        }

        if ($method === 'POST' && count($segments) === 1) {
            return 'pages.created';
        }

        return $method === 'DELETE' ? 'pages.deleted' : 'pages.updated';
    }

    /**
     * HR: Mapira mutacije kalendara, događaja i prava.
     * EN: Maps calendar, event, and permission mutations.
     *
     * @param list<string> $segments
     */
    private function calendarEvent(string $method, array $segments): string
    {
        if (in_array('events', $segments, true)) {
            if ($method === 'POST') {
                return 'calendar_events.created';
            }

            return $method === 'DELETE' ? 'calendar_events.deleted' : 'calendar_events.updated';
        }

        if (in_array('acl', $segments, true)) {
            return 'calendars.acl_changed';
        }

        if ($method === 'POST' && count($segments) === 1) {
            return 'calendars.created';
        }

        return $method === 'DELETE' ? 'calendars.deleted' : 'calendars.updated';
    }

    /**
     * HR: Čita siguran string atribut zahtjeva.
     * EN: Reads a safe request string attribute.
     */
    private function stringAttribute(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getAttribute($name);
        if (!is_scalar($value) || trim((string)$value) === '') {
            return null;
        }

        return trim((string)$value);
    }

    /**
     * HR: Čita opcionalno neprazno zaglavlje odgovora.
     * EN: Reads an optional non-empty response header.
     */
    private function nullableHeader(ResponseInterface $response, string $name): ?string
    {
        $value = trim($response->getHeaderLine($name));

        return $value !== '' ? $value : null;
    }
}
