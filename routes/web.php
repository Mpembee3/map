<?php

use App\Http\Controllers\FlightTrackController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::flight-map')->name('home');

Route::get('/flights/{flight}/track', FlightTrackController::class)->name('flights.track');
