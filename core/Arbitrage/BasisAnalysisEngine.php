<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Builds executable spot/future basis observations from normalized depth books. */
final class BasisAnalysisEngine
{
    public function candidate(string $symbol,string $spotVenue,string $futureVenue,array $spotBook,array $futureBook,
        string $expiryAtUtc,string $observedAtUtc): ?array
    {
        foreach([$spotBook,$futureBook] as $book){
            if(empty($book['asks'])||empty($book['bids'])||empty($book['observed_at']))return null;
        }
        $expiry=strtotime($expiryAtUtc.' UTC');$observed=strtotime($observedAtUtc.' UTC');
        if($expiry===false||$observed===false||$expiry<=$observed)return null;
        $spotAsk=(float)$spotBook['asks'][0]['price'];$spotBid=(float)$spotBook['bids'][0]['price'];
        $futureAsk=(float)$futureBook['asks'][0]['price'];$futureBid=(float)$futureBook['bids'][0]['price'];
        if($spotAsk<=0||$spotBid<=0||$futureAsk<$futureBid||$futureBid<=0)return null;
        $days=($expiry-$observed)/86400;$basis=($futureBid-$spotAsk)/$spotAsk;
        $spotMid=($spotBid+$spotAsk)/2;$futureMid=($futureBid+$futureAsk)/2;
        return ['symbol'=>$symbol,'spot_venue'=>$spotVenue,'future_venue'=>$futureVenue,'spot_bid'=>$spotBid,'spot_ask'=>$spotAsk,
            'future_bid'=>$futureBid,'future_ask'=>$futureAsk,'spot_book'=>$spotBook,'future_book'=>$futureBook,
            'expiry_at'=>$expiryAtUtc,'days_to_expiration'=>$days,'basis'=>$basis,
            'annualized_basis'=>$basis*365/$days,'observed_at'=>$observedAtUtc,
            'relative_mid_basis'=>($futureMid-$spotMid)/max($spotMid,1e-12),
            'cost_model_complete'=>false,'cost_model_status'=>'BOOK_AND_EXPIRY_ONLY',
            'execution_validation'=>'DEPTH_REQUIRED'];
    }
}
