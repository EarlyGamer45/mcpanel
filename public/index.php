<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Core\Request;
use App\Core\Session;
use App\Http\Kernel;

$base = dirname(__DIR__);
App::boot($base);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    // HSTS only on a real HTTPS request: sending it over plain HTTP (IP
    // installations) is ignored by browsers at best and misleading at worst.
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

Session::start((array) config('session', []));
Kernel::boot();

$request = Request::capture();
Kernel::handle($request)->send();
