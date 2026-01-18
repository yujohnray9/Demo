<?php

namespace App\Models;

use App\Traits\UserPermissionsTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

class Deputy extends Authenticatable
{
    use UserPermissionsTrait,HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'id',
        'first_name',
        'middle_name',
        'last_name',
        'extension',
        'username',
        'office',
        'email',
        'password',
        'image',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getFullNameAttribute()
    {
        $name = $this->first_name;
        if ($this->middle_name) {
            $name .= ' ' . $this->middle_name;
        }
        $name .= ' ' . $this->last_name;
        if ($this->extension) {
            $name .= ' ' . $this->extension;
        }
        return $name;
    }

    public function isActive()
    {
        return trim(strtolower($this->status)) === 'activated';
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'apprehending_officer');
    }

    public function sentNotifications()
    {
        return $this->hasMany(Notification::class, 'sender_id');
    }

    public function violator()
    {
        return $this->hasOne(Violator::class, 'violator_id');
    }

    public function getImageUrlAttribute()
    {
        if (!$this->image) {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $this->image)) {
            return $this->image;
        }
        return asset('storage/' . $this->image);
    }
    public function receivedNotifications()
    {
        return $this->morphMany(Notification::class, 'target', 'target_type', 'target_id');
    }
}
