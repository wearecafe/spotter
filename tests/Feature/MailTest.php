<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTest extends TestCase
{
    public function test_the_resend_mailer_can_be_built_when_configured(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key']);

        $transport = Mail::mailer('resend')->getSymfonyTransport();

        $this->assertStringContainsString('resend', strtolower((string) $transport));
    }

    public function test_mail_test_command_sends_and_reports_missing_keys(): void
    {
        Mail::fake();
        $this->artisan('spotter:mail-test', ['email' => 'jon@example.com'])->assertSuccessful();

        config(['mail.default' => 'resend', 'services.resend.key' => null]);
        $this->artisan('spotter:mail-test', ['email' => 'jon@example.com'])->assertFailed();
    }
}
