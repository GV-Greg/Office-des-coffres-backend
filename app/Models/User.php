<?php

namespace App\Models;

use App\Notifications\PasswordResetLink;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Le lien de réinitialisation part au gabarit de l'Office et pointe vers le SITE DES JOUEURS, jamais vers le domaine d'administration (le
     * broker de Laravel appelle cette méthode, y compris pour le formulaire Blade de l'administration).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLink($token));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'pending_email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'deletion_notice_sent_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * @return HasMany<Character>
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'user_id');
    }
}
