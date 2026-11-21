<?php

namespace App\Services\Search;

use App\Models\Order;
use App\Models\Product;
use App\Models\SearchEvent;
use App\Models\SearchSession;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SearchFeedbackService
{
    public const TYPE_CLICK = 'click';

    public const TYPE_CART = 'cart';

    public const TYPE_PURCHASE = 'purchase';

    public function enabled(): bool
    {
        return (bool) config('search-feedback.enabled', true);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @param  array{model_version?: string, mode?: string, is_milk_query?: bool}  $meta
     */
    public function recordSearch(
        Request $request,
        string $query,
        Collection $products,
        int $page,
        string $source = 'search',
        array $meta = [],
        int $perPage = 16,
    ): ?SearchSession {
        if (! $this->enabled() || trim($query) === '' || $products->isEmpty()) {
            return null;
        }

        try {
            $session = SearchSession::query()->create([
                'token' => (string) Str::uuid(),
                'session_key' => $this->sessionKey($request),
                'user_hash' => $this->userHash($request),
                'query' => Str::limit($query, 500, ''),
                'query_norm' => $this->normalize($query),
                'model_version' => $meta['model_version'] ?? null,
                'mode' => $meta['mode'] ?? null,
                'is_milk_query' => $meta['is_milk_query'] ?? null,
                'source' => $source,
                'page' => $page,
            ]);

            $offset = max(0, $page - 1) * max(1, $perPage);
            foreach ($products->values() as $i => $product) {
                $session->impressions()->create([
                    'product_id' => $product->id,
                    'position' => $offset + $i + 1,
                    'page' => $page,
                ]);
            }

            Cookie::queue(
                config('search-feedback.cookie'),
                $session->token,
                (int) config('search-feedback.cookie_minutes', 30),
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            );

            return $session;
        } catch (Throwable $e) {
            Log::warning('Search feedback session failed: '.$e->getMessage());

            return null;
        }
    }

    public function recordEvent(Request $request, int $productId, string $type, ?string $token = null): void
    {
        if (! $this->enabled() || ! in_array($type, [self::TYPE_CLICK, self::TYPE_CART, self::TYPE_PURCHASE], true)) {
            return;
        }

        try {
            $session = $this->resolveSession($request, $token);
            if ($session === null) {
                return;
            }

            $recent = $session->events()
                ->where('product_id', $productId)
                ->where('type', $type)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->exists();
            if ($recent) {
                return;
            }

            $position = $session->impressions()->where('product_id', $productId)->value('position');

            SearchEvent::query()->create([
                'search_session_id' => $session->id,
                'product_id' => $productId,
                'type' => $type,
                'position' => $position,
            ]);
        } catch (Throwable $e) {
            Log::warning('Search feedback event failed: '.$e->getMessage());
        }
    }

    public function recordPurchases(Request $request, Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        $productIds = $order->items->pluck('product_id')->unique()->filter()->all();
        foreach ($productIds as $productId) {
            $this->recordEvent($request, (int) $productId, self::TYPE_PURCHASE);
        }
    }

    public function exportPayload(): array
    {
        $sessions = SearchSession::query()
            ->with(['impressions.product:id,name', 'events'])
            ->orderBy('id')
            ->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'sessions' => $sessions->map(function (SearchSession $session) {
                return [
                    'token' => $session->token,
                    'query' => $session->query,
                    'query_norm' => $session->query_norm,
                    'model_version' => $session->model_version,
                    'mode' => $session->mode,
                    'is_milk_query' => $session->is_milk_query,
                    'source' => $session->source,
                    'created_at' => $session->created_at?->toIso8601String(),
                    'impressions' => $session->impressions->map(fn ($row) => [
                        'product_id' => $row->product_id,
                        'name' => $row->product?->name,
                        'position' => $row->position,
                        'page' => $row->page,
                    ])->all(),
                    'events' => $session->events->map(fn ($row) => [
                        'product_id' => $row->product_id,
                        'type' => $row->type,
                        'position' => $row->position,
                        'created_at' => $row->created_at?->toIso8601String(),
                    ])->all(),
                ];
            })->all(),
        ];
    }

    public function normalize(string $query): string
    {
        return Str::of($query)->lower()->squish()->toString();
    }

    private function sessionKey(Request $request): string
    {
        $sid = $request->hasSession() ? $request->session()->getId() : Str::random(40);

        return hash_hmac('sha256', $sid, (string) config('app.key'));
    }

    private function userHash(Request $request): ?string
    {
        $id = $request->user()?->id;
        if ($id === null) {
            return null;
        }

        return hash_hmac('sha256', (string) $id, (string) config('app.key'));
    }

    private function resolveSession(Request $request, ?string $token): ?SearchSession
    {
        $token = $token
            ?: $request->input('search_session')
            ?: $request->cookie(config('search-feedback.cookie'));

        if (! is_string($token) || $token === '') {
            return null;
        }

        $session = SearchSession::query()->where('token', $token)->first();
        if ($session !== null) {
            return $session;
        }

        return SearchSession::query()
            ->where('session_key', $this->sessionKey($request))
            ->where('created_at', '>=', now()->subMinutes((int) config('search-feedback.cookie_minutes', 30)))
            ->latest('id')
            ->first();
    }
}
