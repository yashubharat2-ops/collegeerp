<?php
namespace App\Http\Requests\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', \App\Models\AcademicYear::class) ?? false; }
    public function rules(): array { return ['name' => ['required','string','max:255'], 'code' => ['required','string','max:50'], 'starts_on' => ['required','date'], 'ends_on' => ['required','date','after:starts_on'], 'status' => ['required','in:active,inactive']]; }
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $collegeId = app(\App\Support\Tenancy\TenantContext::class)->id();
            if (! $collegeId || ! $this->starts_on || ! $this->ends_on) return;
            if (\App\Models\AcademicYear::query()->where('college_id', $collegeId)->where(function ($query) { $query->whereBetween('starts_on', [$this->starts_on, $this->ends_on])->orWhereBetween('ends_on', [$this->starts_on, $this->ends_on])->orWhere(function ($q) { $q->where('starts_on', '<=', $this->starts_on)->where('ends_on', '>=', $this->ends_on); }); })->exists()) $validator->errors()->add('starts_on', 'Academic years cannot overlap for a college.');
        });
    }
}
