<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Fragmento de un documento del curso. El embedding se escribe como texto «[x, y, …]», el formato de pgvector. */
#[Fillable(['course_document_id', 'course_id', 'orden', 'pagina', 'texto', 'embedding'])]
#[Hidden(['embedding'])]
class DocumentFragment extends Model
{
    public function documento(): BelongsTo
    {
        return $this->belongsTo(CourseDocument::class, 'course_document_id');
    }
}
