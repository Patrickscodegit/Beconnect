<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

class ArticleSelector extends Field
{
    protected string $view = 'filament.forms.components.article-selector';
    
    protected mixed $serviceType = null;
    protected mixed $customerType = null;
    protected mixed $carrierCode = null;
    
    public function serviceType($serviceType): static
    {
        $this->serviceType = $serviceType;
        
        return $this;
    }
    
    public function customerType($customerType): static
    {
        $this->customerType = $customerType;
        
        return $this;
    }
    
    public function carrierCode($carrierCode): static
    {
        $this->carrierCode = $carrierCode;
        
        return $this;
    }
    
    public function getServiceType(): ?string
    {
        return $this->evaluate($this->serviceType);
    }
    
    public function getCustomerType(): ?string
    {
        return $this->evaluate($this->customerType);
    }
    
    public function getCarrierCode(): ?string
    {
        return $this->evaluate($this->carrierCode);
    }
}

