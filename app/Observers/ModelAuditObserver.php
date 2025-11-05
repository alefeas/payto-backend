<?php

namespace App\Observers;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ModelAuditObserver
{
    protected function resolveCompanyId(Model $model): ?string
    {
        return $model->getAttribute('company_id');
    }

    protected function resolveUserId(Model $model): ?string
    {
        $userId = Auth::id();
        if ($userId) {
            return $userId;
        }

        // Fallbacks for models that track actor fields
        foreach (['registered_by', 'confirmed_by', 'user_id'] as $candidate) {
            if ($model->getAttribute($candidate)) {
                return (string) $model->getAttribute($candidate);
            }
        }

        return null;
    }

    protected function entityInfo(Model $model): array
    {
        return [
            'type' => class_basename($model),
            'id' => (string) $model->getKey(),
        ];
    }

    protected function audit(): AuditService
    {
        return app(AuditService::class);
    }

    public function created(Model $model): void
    {
        $companyId = $this->resolveCompanyId($model);
        $userId = $this->resolveUserId($model);
        if (!$companyId || !$userId) {
            return; // No registramos si faltan datos esenciales
        }

        $entity = $this->entityInfo($model);
        $this->audit()->log(
            $companyId,
            $userId,
            strtolower($entity['type']) . '.created',
            'Registro creado',
            $entity['type'],
            $entity['id'],
            [ 'attributes' => $model->getAttributes() ]
        );
    }

    public function updated(Model $model): void
    {
        $companyId = $this->resolveCompanyId($model);
        $userId = $this->resolveUserId($model);
        if (!$companyId || !$userId) {
            return;
        }

        $entity = $this->entityInfo($model);
        $changes = [];
        foreach ($model->getChanges() as $key => $newValue) {
            $changes[$key] = [
                'old' => $model->getOriginal($key),
                'new' => $newValue,
            ];
        }

        $this->audit()->log(
            $companyId,
            $userId,
            strtolower($entity['type']) . '.updated',
            'Registro actualizado',
            $entity['type'],
            $entity['id'],
            [ 'changes' => $changes ]
        );
    }

    public function deleted(Model $model): void
    {
        $companyId = $this->resolveCompanyId($model);
        $userId = $this->resolveUserId($model);
        if (!$companyId || !$userId) {
            return;
        }

        $entity = $this->entityInfo($model);
        $this->audit()->log(
            $companyId,
            $userId,
            strtolower($entity['type']) . '.deleted',
            'Registro eliminado',
            $entity['type'],
            $entity['id'],
            [ 'attributes' => $model->getOriginal() ]
        );
    }
}