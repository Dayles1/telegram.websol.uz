<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Query extends Model
{
    protected $table = 'queries'; // aniq qilib qo'ydik
    protected $fillable = [
        'query_id',
        'type',
    ];

    public $timestamps = true;

    // scopes
    public function scopeOnlyQueryIds($query)
    {
        return $query->select('query_id');
    }

    public function scopeOfType($query, ?string $type)
    {
        if ($type === null) return $query;
        return $query->where('type', $type);
    }

    public function scopeByQueryId($query, string $qid)
    {
        return $query->where('query_id', $qid);
    }

    public static function syncForType(string $type, array $queryIds)
    {
        return DB::transaction(function () use ($type, $queryIds) {
            static::where('type', $type)
                ->whereNotIn('query_id', $queryIds)
                ->delete();

            // prepare rows for upsert
            $rows = array_map(fn($qid) => [
                'query_id' => $qid,
                'type' => $type,
                'updated_at' => now(),
                'created_at' => now(),
            ], $queryIds);


            static::upsert($rows, ['query_id', 'type'], ['type', 'updated_at']);

            return static::where('type', $type)->pluck('query_id');
        });
    }
}