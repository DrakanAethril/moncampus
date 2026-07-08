<?php

namespace App\Service;

/**
 * Thrown by App\Service\GotenbergClient on any transport/HTTP failure talking to the Gotenberg
 * service - callers (the Livret Alternant PDF-export controller actions) catch this specifically
 * so a Gotenberg outage degrades to a flash message + redirect, never a raw 500 page, and never
 * affects the separate "View" HTML routes that don't depend on Gotenberg at all.
 */
class GotenbergUnavailableException extends \RuntimeException
{
}
