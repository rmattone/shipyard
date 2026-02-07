<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    /**
     * List all tags for a server.
     */
    public function index(Server $server): JsonResponse
    {
        $tags = $server->tags()
            ->withCount('applications')
            ->orderBy('name')
            ->get();

        return response()->json($tags);
    }

    /**
     * Create a new tag for a server.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
            ],
            'color' => [
                'sometimes',
                'string',
                'in:' . implode(',', Tag::COLORS),
            ],
        ]);

        // Check for duplicate name within server
        $exists = $server->tags()->where('name', $validated['name'])->exists();
        if ($exists) {
            return response()->json([
                'message' => 'A tag with this name already exists.',
                'errors' => ['name' => ['A tag with this name already exists on this server.']],
            ], 422);
        }

        $tag = $server->tags()->create([
            'name' => $validated['name'],
            'color' => $validated['color'] ?? 'gray',
        ]);

        $tag->loadCount('applications');

        return response()->json($tag, 201);
    }

    /**
     * Update a tag.
     */
    public function update(Request $request, Server $server, Tag $tag): JsonResponse
    {
        if ($tag->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:50',
            ],
            'color' => [
                'sometimes',
                'string',
                'in:' . implode(',', Tag::COLORS),
            ],
        ]);

        // Check for duplicate name if name is being changed
        if (isset($validated['name']) && $validated['name'] !== $tag->name) {
            $exists = $server->tags()
                ->where('name', $validated['name'])
                ->where('id', '!=', $tag->id)
                ->exists();
            if ($exists) {
                return response()->json([
                    'message' => 'A tag with this name already exists.',
                    'errors' => ['name' => ['A tag with this name already exists on this server.']],
                ], 422);
            }
        }

        $tag->update($validated);
        $tag->loadCount('applications');

        return response()->json($tag);
    }

    /**
     * Delete a tag.
     */
    public function destroy(Server $server, Tag $tag): JsonResponse
    {
        if ($tag->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $tag->delete();

        return response()->json(null, 204);
    }
}
