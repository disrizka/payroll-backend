<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Definisikan Gate 'admin'
        // Kode ini akan mengecek apakah role user yang sedang login adalah 'admin'
        Gate::define('admin', function (User $user) {
            return $user->role === 'admin';
        });
    }
}