<?php

namespace App\Services\RegulatoryCrawler;

/**
 * Immutable DTO representing a crawled regulatory circular,
 * before it's persisted into regulatory_circulars.
 */
final class CrawledCircular
{
    public function __construct(
        public readonly string $regulatorCode,
        public readonly string $circularNumber,
        public readonly string $title,
        public readonly ?\DateTimeImmutable $issuedAt,
        public readonly string $sourceUrl,
        public readonly ?string $pdfUrl = null,
        public readonly ?string $plainText = null,
    ) {}

    public function toArray(): array
    {
        return [
            'regulator_code' => $this->regulatorCode,
            'circular_number' => $this->circularNumber,
            'title' => $this->title,
            'issued_at' => $this->issuedAt?->format('Y-m-d'),
            'source_url' => $this->sourceUrl,
            'pdf_path' => $this->pdfUrl,
            'plain_text' => $this->plainText,
        ];
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->regulatorCode.'|'.$this->circularNumber.'|'.$this->title);
    }
}
