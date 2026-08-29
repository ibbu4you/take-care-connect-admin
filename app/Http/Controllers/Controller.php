<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 12 ships this class bare.
 *
 * AuthorizesRequests is added back because every controller in this app calls
 * `$this->authorize()`: routes carry a `permission:` middleware for the coarse
 * "may this person open this module at all" question, and the policies answer
 * the finer per-record ones — is this my client, am I the anchor on this
 * interview, is this the last administrator.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
