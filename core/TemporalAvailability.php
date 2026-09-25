<?php
declare(strict_types=1);

namespace Prometheus\core;

/** Conservative availability policy shared by external-data consumers/tests. */
final class TemporalAvailability
{
    /**
     * UNKNOWN historical rows are never eligible. A known publication time is
     * required in addition to ingestion when quality is EXACT; INGESTION_ONLY
     * uses the system's real receipt time as its conservative knowledge bound.
     */
    public static function isEligible(?string $observedAt, ?string $availableAt, ?string $ingestedAt, string $asOf, string $quality, bool $live = false): bool
    {
        $cutoff = strtotime($asOf);
        // DATETIME event/AsOf fields use the application timezone; lineage fields are UTC.
        if ($cutoff === false || $ingestedAt === null || ($ingested = strtotime($ingestedAt . ' UTC')) === false || $ingested > $cutoff) {
            return false;
        }
        if ($observedAt !== null && (($observed = strtotime($observedAt)) === false || $observed > $cutoff)) {
            return false;
        }
        if ($availableAt !== null) {
            $available = strtotime($availableAt . ' UTC');
            if ($available === false || $available > $cutoff || $ingested < $available) return false;
        }
        if ($quality === 'EXACT') {
            $available = $availableAt === null ? false : strtotime($availableAt . ' UTC');
            return $available !== false && $available <= $cutoff;
        }
        if ($quality === 'INGESTION_ONLY') return true;
        return false;
    }
}
