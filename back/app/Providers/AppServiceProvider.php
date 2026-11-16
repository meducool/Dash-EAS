<?php

namespace App\Providers;

use App\Support\Spreadsheet\TextSafeValueBinder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        // Excel exports: text starting with "=" stays text, never a formula.
        Cell::setValueBinder(new TextSafeValueBinder);

        RateLimiter::for('login', function (Request $request) {
            return [
                // 5 tries a minute per account and address…
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
                // …and 20 a minute per address, whatever the e-mail tried.
                Limit::perMinute(20)->by('login-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                optional($request->user())->id ?: $request->ip()
            );
        });
    }
}
