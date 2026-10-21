<?php
declare(strict_types=1);
namespace App\Services;

use Closure;
use RuntimeException;

/** Server-owned, expiring, single-use authorization for a previewed operation. */
final class ChannelTransitionPreviewStore
{
    private Closure $clock;

    public function __construct(private string $directory, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn():int => time();
    }

    public function issue(int $userId, array $binding): array
    {
        if ($userId <= 0) throw new RuntimeException('Preview owner is required.');
        if (is_link($this->directory) || (!is_dir($this->directory) && !mkdir($this->directory,0700,true))) throw new RuntimeException('Preview storage unavailable.');
        $id = bin2hex(random_bytes(32));
        $expires = ($this->clock)() + 600;
        $handle = fopen($this->directory.'/'.$id.'.json','x+b');
        if (!$handle) throw new RuntimeException('Unable to create preview.');
        try {
            $raw = json_encode(['user_id'=>$userId,'binding'=>$this->digest($binding),'expires_at'=>$expires,'consumed'=>false],JSON_THROW_ON_ERROR);
            if (fwrite($handle,$raw)!==strlen($raw) || !fflush($handle)) throw new RuntimeException('Unable to save preview.');
        } finally { fclose($handle); }
        return ['preview_id'=>$id,'expires_at'=>$expires];
    }

    public function claim(string $id, int $userId, array $binding): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) return $this->refuse();
        $path = $this->directory.'/'.$id.'.json';
        if (is_link($this->directory) || is_link($path) || !is_file($path)) return $this->refuse();
        $handle = fopen($path,'r+b');
        if (!$handle) return $this->refuse();
        try {
            if (!flock($handle,LOCK_EX)) return $this->refuse();
            $data = json_decode(stream_get_contents($handle),true);
            if (!is_array($data) || ($data['user_id']??null)!==$userId || ($data['consumed']??true)!==false || ($data['expires_at']??0)<=($this->clock)() || !hash_equals((string)($data['binding']??''),$this->digest($binding))) return $this->refuse();
            $data['consumed'] = true;
            $raw = json_encode($data,JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle,0) || fwrite($handle,$raw)!==strlen($raw) || !fflush($handle)) return $this->refuse();
            return ['ok'=>true];
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }

    private function digest(array $binding): string
    {
        // The service uses a fixed named binding shape; preserve nested arrays exactly.
        ksort($binding);
        return hash('sha256',json_encode($binding,JSON_THROW_ON_ERROR));
    }

    private function refuse(): array
    {
        return ['ok'=>false,'reason_code'=>'preview_expired_or_changed'];
    }
}
