<?php

declare(strict_types=1);

namespace Onelo\Store;

use Onelo\Http\TransportException;
use Onelo\Onelo;
use Onelo\Version;

/**
 * The Store module — `$onelo->store->listPlans()` (#909).
 *
 * Reads the App's plan catalog from GET /api/sdk/store/plans so a backend can
 * show plan prices without copying them from the dashboard. Secret key only.
 */
final class StoreClient
{
    public function __construct(private readonly Onelo $client)
    {
    }

    /**
     * @param string|null $country buyer country (ISO 3166-1 alpha-2, e.g. 'DE') for the
     *                             display prices, used when the seller charges the buyer's own VAT
     * @return list<OneloPlan> the App's active plans, ordered by sort order
     */
    public function listPlans(?string $country = null): array
    {
        if (!$this->client->isSecretKey) {
            throw new \LogicException('$onelo->store->listPlans() requires the Secret Key (onelo_sk_…) — call it from your server.');
        }
        try {
            $resp = $this->client->transport->send(
                'GET',
                $this->client->apiUrl . '/api/sdk/store/plans'
                    . ($country !== null && $country !== '' ? '?country=' . rawurlencode($country) : ''),
                [
                    'Accept' => 'application/json',
                    'User-Agent' => 'onelo-php/' . Version::VERSION,
                    'X-Onelo-Secret-Key' => $this->client->key,
                ],
                null,
                $this->client->requestTimeoutMs,
            );
        } catch (TransportException $e) {
            throw new StoreException('network error: ' . $e->getMessage(), null, $e);
        }
        if ($resp->status >= 400) {
            $detail = '';
            try {
                $body = $resp->json();
                $detail = is_array($body) && isset($body['detail']) ? ' — ' . (string) $body['detail'] : '';
            } catch (\JsonException) {
                // Not JSON; the status alone names the failure.
            }
            throw new StoreException('store/plans failed: HTTP ' . $resp->status . $detail, $resp->status);
        }
        $body = $resp->json();
        $plans = is_array($body) && is_array($body['plans'] ?? null) ? $body['plans'] : [];

        return array_values(array_map(static fn (array $p): OneloPlan => OneloPlan::fromArray($p), $plans));
    }
}
