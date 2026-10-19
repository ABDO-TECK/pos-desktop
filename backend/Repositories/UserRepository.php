<?php

namespace App\Repositories;

use App\Models\User;
use App\Contracts\RepositoryInterface;

class UserRepository implements RepositoryInterface
{
    private User $model;

    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function findByEmail(string $email): ?array
    {
        return $this->model->findByEmail($email);
    }

    public function findById(int $id): ?array
    {
        return $this->model->findById($id);
    }

    public function findByIdInCurrentBranch(int $id): ?array
    {
        return $this->model->findByIdInCurrentBranch($id);
    }

    public function findForPasswordChangeInCurrentBranch(int $id): ?array
    {
        return $this->model->findForPasswordChangeInCurrentBranch($id);
    }

    public function createToken(int $userId): string
    {
        return $this->model->createToken($userId);
    }

    public function deleteToken(string $token): void
    {
        $this->model->deleteToken($token);
    }

    public function extendToken(string $token, string $expiresAt): void
    {
        $this->model->extendToken($token, $expiresAt);
    }

    public function all(array $filters = []): array
    {
        return $this->model->all($filters);
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): void
    {
        $this->model->update($id, $data);
    }

    public function revokeAllSessions(int $userId): void
    {
        $this->model->revokeAllSessions($userId);
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
    }

    public function getModel(): User
    {
        return $this->model;
    }
}
