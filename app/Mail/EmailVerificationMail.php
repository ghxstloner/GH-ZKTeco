<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $pin;
    public $crewId;

    /**
     * Create a new message instance.
     */
    public function __construct(string $pin, string $crewId)
    {
        $this->pin = $pin;
        $this->crewId = $crewId;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Verificación de Email - CrewManager')
                    ->from('fecheverria@echcarst.myscriptcase.com', 'Crew Manager - AITSA')
                    ->view('emails.verification')
                    ->with([
                        'pin' => $this->pin,
                        'crew_id' => $this->crewId,
                    ]);
    }
}
