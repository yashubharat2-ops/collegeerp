<?php
namespace App\Http\Requests\Campus;
use Illuminate\Foundation\Http\FormRequest;
class StoreCampusRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', \App\Models\Campus::class) ?? false; }
    public function rules(): array { return ['name' => ['required','string','max:255'], 'code' => ['required','string','max:50'], 'address' => ['nullable','string','max:255'], 'status' => ['required','in:active,inactive']]; }
}
