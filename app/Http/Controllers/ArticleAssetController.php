<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images and files for a draft.
 *
 * These travel out in the export bundle, because whoever publishes on the live
 * site has to upload them there — this system has no access to the site's media
 * library. That is why alt text is captured here rather than left until then:
 * it is far easier to write while the writer still remembers what the picture
 * shows.
 */
class ArticleAssetController extends Controller
{
    public function store(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('uploadAssets', $article);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['image', 'document', 'link'])],
            'label' => ['nullable', 'string', 'max:160'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            // 50 MB, matching interview assets: a draft with photographs in it
            // is bigger than a page of text, and the old 10 MB cap was set
            // when this only ever carried images for the body editor.
            'file' => ['required_without:url', 'nullable', 'file', 'max:51200'],
            'url' => ['required_without:file', 'nullable', 'url', 'max:1000'],
        ], [
            'file.required_without' => 'Attach a file or paste a link.',
            'url.required_without' => 'Attach a file or paste a link.',
            'file.max' => 'That file is over 10 MB. Compress it before uploading.',
        ]);

        $asset = new ArticleAsset([
            'article_id' => $article->id,
            'kind' => $validated['kind'],
            'label' => $validated['label'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            $asset->fill([
                'disk' => 'workfiles',
                'path' => $file->store("articles/{$article->id}", 'workfiles'),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        } else {
            $asset->url = $validated['url'];
        }

        $asset->save();

        return back()->with('success', 'Attached.');
    }

    public function download(Request $request, ArticleAsset $asset): StreamedResponse|RedirectResponse
    {
        $this->authorize('downloadAsset', $asset->article);

        if ($asset->isLink()) {
            return redirect()->away($asset->url);
        }

        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->download(
            $asset->path,
            $asset->original_filename ?? basename($asset->path),
        );
    }

    public function destroy(Request $request, ArticleAsset $asset): RedirectResponse
    {
        $this->authorize('uploadAssets', $asset->article);

        // File first, then the row: the other order can orphan a file on disk
        // if the delete fails halfway.
        $asset->deleteFile();
        $asset->delete();

        return back()->with('success', 'Removed.');
    }
}
