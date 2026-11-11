<?php

namespace App\Support;

use App\Models\Article;
use App\Models\User;
use App\Services\StageMachine;
use App\Support\Workflow\ArticleStage;

/**
 * Keeping an approval honest.
 *
 * An approval is of a particular draft. Changing the draft, the title or the
 * framing while it is being reviewed — or after it was approved — used to leave
 * the stage where it was, so the new version went out under the old sign-off.
 */
final class ArticleReview
{
    public static function reopenIfChanged(Article $article, User $by, StageMachine $stages): void
    {
        $article->refresh();

        if ($article->stage === ArticleStage::InternalReview) {
            $stages->transition(
                $article,
                ArticleStage::Revisions,
                $by,
                'Changed while under review — send it again when ready.',
            );

            return;
        }

        if ($article->stage === ArticleStage::Approved) {
            $article->forceFill(['approved_at' => null])->save();

            $stages->transition(
                $article,
                ArticleStage::Drafting,
                $by,
                'Changed after approval — needs approving again.',
            );
        }
    }
}
