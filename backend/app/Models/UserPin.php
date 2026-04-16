<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPin extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'candidate_id'];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
