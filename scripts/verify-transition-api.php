<?php
declare(strict_types=1);
require __DIR__.'/../backend/vendor/autoload.php';
use App\Services\{AuthService,BackupService,ChannelTransitionService,DeltaUpdateService,FrontendBuildService,GitService,UpdateService};
use App\Middleware\{AuthMiddleware,CsrfMiddleware,EndpointRateLimiter,PermissionMiddleware};
use App\Core\RequestBody;
function apiAssert(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$router=new class {
    public array $routes=[];
    public function __call(string $method,array $arguments):void{$this->routes[strtoupper($method).' '.$arguments[0]]=$arguments[1];}
};
require __DIR__.'/../backend/routes/admin.php';
$preview=$router->routes['POST /api/updates/transition/preview'];$apply=$router->routes['POST /api/updates/transition/apply'];
apiAssert(in_array(AuthMiddleware::class,$preview[2],true)&&in_array(PermissionMiddleware::require('updates.check'),$preview[2],true),'Preview permission missing');
foreach(['updates.apply','updates.manage_channel'] as $permission)apiAssert(in_array(PermissionMiddleware::require($permission),$apply[2],true),'Apply permission missing: '.$permission);
apiAssert(in_array(EndpointRateLimiter::limit('transition_apply',2,300),$apply[2],true),'Apply rate limit changed');
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['REQUEST_URI']='/api/updates/transition/apply';unset($_COOKIE['XSRF-TOKEN']);
apiAssert((new CsrfMiddleware())->handle(static fn()=>['status_code'=>200])['status_code']===403,'Transition mutation bypassed CSRF');
$lab=sys_get_temp_dir().'/pos-transition-api-'.bin2hex(random_bytes(6));mkdir($lab);putenv('APP_DEPLOY_ROOT='.$lab);
$backup=new BackupService();$updates=new UpdateService(new GitService(),new FrontendBuildService(),$backup,new DeltaUpdateService(null,$lab,$lab));
$auth=new AuthService();$auth->setUser(['id'=>7,'role'=>'admin']);
$controller=new App\Controllers\ChannelTransitionController($auth,new ChannelTransitionService($updates,$backup),$updates);
$cached=new ReflectionProperty(RequestBody::class,'cachedJson');$hasCache=new ReflectionProperty(RequestBody::class,'hasCachedJson');$hasCache->setValue(null,true);
$cached->setValue(null,['target'=>'https://untrusted.invalid']);apiAssert($controller->preview()['status_code']===400,'Preview accepted target override');
foreach([[],['preview_id'=>'../outside','delta_capable'=>true],['preview_id'=>str_repeat('a',64),'delta_capable'=>'true'],['preview_id'=>str_repeat('a',64),'delta_capable'=>true,'force'=>true],['preview_id'=>str_repeat('a',64),'delta_capable'=>true,'url'=>'https://untrusted.invalid']] as $body){$cached->setValue(null,$body);apiAssert($controller->apply()['status_code']===400,'Unsafe apply input accepted');}
rmdir($lab);
echo "PASS transition API permissions, rate contract, CSRF and strict rejection of target/path/force/type overrides\n";
