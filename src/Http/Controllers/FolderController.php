<?php

namespace Eliyas5044\LaravelFileApi\Http\Controllers;

use Carbon\Carbon;
use Eliyas5044\LaravelFileApi\Http\Resources\Folder\FolderResource;
use Eliyas5044\LaravelFileApi\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FolderController extends Controller
{
    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = (int)$request->query('limit', 10);

        $folders = Folder::with('children', 'files')
            ->latest()
            ->whereNull('parent_id')
            ->limit($limit)
            ->get();

        return FolderResource::collection($folders);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required'
        ]);

        DB::beginTransaction();
        try {
            // create slug
            $slug = $this->checkFolderSlug($request->get('name'));

            $folder = Folder::query()->create([
                'name' => $request->get('name'),
                'slug' => $slug,
                'parent_id' => $request->get('parent_id'),
                'parent_folder' => $request->get('parent_folder'),
            ]);
            // create directory
            if ($request->has('parent_id')) {
                $slug = $request->get('parent_folder') . '/' . $slug;
            }
            Storage::makeDirectory($slug);

            DB::commit();

            return response()->json([
                'data' => $folder,
                'message' => 'Successfully Created.'
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
                'error' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * @param Folder $folder
     * @return JsonResponse
     */
    public function show(Folder $folder): JsonResponse
    {
        $folder->load('files', 'children');
        $children = $folder->children()->latest()->get();
        $files = $folder->files()->latest()->get();

        return response()->json([
            'data' => $this->getFilesAndFolders($children, $files)
        ]);
    }

    /**
     * @param Request $request
     * @param Folder $folder
     * @return JsonResponse
     */
    public function update(Request $request, Folder $folder): JsonResponse
    {
        $request->validate([
            'name' => 'required'
        ]);

        DB::beginTransaction();
        try {
            // create slug
            $slug = $this->checkFolderSlug($request->get('name'));
            $old_path = $folder->slug;
            $new_path = $slug;
            if ($request->has('parent_id')) {
                $old_path = $folder->parent_folder . '/' . $folder->slug;
                $new_path = $request->get('parent_folder') . '/' . $slug;
            }
            $folder->update([
                'name' => $request->get('name'),
                'slug' => $slug,
                'parent_id' => $request->get('parent_id'),
                'parent_folder' => $request->get('parent_folder'),
            ]);
            // create directory
            Storage::move($old_path, $new_path);

            DB::commit();

            return response()->json([
                'data' => $folder,
                'message' => 'Successfully Updated.'
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
                'error' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'folder_names' => 'required',
            'folder_ids' => 'required'
        ]);

        DB::beginTransaction();
        try {
            $directory = $request->get('folder_names');
            $folder_ids = $request->get('folder_ids');

            Storage::deleteDirectory($directory);

            Folder::query()->whereIn('id', $folder_ids)->delete();

            DB::commit();
            return response()->json([
                'message' => 'Successfully Deleted'
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json([
                'message' => 'Something went wrong, please try again later.',
                'error' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * @param $name
     * @return string
     */
    protected function checkFolderSlug($name): string
    {
        $slug = Str::slug($name, '-', app()->getLocale());

        # slug repeat check
        $latest = Folder::query()
            ->where('slug', '=', $slug)
            ->latest('id')
            ->value('slug');

        if ($latest) {
            $pieces = explode('-', $latest);
            $number = intval(end($pieces));
            $slug .= '-' . ($number + 1);
        }

        return $slug;
    }

    /**
     * @param $children
     * @param $files
     * @return Collection
     */
    private function getFilesAndFolders($children, $files): Collection
    {
        $collections = collect();
        collect($children)->each(function ($item) use ($collections) {
            $directory = $item->parent_id ? "{$item->parent_folder}/{$item->slug}" : $item->slug;
            $folder = [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => 'folder',
                'parent_id' => $item->parent_id,
                'parent_folder' => $item->parent_folder,
                'items' => count(Storage::allFiles($directory)),
                'createdAt' => Carbon::parse($item->created_at)->toDayDateTimeString()
            ];
            $collections->push($folder);
        });
        collect($files)->each(function ($item) use ($collections) {
            $file = [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'type' => explode('/', $item->mime_type)[0],
                'url' => $item->url,
                'path' => $item->path,
                'folder_id' => $item->folder_id,
                'size' => $this->bytesToHuman($item->size),
                'createdAt' => Carbon::parse($item->created_at)->toDayDateTimeString()
            ];
            $collections->push($file);
        });

        return $collections;
    }

    /**
     * @param $bytes
     * @return string
     */
    private function bytesToHuman($bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
