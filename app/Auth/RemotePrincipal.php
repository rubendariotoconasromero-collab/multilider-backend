<?php

namespace App\Auth;

use Illuminate\Auth\GenericUser;
use JsonSerializable;

final class RemotePrincipal extends GenericUser implements JsonSerializable
{
    public function __construct(array $attributes, public readonly array $claims, private readonly string $credential)
    {
        parent::__construct($attributes);
    }

    public function hasPermission(string $permission): bool
    {
        foreach ($this->attributes['roles_permisos'] as $assignment) {
            if ($assignment['rol_permiso']['permiso']['nombre'] === $permission) {
                return true;
            }
        }

        return false;
    }

    public function credential(): string
    {
        return $this->credential;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->attributes;
    }
}
