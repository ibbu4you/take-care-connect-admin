<?php

namespace App\Http\Controllers;

use App\Models\Deliverable;
use App\Models\DeliverableFile;
use App\Services\StageMachine;
use App\Support\Workflow\DeliverableStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cuts themselves.
 *
 * Each upload is a new version rather than a replacement: creative work goes
 * round more than once, and "send me the previous cut" is a request that will
 * be made.
 *
 * A finished reel is hundreds of megabytes, so pasting a Drive link is a
 * first-class option — the same reasoning as the interview recording. The
 * upload cap is deliberately modest; the shared hosting has a disk quota, and a
 * team that uploads every export will find it.
 */
class DeliverableFileController extends Controller
{
    public function __construct(private readonly StageMachine $stages) {}

    public function store(Request $request, Deliverable $deliverable): RedirectResponse
    {
        $this->authorize('work', $deliverable);

        $validated = $request->validate([
            'file' => ['required_without:url', 'nullable', 'file', 'max:204800'],
            'url' => ['required_without:file', 'nullable', 'url', 'max:1000'],
            'note' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
        ], [
            'file.required_without' => 'Attach the file or paste a link to it.',
            'url.required_without' => 'Attach the file or paste a link to it.',
            'file.max' => 'That file is over 200 MB. Upload it to Drive and paste the link instead.',
        ]);

        DB::transaction(function () use ($request, $deliverable, $validated) {
            $file = new DeliverableFile([
                'deliverable_id' => $deliverable->id,
                'version' => $deliverable->nextVersion(),
                'note' => $validated['note'] ?? null,
                'duration_seconds' => $validated['duration_seconds'] ?? null,
                'uploaded_by' => $request->user()->id,
            ]);

            if ($request->hasFile('file')) {
                $upload = $request->file('file');

                $file->fill([
                    'disk' => 'workfiles',
                    'path' => $upload->store("deliverables/{$deliverable->id}", 'workfiles'),
                    'original_filename' => $upload->getClientOriginalName(),
                    'mime_type' => $upload->getClientMimeType(),
                    'size_bytes' => $upload->getSize(),
                ]);
            } else {
                $file->url = $validated['url'];
            }

            $file->save();

            // Uploading the first cut is the moment work actually starts, so
            // the stage follows rather than waiting for somebody to move it.
            if ($deliverable->stage === DeliverableStage::Pending) {
                $this->stages->transition($deliverable, DeliverableStage::InProgress, $request->user());
            }
        });

        return back()->with('success', 'Uploaded.');
    }

    public function download(Request $request, DeliverableFile $file): StreamedResponse|RedirectResponse
    {
        $this->authorize('downloadFile', $file->deliverable);

        if ($file->isLink()) {
            return redirect()->away($file->url);
        }

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->original_filename ?? basename($file->path),
        );
    }

    public function destroy(Request $request, DeliverableFile $file): RedirectResponse
    {
        $deliverable = $file->deliverable;

        $this->authorize('work', $deliverable);

        // File first, then the row: the other order can orphan a file on disk
        // if the delete fails halfway.
        $file->deleteFile();
        $file->delete();

        return back()->with('success', 'Removed.');
    }
}
