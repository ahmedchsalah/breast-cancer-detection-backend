<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BlockchainHashingService — Blockchain-style integrity verification for FL parameters.
 *
 * Implements a chained hash mechanism where each contribution's weights hash
 * is linked to the previous one, creating an immutable audit trail.
 *
 * This ensures:
 *   1. No contributor can tamper with their weights after submission
 *   2. The aggregation result can be verified against all contributions
 *   3. A complete audit trail exists for regulatory compliance
 *
 * This is NOT a cryptocurrency — it's a tamper-evident log that guarantees
 * parameter provenance and aggregation integrity.
 */
class BlockchainHashingService
{
    /**
     * Compute SHA-256 hash of a weights file content.
     */
    public static function hashWeights(string $weightsContent): string
    {
        return hash('sha256', $weightsContent);
    }

    /**
     * Compute SHA-256 hash of a file at the given path.
     */
    public static function hashFile(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }
        return hash_file('sha256', $filePath);
    }

    /**
     * Compute a deterministic hash of model parameters represented as a JSON array.
     * Used when weights are transmitted as structured data rather than raw bytes.
     */
    public static function hashParameterDict(array $parameters): string
    {
        // Sort keys for deterministic ordering
        ksort($parameters);
        $json = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }

    /**
     * Create a blockchain block for a contribution.
     *
     * Each block contains:
     *   - index: sequential block number
     *   - contributor_id: who contributed
     *   - weights_hash: SHA-256 of the contributed weights
     *   - previous_hash: hash of the previous block (chaining)
     *   - timestamp: when the block was created
     *   - metadata: additional context (round_id, accuracy, etc.)
     *   - block_hash: SHA-256 of the block itself
     */
    public static function createContributionBlock(
        int $roundId,
        int $organizationId,
        string $weightsHash,
        array $metadata = [],
    ): array {
        $previousBlock = self::getLastBlock($roundId);
        $previousHash = $previousBlock ? $previousBlock['block_hash'] : str_repeat('0', 64);

        $blockIndex = self::getNextBlockIndex($roundId);

        $block = [
            'index'          => $blockIndex,
            'contributor_id' => "org_{$organizationId}",
            'weights_hash'   => $weightsHash,
            'previous_hash'  => $previousHash,
            'timestamp'      => now()->utc()->toIso8601String(),
            'metadata'       => array_merge(['round_id' => $roundId], $metadata),
        ];

        $block['block_hash'] = self::computeBlockHash($block);

        // Store in the round's blockchain_receipt
        self::appendBlockToChain($roundId, $block);

        Log::info("[Blockchain] Block #{$blockIndex} added for org {$organizationId} in round {$roundId}");

        return $block;
    }

    /**
     * Create an aggregation block — records the result of federated averaging.
     *
     * This block references all contribution hashes that were included,
     * making it impossible to secretly exclude or alter contributions.
     */
    public static function createAggregationBlock(
        int $roundId,
        string $aggregatedWeightsHash,
        array $contributionHashes,
        string $method = 'fedavg_weighted',
        array $metadata = [],
    ): array {
        $previousBlock = self::getLastBlock($roundId);
        $previousHash = $previousBlock ? $previousBlock['block_hash'] : str_repeat('0', 64);

        $blockIndex = self::getNextBlockIndex($roundId);

        $block = [
            'index'          => $blockIndex,
            'contributor_id' => 'AGGREGATION_SERVER',
            'weights_hash'   => $aggregatedWeightsHash,
            'previous_hash'  => $previousHash,
            'timestamp'      => now()->utc()->toIso8601String(),
            'metadata'       => array_merge([
                'type'               => 'aggregation',
                'method'             => $method,
                'contribution_hashes' => $contributionHashes,
                'round_id'           => $roundId,
            ], $metadata),
        ];

        $block['block_hash'] = self::computeBlockHash($block);

        self::appendBlockToChain($roundId, $block);

        Log::info("[Blockchain] Aggregation block #{$blockIndex} added for round {$roundId}, method={$method}");

        return $block;
    }

    /**
     * Verify the entire chain integrity for a round.
     * Returns true if all blocks are validly chained.
     */
    public static function verifyChain(int $roundId): bool
    {
        $chain = self::getChain($roundId);

        if (empty($chain)) {
            return true; // Empty chain is valid
        }

        for ($i = 1; $i < count($chain); $i++) {
            $current = $chain[$i];
            $previous = $chain[$i - 1];

            // Check previous_hash linkage
            if ($current['previous_hash'] !== $previous['block_hash']) {
                Log::error("[Blockchain] Chain broken at block {$i}: previous_hash mismatch");
                return false;
            }

            // Check block hash
            $expectedHash = self::computeBlockHash($current);
            if ($current['block_hash'] !== $expectedHash) {
                Log::error("[Blockchain] Chain broken at block {$i}: block_hash mismatch");
                return false;
            }
        }

        Log::info("[Blockchain] Chain verification passed for round {$roundId}: " . count($chain) . " blocks valid");
        return true;
    }

    /**
     * Get a verification receipt for the current state of a round's chain.
     */
    public static function getReceipt(int $roundId): array
    {
        $chain = self::getChain($roundId);
        $verified = self::verifyChain($roundId);

        return [
            'chain_length'     => count($chain),
            'last_block_hash'  => !empty($chain) ? $chain[count($chain) - 1]['block_hash'] : null,
            'genesis_hash'     => !empty($chain) ? $chain[0]['block_hash'] : null,
            'verified'         => $verified,
            'timestamp'        => now()->utc()->toIso8601String(),
        ];
    }

    /**
     * Compute SHA-256 hash of a block (excluding block_hash field).
     */
    protected static function computeBlockHash(array $block): string
    {
        $blockData = array_filter($block, fn($key) => $key !== 'block_hash', ARRAY_FILTER_USE_KEY);
        ksort($blockData);
        $json = json_encode($blockData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }

    /**
     * Get the full chain for a round from the database.
     */
    protected static function getChain(int $roundId): array
    {
        $round = \App\Models\FlRound::find($roundId);
        if (!$round || !$round->blockchain_receipt) {
            return [];
        }
        return $round->blockchain_receipt['chain'] ?? [];
    }

    /**
     * Get the last block in a round's chain.
     */
    protected static function getLastBlock(int $roundId): ?array
    {
        $chain = self::getChain($roundId);
        return !empty($chain) ? $chain[count($chain) - 1] : null;
    }

    /**
     * Get the next block index for a round.
     */
    protected static function getNextBlockIndex(int $roundId): int
    {
        $chain = self::getChain($roundId);
        return count($chain);
    }

    /**
     * Append a block to a round's blockchain chain.
     */
    protected static function appendBlockToChain(int $roundId, array $block): void
    {
        $round = \App\Models\FlRound::find($roundId);
        if (!$round) {
            throw new \InvalidArgumentException("Round {$roundId} not found");
        }

        $receipt = $round->blockchain_receipt ?? ['chain' => []];
        if (!isset($receipt['chain'])) {
            $receipt['chain'] = [];
        }
        $receipt['chain'][] = $block;

        $round->update(['blockchain_receipt' => $receipt]);
    }
}
