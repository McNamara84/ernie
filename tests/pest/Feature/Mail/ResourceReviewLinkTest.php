<?php

declare(strict_types=1);

use App\Mail\ResourceReviewLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

covers(ResourceReviewLink::class);

function makeResourceReviewLinkMail(?string $doi = '10.5880/test.review.001'): ResourceReviewLink
{
    return new ResourceReviewLink(
        resourceId: 42,
        resourceTitle: 'Seismic Review Dataset',
        resourceDoi: $doi,
        reviewUrl: 'https://example.test/10.5880/test.review.001/seismic?preview=secret-token',
        recipientName: 'Ada Reviewer',
        initiatorName: 'Ernie Curator',
        initiatorEmail: 'curator@example.test',
        contactAddress: 'datapub@example.test',
    );
}

describe('envelope', function (): void {
    it('sets the subject, configured Reply-To and configured Cc', function (): void {
        $envelope = makeResourceReviewLinkMail()->envelope();

        expect($envelope->subject)->toBe('Review requested: Seismic Review Dataset')
            ->and($envelope->replyTo)->toHaveCount(1)
            ->and($envelope->replyTo[0]->address)->toBe('datapub@example.test')
            ->and($envelope->cc)->toHaveCount(1)
            ->and($envelope->cc[0]->address)->toBe('datapub@example.test');
    });
});

describe('content', function (): void {
    it('uses multipart review templates and passes all deterministic values', function (): void {
        $content = makeResourceReviewLinkMail()->content();

        expect($content->view)->toBe('emails.resource-review-link')
            ->and($content->text)->toBe('emails.resource-review-link-text')
            ->and($content->with)->toMatchArray([
                'resourceTitle' => 'Seismic Review Dataset',
                'resourceDoi' => '10.5880/test.review.001',
                'reviewUrl' => 'https://example.test/10.5880/test.review.001/seismic?preview=secret-token',
                'recipientName' => 'Ada Reviewer',
                'initiatorName' => 'Ernie Curator',
                'initiatorEmail' => 'curator@example.test',
            ]);
    });

    it('renders the personalized HTML and text bodies with and without a DOI', function (?string $doi): void {
        $mailable = makeResourceReviewLinkMail($doi);

        $html = $mailable->render();
        $text = view('emails.resource-review-link-text', $mailable->content()->with)->render();

        expect($html)->toContain('Dear Ada Reviewer')
            ->toContain('Seismic Review Dataset')
            ->toContain('preview=secret-token')
            ->toContain('Ernie Curator')
            ->toContain('curator@example.test')
            ->and($text)->toContain('Dear Ada Reviewer')
            ->toContain('preview=secret-token');

        if ($doi === null) {
            expect($html)->not->toContain('<strong>DOI:</strong>')
                ->and($text)->not->toContain('DOI:');
        } else {
            expect($html)->toContain($doi)
                ->and($text)->toContain($doi);
        }
    })->with([
        'with DOI' => '10.5880/test.review.001',
        'without DOI' => null,
    ]);
});

it('is queued and has no attachments', function (): void {
    $mailable = makeResourceReviewLinkMail();

    expect($mailable)->toBeInstanceOf(ShouldQueue::class)
        ->and($mailable->attachments())->toBeEmpty();
});

it('logs a delivery failure without including the review URL', function (): void {
    Log::spy();

    makeResourceReviewLinkMail()->failed(new RuntimeException('SMTP unavailable'));

    Log::shouldHaveReceived('error')
        ->once()
        ->with('Resource review email delivery failed', [
            'resource_id' => 42,
            'error' => 'SMTP unavailable',
        ]);
});
