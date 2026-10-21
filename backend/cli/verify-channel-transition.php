<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403);exit; }
$planPath=$argv[1]??'';
if (!preg_match('/^[a-f0-9]{64}\.plan\.json$/D',basename($planPath)) || realpath(dirname($planPath))!==realpath(__DIR__)) exit(2);
$plan=json_decode((string)file_get_contents($planPath),true,64,JSON_THROW_ON_ERROR);
if (!is_array($plan) || basename($planPath)!==$plan['receipt_id'].'.plan.json' || !is_file($plan['source_phar']) || !hash_equals($plan['source_sha256'],hash_file('sha256',$plan['source_phar']))) exit(3);
require 'phar://'.str_replace('\\','/',$plan['source_phar']).'/vendor/autoload.php';
require 'phar://'.str_replace('\\','/',$plan['source_phar']).'/Config/config.php';
(new App\Services\ChannelTransitionReceiptService($plan['root'],$plan['storage']))->observe($plan);
