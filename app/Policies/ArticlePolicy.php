<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;
use App\Support\Scope;
use App\Support\Workflow\ArticleStage;

/**
 * Who may read, write, review and publish an article.
 *
 * The rule that shapes most of this: **a writer may not approve their own
 * work.** Review exists to put a second pair of eyes on a piece before it goes
 * out under the organisation's name, and a review you can grant yourself is not
 * a review. An Admin can, via the Gate bypass, for the case where one person is
 * genuinely the whole content team that week.
 */
class ArticlePolicy
{
    /** @var list<string> */
    private const PARTICIPANTS = ['writer_id', 'reviewer_id'];

    public function viewAny(User $actor): bool
    {
        return $actor->canAny(['articles.view', 'articles.view.all']);
    }

    public function view(User $actor, Article $article): bool
    {
        if (! $this->viewAny($actor)) {
            return false;
        }

        if (Scope::seesEverything($actor, 'articles')) {
            return true;
        }

        return $this->isParticipant($actor, $article);
    }

    public function create(User $actor): bool
    {
        return $actor->can('articles.create');
    }

    /**
     * Editing the body.
     *
     * Stops once the article is published: it is live on the website at that
     * point, and a draft that quietly diverges from the published page is worse
     * than no draft at all. Corrections happen on the site.
     */
    public function update(User $actor, Article $article): bool
    {
        return $actor->can('articles.update')
            && $article->stage !== ArticleStage::Published
            && $this->view($actor, $article);
    }

    /** Sending it for review — the writer's own act. */
    public function submit(User $actor, Article $article): bool
    {
        return $this->update($actor, $article)
            && in_array($article->stage, [ArticleStage::Assigned, ArticleStage::Drafting, ArticleStage::Revisions], true);
    }

    /**
     * Approving, or sending it back with notes.
     *
     * Not the writer. See the note at the top of this class.
     */
    public function review(User $actor, Article $article): bool
    {
        return $actor->can('articles.review')
            && $article->stage === ArticleStage::InternalReview
            && $article->writer_id !== $actor->getKey()
            && $this->view($actor, $article);
    }

    public function approve(User $actor, Article $article): bool
    {
        return $actor->can('articles.approve') && $this->review($actor, $article);
    }

    /**
     * Marking it live and recording the URL.
     *
     * Only from `approved`: publishing something that never passed review would
     * make the review step optional in practice, which is the same as not
     * having one.
     */
    public function publish(User $actor, Article $article): bool
    {
        return $actor->can('articles.publish')
            && $article->stage === ArticleStage::Approved
            && $this->view($actor, $article);
    }

    public function assign(User $actor, Article $article): bool
    {
        return $actor->can('articles.assign') && $this->view($actor, $article);
    }

    /**
     * Downloading the publish-ready bundle.
     *
     * Available from `approved` onwards, and still available after publishing —
     * somebody re-uploading a lost image should not have to un-publish the
     * article to get at it.
     */
    public function export(User $actor, Article $article): bool
    {
        return $actor->can('articles.export')
            && in_array($article->stage, [ArticleStage::Approved, ArticleStage::Published], true)
            && $this->view($actor, $article);
    }

    public function delete(User $actor, Article $article): bool
    {
        return $actor->can('articles.delete') && $article->stage !== ArticleStage::Published;
    }

    public function uploadAssets(User $actor, Article $article): bool
    {
        return $actor->can('assets.upload') && $this->update($actor, $article);
    }

    public function downloadAsset(User $actor, Article $article): bool
    {
        return $this->view($actor, $article);
    }

    private function isParticipant(User $actor, Article $article): bool
    {
        foreach (self::PARTICIPANTS as $column) {
            if ($article->{$column} === $actor->getKey()) {
                return true;
            }
        }

        return false;
    }
}
