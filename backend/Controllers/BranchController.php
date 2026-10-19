<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Models\Branch;
use App\Requests\BranchRequest;

class BranchController extends Controller {
    private Branch $branchModel;

    public function __construct(Branch $branchModel) {
        $this->branchModel = $branchModel;
    }

    public function index() {
        return Response::cacheable($this->branchModel->all(), 300);
    }

    public function store() {
        $request = new BranchRequest($this->getBody());
        $data = $request->validated();
        $id = $this->branchModel->create($data);
        return Response::success(['id' => $id], 'Branch created', 201);
    }

    public function update(string $id) {
        $id = $this->resolveId($id);
        $request = new BranchRequest($this->getBody());
        $data = $request->validated();
        $this->branchModel->update($id, $data);
        return Response::success(null, 'Branch updated');
    }
}
