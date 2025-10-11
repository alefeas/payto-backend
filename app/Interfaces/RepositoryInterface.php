<?php

namespace App\Interfaces;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    public function all(): Collection;
    
    public function find(int $id): ?Model;
    
    public function create(array $data): Model;
    
    public function update(int $id, array $data): bool;
    
    public function delete(int $id): bool;
    
    public function findBy(string $field, $value): ?Model;
    
    public function findWhere(array $criteria): Collection;
}
