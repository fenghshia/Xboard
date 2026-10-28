<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExternalServerSave;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ExternalController extends Controller
{
    public function fetch()
    {
        return $this->success(Server::where('is_external', true)->orderBy('sort')->orderBy('id')->get());
    }

    public function save(ExternalServerSave $request)
    {
        $params = $request->validated();
        $server = isset($params['id'])
            ? Server::where('is_external', true)->findOrFail($params['id'])
            : new Server();
        $server->fill(Arr::except($params, ['id', 'credentials']));
        $server->is_external = true;
        $server->external_credentials = Arr::only($params['credentials'], match ($params['type']) {
            'vless', 'vmess' => ['uuid'],
            'tuic' => ['uuid', 'password'],
            'naive', 'http', 'socks', 'mieru' => ['username', 'password'],
            default => ['password'],
        });
        $server->group_ids = array_map('strval', $params['group_ids']);
        $server->parent_id = null;
        $server->machine_id = null;
        $server->enabled = false;
        $server->save();
        return $this->success(['id' => $server->id]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|min:1']);
        Server::where('is_external', true)->findOrFail($params['id'])->delete();
        return $this->success(true);
    }
}
