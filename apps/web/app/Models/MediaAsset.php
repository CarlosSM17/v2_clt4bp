<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['course_id', 'uid', 'ruta', 'mime', 'bytes', 'sha256', 'subido_por'])]
class MediaAsset extends Model {}
