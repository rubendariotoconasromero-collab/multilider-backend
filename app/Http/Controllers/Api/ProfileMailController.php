<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProfileMailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class ProfileMailController extends Controller
{
    private function email(Request $request): string
    {
        $email = strtolower(trim($request->user()->correo));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) && str_ends_with($email, '@tecnoweb.space') && $request->user()->estado,
            422, 'Para usar este gestor necesitas un usuario activo con buzón @tecnoweb.space.');
        return $email;
    }

    private function key(Request $request): string
    {
        return \App\Services\ProfileMailSession::key($request);
    }

    public function status(Request $request)
    {
        $this->email($request);
        return response()->json(['email' => $request->user()->correo, 'connected' => Cache::has($this->key($request))]);
    }

    public function connect(Request $request, ProfileMailbox $mail)
    {
        $email = $this->email($request);
        $data = $request->validate(['password' => 'required|string|max:256']);
        try {
            $connection = $mail->open($email, $data['password']);
            $mail->close($connection);
            Cache::put($this->key($request), Crypt::encryptString($data['password']), now()->addMinutes(20));
            \App\Services\ProfileMailSession::remember($request);
            return response()->json(['email' => $email, 'connected' => true]);
        } catch (\Throwable $e) {
            Cache::forget($this->key($request));
            return response()->json(['message' => 'No se pudo conectar. Comprueba la contraseña del correo; si el buzón no existe, solicita su creación al administrador.'], 422);
        }
    }

    public function disconnect(Request $request)
    {
        \App\Services\ProfileMailSession::forget($request);
        return response()->json(['connected' => false]);
    }

    private function withMailbox(Request $request, ProfileMailbox $mail, callable $action)
    {
        $email = $this->email($request);
        $encrypted = Cache::get($this->key($request));
        if (!$encrypted) return response()->json(['message' => 'Vuelve a conectar tu buzón; la conexión dura 20 minutos.'], 409);
        $connection = null;
        try {
            $password = Crypt::decryptString($encrypted);
            $connection = $mail->open($email, $password);
        } catch (\Throwable $e) {
            Cache::forget($this->key($request));
            return response()->json(['message' => 'Se perdió la conexión al buzón. Vuelve a conectar con la contraseña del correo.'], 409);
        }
        try {
            return response()->json($action($connection, $email, $password));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo completar la operación de correo. Comprueba la conexión o vuelve a conectar el buzón.'], 502);
        } finally {
            if ($connection) $mail->close($connection);
        }
    }

    public function messages(Request $request, ProfileMailbox $mail)
    {
        $data = $request->validate(['folder' => ['required', Rule::in(ProfileMailbox::FOLDERS)], 'page' => 'sometimes|integer|min:1|max:100000']);
        return $this->withMailbox($request, $mail, fn($connection) => $mail->messages($connection, $data['folder'], (int)($data['page'] ?? 1)));
    }

    public function read(Request $request, ProfileMailbox $mail, int $uid)
    {
        $data = $request->validate(['folder' => ['required', Rule::in(ProfileMailbox::FOLDERS)]]);
        abort_unless($uid > 0, 404);
        return $this->withMailbox($request, $mail, fn($connection) => $mail->read($connection, $data['folder'], $uid));
    }

    public function move(Request $request, ProfileMailbox $mail, int $uid)
    {
        $data = $request->validate(['folder' => ['required', Rule::in(ProfileMailbox::FOLDERS)]]);
        abort_unless($uid > 0, 404);
        return $this->withMailbox($request, $mail, function ($connection) use ($mail, $data, $uid) {
            $mail->move($connection, $data['folder'], $uid);
            return ['message' => $data['folder'] === 'Trash' ? 'Mensaje restaurado.' : 'Mensaje movido a Papelera.'];
        });
    }

    public function send(Request $request, ProfileMailbox $mail)
    {
        $data = $request->validate(['to' => 'required|email:rfc|max:254', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:20000']);
        return $this->withMailbox($request, $mail, fn($connection, $email, $password) => $mail->send($connection, $email, $request->user()->nombre, $password, $data));
    }
}
