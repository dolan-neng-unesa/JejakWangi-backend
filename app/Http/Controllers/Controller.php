<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Ukuran halaman dari ?per_page= yang dijepit ke 1..$max. Tanpa ini per_page=0 / negatif
     * memicu galat paginator dan per_page=1000000 membuat endpoint publik mengeluarkan
     * seluruh tabel dalam satu respons.
     */
    protected function perPage(Request $request, int $default = 10, int $max = 100): int
    {
        return max(1, min($max, (int) $request->query('per_page', $default)));
    }
}
