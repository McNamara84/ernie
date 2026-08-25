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
            ->and($content->with)->toBe([
                'resourceTitle' => 'Seismic Review Dataset',
                'resourceDoi' => '10.5880/test.review.001',
                'reviewUrl' => 'https://example.test/10.5880/test.review.001/seismic?preview=secret-token',
                'recipientName' => 'Ada Reviewer',
                'initiatorName' => 'Ernie Curator',
                'initiatorEmail' => 'curator@example.test',
            ]);
    });

    it('renders the normal invitation without migration claims in HTML and text', function (?string $doi): void {
        $mailable = makeResourceReviewLinkMail($doi);

        $html = $mailable->render();
        $text = view('emails.resource-review-link-text', $mailable->content()->with)->render();

        $expectedCopy = [
            'Dear Ada Reviewer,',
            'Please review the following resource before publication:',
            'Title:',
            'Seismic Review Dataset',
            'Review link:',
            'https://example.test/10.5880/test.review.001/seismic?preview=secret-token',
            'If you have questions or feedback, please contact:',
            'Ernie Curator',
            'curator@example.test',
            'This review link provides access to a non-public preview. Please do not forward it beyond the intended review group.',
        ];

        foreach ($expectedCopy as $copy) {
            expect($html)->toContain($copy)
                ->and($text)->toContain($copy);
        }

        expect($html)->toContain('href="https://example.test/10.5880/test.review.001/seismic?preview=secret-token"')
            ->toContain('href="mailto:curator@example.test"')
            ->not->toContain('Your review link has changed')
            ->not->toContain('server migration')
            ->not->toContain('The old review link is no longer valid')
            ->and($text)->toContain('GFZ DATA SERVICES - RESOURCE REVIEW LINK')
            ->not->toContain('Your review link has changed')
            ->not->toContain('server migration')
            ->not->toContain('The old review link is no longer valid');

        if ($doi === null) {
            expect($html)->not->toContain('<strong>DOI:</strong>')
                ->and($text)->not->toContain('DOI:');
        } else {
            expect($html)->toContain("<strong>DOI:</strong> {$doi}")
                ->and($text)->toContain("DOI: {$doi}");
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
