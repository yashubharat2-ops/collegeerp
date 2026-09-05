<?php
namespace App\Http\Requests\Settings;
use Illuminate\Foundation\Http\FormRequest;
class UpdateInstitutionalSettingRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->hasPermission('settings.update') ?? false; }
    public function rules(): array { return ['key' => ['required','string','max:150','regex:/^[a-zA-Z0-9._-]+$/'], 'value' => ['nullable','string','max:10000'], 'type' => ['required','in:string,integer,boolean,json']]; }
}
