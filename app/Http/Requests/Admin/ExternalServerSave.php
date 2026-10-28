<?php

namespace App\Http\Requests\Admin;

use App\Models\Server;

class ExternalServerSave extends ServerSave
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $credentials = $this->input('credentials', []);
        if (!is_array($credentials)) {
            return;
        }
        $id = filter_var($this->input('id'), FILTER_VALIDATE_INT);
        $existing = $id ? Server::where('is_external', true)->find($id) : null;
        $saved = $existing && $existing->type === $this->input('type')
            ? ($existing->external_credentials ?? []) : [];
        foreach (['uuid', 'username', 'password'] as $key) {
            if ((!isset($credentials[$key]) || $credentials[$key] === '') && isset($saved[$key])) {
                $credentials[$key] = $saved[$key];
            }
        }
        $this->merge([
            'credentials' => $credentials,
            'server_port' => $this->input('port'),
            'rate' => 0,
            'enabled' => false,
            'parent_id' => null,
            'machine_id' => null,
        ]);
    }

    public function rules(): array
    {
        if (!in_array($this->input('type'), Server::VALID_TYPES, true)) {
            return ['type' => 'required|in:' . implode(',', Server::VALID_TYPES)];
        }
        $rules = parent::rules();
        $rules['id'] = 'nullable|integer|min:1';
        $rules['name'] = ['required', 'string', 'max:128', 'not_regex:/[\r\n\x00]/'];
        $rules['host'] = ['required', 'string', 'max:253', 'not_regex:/[\s\/@?#]/'];
        $rules['port'] = 'required|integer|between:1,65535';
        $rules['group_ids'] = 'required|array|min:1';
        $rules['group_ids.*'] = 'required|integer|exists:v2_server_group,id';
        $rules['show'] = 'required|boolean';
        $rules['sort'] = 'nullable|integer|min:0';
        $rules['credentials'] = 'required|array:uuid,username,password';
        $uuidRequired = in_array($this->input('type'), ['vless', 'vmess', 'tuic'], true);
        $rules['credentials.uuid'] = ($uuidRequired ? 'required' : 'nullable') . '|uuid';
        $rules['credentials.username'] = ['nullable', 'string', 'max:256', 'not_regex:/[:\r\n\x00]/'];
        $rules['credentials.password'] = [$uuidRequired && $this->input('type') !== 'tuic' ? 'nullable' : 'required', 'string', 'max:2048', 'not_regex:/[\r\n\x00]/'];
        if (in_array($this->input('type'), ['naive', 'http', 'socks', 'mieru'], true)) {
            $rules['credentials.username'][0] = 'required';
        }
        if ($this->input('type') === 'naive') {
            $rules['protocol_settings.tls'] = 'required|integer|in:1';
        }
        if ($this->input('type') === 'mieru') {
            $rules['protocol_settings.traffic_pattern'] = 'nullable|string';
        }
        if ($this->input('type') === 'tuic') {
            $rules['protocol_settings.version'] = 'required|integer|in:5';
        }
        return $rules;
    }
}
