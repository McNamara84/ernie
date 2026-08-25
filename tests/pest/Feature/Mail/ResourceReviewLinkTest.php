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
        contactAddress: 'datapub@example.test',
    );
}

describe('envelope', function (): void {
    it('sets the subject, configured Reply-To and configured Cc', function (): void {
        $envelope = makeResourceReviewLinkMail()->envelope();

        expect($envelope->subject)->toBe('Your review link has changed - Seismic Review Dataset')
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
                'contactAddress' => 'datapub@example.test',
            ]);
    });

    it('renders the complete personalized migration notice in HTML and text', function (?string $doi): void {
        $mailable = makeResourceReviewLinkMail($doi);

        $html = $mailable->render();
        $text = view('emails.resource-review-link-text', $mailable->content()->with)->render();

        $expectedCopy = [
            'Dear Ada Reviewer,',
            'with this automated email we want to inform you that your review link has changed due to a server migration on our side. This change is necessary to continue providing our services as quickly as possible.',
            'Title:',
            'Seismic Review Dataset',
            'Your new review link:',
            'https://example.test/10.5880/test.review.001/seismic?preview=secret-token',
            'The old review link is no longer valid. Therefore, if your work is currently under review by a journal, we kindly ask you to resend the updated review link to the reviewers to grant them access before your dataset is published.',
            'The DOI link is not affected by this change and can be cited as usual.',
            'We expect to be able to process data publication requests again starting September 3. Until then, we appreciate your patience.',
            "This is an automated mail. Please do not reply to the sender's address.",
            'If you have any questions, please contact us via:',
            'datapub@example.test',
            'Kind regards,',
            'the data publication team at GFZ Data Services',
        ];

        foreach ($expectedCopy as $copy) {
            expect($html)->toContain($copy)
                ->and($text)->toContain($copy);
        }

        expect($html)->toContain('href="https://example.test/10.5880/test.review.001/seismic?preview=secret-token"')
            ->toContain('href="mailto:datapub@example.test"')
            ->not->toContain('Resource review requested')
            ->not->toContain('Please review the following resource before publication')
            ->not->toContain('This review link provides access to a non-public preview')
            ->not->toContain('Ernie Curator')
            ->not->toContain('curator@example.test')
            ->and($text)->not->toContain('GFZ DATA SERVICES - RESOURCE REVIEW LINK')
            ->not->toContain('Please review the following resource before publication')
            ->not->toContain('This review link provides access to a non-public preview')
            ->not->toContain('Ernie Curator')
            ->not->toContain('curator@example.test');

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
