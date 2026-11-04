<?php

namespace App\Services\Remote;

final class IdentityResponseMapper
{
    public static function user(array $dto, ?string $subject = null, bool $requireActive = true): array
    {
        if (! isset($dto['id'], $dto['nombre'], $dto['correo'], $dto['estado'])
            || ! is_int($dto['id']) || $dto['id'] < 1 || ($subject !== null && (string) $dto['id'] !== $subject)
            || ! is_string($dto['nombre']) || ! is_string($dto['correo']) || ! filter_var($dto['correo'], FILTER_VALIDATE_EMAIL)
            || ! is_bool($dto['estado']) || ($requireActive && ! $dto['estado']) || ! is_array($dto['rolesPermisos'] ?? null)) {
            throw new IntegrationException('Respuesta de identidad inválida.', 503, 'INVALID_SERVICE_RESPONSE');
        }
        $assignments = [];
        foreach ($dto['rolesPermisos'] as $row) {
            if (! is_array($row) || ! isset($row['rolPermisoId'], $row['rolId'], $row['permisoId'], $row['nombreRol'], $row['nombrePermiso'])) {
                throw new IntegrationException('Asignaciones de identidad inválidas.', 503, 'INVALID_SERVICE_RESPONSE');
            }
            $assignments[] = ['rol_permiso_id' => $row['rolPermisoId'], 'user_id' => $dto['id'],
                'rol_permiso' => ['id' => $row['rolPermisoId'], 'rol_id' => $row['rolId'], 'permiso_id' => $row['permisoId'],
                    'rol' => ['id' => $row['rolId'], 'nombre' => $row['nombreRol']],
                    'permiso' => ['id' => $row['permisoId'], 'nombre' => $row['nombrePermiso']]]];
        }

        return ['id' => $dto['id'], 'nombre' => $dto['nombre'], 'correo' => $dto['correo'], 'estado' => $dto['estado'],
            'roles_permisos' => $assignments, 'auth_source' => 'microservices'];
    }
}
