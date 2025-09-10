<?php

namespace App\Support;

final class CustomerNormalizer
{
    public function normalize(array $extraction, array $opts = []): array
    {
        $f = $extraction['fields'] ?? $extraction;
        $rawText = $extraction['raw_text'] ?? '';
        
        // Handle nested structure (contact, raw_data, etc.)
        if (isset($extraction['contact']) || isset($extraction['raw_data'])) {
            $f = array_merge(
                $f, 
                $extraction['contact'] ?? [], 
                $extraction['raw_data'] ?? []
            );
        }

        // Prefer explicit company match if provided
        $company = $opts['preferred_company'] ?? null;
        $company = $company ?: (
            $f['company'] ?? 
            $f['company_name'] ?? 
            $f['client_name'] ?? 
            $f['customer_name'] ?? 
            $f['sender_company'] ?? 
            $f['contact']['company'] ?? 
            null
        );

        // Extract company from raw text if not found in structured data
        if (!$company && $rawText) {
            // Look for company patterns like "We are [Company Name]" or "From: [Company]"
            if (preg_match('/(?:we are|from:|company:|société:|firma:)\s*([A-Z][A-Za-z\s&\-\.BV\sNV\sLtd\sLLC\sGmbH\sInc]+?)(?:\s*[,\.]|\s*$)/i', $rawText, $matches)) {
                $company = trim($matches[1]);
            }
            // Look for email domain-based company
            elseif (preg_match('/@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $rawText, $matches)) {
                $domain = $matches[1];
                $domainParts = explode('.', $domain);
                if (!in_array($domainParts[0], ['gmail', 'yahoo', 'hotmail', 'outlook', 'live'])) {
                    $company = ucfirst($domainParts[0]);
                }
            }
        }

        // Contact person
        $contactName = $f['contact_name'] ?? 
                      $f['contact']['name'] ?? 
                      $f['person'] ?? 
                      $f['from_name'] ?? 
                      $f['sender_name'] ?? 
                      null;

        // Extract contact name from signature or email content
        if (!$contactName && $rawText) {
            // Look for signature patterns
            if (preg_match('/(?:best regards|regards|cordialement|met vriendelijke groet)[,\s]*\n\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)*)/i', $rawText, $matches)) {
                $contactName = trim($matches[1]);
            }
        }

        // Email
        $email = $f['client_email'] ?? 
                $f['customer_email'] ?? 
                $f['email'] ?? 
                $f['contact']['email'] ?? 
                $f['from_email'] ?? 
                $f['sender_email'] ?? 
                null;

        // Extract email from raw text if not found
        if (!$email && $rawText) {
            if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $rawText, $matches)) {
                $email = $matches[0];
            }
        }

        // Phone (WhatsApp acceptable)
        $phone = $f['client_phone'] ?? 
                $f['customer_phone'] ?? 
                $f['phone'] ?? 
                $f['contact']['phone'] ?? 
                $f['tel'] ?? 
                $f['telephone'] ?? 
                $f['whatsapp'] ?? 
                null;

        // Mobile/GSM
        $mobile = $f['client_mobile'] ?? 
                 $f['customer_mobile'] ?? 
                 $f['mobile'] ?? 
                 $f['contact']['mobile'] ?? 
                 $f['gsm'] ?? 
                 null;

        // Extract phone numbers from raw text if not found
        if ((!$phone || !$mobile) && $rawText) {
            // Look for phone patterns with labels
            preg_match_all('/(?:tel|phone|tél|telefoon|mobile|gsm)[\s\.:]*(\+?[\d\s\(\)\-\.]{8,})/i', $rawText, $phoneMatches, PREG_SET_ORDER);
            
            foreach ($phoneMatches as $match) {
                $foundPhone = trim($match[1]);
                $label = strtolower($match[0]);
                
                if (str_contains($label, 'mobile') || str_contains($label, 'gsm')) {
                    if (!$mobile) $mobile = $foundPhone;
                } else {
                    if (!$phone) $phone = $foundPhone;
                }
            }
            
            // Generic phone pattern fallback
            if (!$phone && !$mobile) {
                if (preg_match('/(\+?\d[\d\s\(\)\-\.]{7,}\d)/', $rawText, $matches)) {
                    $phone = $matches[1];
                }
            }
        }

        $phone = $this->normalizePhone($phone, $opts['default_country'] ?? 'BE');
        $mobile = $this->normalizePhone($mobile, $opts['default_country'] ?? 'BE');

        // VAT
        $vat = $f['vat'] ?? 
              $f['vat_number'] ?? 
              $f['btw'] ?? 
              $f['btw_nummer'] ?? 
              $f['client_vat'] ?? 
              $f['customer_vat'] ?? 
              null;

        // Extract VAT from raw text
        if (!$vat && $rawText) {
            if (preg_match('/(?:vat|btw|tax)[\s\w]*[:\s]*([A-Z]{0,2}[\s\-\.]?\d[\d\s\.\-]{6,})/i', $rawText, $matches)) {
                $vat = trim($matches[1]);
            }
        }

