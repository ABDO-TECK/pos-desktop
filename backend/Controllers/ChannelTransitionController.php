<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\ChannelTransitionService;
use App\Services\UpdateService;

final class ChannelTransitionController extends Controller
{
    public function __construct(private AuthService $auth,private ChannelTransitionService $transitions,private UpdateService $updates) {}

    public function preview(): array
    {
        if ($this->getBody()!==[]) return Response::error('Preview does not accept target overrides.',400);
        return Response::success($this->transitions->preview((int)$this->auth->user()['id'],$this->updates->getClientChannel()));
    }

    public function apply(): array
    {
        $body=$this->getBody();
        if (array_diff(array_keys($body),['preview_id','delta_capable']) || !is_string($body['preview_id']??null) || !preg_match('/^[a-f0-9]{64}$/D',$body['preview_id']) || !is_bool($body['delta_capable']??null)) return Response::error('Invalid transition confirmation.',400);
        $result=$this->transitions->prepare((int)$this->auth->user()['id'],$body['preview_id'],$body['delta_capable']);
        if (!$result['ok']) return Response::error('تعذر إكمال الانتقال. لم يتم تأكيد نجاحه؛ راجع حالة التحديث قبل إعادة المحاولة.',$result['code'],['reason_code'=>$result['reason_code']]);
        return Response::success($result);
    }
}
