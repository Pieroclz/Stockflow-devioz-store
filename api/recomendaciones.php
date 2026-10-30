<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/ai_recommendations.php';
header('Content-Type: application/json; charset=UTF-8');
function recommendation_api_response(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST')recommendation_api_response(405,['success'=>false,'message'=>'Método no permitido.']);
$input=json_decode((string)file_get_contents('php://input'),true);$input=is_array($input)?$input:[];
if(in_array(($input['action']??''),['confirm','finalize'],true)){
    $token=$input['confirmation']??'';$proposal=$input['items']??null;$reconciled=$input['reconciled']??null;
    if(!is_string($token)||!is_array($proposal)||!is_array($reconciled)||!recommendation_confirmation_matches($token,$proposal))recommendation_api_response(409,['success'=>false,'message'=>'La propuesta ya venció, fue alterada o fue confirmada. Genera una nueva propuesta.']);
    $record=recommendation_confirmation_record($token);$canonical=recommendation_confirmation_items($proposal);$reconciledLines=recommendation_reconciled_items($reconciled);
    if($record===null||$canonical===[]||$reconciledLines===[]||count($canonical)!==count($reconciledLines))recommendation_api_response(409,['success'=>false,'message'=>'La reconciliación de la propuesta no coincide. Genera una nueva propuesta.']);
    foreach($canonical as $index=>$line)if(($reconciledLines[$index]['id']??0)!==$line['id']||($reconciledLines[$index]['qty']??0)!==$line['qty'])recommendation_api_response(409,['success'=>false,'message'=>'El inventario cambió antes de confirmar. Genera una nueva propuesta.']);
    $connection=null;
    try{
        $request=is_array($record['request']??null)?$record['request']:[];$ids=array_map(static fn(array $line):int=>$line['id'],$canonical);
        $freshCandidates=recommendation_candidates_for_ids(db(),$request,$ids);$freshMap=[];foreach($freshCandidates as $candidate)$freshMap[(int)$candidate['id']]=$candidate;
        $freshSelection=array_map(static fn(array $line):array=>['id'=>$line['id'],'qty'=>$line['qty']],$canonical);
        $lineLimit=(int)($request['line_limit']??$request['limit']??count($canonical));
        $quantityLimit=(int)($request['quantity_limit']??RECOMMENDATION_MAX_QUANTITY);
        $totalQuantityLimit=(int)($request['total_quantity_limit']??RECOMMENDATION_MAX_TOTAL_QUANTITY);
        if($lineLimit<1||$lineLimit>RECOMMENDATION_MAX_LINES||$quantityLimit<1||$quantityLimit>RECOMMENDATION_MAX_QUANTITY||$totalQuantityLimit<1||$totalQuantityLimit>100)recommendation_api_response(409,['success'=>false,'message'=>'La propuesta no cumple los límites de compra. Genera una nueva propuesta.']);
        foreach($canonical as $line)if($line['qty']>$quantityLimit)recommendation_api_response(409,['success'=>false,'message'=>'La propuesta no cumple los límites de compra. Genera una nueva propuesta.']);
        $validated=validate_model_recommendations($freshSelection,$freshMap,$request['budget']??null,$lineLimit,$totalQuantityLimit);
        if(count($validated)!==count($canonical))recommendation_api_response(409,['success'=>false,'message'=>'El inventario cambió antes de confirmar. Genera una nueva propuesta.']);
        foreach($canonical as $index=>$line){$fresh=$freshMap[$line['id']]??null;$valid=$validated[$index]??null;if(!is_array($fresh)||!is_array($valid)||$valid['id']!==$line['id']||$valid['qty']!==$line['qty']||recommendation_money_cents($fresh['price']??null)!==$line['price_cents'])recommendation_api_response(409,['success'=>false,'message'=>'El precio, stock o publicación cambió antes de confirmar. Genera una nueva propuesta.']);}
        $connection=db();$connection->beginTransaction();
        $connection->exec('INSERT INTO ai_usage_daily(usage_day,confirmed_additions) VALUES(CURDATE(),1) ON DUPLICATE KEY UPDATE confirmed_additions=confirmed_additions+1');
        $connection->commit();
        if(!recommendation_confirmation_consume($token,$proposal))recommendation_api_response(409,['success'=>false,'message'=>'La propuesta ya fue confirmada.']);
        recommendation_api_response(200,['success'=>true]);
    }catch(PDOException $exception){
        if($connection instanceof PDO&&$connection->inTransaction())$connection->rollBack();
        error_log('AI confirmation metric failed: '.$exception->getMessage());
        recommendation_api_response(503,['success'=>false,'message'=>'No se pudo registrar la confirmación. Inténtalo nuevamente.']);
    }catch(Throwable $exception){
        if($connection instanceof PDO&&$connection->inTransaction())$connection->rollBack();
        error_log('AI confirmation failed: '.$exception->getMessage());
        recommendation_api_response(409,['success'=>false,'message'=>'El inventario cambió antes de confirmar. Genera una nueva propuesta.']);
    }
}
if((int)($_SESSION['recommendation_requests']??0)>=20)recommendation_api_response(429,['success'=>false,'message'=>'Límite temporal alcanzado. Inténtalo más tarde.']);
$_SESSION['recommendation_requests']=(int)($_SESSION['recommendation_requests']??0)+1;
try{
    $request=recommendation_request($input);$candidates=recommendation_candidates(db(),$request);$config=require __DIR__.'/../config/shopping_agent.php';$selection=ollama_recommendations($candidates,$request,$config);$provider='ollama';
    if($selection===null){$provider='rules';$rules=rule_based_recommendations($candidates,$request);$selection=array_map(static fn(array $product):array=>['id'=>(int)$product['id'],'qty'=>1],$rules);}
    $selectedIds=array_values(array_unique(array_map(static fn(array $line):int=>(int)$line['id'],$selection)));
    $freshCandidates=recommendation_candidates_for_ids(db(),$request,$selectedIds);
    $payload=recommendation_payload($freshCandidates,$selection,$request['budget'],$request['limit']);$confirmation=recommendation_confirmation_create($payload['items'],$request);
    try{$metric=$provider==='ollama'?'ollama_answers':'rule_fallbacks';db()->exec("INSERT INTO ai_usage_daily(usage_day,searches,$metric) VALUES(CURDATE(),1,1) ON DUPLICATE KEY UPDATE searches=searches+1,$metric=$metric+1");}catch(PDOException $exception){error_log('AI usage metric failed: '.$exception->getMessage());}
    recommendation_api_response(200,['success'=>true,'provider'=>$provider,'confirmation'=>$confirmation]+$payload);
}catch(Throwable $exception){error_log('Recommendation API failed: '.$exception->getMessage());recommendation_api_response(400,['success'=>false,'message'=>'No se pudo preparar la recomendación. Inténtalo nuevamente.']);}
