<?php
namespace App\Models;
use App\Domain\Foundation\Traits\BelongsToCollege; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\SoftDeletes; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AcademicCalendarEvent extends Model { use BelongsToCollege, SoftDeletes; protected $table='academic_calendar_events'; protected $guarded=['id']; protected function casts():array{return ['start_date'=>'date','end_date'=>'date'];} public function academicYear():BelongsTo{return $this->belongsTo(AcademicYear::class);} public function academicTerm():BelongsTo{return $this->belongsTo(AcademicTerm::class);} }
