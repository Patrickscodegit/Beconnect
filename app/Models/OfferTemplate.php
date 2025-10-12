<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class OfferTemplate extends Model
{
    protected $fillable = [
        'template_code',
        'template_name',
        'template_type',
        'service_type',
        'customer_type',
        'content',
        'available_variables',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'available_variables' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Render template with variable substitution
     *
     * @param array $variables Key-value pairs for template variables
     * @return string
     */
    public function render(array $variables): string
    {
        $content = $this->content;
        
        foreach ($variables as $key => $value) {
            $placeholder = '${' . $key . '}';
            $content = str_replace($placeholder, $value ?? '', $content);
        }
        
        return $content;
    }

    /**
     * Extract variables from template content
     *
     * @return array
     */
    public function extractVariables(): array
    {
        preg_match_all('/\$\{([^}]+)\}/', $this->content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Get list of missing variables for given data
     *
     * @param array $variables
     * @return array
     */
    public function getMissingVariables(array $variables): array
    {
        $required = $this->extractVariables();
        $provided = array_keys($variables);
        
        return array_diff($required, $provided);
    }

    /**
     * Check if all required variables are provided
     *
     * @param array $variables
     * @return bool
     */
    public function hasAllVariables(array $variables): bool
    {
        return empty($this->getMissingVariables($variables));
    }

    /**
     * Scopes
     */
    
    /**
     * Scope for active templates
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for service type
     */
    public function scopeForService(Builder $query, string $serviceType): Builder
    {
        return $query->where(function ($q) use ($serviceType) {
            $q->where('service_type', $serviceType)
              ->orWhere('service_type', 'GENERAL');
        });
    }

    /**
     * Scope for template type
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('template_type', $type);
    }

    /**
     * Scope for customer type
     */
    public function scopeForCustomerType(Builder $query, ?string $customerType): Builder
    {
        if (!$customerType) {
            return $query->whereNull('customer_type');
        }

        return $query->where(function ($q) use ($customerType) {
            $q->where('customer_type', $customerType)
              ->orWhereNull('customer_type'); // General templates
        });
    }

    /**
     * Scope ordered by sort_order
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('template_name');
    }

    /**
     * Static helper methods
     */
    
    /**
     * Get intro templates for service
     *
     * @param string $serviceType
     * @param string|null $customerType
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getIntroTemplates(string $serviceType, ?string $customerType = null)
    {
        return self::active()
            ->ofType('intro')
            ->forService($serviceType)
            ->forCustomerType($customerType)
            ->ordered()
            ->get();
    }

    /**
     * Get end templates for service
     *
     * @param string $serviceType
     * @param string|null $customerType
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getEndTemplates(string $serviceType, ?string $customerType = null)
    {
        return self::active()
            ->ofType('end')
            ->forService($serviceType)
            ->forCustomerType($customerType)
            ->ordered()
            ->get();
    }

    /**
     * Find template by code
     *
     * @param string $code
     * @return self|null
     */
    public static function findByCode(string $code): ?self
    {
        return self::where('template_code', $code)->first();
    }
}

