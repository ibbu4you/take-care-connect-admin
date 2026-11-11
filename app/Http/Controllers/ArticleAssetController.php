<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleAsset;
use App\Rules\PublicUrl;
use App\Services\StageMachine;
use App\Support\ArticleReview;
use App\Support\Workflow\ArticleStage;
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
    public function __construct(private readonly StageMachine $stages) {}

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
            // A draft is a document and an image is an image. With no list, an
            // .html or .exe was accepted as "the draft".
            'file' => ['required_without:url', 'nullable', 'file', 'max:51200', match ($request->input('kind')) {
                'image' => 'mimes:jpg,jpeg,png,gif,webp',
                default => 'mimes:docx,doc,pdf,odt,rtf,txt',
            }],
            'url' => ['required_without:file', 'nullable', 'max:1000', new PublicUrl([], 'Paste a link that starts with https:// — a Google Doc or Drive link works.')],
        ], [
            'file.required_without' => 'Attach a file or paste a link.',
            'url.required_without' => 'Attach a file or paste a link.',
            'file.max' => 'That file is over 50 MB. Compress it, or paste a Drive link instead.',
            'file.mimes' => $request->input('kind') === 'image'
                ? 'Upload a JPG, PNG, GIF or WebP image.'
                : 'Upload the draft as Word, PDF, ODT, RTF or plain text.',
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

        // A new draft under review, or after approval, is not what was
        // reviewed or approved.
        if ($asset->kind === 'document') {
            ArticleReview::reopenIfChanged($article, $request->user(), $this->stages);
        }

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
        $article = $asset->article;
        $wasDraft = $asset->kind === 'document';

        // The draft a reviewer is reading — or approved — stays. Upload a new
        // version instead; deleting it left the review pointing at nothing.
        if ($wasDraft && in_array($article->stage, [ArticleStage::InternalReview, ArticleStage::Approved], true)) {
            return back()->with('error', 'This draft is under review or approved. Upload a new version instead of deleting it.');
        }

        $asset->deleteFile();
        $asset->delete();

        if ($wasDraft) {
            ArticleReview::reopenIfChanged($article, $request->user(), $this->stages);
        }

        return back()->with('success', 'Removed.');
    }
}
