<?php

namespace App\Services;

use App\Jobs\SyncSallaSharedCityCatalog;
use App\Models\Channel;
use App\Models\SallaCityCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SharedSallaCityCatalogService
{
    public const FRESH_FOR_HOURS = 24;
    public const PAGES_PER_JOB = 20;
    private const REQUEST_DELAY_MICROSECONDS = 200000;

    public function __construct(private readonly SallaService $salla)
    {
    }

    public function isHealthy(string $countryCode): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        $catalog = SallaCityCatalog::query()->where('country_code', $countryCode)->first();
        if (!$catalog || !$catalog->country_id || !$catalog->synced_at
            || (int) $catalog->saved_city_count < 1
            || (int) $catalog->saved_city_count !== (int) $catalog->expected_city_count
            || $catalog->synced_at->lt(now()->subHours(self::FRESH_FOR_HOURS))) {
            return false;
        }

        return (int) DB::table('salla_city_reference_cities')
            ->where('country_id', $catalog->country_id)
            ->count() === (int) $catalog->saved_city_count;
    }

    public function isFresh(string $countryCode): bool
    {
        return $this->isHealthy($countryCode);
    }

    public function hasAvailableSource(): bool
    {
        return $this->eligibleChannels()->isNotEmpty();
    }

    public function queueIfStale(string $countryCode): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode) || $this->isFresh($countryCode) || !$this->hasAvailableSource()) {
            return false;
        }

        SyncSallaSharedCityCatalog::dispatch($countryCode);
        return true;
    }

    /** Process one durable page batch. The job queues the next batch when needed. */
    public function syncChunk(string $countryCode, ?int $preferredChannelId = null, int $pageLimit = self::PAGES_PER_JOB): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            throw new \InvalidArgumentException('A two-letter ISO country code is required.');
        }

        $lock = Cache::lock('salla:city-catalog:country:' . $countryCode, 1800);
        if (!$lock->get()) {
            return ['done' => false, 'locked' => true, 'pages_completed' => 0];
        }

        $catalog = null;
        try {
            $catalog = SallaCityCatalog::query()->firstOrCreate(
                ['country_code' => $countryCode],
                ['status' => 'pending']
            );

            if ($catalog->status === 'succeeded' && $this->isHealthy($countryCode)) {
                return ['done' => true, 'locked' => false, 'pages_completed' => (int) $catalog->pages_completed];
            }

            $resumable = in_array($catalog->status, ['running', 'failed'], true)
                && $catalog->generation
                && $catalog->started_at
                && $catalog->started_at->greaterThan(now()->subDays(7));

            if (!$resumable) {
                $catalog->generation = (string) Str::uuid();
                $catalog->status = 'running';
                $catalog->pages_completed = 0;
                $catalog->total_pages = null;
                $catalog->expected_city_count = null;
                $catalog->staged_city_count = 0;
                $catalog->started_at = now();
                $catalog->last_error_code = null;
                $catalog->last_error_message = null;
                $catalog->save();
                DB::table('salla_city_reference_staging')->where('country_id', $catalog->country_id)->delete();
            } else {
                $catalog->status = 'running';
                $catalog->last_error_code = null;
                $catalog->last_error_message = null;
                $catalog->save();
            }

            $channel = $this->selectSourceChannel($preferredChannelId ?? $catalog->source_channel_id);
            if (!$channel) {
                throw new \RuntimeException('No connected, claimed Salla store with a usable metadata scope is available as a catalog source.');
            }

            $countryId = (int) ($catalog->country_id ?? 0);
            if (!$countryId) {
                [$channel, $countryId] = $this->resolveCountryId($countryCode, $channel);
                $catalog->country_id = $countryId;
                $catalog->source_channel_id = $channel->id;
                $catalog->save();
            }

            // Page 1 validates the current pagination snapshot before resuming a saved page cursor.
            $pageOne = $this->fetchPageWithRetry($channel, $countryId, 1, true, [], $catalog);
            $pagination = $this->validatedPagination($pageOne, 1);
            if ($catalog->total_pages !== null && (
                (int) $catalog->total_pages !== $pagination['total_pages']
                || (int) $catalog->expected_city_count !== $pagination['total_count']
            )) {
                DB::table('salla_city_reference_staging')
                    ->where('country_id', $countryId)
                    ->where('generation', $catalog->generation)
                    ->delete();
                $catalog->generation = (string) Str::uuid();
                $catalog->pages_completed = 0;
                $catalog->staged_city_count = 0;
                $catalog->started_at = now();
            }

            $catalog->country_id = $countryId;
            $catalog->total_pages = $pagination['total_pages'];
            $catalog->expected_city_count = $pagination['total_count'];
            $catalog->source_channel_id = $channel->id;
            $catalog->save();

            $startPage = max(1, (int) $catalog->pages_completed + 1);
            $endPage = min($pagination['total_pages'], $startPage + max(1, $pageLimit) - 1);
            for ($page = $startPage; $page <= $endPage; $page++) {
                $response = $page === 1 ? $pageOne : $this->fetchPageWithRetry($channel, $countryId, $page, true, [], $catalog);
                $pagePagination = $this->validatedPagination($response, $page);
                if ($pagePagination !== $pagination) {
                    throw new \UnexpectedValueException('Salla city pagination changed while a country catalog was syncing.');
                }

                $rows = $this->normalizePage($response['data'], $countryId);
                if ($rows === []) {
                    throw new \UnexpectedValueException("Salla returned an empty city page {$page}.");
                }

                $now = now();
                $stagedRows = array_map(fn (array $row) => $row + [
                    'country_id' => $countryId,
                    'generation' => $catalog->generation,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rows);

                DB::table('salla_city_reference_staging')->upsert(
                    $stagedRows,
                    ['country_id', 'generation', 'salla_city_id'],
                    ['name', 'name_en', 'name_ar', 'normalized_name', 'normalized_en', 'normalized_ar', 'updated_at']
                );

                $catalog->pages_completed = $page;
                $catalog->staged_city_count = (int) DB::table('salla_city_reference_staging')
                    ->where('country_id', $countryId)
                    ->where('generation', $catalog->generation)
                    ->count();
                $catalog->save();

                if ($page < $pagination['total_pages']) {
                    usleep(self::REQUEST_DELAY_MICROSECONDS);
                }
            }

            $morePages = (int) $catalog->pages_completed < $pagination['total_pages'];
            if ($morePages) {
                return ['done' => false, 'locked' => false, 'pages_completed' => (int) $catalog->pages_completed];
            }

            return $this->publishGeneration($catalog, $countryId, $pagination['total_count']);
        } catch (Throwable $exception) {
            if ($catalog) {
                $safeMessage = OrderCheckoutService::maskSensitive($exception->getMessage());
                $catalog->status = 'failed';
                $catalog->last_error_code = class_basename($exception);
                $catalog->last_error_message = mb_substr($safeMessage, 0, 2000);
                $catalog->save();
            }

            Log::error('Shared Salla city catalog sync batch failed', [
                'country_code' => $countryCode,
                'pages_completed' => (int) ($catalog?->pages_completed ?? 0),
                'exception_type' => get_class($exception),
                'message' => OrderCheckoutService::maskSensitive($exception->getMessage()),
            ]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /** Synchronous compatibility path for the manual CLI command. */
    public function syncAll(string $countryCode, ?int $preferredChannelId = null, ?callable $onProgress = null): int
    {
        do {
            $result = $this->syncChunk($countryCode, $preferredChannelId, 50);
            if ($onProgress) {
                $onProgress($result);
            }
            if ($result['locked'] ?? false) {
                throw new \RuntimeException('The shared Salla city catalog is already being synchronized.');
            }
        } while (!($result['done'] ?? false));

        return (int) SallaCityCatalog::query()->where('country_code', strtoupper($countryCode))->value('saved_city_count');
    }

    /** Compare a complete second-store city ID set against the published shared catalog. */
    public function verifyAgainstSecondStore(string $countryCode, ?int $channelId = null): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $catalog = SallaCityCatalog::query()->where('country_code', $countryCode)->firstOrFail();
        if (!$this->isHealthy($countryCode) || !$catalog->country_id || !$catalog->source_channel_id) {
            throw new \RuntimeException('A fresh, complete shared catalog is required before cross-store verification.');
        }

        $sourcePageId = Channel::query()->whereKey($catalog->source_channel_id)->value('page_id');
        $channels = $this->eligibleChannels()->reject(fn (Channel $channel) =>
            (int) $channel->id === (int) $catalog->source_channel_id
            || (string) $channel->page_id === (string) $sourcePageId
        );
        if ($channelId) {
            $channels = $channels->filter(fn (Channel $channel) => (int) $channel->id === $channelId);
        }
        $secondStore = $channels->first();
        if (!$secondStore) {
            throw new \RuntimeException('A second connected, claimed Salla store is required for verification.');
        }

        $countries = $this->salla->getCountriesForChannel($secondStore)['data'] ?? null;
        $country = collect(is_array($countries) ? $countries : [])->first(
            fn ($row) => strtoupper((string) ($row['code'] ?? '')) === $countryCode
        );
        $secondCountryId = filter_var($country['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$secondCountryId || (int) $secondCountryId !== (int) $catalog->country_id) {
            throw new \UnexpectedValueException('The second store returned a different Salla country ID.');
        }

        $firstPage = $this->fetchPageWithRetry($secondStore, (int) $catalog->country_id, 1, false);
        $pagination = $this->validatedPagination($firstPage, 1);
        if ($pagination['total_count'] !== (int) $catalog->saved_city_count) {
            throw new \UnexpectedValueException('The second store city total differs from the shared catalog total.');
        }

        $ids = [];
        for ($page = 1; $page <= $pagination['total_pages']; $page++) {
            $response = $page === 1 ? $firstPage : $this->fetchPageWithRetry($secondStore, (int) $catalog->country_id, $page, false);
            if ($this->validatedPagination($response, $page) !== $pagination) {
                throw new \UnexpectedValueException('Salla pagination changed during cross-store verification.');
            }
            foreach ($response['data'] as $city) {
                $id = filter_var($city['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || (isset($city['country_id']) && (int) $city['country_id'] !== (int) $catalog->country_id)) {
                    throw new \UnexpectedValueException("Invalid city identity in second store page {$page}.");
                }
                $ids[] = (int) $id;
            }
            if ($page < $pagination['total_pages']) {
                usleep(self::REQUEST_DELAY_MICROSECONDS);
            }
        }

        if (count($ids) !== $pagination['total_count'] || count(array_unique($ids)) !== count($ids)) {
            throw new \UnexpectedValueException('The second store returned an incomplete or duplicate city ID set.');
        }

        $secondHash = self::hashIds($ids);
        $matches = hash_equals((string) $catalog->id_set_sha256, $secondHash);
        $catalog->forceFill([
            'cross_store_verified_at' => now(),
            'verification_channel_id' => $secondStore->id,
            'verification_id_set_sha256' => $secondHash,
            'verification_matches' => $matches,
        ])->save();

        return [
            'channel_id' => $secondStore->id,
            'country_code' => $countryCode,
            'country_id' => (int) $catalog->country_id,
            'total_pages' => $pagination['total_pages'],
            'total_cities' => count($ids),
            'catalog_hash' => $catalog->id_set_sha256,
            'second_store_hash' => $secondHash,
            'matches' => $matches,
        ];
    }

    public function allConfiguredCatalogsVerified(): bool
    {
        $countries = (array) config('services.salla.city_catalog_countries', ['SA']);
        foreach ($countries as $code) {
            $catalog = SallaCityCatalog::query()->where('country_code', strtoupper(trim((string) $code)))->first();
            if (!$catalog || !$catalog->verification_matches || !$this->isHealthy((string) $code)) {
                return false;
            }
        }
        return $countries !== [];
    }

    private function publishGeneration(SallaCityCatalog $catalog, int $countryId, int $expectedCount): array
    {
        $staging = DB::table('salla_city_reference_staging')
            ->where('country_id', $countryId)
            ->where('generation', $catalog->generation);
        $stagedCount = (int) (clone $staging)->count();
        if ($stagedCount !== $expectedCount) {
            throw new \UnexpectedValueException("Salla catalog is incomplete: received {$stagedCount}/{$expectedCount} city IDs.");
        }

        $ids = (clone $staging)->orderBy('salla_city_id')->pluck('salla_city_id')->map(fn ($id) => (int) $id)->all();
        if (count(array_unique($ids)) !== $expectedCount) {
            throw new \UnexpectedValueException('The staged Salla city catalog contains duplicate IDs.');
        }
        $hash = self::hashIds($ids);
        $now = now();

        DB::transaction(function () use ($countryId, $catalog, $expectedCount, $hash, $now): void {
            DB::table('salla_city_reference_staging')
                ->where('country_id', $countryId)
                ->where('generation', $catalog->generation)
                ->orderBy('id')
                ->chunk(400, function (Collection $batch) use ($now): void {
                    $rows = $batch->map(fn ($row) => [
                        'country_id' => $row->country_id,
                        'salla_city_id' => $row->salla_city_id,
                        'name' => $row->name,
                        'name_en' => $row->name_en,
                        'name_ar' => $row->name_ar,
                        'normalized_name' => $row->normalized_name,
                        'normalized_en' => $row->normalized_en,
                        'normalized_ar' => $row->normalized_ar,
                        'synced_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();
                    DB::table('salla_city_reference_cities')->upsert(
                        $rows,
                        ['country_id', 'salla_city_id'],
                        ['name', 'name_en', 'name_ar', 'normalized_name', 'normalized_en', 'normalized_ar', 'synced_at', 'updated_at']
                    );
                });

            DB::table('salla_city_reference_cities')
                ->where('country_id', $countryId)
                ->whereNotExists(function ($query) use ($countryId, $catalog): void {
                    $query->selectRaw('1')
                        ->from('salla_city_reference_staging as stage')
                        ->where('stage.country_id', $countryId)
                        ->where('stage.generation', $catalog->generation)
                        ->whereColumn('stage.salla_city_id', 'salla_city_reference_cities.salla_city_id');
                })
                ->delete();

            $saved = (int) DB::table('salla_city_reference_cities')->where('country_id', $countryId)->count();
            if ($saved !== $expectedCount) {
                throw new \UnexpectedValueException("Published Salla city count {$saved} does not match expected {$expectedCount}.");
            }

            $catalog->forceFill([
                'status' => 'succeeded',
                'pages_completed' => (int) $catalog->total_pages,
                'expected_city_count' => $expectedCount,
                'staged_city_count' => $expectedCount,
                'saved_city_count' => $saved,
                'synced_at' => $now,
                'id_set_sha256' => $hash,
                'cross_store_verified_at' => null,
                'verification_channel_id' => null,
                'verification_id_set_sha256' => null,
                'verification_matches' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            DB::table('salla_city_reference_staging')
                ->where('country_id', $countryId)
                ->where('generation', $catalog->generation)
                ->delete();
        });

        Log::info('Shared Salla city catalog published', [
            'country_code' => $catalog->country_code,
            'country_id' => $countryId,
            'source_channel_id' => $catalog->source_channel_id,
            'pages' => $catalog->total_pages,
            'city_count' => $expectedCount,
            'id_set_sha256' => $hash,
        ]);

        return ['done' => true, 'locked' => false, 'pages_completed' => (int) $catalog->pages_completed];
    }

    private function resolveCountryId(string $countryCode, Channel $initialSource): array
    {
        foreach ($this->eligibleChannels((int) $initialSource->id) as $channel) {
            try {
                $countries = $this->salla->getCountriesForChannel($channel)['data'] ?? null;
                if (!is_array($countries)) {
                    throw new \UnexpectedValueException('Salla returned an invalid countries response.');
                }
                $country = collect($countries)->first(
                    fn ($row) => strtoupper((string) ($row['code'] ?? '')) === $countryCode
                );
                $countryId = filter_var($country['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$countryId || $countryId < 1) {
                    throw new \RuntimeException("Salla did not provide a valid country ID for {$countryCode}.");
                }
                return [$channel, (int) $countryId];
            } catch (Throwable $exception) {
                Log::warning('Salla store could not provide shared country metadata; trying another eligible store', [
                    'channel_id' => $channel->id,
                    'country_code' => $countryCode,
                    'exception_type' => get_class($exception),
                ]);
            }
        }
        throw new \RuntimeException('No eligible connected Salla store could provide country metadata.');
    }

    private function selectSourceChannel(?int $preferredChannelId = null): ?Channel
    {
        $channels = $this->eligibleChannels($preferredChannelId);
        return $channels->first();
    }

    private function eligibleChannels(?int $preferredChannelId = null): Collection
    {
        $channels = Channel::query()
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->whereNotNull('user_id')
            ->whereNotNull('business_id')
            ->orderByDesc('connected_at')
            ->get();

        $eligible = $channels->filter(function (Channel $channel): bool {
            if (!is_string($channel->access_token) || trim($channel->access_token) === '') {
                return false;
            }
            $scopes = $channel->metadata['scopes'] ?? null;
            if (is_array($scopes)) {
                return array_intersect(['metadata.read', 'metadata.read_write'], $scopes) !== [];
            }
            if (is_string($scopes) && trim($scopes) !== '') {
                return array_intersect(['metadata.read', 'metadata.read_write'], preg_split('/[\s,]+/', trim($scopes)) ?: []) !== [];
            }
            // Older successful connections may not have persisted the granted scopes; let Salla validate them.
            return true;
        })->values();

        if ($preferredChannelId) {
            $preferred = $eligible->first(fn (Channel $channel) => (int) $channel->id === $preferredChannelId);
            if ($preferred) {
                $eligible = $eligible->reject(fn (Channel $channel) => (int) $channel->id === $preferredChannelId)
                    ->prepend($preferred)->values();
            }
        }
        return $eligible;
    }

    private function fetchPageWithRetry(
        Channel &$channel,
        int $countryId,
        int $page,
        bool $allowSourceFailover = true,
        array $excludedChannelIds = [],
        ?SallaCityCatalog $catalog = null
    ): array
    {
        $last = null;
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            try {
                return $this->salla->getCitiesPageForChannel($channel, $countryId, $page);
            } catch (Throwable $exception) {
                $last = $exception;
                $message = $exception->getMessage();
                $authFailure = str_contains($message, 'Access token expired') || str_contains($message, 'Salla API scope missing');
                if ($authFailure && $allowSourceFailover) {
                    $excludedChannelIds[] = (int) $channel->id;
                    $alternate = $this->eligibleChannels()->first(
                        fn (Channel $candidate) => !in_array((int) $candidate->id, $excludedChannelIds, true)
                    );
                    if ($alternate) {
                        Log::warning('Salla city catalog source rejected; switching to another healthy connected store', [
                            'country_code' => $catalog?->country_code,
                            'from_channel_id' => $channel->id,
                            'to_channel_id' => $alternate->id,
                            'page' => $page,
                        ]);
                        $channel = $alternate;
                        if ($catalog) {
                            $catalog->source_channel_id = $alternate->id;
                            $catalog->save();
                        }
                        return $this->fetchPageWithRetry(
                            $channel,
                            $countryId,
                            $page,
                            true,
                            $excludedChannelIds,
                            $catalog
                        );
                    }
                }
                $retryable = str_contains($message, 'Rate limited')
                    || preg_match('/API call failed: 5\d\d/', $message) === 1
                    || $exception instanceof \Illuminate\Http\Client\ConnectionException;
                if (!$retryable || $attempt === 7) {
                    throw $exception;
                }
                $delay = 2 ** min($attempt, 6);
                if (preg_match('/Retry after\s+(\d+)/i', $message, $matches)) {
                    $delay = max($delay, (int) $matches[1]);
                }
                sleep(min(300, max(1, $delay)));
            }
        }
        throw $last ?? new \RuntimeException("Salla city page {$page} could not be fetched.");
    }

    private function validatedPagination(array $response, int $page): array
    {
        $cities = $response['data'] ?? null;
        $pagination = $response['pagination'] ?? null;
        if (!is_array($cities) || !is_array($pagination)) {
            throw new \UnexpectedValueException("Salla returned an invalid cities response on page {$page}.");
        }
        $totalPages = filter_var($pagination['totalPages'] ?? $pagination['total_pages'] ?? null, FILTER_VALIDATE_INT);
        $totalCount = filter_var($pagination['total'] ?? $pagination['totalCount'] ?? $pagination['total_count'] ?? null, FILTER_VALIDATE_INT);
        if (!$totalPages || $totalPages < 1 || $totalPages > 5000 || $totalCount === false || $totalCount === null || $totalCount < 1) {
            throw new \UnexpectedValueException("Salla omitted valid city pagination totals on page {$page}.");
        }
        if ($page > $totalPages) {
            throw new \UnexpectedValueException("Salla returned an invalid page range for city page {$page}.");
        }
        return ['total_pages' => (int) $totalPages, 'total_count' => (int) $totalCount];
    }

    private function normalizePage(array $cities, int $countryId): array
    {
        $service = app(SallaService::class);
        $rows = [];
        foreach ($cities as $city) {
            $cityId = filter_var($city['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$cityId || (isset($city['country_id']) && (int) $city['country_id'] !== $countryId)) {
                throw new \UnexpectedValueException('Salla returned a city with an invalid ID or country assignment.');
            }
            $name = trim((string) ($city['name'] ?? $city['name_en'] ?? $city['name_ar'] ?? ''));
            if ($name === '') {
                throw new \UnexpectedValueException("Salla returned city {$cityId} without a usable name.");
            }
            $nameEn = isset($city['name_en']) ? trim((string) $city['name_en']) : null;
            $nameAr = isset($city['name_ar']) ? trim((string) $city['name_ar']) : null;
            $rows[] = [
                'salla_city_id' => (int) $cityId,
                'name' => mb_substr($name, 0, 255),
                'name_en' => $nameEn !== null && $nameEn !== '' ? mb_substr($nameEn, 0, 255) : null,
                'name_ar' => $nameAr !== null && $nameAr !== '' ? mb_substr($nameAr, 0, 255) : null,
                'normalized_name' => $this->normalized($service->normalizeCitySearchText($name)),
                'normalized_en' => $nameEn ? $this->normalized($service->normalizeCitySearchText($nameEn)) : null,
                'normalized_ar' => $nameAr ? $this->normalized($service->normalizeCitySearchText($nameAr)) : null,
            ];
        }
        return $rows;
    }

    private function normalized(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 180);
    }

    public static function hashIds(array $ids): string
    {
        $ids = array_map('intval', $ids);
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(',', $ids));
    }
}
