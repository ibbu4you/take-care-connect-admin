<?php

namespace App\Http\Controllers;

use App\Models\Interview;
use App\Models\InterviewAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The transcript, the recording, the photos.
 *
 * Files go to the private `workfiles` disk and are only ever served through
 * {@see download()}, which asks the policy first. These are unpublished
 * interviews about real businesses — a guessable public URL would be a leak,
 * which is why the disk is not the public one.
 */
class InterviewAssetController extends Controller
{
    /** Documents, transcripts and photos are uploaded; recordings usually are not. */
    private const UPLOAD_KINDS = ['document', 'transcript', 'photo', 'recording'];

    private const LINK_KINDS = ['recording', 'link', 'transcript', 'document'];

    public function store(Request $request, Interview $interview): RedirectResponse
    {
        $this->authorize('uploadAssets', $interview);

        /*
         | One endpoint, two shapes: an uploaded file or a pasted link.
         |
         | A Google Meet recording is a Drive URL — the file itself is gigabytes
         | and the shared hosting has a disk quota — so linking has to be a
         | first-class option rather than a workaround.
         */
        $validated = $request->validate([
            'kind' => ['required', Rule::in(array_unique([...self::UPLOAD_KINDS, ...self::LINK_KINDS]))],
            'label' => ['nullable', 'string', 'max:160'],
            'file' => ['required_without:url', 'nullable', 'file', 'max:51200'],
            'url' => ['required_without:file', 'nullable', 'url', 'max:1000'],
        ], [
            'file.required_without' => 'Attach a file or paste a link.',
            'url.required_without' => 'Attach a file or paste a link.',
            'file.max' => 'That file is over 50 MB. Upload it to Drive and paste the link instead.',
        ]);

        $asset = new InterviewAsset([
            'interview_id' => $interview->id,
            'kind' => $validated['kind'],
            'label' => $validated['label'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            // Foldered per interview so a stray file is traceable to its record,
            // and Laravel's hashed name keeps the original out of the path —
            // filenames here are often somebody's actual name.
            $asset->fill([
                'disk' => 'workfiles',
                'path' => $file->store("interviews/{$interview->id}", 'workfiles'),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        } else {
            $asset->url = $validated['url'];

            // A recording link is also the interview's own recording_url, which
            // is what missingForHandover() checks. Setting both means pasting
            // the link once satisfies the handover requirement.
            if ($validated['kind'] === 'recording' && blank($interview->recording_url)) {
                $interview->update(['recording_url' => $validated['url']]);
            }
        }

        $asset->save();

        return back()->with('success', 'Attached.');
    }

    public function download(Request $request, InterviewAsset $asset): StreamedResponse|RedirectResponse
    {
        $this->authorize('downloadAsset', $asset->interview);

        if ($asset->isLink()) {
            return redirect()->away($asset->url);
        }

        abort_unless(Storage::disk($asset->disk)->exists($asset->path), 404);

        return Storage::disk($asset->disk)->download(
            $asset->path,
            $asset->original_filename ?? basename($asset->path),
        );
    }

    public function destroy(Request $request, InterviewAsset $asset): RedirectResponse
    {
        $interview = $asset->interview;

        $this->authorize('deleteAsset', $interview);

        // File first, then the row: the other order can leave an orphaned file
        // on disk with nothing pointing at it if the delete fails halfway.
        $asset->deleteFile();
        $asset->delete();

        // The interview's own recording_url mirrors a recording link, so it has
        // to be cleared alongside — otherwise handover would still pass on the
        // strength of a link that no longer exists.
        if ($asset->kind === 'recording' && $interview->recording_url === $asset->url) {
            $interview->update(['recording_url' => null]);
        }

        return back()->with('success', 'Removed.');
    }
}
