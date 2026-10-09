<?php
declare(strict_types=1);
require_once __DIR__ . '/lumen_preferences.php';

/** Internal adapter only: caller must authenticate/authorize scope. No endpoint or import command. */
final class LumenFavoriteTransfer
{
    public function __construct(private PDO $db)
    {
        try{$mode=$db->getAttribute(PDO::ATTR_ERRMODE);}catch(Throwable $e){throw new RuntimeException('Transfer connection mode unavailable.');}
        if($mode!==PDO::ERRMODE_EXCEPTION){throw new RuntimeException('Transfer requires exception-mode PDO.');}
    }

    private static function canonical(string $raw): string
    {
        $keys=[];
        foreach(explode(',',$raw) as $part){
            $part=strtolower(trim($part)); if($part===''){continue;}
            if(!preg_match('~\A[a-z0-9_./-]{1,80}\z~',$part)){throw new InvalidArgumentException('Invalid favorite value.');}
            if(!in_array($part,$keys,true)){$keys[]=$part;}
        }
        if(count($keys)>40){throw new InvalidArgumentException('Favorite limit exceeded.');}
        return implode(',',$keys);
    }

    public static function prepare(string $requestKey,array $items): array
    {
        if(!preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/',$requestKey) || !$items || count($items)>10000){throw new InvalidArgumentException('Invalid transfer request.');}
        $scope=null; $people=[]; $actors=[]; $validated=[];
        foreach($items as $item){
            if(!is_array($item)){throw new InvalidArgumentException('Invalid transfer item.');}
            $keys=array_keys($item);sort($keys);
            if($keys!==['actor','connection','expected_raw','firma','personel','value']){throw new InvalidArgumentException('Invalid transfer fields.');}
            foreach(['connection','actor','value'] as $key){if(!is_string($item[$key])){throw new InvalidArgumentException('Invalid transfer type.');}}
            if(!is_int($item['firma']) || !is_int($item['personel'])){throw new InvalidArgumentException('Invalid scope type.');}
            lumen_favorites_scope(['plasiyer_id'=>$item['personel']],$item['personel'],1,$item['firma'],'LG_'.str_pad((string)$item['firma'],3,'0',STR_PAD_LEFT).'_',$item['connection']);
            if(!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/',$item['actor']) || self::canonical($item['value'])!==$item['value']){throw new InvalidArgumentException('Invalid canonical transfer value.');}
            if($item['expected_raw']!==null && (!is_string($item['expected_raw']) || strlen($item['expected_raw'])>3239 || self::canonical($item['expected_raw'])!==$item['value'])){throw new InvalidArgumentException('Overwrite intent rejected.');}
            $current=[$item['connection'],$item['firma']];
            if(($scope!==null && $scope!==$current) || isset($people[$item['personel']]) || isset($actors[$item['actor']])){throw new InvalidArgumentException('Mixed or duplicate identity.');}
            $scope=$current;$people[$item['personel']]=true;$actors[$item['actor']]=true;
            // Explicit order makes fingerprint independent of incoming JSON object key order.
            $validated[]=['connection'=>$item['connection'],'firma'=>$item['firma'],'personel'=>$item['personel'],'actor'=>$item['actor'],'value'=>$item['value'],'expected_raw'=>$item['expected_raw']];
        }
        usort($validated,fn($a,$b)=>$a['personel']<=>$b['personel']);
        return ['scope'=>$scope,'items'=>$validated,'fingerprint'=>hash('sha256',json_encode($validated,JSON_THROW_ON_ERROR))];
    }

    private function row(string $sql,array $params): array|false
    {
        $stmt=$this->db->prepare($sql);
        if($stmt===false || !$stmt->execute($params)){throw new RuntimeException('Transfer query failed.');}
        $row=$stmt->fetch(PDO::FETCH_ASSOC);$stmt->closeCursor();return $row;
    }

    public function apply(string $requestKey,array $items): array
    {
        $plan=self::prepare($requestKey,$items);
        if($this->db->inTransaction()){throw new RuntimeException('Separate transfer transaction required.');}
        $base=[':c'=>$plan['scope'][0],':f'=>$plan['scope'][1]];
        try{
            if(!$this->db->beginTransaction()){throw new RuntimeException('Transfer transaction unavailable.');}
            // Serializable key-range lock protects both missing and existing request keys.
            $prior=$this->row('SELECT FINGERPRINT,INSERTED_COUNT,UNCHANGED_COUNT FROM dbo.LUMEN_FAVORITE_TRANSFER WITH (UPDLOCK,HOLDLOCK) WHERE CONNECTION_KEY=:c AND FIRMA=:f AND REQUEST_KEY=:k',$base+[':k'=>$requestKey]);
            if($prior!==false){
                if(!hash_equals($plan['fingerprint'],(string)$prior['FINGERPRINT'])){throw new DomainException('Request key reused.');}
                if(!$this->db->commit()){throw new RuntimeException('Transfer commit uncertain.');}return ['inserted'=>(int)$prior['INSERTED_COUNT'],'unchanged'=>(int)$prior['UNCHANGED_COUNT'],'replay'=>true];
            }
            $inserted=0;$unchanged=0;
            foreach($plan['items'] as $item){
                $link=$this->row('SELECT ACTOR_ID FROM dbo.LUMEN_ACTOR_LINK WITH (UPDLOCK,HOLDLOCK) WHERE CONNECTION_KEY=:c AND FIRMA=:f AND LOGO_PERSONEL=:p',$base+[':p'=>$item['personel']]);
                if($link===false || strtolower((string)$link['ACTOR_ID'])!==$item['actor']){throw new DomainException('Actor mapping changed.');}
                $target=$this->row('SELECT FAVORITES FROM dbo.LUMEN_FAVORITES WITH (UPDLOCK,HOLDLOCK) WHERE CONNECTION_KEY=:c AND FIRMA=:f AND ACTOR_ID=:a',$base+[':a'=>$item['actor']]);
                if(($target===false?null:(string)$target['FAVORITES'])!==$item['expected_raw']){throw new DomainException('Target changed.');}
                if($target===false){
                    $stmt=$this->db->prepare('INSERT INTO dbo.LUMEN_FAVORITES (CONNECTION_KEY,FIRMA,ACTOR_ID,FAVORITES) VALUES (:c,:f,:a,:v)');
                    if($stmt===false || !$stmt->execute($base+[':a'=>$item['actor'],':v'=>$item['value']])){throw new RuntimeException('Transfer insert failed.');}$stmt->closeCursor();$inserted++;
                }else{$unchanged++;}
            }
            $stmt=$this->db->prepare('INSERT INTO dbo.LUMEN_FAVORITE_TRANSFER (CONNECTION_KEY,FIRMA,REQUEST_KEY,FINGERPRINT,INSERTED_COUNT,UNCHANGED_COUNT) VALUES (:c,:f,:k,:h,:i,:u)');
            if($stmt===false || !$stmt->execute($base+[':k'=>$requestKey,':h'=>$plan['fingerprint'],':i'=>$inserted,':u'=>$unchanged])){throw new RuntimeException('Transfer ledger failed.');}$stmt->closeCursor();
            if(!$this->db->commit()){throw new RuntimeException('Transfer commit uncertain.');}return ['inserted'=>$inserted,'unchanged'=>$unchanged,'replay'=>false];
        }catch(Throwable $e){
            try{if($this->db->inTransaction()){$this->db->rollBack();}}catch(Throwable $ignored){}
            if($e instanceof DomainException){throw new DomainException($e->getMessage());}
            $driverCode=$e instanceof PDOException?(int)($e->errorInfo[1]??0):0;
            $safeCode=in_array($driverCode,[1205,1222],true)?$driverCode:0;
            throw new RuntimeException('Transfer unavailable; reconcile or retry the same intent.',$safeCode);
        }
    }
}
