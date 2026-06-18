<?php

namespace Evalty\Survey\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SurveyInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $survey;

    public $link;

    public function __construct($survey, $link)
    {
        $this->survey = $survey;
        $this->link = $link;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are invited to take a survey'
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.survey-invite'
        );
    }
}
