<?php

namespace App\Contracts;

use App\Models\Booking;

interface PaymentGateway
{
    /**
     * Create a hosted checkout session for the booking's resource price and
     * return the URL the client should be redirected to in order to pay.
     */
    public function createCheckoutSession(Booking $booking): string;
}
