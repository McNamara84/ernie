<?php

declare(strict_types=1);

use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

covers(WelcomeNewUser::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'name' => 'Max Mustermann',
        'email' => 'max@example.com',
    ]);
});

describe('envelope', function () {
    it('sets correct subject line', function () {
        $mailable = new WelcomeNewUser($this->user);
        $envelope = $mailable->envelope();

        expect($envelope->subject)->toBe('Welcome to ERNIE - Set Your Password');
    });
});

describe('content', function () {
    it('uses the correct view template', function () {
        $mailable = new WelcomeNewUser($this->user);
        $content = $mailable->content();

        expect($content->view)->toBe('emails.welcome-new-user')
            ->and($content->text)->toBe('emails.welcome-new-user-text');
    });

    it('passes user name to the view', function () {
        $mailable = new WelcomeNewUser($this->user);
        $content = $mailable->content();

        expect($content->with['userName'])->toBe('Max Mustermann');
    });

    it('passes welcome URL to the view', function () {
        $mailable = new WelcomeNewUser($this->user);
        $content = $mailable->content();

        expect($content->with['welcomeUrl'])->toBeString()
            ->and($content->with['welcomeUrl'])->toContain('welcome');
    });

    it('passes expiration info to the view', function () {
        $mailable = new WelcomeNewUser($this->user);
        $content = $mailable->content();

        expect($content->with['expiresIn'])->toBe('72 hours');
    });
});

describe('welcome URL', function () {
    it('generates a signed URL', function () {
        $mailable = new WelcomeNewUser($this->user);

        expect($mailable->welcomeUrl)->toContain('signature=');
    });

    it('includes the user ID in the URL', function () {
        $mailable = new WelcomeNewUser($this->user);

        expect($mailable->welcomeUrl)->toContain((string) $this->user->id);
    });

    it('generates a temporary signed route', function () {
        $mailable = new WelcomeNewUser($this->user);

        expect($mailable->welcomeUrl)->toContain('expires=');
    });
});

describe('attachments', function () {
    it('has no attachments', function () {
        $mailable = new WelcomeNewUser($this->user);

        expect($mailable->attachments())->toBeEmpty();
    });
});

describe('rendering', function () {
    it('is not queued (sent synchronously)', function () {
        $mailable = new WelcomeNewUser($this->user);

        // WelcomeNewUser does not implement ShouldQueue
        expect($mailable)->not->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });
});
