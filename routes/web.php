<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    if (str_starts_with((string) $request->userAgent(), 'GoogleHC')) {
        return response('OK');
    }

    return redirect('/admin');
});
