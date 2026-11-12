<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationFileUpload;
use App\Services\ApplicationFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ApplicationFileController extends Controller
{
    public function __construct(private ApplicationFileService $files) {}

    public function directories(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate(['path' => ['nullable', 'string', 'max:2048']]);

        // An explicit empty path means the application root; omission means
        // private storage. ConvertEmptyStringsToNull normalizes the former.
        $path = $request->has('path') ? ($validated['path'] ?? '') : null;

        return $this->remote(fn () => response()->json($this->files->browse($application, $path)));
    }

    public function createDirectory(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['present', 'nullable', 'string', 'max:2048'],
            'name' => ['required', 'string', 'max:255'],
            'allow_public' => ['sometimes', 'boolean'],
        ]);

        return $this->remote(fn () => response()->json([
            'path' => $this->files->createDirectory($application, $validated['path'] ?? '', $validated['name'], $request->boolean('allow_public')),
        ], 201));
    }

    public function index(Application $application): JsonResponse
    {
        return response()->json(['data' => ApplicationFileUpload::where('application_id', $application->id)
            ->with('user:id,name')->latest('id')->limit(20)->get()]);
    }

    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'path' => ['present', 'nullable', 'string', 'max:2048'],
            'file' => ['required', 'file', 'max:'.ApplicationFileService::MAX_UPLOAD_KB],
            'overwrite' => ['sometimes', 'boolean'],
            'allow_public' => ['sometimes', 'boolean'],
        ]);

        return $this->remote(function () use ($request, $application, $validated) {
            $file = $request->file('file');
            $path = $this->files->upload($application, $validated['path'] ?? '', $file, $request->boolean('overwrite'), $request->boolean('allow_public'));
            $upload = ApplicationFileUpload::create([
                'application_id' => $application->id,
                'user_id' => $request->user()->id,
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'size_bytes' => $file->getSize(),
                'replaced_existing' => $request->boolean('overwrite'),
            ]);

            return response()->json(['data' => $upload->load('user:id,name')], 201);
        });
    }

    private function remote(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            report($e);

            return response()->json(['message' => 'The file operation failed. Check the server connection, available space, and filesystem permissions, then try again.'], 502);
        }
    }
}
