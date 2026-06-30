<?php

declare(strict_types=1);

namespace BB\Scraper;

use BB\Database\DB;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PDO;

final class ProductScraper
{
    public function scrape(string $url): array
    {
        $this->validateUrl($url);

        $cached = $this->cached($url);
        if ($cached) {
            return $cached;
        }

        $html = $this->fetch($url);
        $product = $this->extract($url, $html);
        $this->store($product, $html);

        return $product;
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid URL');
        }

        $host = strtolower(preg_replace('/^www\./', '', $parts['host']));
        $allowed = defined('SCRAPER_ALLOWED_HOSTS') ? array_map('trim', explode(',', SCRAPER_ALLOWED_HOSTS)) : [];

        foreach ($allowed as $allowedHost) {
            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost))) {
                return;
            }
        }

        throw new InvalidArgumentException('Host is not allowed');
    }

    private function cached(string $url): ?array
    {
        $stmt = DB::pdo()->prepare(
            'SELECT * FROM scraped_products WHERE canonical_url = ? OR url = ? ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([$url, $url]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if (strtotime((string) $row['updated_at']) < time() - 3600) {
            return null;
        }

        return $this->rowToProduct($row);
    }

    private function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 14,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-IN,en;q=0.9',
                'Cache-Control: no-cache',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            ],
        ]);

        $html = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($html) || $html === '' || $status >= 400) {
            throw new InvalidArgumentException($error ?: 'Unable to fetch product page');
        }

        return $html;
    }

    private function extract(string $url, string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();

        $jsonLd = $this->jsonLdProduct($xpath);
        $offers = $jsonLd['offers'] ?? [];

        $title = $this->firstText($xpath, [
            '//*[@id="productTitle"]',
            '//h1[contains(@class,"pdp-title")]',
            '//h1[contains(@class,"pdp-name")]',
            '//h1',
        ]) ?: ($jsonLd['name'] ?? $this->meta($xpath, 'og:title'));

        $price = $this->firstText($xpath, [
            '//*[contains(@id,"corePrice") or contains(@id,"apex")]//*[contains(@class,"a-offscreen")]',
            '//*[contains(@class,"a-price")]//*[contains(@class,"a-offscreen")]',
            '//*[@id="priceblock_ourprice"]',
            '//*[@id="priceblock_dealprice"]',
            '//*[contains(@class,"pdp-price")]',
            '//*[contains(@class,"_30jeq3")]',
            '//*[contains(@class,"_16Jk6d")]',
            '//*[contains(@class,"Nx9bqj")]',
            '//*[contains(@class,"CxhGGd")]',
            '//*[contains(@class,"yRaY8j")]',
        ]) ?: $this->offerValue($offers, 'price') ?: $this->meta($xpath, 'product:price:amount');

        $currency = $this->offerValue($offers, 'priceCurrency')
            ?: $this->meta($xpath, 'product:price:currency')
            ?: (str_contains((string) $price, "\u{20B9}") ? 'INR' : null);

        $image = $this->firstAttr($xpath, [
            '//*[@id="landingImage"]',
            '//*[@id="imgTagWrapperId"]//img',
            '//img[contains(@class,"pdp-image")]',
            '//img[contains(@class,"image-grid-image")]',
            '//img[contains(@class,"_396cs4")]',
            '//img[contains(@class,"DByuf4")]',
        ], ['data-old-hires', 'data-a-hires', 'src'])
            ?: $this->jsonLdImage($jsonLd)
            ?: $this->meta($xpath, 'og:image')
            ?: $this->meta($xpath, 'twitter:image');

        $availability = $this->firstText($xpath, ['//*[@id="availability"]'])
            ?: $this->offerValue($offers, 'availability');
        $rating = $this->firstText($xpath, ['//*[@id="acrPopover"]//*[contains(@class,"a-icon-alt")]'])
            ?: ($jsonLd['aggregateRating']['ratingValue'] ?? null);
        $brand = $this->firstText($xpath, ['//*[@id="bylineInfo"]'])
            ?: ($jsonLd['brand']['name'] ?? $jsonLd['brand'] ?? null);
        $canonical = $this->firstAttr($xpath, ['//link[@rel="canonical"]'], ['href']) ?: $url;
        $host = strtolower(preg_replace('/^www\./', '', parse_url($url, PHP_URL_HOST) ?: ''));

        $confidence = 0.0;
        if ($title) $confidence += 0.35;
        if ($price) $confidence += 0.30;
        if ($image) $confidence += 0.20;
        if ($availability) $confidence += 0.05;
        if ($rating) $confidence += 0.05;
        if ($jsonLd) $confidence += 0.05;

        return [
            'url' => $url,
            'canonicalUrl' => $canonical,
            'sourceHost' => $host,
            'title' => $this->clean($title),
            'price' => $this->clean($price),
            'currency' => $this->clean($currency),
            'image' => $this->absoluteUrl($image, $url),
            'availability' => $this->clean($availability),
            'rating' => $this->clean($rating),
            'brand' => is_array($brand) ? null : $this->clean($brand),
            'source' => 'backend',
            'confidence' => min(1.0, $confidence),
            'extractedAt' => gmdate(DATE_ATOM),
        ];
    }

    private function store(array $product, string $html): void
    {
        $stmt = DB::pdo()->prepare(
            'INSERT INTO scraped_products (url, canonical_url, source_host, title, price, currency, image, availability, rating, brand, confidence, raw_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $product['url'],
            $product['canonicalUrl'],
            $product['sourceHost'],
            $product['title'],
            $product['price'],
            $product['currency'],
            $product['image'],
            $product['availability'],
            $product['rating'],
            $product['brand'],
            $product['confidence'],
            json_encode(['html_hash' => hash('sha256', $html), 'product' => $product], JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function rowToProduct(array $row): array
    {
        return [
            'url' => $row['url'],
            'canonicalUrl' => $row['canonical_url'],
            'sourceHost' => $row['source_host'],
            'title' => $row['title'],
            'price' => $row['price'],
            'currency' => $row['currency'],
            'image' => $row['image'],
            'availability' => $row['availability'],
            'rating' => $row['rating'],
            'brand' => $row['brand'],
            'source' => 'backend',
            'confidence' => (float) $row['confidence'],
            'extractedAt' => gmdate(DATE_ATOM, strtotime((string) $row['updated_at'])),
        ];
    }

    private function firstText(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                $text = $this->clean($node->textContent);
                if ($text) {
                    return $text;
                }
            }
        }
        return null;
    }

    private function firstAttr(DOMXPath $xpath, array $queries, array $attrs): ?string
    {
        foreach ($queries as $query) {
            foreach ($xpath->query($query) ?: [] as $node) {
                foreach ($attrs as $attr) {
                    $value = $node->attributes?->getNamedItem($attr)?->nodeValue;
                    if ($value) {
                        return $value;
                    }
                }
            }
        }
        return null;
    }

    private function meta(DOMXPath $xpath, string $name): ?string
    {
        $query = '//meta[@property="' . $name . '" or @name="' . $name . '"]/@content';
        $node = ($xpath->query($query) ?: [])->item(0);
        return $node ? $this->clean($node->nodeValue) : null;
    }

    private function jsonLdProduct(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode($node->textContent, true);
            $product = $this->findProductNode($decoded);
            if ($product) {
                return $product;
            }
        }
        return [];
    }

    private function findProductNode(mixed $node): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        $type = $node['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        if (in_array('Product', $types, true)) {
            return $node;
        }
        foreach (['@graph', 'itemListElement'] as $key) {
            foreach (($node[$key] ?? []) as $child) {
                $found = $this->findProductNode($child);
                if ($found) {
                    return $found;
                }
            }
        }
        foreach ($node as $child) {
            $found = $this->findProductNode($child);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    private function offerValue(mixed $offers, string $key): ?string
    {
        if (is_array($offers) && array_is_list($offers)) {
            return $this->offerValue($offers[0] ?? [], $key);
        }
        return is_array($offers) && isset($offers[$key]) ? (string) $offers[$key] : null;
    }

    private function jsonLdImage(array $jsonLd): ?string
    {
        $image = $jsonLd['image'] ?? null;
        if (is_string($image)) return $image;
        if (is_array($image) && isset($image[0]) && is_string($image[0])) return $image[0];
        if (is_array($image) && isset($image['url'])) return (string) $image['url'];
        return null;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) return null;
        $clean = trim(preg_replace('/\s+/', ' ', (string) $value) ?: '');
        return $clean === '' ? null : $clean;
    }

    private function absoluteUrl(?string $url, string $base): ?string
    {
        if (!$url) return null;
        if (filter_var($url, FILTER_VALIDATE_URL)) return $url;
        $parts = parse_url($base);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return $url;
        if (str_starts_with($url, '//')) return $parts['scheme'] . ':' . $url;
        if (str_starts_with($url, '/')) return $parts['scheme'] . '://' . $parts['host'] . $url;
        return $url;
    }
}
