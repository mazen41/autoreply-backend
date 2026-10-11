# Phase 5 A5 — Training and knowledge indexing safety

## Changes

- Generate all chunks and embeddings before changing the live index, then atomically replace a file's prior chunks in one transaction.
- Keep the last indexed chunks when embedding, chunking, or database work fails. Queue failures retry three times and the `failed()` hook records a safe, user-facing status.
- Make per-file processing unique to avoid concurrent jobs swapping the same file.
- Cap files at 500 chunks by default (`KNOWLEDGE_MAX_CHUNKS_PER_FILE` can override the backend limit).
- Detect duplicate normalized content per business with a SHA-256 hash. Existing files are backfilled; duplicates are reported and do not create another file or processing job.
- Reindex continues to use the same per-file swap job.
- The Knowledge UI reports the real chunk count and indexing status, warns on duplicates, shows failed indexing information, and states the chunk limit.

## Migration

Run `php artisan migrate`. Migration `2026_10_10_000018_add_content_hash_to_business_knowledge_files` adds `file_size` and `content_hash`, backfills hashes for existing knowledge files, and adds a per-business unique index. Duplicate older records retain their existing rows; only one matching row receives the hash.

## Verification

- `ProcessKnowledgeFileTest`: previous chunks survive failed indexing, successful indexing swaps the set, duplicate content is detected, and chunk overflow preserves the existing set.
- Combined knowledge, retrieval, training, workflow, handoff, and sequence regression: 115 passed, 361 assertions.
- Frontend `npx tsc --noEmit`: passed.

## Rollback

Rollback only migration `2026_10_10_000018_add_content_hash_to_business_knowledge_files` if it is the latest migration. Remove the duplicate-warning UI/service and restore the earlier indexing job in the same code rollback. Knowledge chunk data is not deleted by the migration rollback.
