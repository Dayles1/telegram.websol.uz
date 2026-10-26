<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PeerController extends Controller
{
    public function index(Request $request)
    {
        $query = \App\Models\Peer::query();

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('peer', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('peer_id', 'like', "%{$search}%");
            });
        }

        $peers = $query
            ->orderBy('usage_count', 'desc')
            ->paginate(20)
            ->appends($request->query());

        return \App\Http\Resources\PeerResource::collection($peers);
    }
    public function show(Request $request, $id)
    {
        $peer = \App\Models\Peer::findOrFail($id);

        return new \App\Http\Resources\PeerResource($peer);
    }
}
