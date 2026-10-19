<?php
namespace App\Middleware;

use App\Services\AuthService;

class BranchScope {
    private AuthService $auth;
    
    public function __construct(AuthService $auth) { 
        $this->auth = $auth; 
    }

    public function handle(callable $next): mixed {
        $user = $this->auth->user();
        if ($user) {
            $this->auth->setBranchId((int) ($user['branch_id'] ?? 1));
        } else {
            $this->auth->setBranchId(1);
        }
        return $next();
    }
}
