<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use App\Models\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QueryController extends Controller
{
    /**
     * GET /api/queries?type=gorit
     * Returns array of query_id values (optionally filtered by type)
     */
    public function index(Request $request)
{
    $type = $request->query('type');

    $query = Query::query();

    if ($type) {
        $query->where('type', $type);
    }

    // only query_id column
    $queryIds = $query->pluck('query_id')
                      ->map(fn($id) => (int) $id); // int ga aylantiramiz

    return response()->json([
        'success' => true,
        'queries' => $queryIds,
    ]);
}

    /**
     * POST /api/queries
     * body: { "type": "gorit", "query_ids": ["a","b","c"] }
     * Syncs DB for given type.
     */
    public function insert(Request $request)
    {
        $validated = $request->validate([
            'type' => 'nullable|string',
            'query_ids' => 'required|array',
            'query_ids.*' => 'required',
        ]);

        $type = $validated['type'] ?? 'gorit'; 
        $queryIds = $validated['query_ids'];

        DB::beginTransaction();
        try {
            Query::where('type', $type)
                ->whereNotIn('query_id', $queryIds)
                ->delete();

            $rows = array_map(fn($qid) => [
                'query_id' => $qid,
                'type' => $type,
                'updated_at' => now(),
                'created_at' => now(),
            ], $queryIds);

            Query::upsert($rows, ['query_id', 'type'], ['type', 'updated_at']);

            DB::commit();

            $current = Query::where('type', $type)->pluck('query_id');

            return response()->json([
                'success' => true,
                'message' => 'Queries synced successfully.',
                'queries' => $current,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync queries.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