        $vat = $this->normalizeVat($vat, $opts['default_country'] ?? 'BE');

        // Website
        $website = $f['website'] ?? 
                  $f['web'] ?? 
                  $f['url'] ?? 
                  null;

        // Extract website from raw text or email domain
        if (!$website && $rawText) {
            if (preg_match('/(?:website|web|www)[\s\.:]*([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $rawText, $matches)) {
                $website = $matches[1];
            } elseif ($email && preg_match('/@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $email, $matches)) {
                $website = 'www.' . $matches[1];
            }
        }

        // Ensure website has protocol
        if ($website && !preg_match('/^https?:\/\//', $website)) {
            if (!str_starts_with($website, 'www.')) {
                $website = 'www.' . $website;
            }
        }

        // Address pieces
        $street = $f['street'] ?? 
                 $f['address'] ?? 
                 $f['client_address'] ?? 
                 $f['customer_address'] ?? 
                 null;

        $zip = $f['zip'] ?? 
              $f['postal'] ?? 
              $f['postcode'] ?? 
              $f['postal_code'] ?? 
              null;

        $city = $f['city'] ?? 
               $f['client_city'] ?? 
               $f['customer_city'] ?? 
               null;

        $country = $f['country'] ?? 
                  $f['client_country'] ?? 
                  $f['customer_country'] ?? 
                  ($opts['default_country'] ?? null);

        // Extract address components from raw text
        if ((!$street || !$city || !$zip) && $rawText) {
            // Look for address patterns (Belgian format: Street Number, B-ZIP City)
            if (preg_match('/([A-Za-z\s]+\d+[A-Za-z]?)\s*,?\s*([B-]?\d{4})\s+([A-Za-z\s\(\)]+)(?:\s*,?\s*(Belgium|België|Belgique))?/i', $rawText, $matches)) {
                if (!$street) $street = trim($matches[1]);
                if (!$zip) $zip = trim($matches[2]);
                if (!$city) $city = trim($matches[3]);
                if (!$country && !empty($matches[4])) $country = 'Belgium';
            }
        }

        // Normalize country
        if ($country) {
            $country = $this->normalizeCountry($country);
        }

        return [
            'name' => $company,
            'vat' => $vat,
            'email' => $email,
            'phone' => $phone,
            'mobile' => $mobile,
            'website' => $website,
            'contact' => [
                'name' => $contactName,
                'email' => $email,
                'phone' => $phone,
                'mobile' => $mobile,
            ],
            'address' => [
                'street' => $street,
                'zip' => $zip,
                'city' => $city,
                'country' => $country,
            ],
            'client_type' => $this->determineClientType($company, $vat),
        ];
    }

    private function normalizePhone(?string $raw, string $defaultCountry): ?string
    {
        if (!$raw) return null;
        
        // Clean up the phone number
        $s = preg_replace('/[^\d+]/', '', $raw);
        if ($s === '') return null;
        
        // Convert 00 prefix to +
        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
        }
        
        // If already has +, return as is
        if (str_starts_with($s, '+')) {
            return $s;
        }

        // Add country prefix based on default country
        if ($defaultCountry === 'BE') {
            // Remove leading 0 for Belgian numbers
            if (str_starts_with($s, '0')) {
                $s = substr($s, 1);
            }
            return '+32' . $s;
        }
        
        return $s;
    }

    private function normalizeVat(?string $vat, string $country): ?string
    {
        if (!$vat) return null;
        
        // Clean up VAT number
        $s = strtoupper(preg_replace('/\s+|[\.:,\-]/', '', $vat));
        
        // Ensure country prefix
        if ($country === 'BE' && !str_starts_with($s, 'BE')) {
            $s = 'BE' . $s;
        }
        
        return $s;
    }

    private function normalizeCountry(?string $country): ?string
    {
        if (!$country) return null;
        
        $countryMap = [
            'belgië' => 'Belgium',
            'belgique' => 'Belgium',
            'belgie' => 'Belgium',
            'be' => 'Belgium',
            'netherlands' => 'Netherlands',
            'nederland' => 'Netherlands',
            'nl' => 'Netherlands',
            'germany' => 'Germany',
            'deutschland' => 'Germany',
            'de' => 'Germany',
        ];
        
        $normalized = strtolower(trim($country));
        return $countryMap[$normalized] ?? ucfirst(strtolower($country));
    }

    private function determineClientType(?string $name, ?string $vat): string
    {
        // If has VAT number, likely a company
        if ($vat) {
            return 'company';
        }
        
        // Check for company indicators in name
        if ($name) {
            $companyIndicators = ['BV', 'NV', 'Ltd', 'LLC', 'GmbH', 'SA', 'Inc', 'Corp', 'Company', 'Co.'];
            foreach ($companyIndicators as $indicator) {
                if (stripos($name, $indicator) !== false) {
                    return 'company';
                }
            }
        }
        
        return 'individual';
    }
}
