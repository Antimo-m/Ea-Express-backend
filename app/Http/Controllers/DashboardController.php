<?php

namespace App\Http\Controllers;

use App\Support\DashboardPreview;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardPreview $preview): View
    {
        $now = now('Europe/Rome')->locale('it');

        return view('dashboard.index', [
            ...$preview->data(),
            'greeting' => match (true) {
                $now->hour < 12 => 'Buongiorno',
                $now->hour < 18 => 'Buon pomeriggio',
                default => 'Buonasera',
            },
            'today' => $now->translatedFormat('l j F Y'),
        ]);
    }
}
