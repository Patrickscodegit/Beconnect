<?php

namespace Tests\Unit\Services\Robaws;

use App\Services\Robaws\ArticleNameParser;
use App\Models\Port;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ArticleNameParserTest extends TestCase
{
    use RefreshDatabase;

    protected ArticleNameParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ArticleNameParser();
        
        // Create test ports
        Port::create([
            'code' => 'ANR',
            'name' => 'Antwerp',
            'country' => 'Belgium',
            'is_european_origin' => true,
        ]);
        
        Port::create([
            'code' => 'CKY',
            'name' => 'Conakry',
            'country' => 'Guinea',
            'is_african_destination' => true,
        ]);
        
        Port::create([
            'code' => 'ABJ',
            'name' => 'Abidjan',
            'country' => 'Ivory Coast',
            'is_african_destination' => true,
        ]);
        
        Port::create([
            'code' => 'HAL',
            'name' => 'Halifax',
            'country' => 'Canada',
        ]);
    }

    /** @test */
    public function it_extracts_pol_from_simple_code_format()
    {
        $articleName = 'Seafreight (ANR) Export';
        $pol = $this->parser->extractPOL($articleName);
        
        $this->assertNotNull($pol);
        $this->assertEquals('ANR', $pol['code']);
        $this->assertEquals('Antwerp', $pol['name']);
        $this->assertEquals('Belgium', $pol['country']);
        $this->assertEquals('Antwerp (ANR), Belgium', $pol['formatted']);
    }

    /** @test */
    public function it_extracts_pol_from_code_with_numbers_format()
    {
        $articleName = 'ACL(ANR 1333) Halifax Canada';
        $pol = $this->parser->extractPOL($articleName);
        
        $this->assertNotNull($pol);
        $this->assertEquals('ANR', $pol['code']);
        $this->assertEquals('Antwerp (ANR), Belgium', $pol['formatted']);
    }

    /** @test */
    public function it_extracts_pol_from_code_with_slashes_format()
    {
        $articleName = 'Sallaum(ANR 332/740) Conakry Guinea';
        $pol = $this->parser->extractPOL($articleName);
        
        $this->assertNotNull($pol);
        $this->assertEquals('ANR', $pol['code']);
        $this->assertEquals('Antwerp (ANR), Belgium', $pol['formatted']);
    }

    /** @test */
    public function it_extracts_pod_from_city_country_format()
    {
        $articleName = 'Sallaum(ANR 332/740) Conakry Guinea, BIG VAN Seafreight';
        $pod = $this->parser->extractPOD($articleName);
        
        $this->assertNotNull($pod);
        $this->assertEquals('Conakry', $pod['name']);
        $this->assertEquals('Guinea', $pod['country']);
        $this->assertEquals('CKY', $pod['code']);
        $this->assertEquals('Conakry (CKY), Guinea', $pod['formatted']);
    }

    /** @test */
    public function it_extracts_pod_from_city_dash_country_format()
    {
        $articleName = 'Sallaum(ANR 332/740) Abidjan - Ivory Coast, LM Seafreight';
        $pod = $this->parser->extractPOD($articleName);
        
        $this->assertNotNull($pod);
        $this->assertEquals('Abidjan', $pod['name']);
        $this->assertEquals('Ivory Coast', $pod['country']);
        $this->assertEquals('ABJ', $pod['code']);
        $this->assertEquals('Abidjan (ABJ), Ivory Coast', $pod['formatted']);
    }

    /** @test */
    public function it_returns_null_for_articles_without_pol()
    {
        $articleName = '20ft FR Flatrack seafreight (head)';
        $pol = $this->parser->extractPOL($articleName);
        
        $this->assertNull($pol);
    }

    /** @test */
    public function it_returns_null_for_articles_without_pod()
    {
        $articleName = '40ft FR Flatrack seafreight (head)';
        $pod = $this->parser->extractPOD($articleName);
        
        $this->assertNull($pod);
    }

    /** @test */
    public function it_extracts_shipping_line()
    {
        $this->assertEquals('Grimaldi', $this->parser->extractShippingLine('Grimaldi(ANR) Alexandria Egypt'));
        $this->assertEquals('ACL', $this->parser->extractShippingLine('ACL(ANR 1333) Halifax Canada'));
        $this->assertEquals('Sallaum', $this->parser->extractShippingLine('Sallaum(ANR 332/740) Conakry Guinea'));
        $this->assertNull($this->parser->extractShippingLine('Unknown Carrier'));
    }

    /** @test */
    public function it_extracts_service_type()
    {
        $this->assertEquals('EXPORT', $this->parser->extractServiceType('FCL EXPORT to Dubai'));
        $this->assertEquals('IMPORT', $this->parser->extractServiceType('FCL IMPORT from Hamburg'));
        $this->assertEquals('RORO', $this->parser->extractServiceType('RORO Service'));
        $this->assertEquals('FCL', $this->parser->extractServiceType('FCL Container'));
        $this->assertEquals('SEAFREIGHT', $this->parser->extractServiceType('Seafreight service'));
        $this->assertNull($this->parser->extractServiceType('Unknown service'));
    }

    /** @test */
    public function it_extracts_all_metadata_at_once()
    {
        $articleName = 'Sallaum(ANR 332/740) Conakry Guinea, BIG VAN Seafreight';
        $metadata = $this->parser->extractAll($articleName);
        
        $this->assertNotNull($metadata['pol']);
        $this->assertEquals('ANR', $metadata['pol']['code']);
        
        $this->assertNotNull($metadata['pod']);
        $this->assertEquals('Conakry', $metadata['pod']['name']);
        
        $this->assertEquals('Sallaum', $metadata['shipping_line']);
        $this->assertEquals('SEAFREIGHT', $metadata['service_type']);
    }

    /** @test */
    public function it_handles_multiple_pol_patterns_correctly()
    {
        // Test that more specific patterns take precedence
        $testCases = [
            '(ANR)' => 'ANR',
            '(ANR 1333)' => 'ANR',
            '(ANR 332/740)' => 'ANR',
        ];
        
        foreach ($testCases as $pattern => $expectedCode) {
            $pol = $this->parser->extractPOL("Test $pattern Test");
            $this->assertNotNull($pol, "Failed to extract POL from pattern: $pattern");
            $this->assertEquals($expectedCode, $pol['code'], "Wrong code for pattern: $pattern");
        }
    }

    /** @test */
    public function it_returns_unparsed_name_if_port_not_found_in_database()
    {
        $articleName = 'Carrier(XXX 123) UnknownCity UnknownCountry';
        
        $pol = $this->parser->extractPOL($articleName);
        $this->assertNotNull($pol);
        $this->assertEquals('XXX', $pol['code']);
        $this->assertEquals('XXX', $pol['formatted']); // Falls back to just the code
        
        $pod = $this->parser->extractPOD($articleName);
        $this->assertNotNull($pod);
        $this->assertEquals('UnknownCity', $pod['name']);
        $this->assertEquals('UnknownCity', $pod['formatted']); // Falls back to just the name
    }
}

