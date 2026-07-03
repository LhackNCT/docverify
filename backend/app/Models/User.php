<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Auth\Notifications\ResetPassword;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'password',
        'role',
        'telephone',
        'nom_institution',
        'type_institution',
        'adresse',
        'is_active',
        'is_certified',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'is_certified'      => 'boolean',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        ResetPassword::createUrlUsing(fn($notifiable, $t) =>
            rtrim(config('app.frontend_url'), '/') . '/reset-password?token=' . $t . '&email=' . urlencode($notifiable->email)
        );
        $this->notify(new ResetPassword($token));
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'emetteur_id');
    }

    public function revokedDocuments()
    {
        return $this->hasMany(Document::class, 'revoked_by');
    }

    public function demandesCertifications()
    {
        return $this->hasMany(DemandesCertification::class, 'user_id');
    }

    public function traiteDemandesCertifications()
    {
        return $this->hasMany(DemandesCertification::class, 'traite_par');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'admin_id');
    }
}
