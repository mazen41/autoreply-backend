<?php

namespace App\Console\Commands;

use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessProfile;
use App\Services\AiGateway;
use App\Services\EmbeddingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReindexKnowledgeEmbeddings extends Command
{
    protected $signature = 'knowledge:reindex {businessId? : Business profile ID} {--all : Reindex all businesses} {--dry-run : Show the number of chunks that need reindexing without embedding or saving}';

    protected $description = 'Re-embed knowledge chunks whose provider, model, or dimension differs from the active embedding configuration.';

    public function handle(EmbeddingsService $embeddings): int
    {
        $businessId = $this->argument('businessId');
        if (($businessId && $this->option('all')) || (!$businessId && !$this->option('all'))) {
            $this->error('Pass one businessId or --all.');
            return self::FAILURE;
        }

        $provider = $embeddings->activeProvider();
        $model = $embeddings->activeModel();
        $dimension = $embeddings->dimension();
        if (!$provider || !$model || !$dimension) {
            $this->error('No supported embedding provider is configured.');
            return self::FAILURE;
        }

        $businessIds = $businessId
            ? [(int) $businessId]
            : BusinessKnowledgeChunk::query()
                ->select('business_profile_id')
                ->where(function ($query) use ($provider, $model, $dimension): void {
                    $query->whereNull('embedding_provider')->orWhere('embedding_provider', '!=', $provider)
                        ->orWhereNull('embedding_model')->orWhere('embedding_model', '!=', $model)
                        ->orWhereNull('embedding_dim')->orWhere('embedding_dim', '!=', $dimension);
                })
                ->distinct()
                ->orderBy('business_profile_id')
                ->pluck('business_profile_id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $total = 0;
        foreach ($businessIds as $id) {
            $query = $this->mismatchedChunks($id, $provider, $model, $dimension);
            $count = (clone $query)->count();
            $total += $count;
            if ($this->option('dry-run') || $count === 0) {
                continue;
            }

            $business = BusinessProfile::query()->find($id);
            if (!$business) {
                $this->warn("Business {$id} was not found; skipped.");
                continue;
            }

            $query->chunkById(100, function ($chunks) use ($embeddings, $business, $id, $provider, $model, $dimension): void {
                $texts = $chunks->pluck('content')->all();
                $ids = $chunks->pluck('id')->map(fn ($chunkId) => (int) $chunkId)->all();
                $operationId = AiGateway::stableOperationId('knowledge-reindex:' . $id . ':' . implode(',', $ids) . ':' . $provider . ':' . $model . ':' . $dimension);
                $vectors = $embeddings->embedBatch($texts, $business, $operationId);

                if (count($vectors) !== count($ids)) {
                    throw new RuntimeException('Embedding provider returned an incomplete reindex batch.');
                }
                foreach ($vectors as $vector) {
                    if (!is_array($vector) || count($vector) !== $dimension) {
                        throw new RuntimeException('Embedding provider returned an unexpected vector dimension.');
                    }
                }

                DB::transaction(function () use ($id, $ids, $vectors, $provider, $model, $dimension): void {
                    foreach ($ids as $index => $chunkId) {
                        BusinessKnowledgeChunk::query()
                            ->where('business_profile_id', $id)
                            ->whereKey($chunkId)
                            ->update([
                                'embedding' => json_encode($vectors[$index]),
                                'embedding_provider' => $provider,
                                'embedding_model' => $model,
                                'embedding_dim' => $dimension,
                                'updated_at' => now(),
                            ]);
                    }
                });
            });
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$total} chunks need reindexing.");
            return self::SUCCESS;
        }

        $this->info("Reindexed {$total} chunks.");
        return self::SUCCESS;
    }

    private function mismatchedChunks(int $businessId, string $provider, string $model, int $dimension)
    {
        return BusinessKnowledgeChunk::query()
            ->where('business_profile_id', $businessId)
            ->where(function ($query) use ($provider, $model, $dimension): void {
                $query->whereNull('embedding_provider')->orWhere('embedding_provider', '!=', $provider)
                    ->orWhereNull('embedding_model')->orWhere('embedding_model', '!=', $model)
                    ->orWhereNull('embedding_dim')->orWhere('embedding_dim', '!=', $dimension);
            })
            ->orderBy('id');
    }
}
