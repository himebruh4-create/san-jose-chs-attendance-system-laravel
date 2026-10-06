<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A login: Super Admin, Admin or Principal. Personnel who scan at the kiosk
 * are Teacher records and never log in.
 */
class Account extends Authenticatable
{
    public const ROLES = ['superadmin', 'admin', 'principal'];

    public const ROLE_LABELS = [
        'superadmin' => 'Administrative Aide III',
        'admin' => 'Administrative Officer IV',
        'principal' => 'Principal',
    ];

    protected $fillable = [
        'full_name', 'email', 'password', 'role',
        'security_question', 'security_answer_hash',
    ];

    protected $hidden = ['password', 'remember_token', 'security_answer_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_deleted', false);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? ucfirst($this->role);
    }

    /** Where this account lands after logging in. */
    public function homeRoute(): string
    {
        return $this->role.'.dashboard';
    }
}
